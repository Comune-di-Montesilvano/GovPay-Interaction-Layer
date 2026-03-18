#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
OUTPUT_DIR="$SCRIPT_DIR/cieoidc"

FROM_PUBLIC=0
FORCE=0

for arg in "$@"; do
  case "$arg" in
    --from-public) FROM_PUBLIC=1 ;;
    --force)       FORCE=1 ;;
    -h|--help)
      echo "Utilizzo: bash metadata/export-cieoidc.sh [--from-public] [--force]"
      echo ""
      echo "  --from-public  esporta dagli endpoint pubblici invece che da localhost"
      echo "  --force        sovrascrive un export esistente non scaduto"
      echo ""
      echo "ATTENZIONE: una volta registrati nella federazione CIE OIDC, le chiavi"
      echo "esportate NON devono cambiare finché l'Entity Statement non è scaduto."
      exit 0
      ;;
    *)
      echo "[ERROR] Opzione non riconosciuta: $arg" >&2
      exit 1
      ;;
  esac
done

for ENV_FILE in "$PROJECT_ROOT/.env" "$PROJECT_ROOT/.iam-proxy.env"; do
  if [[ -f "$ENV_FILE" ]]; then
    set -o allexport
    source <(grep -v '^[[:space:]]*#' "$ENV_FILE" | grep -v '^[[:space:]]*$' | sed 's/\r//')
    set +o allexport
  fi
done

IAM_PROXY_HTTP_PORT="${IAM_PROXY_HTTP_PORT:-9445}"
IAM_PROXY_PUBLIC_BASE_URL="${IAM_PROXY_PUBLIC_BASE_URL:-}"
CIE_OIDC_CLIENT_ID="${CIE_OIDC_CLIENT_ID:-}"

if [[ -z "$CIE_OIDC_CLIENT_ID" ]]; then
  if [[ -n "$IAM_PROXY_PUBLIC_BASE_URL" ]]; then
    CIE_OIDC_CLIENT_ID="${IAM_PROXY_PUBLIC_BASE_URL%/}/CieOidcRp"
  else
    CIE_OIDC_CLIENT_ID="http://127.0.0.1:${IAM_PROXY_HTTP_PORT}/CieOidcRp"
  fi
fi

COMPONENT_IDENTIFIER="http://127.0.0.1:${IAM_PROXY_HTTP_PORT}/CieOidcRp"
if [[ "$FROM_PUBLIC" -eq 1 ]]; then
  COMPONENT_IDENTIFIER="${CIE_OIDC_CLIENT_ID%/}"
fi

ENTITY_CONFIG_URL="$COMPONENT_IDENTIFIER/.well-known/openid-federation"
JWKS_RP_JSON_URL="$COMPONENT_IDENTIFIER/openid_relying_party/jwks.json"
JWKS_RP_JOSE_URL="$COMPONENT_IDENTIFIER/openid_relying_party/jwks.jose"

PUBLIC_COMPONENT_IDENTIFIER="${CIE_OIDC_CLIENT_ID%/}"
PUBLIC_ENTITY_CONFIG_URL="$PUBLIC_COMPONENT_IDENTIFIER/.well-known/openid-federation"
PUBLIC_JWKS_RP_JSON_URL="$PUBLIC_COMPONENT_IDENTIFIER/openid_relying_party/jwks.json"
PUBLIC_JWKS_RP_JOSE_URL="$PUBLIC_COMPONENT_IDENTIFIER/openid_relying_party/jwks.jose"

FEDERATION_RESOLVE_ENDPOINT="${CIE_OIDC_FEDERATION_RESOLVE_ENDPOINT:-$COMPONENT_IDENTIFIER/resolve}"
FEDERATION_FETCH_ENDPOINT="${CIE_OIDC_FEDERATION_FETCH_ENDPOINT:-$COMPONENT_IDENTIFIER/fetch}"
FEDERATION_TRUST_MARK_STATUS_ENDPOINT="${CIE_OIDC_FEDERATION_TRUST_MARK_STATUS_ENDPOINT:-$COMPONENT_IDENTIFIER/trust_mark_status}"
FEDERATION_LIST_ENDPOINT="${CIE_OIDC_FEDERATION_LIST_ENDPOINT:-$COMPONENT_IDENTIFIER/list}"

mkdir -p "$OUTPUT_DIR"

# ---------------------------------------------------------------------------
# Guard: export esistente e non scaduto → rifiuta senza --force
# ---------------------------------------------------------------------------
PREV_COMPONENT_VALUES="$OUTPUT_DIR/component-values.env"
if [[ -f "$PREV_COMPONENT_VALUES" ]] && [[ "$FORCE" -eq 0 ]]; then
  PREV_EXP_EPOCH=""
  PREV_EXP_UTC=""
  PREV_DAYS=""
  while IFS='=' read -r key value; do
    case "$key" in
      ENTITY_STATEMENT_EXP_EPOCH)          PREV_EXP_EPOCH="$value" ;;
      ENTITY_STATEMENT_EXP_UTC)            PREV_EXP_UTC="$value" ;;
      ENTITY_STATEMENT_EXP_DAYS_REMAINING) PREV_DAYS="$value" ;;
    esac
  done < "$PREV_COMPONENT_VALUES"

  if [[ -n "$PREV_EXP_EPOCH" ]] && [[ "$PREV_EXP_EPOCH" =~ ^[0-9]+$ ]]; then
    NOW_EPOCH="$(date +%s)"
    if [[ "$PREV_EXP_EPOCH" -gt "$NOW_EPOCH" ]]; then
      echo "[ERROR] Export CIE OIDC già presente e non scaduto." >&2
      echo "        Scadenza: ${PREV_EXP_UTC:-sconosciuta} (${PREV_DAYS:-?} giorni residui)" >&2
      echo "" >&2
      echo "        Le chiavi federate NON devono cambiare finché l'Entity Statement è valido." >&2
      echo "        Usa --force solo se stai rinnovando consapevolmente la federazione" >&2
      echo "        (dopo aver rigenerato le chiavi con setup-cie-oidc.sh --force)." >&2
      exit 1
    fi
  fi
fi

echo "[INFO] Export CIE OIDC metadata da: $COMPONENT_IDENTIFIER"

curl -fsSL "$ENTITY_CONFIG_URL" -o "$OUTPUT_DIR/entity-configuration.jwt"
curl -fsSL "$JWKS_RP_JSON_URL" -o "$OUTPUT_DIR/jwks-rp.json"
curl -fsSL "$JWKS_RP_JOSE_URL" -o "$OUTPUT_DIR/jwks-rp.jose"

python3 - "$OUTPUT_DIR/entity-configuration.jwt" "$OUTPUT_DIR/entity-configuration.json" "$OUTPUT_DIR/jwks-federation-public.json" <<'PY'
import base64
import json
import sys
from pathlib import Path

jwt_path = Path(sys.argv[1])
json_path = Path(sys.argv[2])
jwks_fed_path = Path(sys.argv[3])

jwt = jwt_path.read_text(encoding='utf-8').strip()
parts = jwt.split('.')
if len(parts) < 2:
    raise SystemExit('JWT entity statement non valido')

payload = parts[1]
payload += '=' * ((4 - len(payload) % 4) % 4)
payload = payload.replace('-', '+').replace('_', '/')
raw = base64.b64decode(payload)
obj = json.loads(raw.decode('utf-8'))

json_path.write_text(json.dumps(obj, indent=2, ensure_ascii=False), encoding='utf-8')
keys = obj.get('jwks', {}).get('keys', [])
jwks_fed_path.write_text(json.dumps({"keys": keys}, indent=2, ensure_ascii=False), encoding='utf-8')

exp = obj.get('exp')
print(exp if exp is not None else '')
PY

EXP_EPOCH="$(python3 - "$OUTPUT_DIR/entity-configuration.json" <<'PY'
import json
import sys
from datetime import datetime, timezone

obj = json.load(open(sys.argv[1], encoding='utf-8'))
exp = obj.get('exp')
if exp is None:
    print('')
    print('')
    print('')
else:
    exp_dt = datetime.fromtimestamp(int(exp), tz=timezone.utc)
    days = int((exp_dt - datetime.now(timezone.utc)).total_seconds() // 86400)
    print(int(exp))
    print(exp_dt.strftime('%Y-%m-%dT%H:%M:%SZ'))
    print(days)
PY
)"

EXP_LINE1="$(echo "$EXP_EPOCH" | sed -n '1p')"
EXP_LINE2="$(echo "$EXP_EPOCH" | sed -n '2p')"
EXP_LINE3="$(echo "$EXP_EPOCH" | sed -n '3p')"

cat > "$OUTPUT_DIR/component-values.env" <<EOF
COMPONENT_IDENTIFIER=$COMPONENT_IDENTIFIER
PUBLIC_COMPONENT_IDENTIFIER=$PUBLIC_COMPONENT_IDENTIFIER
ENTITY_CONFIG_URL=$ENTITY_CONFIG_URL
PUBLIC_ENTITY_CONFIG_URL=$PUBLIC_ENTITY_CONFIG_URL
FEDERATION_RESOLVE_ENDPOINT=$FEDERATION_RESOLVE_ENDPOINT
FEDERATION_FETCH_ENDPOINT=$FEDERATION_FETCH_ENDPOINT
FEDERATION_TRUST_MARK_STATUS_ENDPOINT=$FEDERATION_TRUST_MARK_STATUS_ENDPOINT
FEDERATION_LIST_ENDPOINT=$FEDERATION_LIST_ENDPOINT
JWKS_FEDERATION_PUBLIC_FILE=metadata/cieoidc/jwks-federation-public.json
JWKS_RP_JSON_URL=$JWKS_RP_JSON_URL
JWKS_RP_JOSE_URL=$JWKS_RP_JOSE_URL
PUBLIC_JWKS_RP_JSON_URL=$PUBLIC_JWKS_RP_JSON_URL
PUBLIC_JWKS_RP_JOSE_URL=$PUBLIC_JWKS_RP_JOSE_URL
ENTITY_STATEMENT_EXP_EPOCH=$EXP_LINE1
ENTITY_STATEMENT_EXP_UTC=$EXP_LINE2
ENTITY_STATEMENT_EXP_DAYS_REMAINING=$EXP_LINE3
EOF

echo "[OK] Export CIE OIDC completato in $OUTPUT_DIR"
echo ""
echo "========================================================"
echo "  Per il portale CIE OIDC usare:"
echo "    File JWT : $OUTPUT_DIR/entity-configuration.jwt"
echo "    JWKS fed : $OUTPUT_DIR/jwks-federation-public.json"
echo "    Entity ID: $PUBLIC_COMPONENT_IDENTIFIER"
echo "  IMPORTANTE: nel form del portale inserire l'Entity ID"
echo "  nel campo \"sub\" / \"Identificativo Soggetto\"."
echo "========================================================"
