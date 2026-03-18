#!/usr/bin/env bash
# =============================================================================
# metadata/setup-cie-oidc.sh
#
# Genera le chiavi JWK RSA per CIE OIDC e le salva in metadata/cieoidc-keys/.
# Da eseguire UNA VOLTA (o con --force) PRIMA di scripts/sync-iam-proxy-italia.sh.
#
# Le chiavi generate sono:
#   metadata/cieoidc-keys/jwk-federation.json  chiave firma federation
#   metadata/cieoidc-keys/jwk-core-sig.json    chiave firma core
#   metadata/cieoidc-keys/jwk-core-enc.json    chiave encryption core (RSA-OAEP)
#   metadata/cieoidc-keys/GENERATED_AT         timestamp generazione (file lock)
#
# ATTENZIONE: una volta federati su CIE OIDC, NON rigenerare le chiavi finché
# l'Entity Statement non è scaduto. Rigenerare rompe la federazione.
#
# Utilizzo:
#   bash metadata/setup-cie-oidc.sh [--force] [--i-know-what-i-am-doing]
#
# Opzioni:
#   --force                    rigenera anche se le chiavi esistono
#   --i-know-what-i-am-doing   richiesto con --force se l'export federazione esiste
#
# Requisiti: Docker attivo (usa python:3-slim, no dipendenze host)
# Su Windows: usare Git Bash oppure WSL.
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
KEYS_DIR="$SCRIPT_DIR/cieoidc-keys"
LOCK_FILE="$KEYS_DIR/GENERATED_AT"
EXPORT_CHECK="$SCRIPT_DIR/cieoidc/component-values.env"

FORCE=0
I_KNOW=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --force)                   FORCE=1 ;;
        --i-know-what-i-am-doing)  I_KNOW=1 ;;
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

echo "========================================================"
echo "  GovPay Interaction Layer — Setup chiavi CIE OIDC"
echo "========================================================"
echo "  Keys dir:  $KEYS_DIR"
echo "========================================================"
echo ""

# ---------------------------------------------------------------------------
# Guard: chiavi già presenti
# ---------------------------------------------------------------------------
if [[ -f "$LOCK_FILE" ]] && [[ "$FORCE" -eq 0 ]]; then
    GENERATED_AT="$(cat "$LOCK_FILE")"
    echo "[INFO] Chiavi CIE OIDC già presenti (generate il $GENERATED_AT) — skip."
    echo "       Usa --force per rigenerare (solo se sai cosa stai facendo)."
    exit 0
fi

# ---------------------------------------------------------------------------
# Guard: chiavi già federate (component-values.env esiste e non è scaduto)
# ---------------------------------------------------------------------------
if [[ "$FORCE" -eq 1 ]] && [[ -f "$EXPORT_CHECK" ]]; then
    EXP_EPOCH=""
    if grep -q 'ENTITY_STATEMENT_EXP_EPOCH=' "$EXPORT_CHECK" 2>/dev/null; then
        EXP_EPOCH="$(grep 'ENTITY_STATEMENT_EXP_EPOCH=' "$EXPORT_CHECK" | cut -d= -f2 | tr -d '[:space:]')"
    fi

    NOT_EXPIRED=0
    if [[ -n "$EXP_EPOCH" ]] && [[ "$EXP_EPOCH" =~ ^[0-9]+$ ]]; then
        NOW_EPOCH="$(date +%s)"
        if [[ "$EXP_EPOCH" -gt "$NOW_EPOCH" ]]; then
            NOT_EXPIRED=1
        fi
    fi

    if [[ "$NOT_EXPIRED" -eq 1 ]] && [[ "$I_KNOW" -eq 0 ]]; then
        EXP_UTC=""
        if grep -q 'ENTITY_STATEMENT_EXP_UTC=' "$EXPORT_CHECK" 2>/dev/null; then
            EXP_UTC="$(grep 'ENTITY_STATEMENT_EXP_UTC=' "$EXPORT_CHECK" | cut -d= -f2 | tr -d '[:space:]')"
        fi
        DAYS=""
        if grep -q 'ENTITY_STATEMENT_EXP_DAYS_REMAINING=' "$EXPORT_CHECK" 2>/dev/null; then
            DAYS="$(grep 'ENTITY_STATEMENT_EXP_DAYS_REMAINING=' "$EXPORT_CHECK" | cut -d= -f2 | tr -d '[:space:]')"
        fi
        echo "[ERROR] Le chiavi CIE OIDC risultano FEDERATE e l'Entity Statement non è scaduto." >&2
        echo "        Scadenza: ${EXP_UTC:-sconosciuta} (${DAYS:-?} giorni residui)" >&2
        echo "" >&2
        echo "        Rigenerare le chiavi ORA romperà la federazione CIE OIDC." >&2
        echo "        Se sei consapevole delle conseguenze e hai un piano di rinnovo," >&2
        echo "        usa: --force --i-know-what-i-am-doing" >&2
        exit 1
    fi
fi

# ---------------------------------------------------------------------------
# Generazione chiavi JWK tramite Docker (python:3-slim, senza dipendenze host)
# ---------------------------------------------------------------------------
echo "[INFO] Generazione chiavi JWK CIE OIDC (RSA 2048-bit, via Docker)..."
mkdir -p "$KEYS_DIR"

docker run --rm \
    -v "$KEYS_DIR:/keys" \
    python:3-slim \
    sh -c 'pip install cryptography --quiet && python3 - /keys' <<'PY'
import sys
import json
import base64
import hashlib
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.backends import default_backend

OUTPUT_DIR = Path(sys.argv[1])

def int_to_b64url(n):
    length = (n.bit_length() + 7) // 8
    return base64.urlsafe_b64encode(n.to_bytes(length, "big")).rstrip(b"=").decode()

def rfc7638_kid(e_b64, n_b64):
    """RFC 7638 JWK Thumbprint (SHA-256)"""
    canonical = json.dumps({"e": e_b64, "kty": "RSA", "n": n_b64},
                           separators=(",", ":"), sort_keys=True)
    digest = hashlib.sha256(canonical.encode()).digest()
    return base64.urlsafe_b64encode(digest).rstrip(b"=").decode()

def gen_rsa_jwk(extra_fields=None):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048, backend=default_backend())
    priv = key.private_numbers()
    pub = priv.public_numbers
    e_b64 = int_to_b64url(pub.e)
    n_b64 = int_to_b64url(pub.n)
    jwk = {
        "kty": "RSA",
        "kid": rfc7638_kid(e_b64, n_b64),
        "e": e_b64,
        "n": n_b64,
        "d": int_to_b64url(priv.d),
        "p": int_to_b64url(priv.p),
        "q": int_to_b64url(priv.q),
    }
    if extra_fields:
        jwk = {**extra_fields, **jwk}
    return jwk

# Chiave 1: federation signing
jwk_fed = gen_rsa_jwk()
(OUTPUT_DIR / "jwk-federation.json").write_text(json.dumps(jwk_fed, indent=2), encoding="utf-8")
print(f"[OK] jwk-federation.json  kid={jwk_fed['kid']}")

# Chiave 2: core signing
jwk_sig = gen_rsa_jwk({"use": "sig"})
(OUTPUT_DIR / "jwk-core-sig.json").write_text(json.dumps(jwk_sig, indent=2), encoding="utf-8")
print(f"[OK] jwk-core-sig.json    kid={jwk_sig['kid']}")

# Chiave 3: core encryption (RSA-OAEP)
jwk_enc = gen_rsa_jwk({"use": "enc", "alg": "RSA-OAEP"})
(OUTPUT_DIR / "jwk-core-enc.json").write_text(json.dumps(jwk_enc, indent=2), encoding="utf-8")
print(f"[OK] jwk-core-enc.json    kid={jwk_enc['kid']}")
PY

# Scrivi il file lock con il timestamp di generazione
GENERATED_NOW="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "$GENERATED_NOW" > "$LOCK_FILE"

echo ""
echo "[OK] Chiavi CIE OIDC generate e salvate in: $KEYS_DIR"
echo ""
echo "========================================================"
echo "  IMPORTANTE — Le chiavi sono ora bloccate."
echo "  NON eseguire --force dopo la federazione CIE OIDC."
echo "  Prossimi step:"
echo "    1. bash scripts/sync-iam-proxy-italia.sh"
echo "    2. docker compose up -d"
echo "    3. bash metadata/export-cieoidc.sh"
echo "       (esporta per onboarding al portale CIE OIDC)"
echo "========================================================"
