#!/usr/bin/env bash
# =============================================================================
# metadata/setup-sp.sh
#
# Genera certificati SPID-compliant ed esporta metadata pubblico SATOSA (AgID).
# Da eseguire UNA VOLTA (o con --force) PRIMA di docker compose up.
#
# Output prodotto:
#   volume Docker (govpay_spid_certs): cert.pem + privkey.pem
#   metadata/agid/satosa_spid_public_metadata.xml   metadata pubblico da inviare ad AGID
#
# Utilizzo:
#   bash metadata/setup-sp.sh [--force] [--certs-only] [--metadata-only]
#
# Opzioni:
#   --force           rigenera anche se i file esistono già
#   --certs-only      genera solo i certificati SPID (skip metadata)
#   --metadata-only   esporta solo il metadata pubblico SATOSA (skip cert)
#
# Requisiti: Docker (per generazione cert e metadata, no dipendenze host)
#
# Su Windows: usare Git Bash oppure WSL.
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

SPID_CERTS_DOCKER_VOLUME="${SPID_CERTS_DOCKER_VOLUME:-govpay_spid_certs}"
AGID_METADATA_DIR="$SCRIPT_DIR/agid"
AGID_METADATA_FILE="$AGID_METADATA_DIR/satosa_spid_public_metadata.xml"

FORCE=0
CERTS_ONLY=0
METADATA_ONLY=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --force)         FORCE=1 ;;
        --certs-only)    CERTS_ONLY=1 ;;
        --metadata-only) METADATA_ONLY=1 ;;
        -h|--help)
            sed -n '/^# =/,/^# =/p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'
            exit 0
            ;;
        *)
            echo "[ERROR] Opzione non riconosciuta: $1" >&2
            exit 1
            ;;
    esac
    shift
done

# ---------------------------------------------------------------------------
# Carica variabili da .env
# ---------------------------------------------------------------------------
for ENV_FILE in "$PROJECT_ROOT/.env" "$PROJECT_ROOT/.iam-proxy.env"; do
    if [[ -f "$ENV_FILE" ]]; then
        set -o allexport
        # Rimuove CRLF (compatibilità Windows), righe vuote e commenti prima di fare source
        source <(grep -v '^[[:space:]]*#' "$ENV_FILE" | grep -v '^[[:space:]]*$' | sed 's/\r//')
        set +o allexport
    fi
done

# ---------------------------------------------------------------------------
# Valori con default (overridabili da .env o da variabili d'ambiente)
# ---------------------------------------------------------------------------
FRONTOFFICE_PUBLIC_BASE_URL="${FRONTOFFICE_PUBLIC_BASE_URL:-https://127.0.0.1:8444}"

SPID_CERT_COMMON_NAME="${SPID_CERT_COMMON_NAME:-${APP_ENTITY_NAME:-GovPay}}"
SPID_CERT_DAYS="${SPID_CERT_DAYS:-365}"
SPID_CERT_ENTITY_ID="${SPID_CERT_ENTITY_ID:-${FRONTOFFICE_PUBLIC_BASE_URL}/saml/sp}"
SPID_CERT_KEY_SIZE="${SPID_CERT_KEY_SIZE:-3072}"
SPID_CERT_LOCALITY_NAME="${SPID_CERT_LOCALITY_NAME:-Roma}"
SPID_CERT_ORG_ID="${SPID_CERT_ORG_ID:-PA:IT-${APP_ENTITY_IPA_CODE:-c_x000}}"
SPID_CERT_ORG_NAME="${SPID_CERT_ORG_NAME:-${APP_ENTITY_NAME:-GovPay}}"

IAM_PROXY_HTTP_PORT="${IAM_PROXY_HTTP_PORT:-9445}"

mkdir -p "$AGID_METADATA_DIR"
docker volume create "$SPID_CERTS_DOCKER_VOLUME" >/dev/null

spid_certs_present() {
    docker run --rm -v "$SPID_CERTS_DOCKER_VOLUME:/certs" alpine:latest sh -c "test -s /certs/cert.pem && test -s /certs/privkey.pem" >/dev/null 2>&1
}

pretty_print_xml_file() {
    local xml_file="$1"

    if command -v python3 >/dev/null 2>&1; then
        python3 - "$xml_file" <<'PY'
import sys
from xml.dom import minidom

path = sys.argv[1]
with open(path, "r", encoding="utf-8") as f:
    raw = f.read()

dom = minidom.parseString(raw.encode("utf-8"))
pretty = dom.toprettyxml(indent="  ", newl="\n", encoding="utf-8").decode("utf-8")
lines = [line for line in pretty.splitlines() if line.strip()]

with open(path, "w", encoding="utf-8", newline="\n") as f:
    f.write("\n".join(lines) + "\n")
PY
        return 0
    fi

    if command -v xmllint >/dev/null 2>&1; then
        xmllint --format "$xml_file" > "$xml_file.tmp" && mv "$xml_file.tmp" "$xml_file"
        return 0
    fi

    return 1
}

echo "========================================================"
echo "  GovPay Interaction Layer — Setup SP SPID"
echo "========================================================"
echo "  Project root:  $PROJECT_ROOT"
echo "  Certs volume:  $SPID_CERTS_DOCKER_VOLUME"
echo "  AgID dir:      $AGID_METADATA_DIR"
echo "  EntityID:      ${FRONTOFFICE_PUBLIC_BASE_URL}/saml/sp"
echo "========================================================"
echo ""

# ===========================================================================
# STEP 1: Certificati SPID-compliant
# ===========================================================================
if [[ "$METADATA_ONLY" -eq 0 ]]; then
    if spid_certs_present && [[ "$FORCE" -eq 0 ]]; then
        echo "[INFO] Certificati SPID già presenti — skip. (usa --force per rigenerare)"
    else
        echo "[INFO] Generazione certificati SPID..."
        echo "       Common Name:   $SPID_CERT_COMMON_NAME"
        echo "       Org ID:        $SPID_CERT_ORG_ID"
        echo "       Entity ID:     $SPID_CERT_ENTITY_ID"
        echo "       Validità:      ${SPID_CERT_DAYS} giorni"

        # Usa alpine via Docker: non richiede openssl installato sul host
        docker run --rm \
            -v "$SPID_CERTS_DOCKER_VOLUME:/certs" \
            -v "$SCRIPT_DIR/spid-gencert-public.sh:/scripts/spid-gencert-public.sh:ro" \
            -e COMMON_NAME="$SPID_CERT_COMMON_NAME" \
            -e DAYS="$SPID_CERT_DAYS" \
            -e ENTITY_ID="$SPID_CERT_ENTITY_ID" \
            -e KEY_LEN="$SPID_CERT_KEY_SIZE" \
            -e LOCALITY_NAME="$SPID_CERT_LOCALITY_NAME" \
            -e ORGANIZATION_IDENTIFIER="$SPID_CERT_ORG_ID" \
            -e ORGANIZATION_NAME="$SPID_CERT_ORG_NAME" \
            -e MD_ALG="sha256" \
            alpine:latest \
            sh -c '
                apk add --no-cache bash openssl curl jq >/dev/null 2>&1
                cd /certs
                bash /scripts/spid-gencert-public.sh
                cp /certs/crt.pem /certs/cert.pem
                cp /certs/key.pem /certs/privkey.pem
                # Permessi 644: uwsgi in iam-proxy-italia deve poter leggere la chiave
                chmod 644 /certs/*.pem
                rm -f /certs/crt.pem /certs/key.pem /certs/csr.pem
            '
        echo "[OK] Certificati generati:"
        echo "       Public cert: /certs/cert.pem (docker volume)"
        echo "       Private key: /certs/privkey.pem (docker volume)"
        echo ""
    fi
fi

# ===========================================================================
# STEP 2: Export metadata pubblico SATOSA (file da inviare ad AGID)
# ===========================================================================
if [[ "$CERTS_ONLY" -eq 0 ]]; then
    if ! spid_certs_present; then
        echo "[ERROR] Certificati SPID mancanti nel volume Docker '$SPID_CERTS_DOCKER_VOLUME' (/certs/cert.pem, /certs/privkey.pem)." >&2
        echo "        Esegui metadata/setup-sp.sh senza --metadata-only (o con --force) per generarli prima." >&2
        exit 1
    fi

        echo "[INFO] Sync progetto iam-proxy-italia..."
        docker compose --profile iam-proxy run --rm sync-iam-proxy

        echo "[INFO] Generazione/refresh metadata interno Frontoffice SP (volume Docker)..."
        docker compose --profile iam-proxy run --rm init-frontoffice-sp-metadata

        echo "[INFO] Avvio SATOSA/NGINX per export metadata pubblico..."
        docker compose --profile iam-proxy up -d --force-recreate satosa-mongo iam-proxy-italia satosa-nginx refresh-frontoffice-sp-metadata

        echo "[INFO] Export metadata pubblico SATOSA (/spidSaml2/metadata)..."
        PUBLIC_METADATA_URL="http://127.0.0.1:${IAM_PROXY_HTTP_PORT}/spidSaml2/metadata"
        ok=0
        for _ in $(seq 1 40); do
            if curl -fsSL "$PUBLIC_METADATA_URL" > "$AGID_METADATA_FILE" 2>/dev/null; then
                if grep -Eq '<EntityDescriptor|<md:EntityDescriptor' "$AGID_METADATA_FILE"; then
                    ok=1
                    break
                fi
            fi
            sleep 3
        done

        if [[ "$ok" -ne 1 ]]; then
            echo "[ERROR] Impossibile esportare metadata pubblico SATOSA" >&2
            exit 1
        fi

        if ! pretty_print_xml_file "$AGID_METADATA_FILE"; then
            echo "[WARN] Nessun formatter XML disponibile (python3/xmllint). Metadata salvato non formattato." >&2
        fi

    echo ""
        echo "[OK] Metadata pubblico esportato:"
        echo "       $AGID_METADATA_FILE"
    echo ""
        echo "  >>> Invia questo file ad AGID per la federazione SPID <<<"
    echo ""
fi

echo "========================================================"
echo "  Setup completato. Ora puoi eseguire:"
echo "    docker compose up -d"
echo "========================================================"
