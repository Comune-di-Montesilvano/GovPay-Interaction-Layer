#!/usr/bin/env bash
# export-cieoidc.sh — esporta artifact CIE OIDC per onboarding alla federazione
# Curla auth-proxy-nginx via rete Docker interna (servizio deve essere up)
set -euo pipefail

OUTPUT_DIR="/output/cieoidc"
FORCE="${FORCE:-0}"

# URL interno Docker (service name) per i curl
SATOSA_HOSTNAME="${SATOSA_HOSTNAME:-auth-proxy-nginx}"
SATOSA_SCHEME="$( [ "${SSL:-off}" = "on" ] && echo "https" || echo "http" )"
IAM_PROXY_INTERNAL_BASE="${SATOSA_SCHEME}://${SATOSA_HOSTNAME}"
CURL_INSECURE="$( [ "${SSL:-off}" = "on" ] && echo "-k" ) --connect-timeout 5 --max-time 10"

# URL pubblico (per component-values.env — usato nel portale CIE)
IAM_PROXY_PUBLIC_BASE_URL="${IAM_PROXY_PUBLIC_BASE_URL:-}"
CIE_OIDC_CLIENT_ID="${CIE_OIDC_CLIENT_ID:-}"

if [[ -z "$CIE_OIDC_CLIENT_ID" ]]; then
  if [[ -n "$IAM_PROXY_PUBLIC_BASE_URL" ]]; then
    CIE_OIDC_CLIENT_ID="${IAM_PROXY_PUBLIC_BASE_URL%/}/CieOidcRp"
  else
    echo "[WARN] CIE_OIDC_CLIENT_ID non impostato. Imposta IAM_PROXY_PUBLIC_BASE_URL in .iam-proxy.env"
    CIE_OIDC_CLIENT_ID="https://CONFIGURA_IAM_PROXY_PUBLIC_BASE_URL/CieOidcRp"
  fi
fi

PUBLIC_COMPONENT_IDENTIFIER="${CIE_OIDC_CLIENT_ID%/}"
INTERNAL_COMPONENT_IDENTIFIER="${IAM_PROXY_INTERNAL_BASE}/CieOidcRp"

# Guard: export esistente e non scaduto → rifiuta senza FORCE=1
PREV_COMPONENT_VALUES="$OUTPUT_DIR/component-values.env"
if [[ -f "$PREV_COMPONENT_VALUES" ]] && [[ "$FORCE" != "1" ]]; then
  PREV_EXP_EPOCH=""
  while IFS='=' read -r key value; do
    [[ "$key" == "ENTITY_STATEMENT_EXP_EPOCH" ]] && PREV_EXP_EPOCH="$value"
  done < "$PREV_COMPONENT_VALUES"

  if [[ -n "$PREV_EXP_EPOCH" ]] && [[ "$PREV_EXP_EPOCH" =~ ^[0-9]+$ ]]; then
    NOW_EPOCH="$(date +%s)"
    if [[ "$PREV_EXP_EPOCH" -gt "$NOW_EPOCH" ]]; then
      PREV_DAYS=$(grep "ENTITY_STATEMENT_EXP_DAYS_REMAINING" "$PREV_COMPONENT_VALUES" | cut -d= -f2 || echo "?")
      PREV_UTC=$(grep "ENTITY_STATEMENT_EXP_UTC" "$PREV_COMPONENT_VALUES" | cut -d= -f2 || echo "sconosciuta")
      echo "[ERROR] Export CIE OIDC già presente e non scaduto." >&2
      echo "        Scadenza: $PREV_UTC ($PREV_DAYS giorni residui)" >&2
      echo "        Le chiavi federate NON devono cambiare finché l'Entity Statement è valido." >&2
      echo "        Usa FORCE=1 solo dopo aver rigenerato le chiavi (renew-cieoidc)." >&2
      exit 1
    fi
  fi
fi

mkdir -p "$OUTPUT_DIR"

echo "[INFO] Export CIE OIDC da: $INTERNAL_COMPONENT_IDENTIFIER"
echo "[INFO] Attendo ${SATOSA_HOSTNAME}..."

ENTITY_CONFIG_URL="$INTERNAL_COMPONENT_IDENTIFIER/.well-known/openid-federation"
JWKS_RP_JSON_URL="$INTERNAL_COMPONENT_IDENTIFIER/openid_relying_party/jwks.json"
JWKS_RP_JOSE_URL="$INTERNAL_COMPONENT_IDENTIFIER/openid_relying_party/jwks.jose"

for i in $(seq 1 40); do
  if curl -sf $CURL_INSECURE "$ENTITY_CONFIG_URL" -o "$OUTPUT_DIR/entity-configuration.jwt" 2>/dev/null; then
    break
  fi
  echo "  Tentativo $i/40 (3s)..."
  sleep 3
  if [[ $i -eq 40 ]]; then
    echo "[ERROR] ${SATOSA_HOSTNAME} non risponde. Verificare che il servizio auth-proxy sia avviato." >&2
    exit 1
  fi
done

curl -fsSL $CURL_INSECURE "$JWKS_RP_JSON_URL" -o "$OUTPUT_DIR/jwks-rp.json"
curl -fsSL $CURL_INSECURE "$JWKS_RP_JOSE_URL" -o "$OUTPUT_DIR/jwks-rp.jose"

# Decode JWT payload → entity-configuration.json + jwks-federation-public.json + EXP
python3 - "$OUTPUT_DIR/entity-configuration.jwt" \
           "$OUTPUT_DIR/entity-configuration.json" \
           "$OUTPUT_DIR/jwks-federation-public.json" \
           "$PUBLIC_COMPONENT_IDENTIFIER" <<'PY'
import base64, json, sys
from pathlib import Path
from datetime import datetime, timezone

jwt_path   = Path(sys.argv[1])
json_path  = Path(sys.argv[2])
jwks_path  = Path(sys.argv[3])
public_id  = sys.argv[4]

jwt = jwt_path.read_text(encoding='utf-8').strip()
parts = jwt.split('.')
if len(parts) < 2:
    raise SystemExit('JWT entity statement non valido')

payload = parts[1]
payload += '=' * ((4 - len(payload) % 4) % 4)
payload = payload.replace('-', '+').replace('_', '/')
obj = json.loads(base64.b64decode(payload).decode('utf-8'))

json_path.write_text(json.dumps(obj, indent=2, ensure_ascii=False), encoding='utf-8')
keys = obj.get('jwks', {}).get('keys', [])
jwks_path.write_text(json.dumps({"keys": keys}, indent=2, ensure_ascii=False), encoding='utf-8')

exp = obj.get('exp')
if exp:
    exp_dt = datetime.fromtimestamp(int(exp), tz=timezone.utc)
    days   = int((exp_dt - datetime.now(timezone.utc)).total_seconds() // 86400)
    print(int(exp))
    print(exp_dt.strftime('%Y-%m-%dT%H:%M:%SZ'))
    print(days)
else:
    print('')
    print('')
    print('')
PY

EXP_EPOCH="$(python3 -c "
import json, sys
from pathlib import Path
from datetime import datetime, timezone
obj = json.loads(Path('$OUTPUT_DIR/entity-configuration.json').read_text(encoding='utf-8'))
exp = obj.get('exp')
if exp:
    exp_dt = datetime.fromtimestamp(int(exp), tz=timezone.utc)
    days   = int((exp_dt - datetime.now(timezone.utc)).total_seconds() // 86400)
    print(f'{int(exp)}\n{exp_dt.strftime(\"%Y-%m-%dT%H:%M:%SZ\")}\n{days}')
else:
    print('\n\n')
")"

EXP_LINE1="$(echo "$EXP_EPOCH" | sed -n '1p')"
EXP_LINE2="$(echo "$EXP_EPOCH" | sed -n '2p')"
EXP_LINE3="$(echo "$EXP_EPOCH" | sed -n '3p')"

PUBLIC_ENTITY_CONFIG_URL="${PUBLIC_COMPONENT_IDENTIFIER}/.well-known/openid-federation"
PUBLIC_JWKS_RP_JSON_URL="${PUBLIC_COMPONENT_IDENTIFIER}/openid_relying_party/jwks.json"
PUBLIC_JWKS_RP_JOSE_URL="${PUBLIC_COMPONENT_IDENTIFIER}/openid_relying_party/jwks.jose"

cat > "$OUTPUT_DIR/component-values.env" <<EOF
COMPONENT_IDENTIFIER=$INTERNAL_COMPONENT_IDENTIFIER
PUBLIC_COMPONENT_IDENTIFIER=$PUBLIC_COMPONENT_IDENTIFIER
ENTITY_CONFIG_URL=$ENTITY_CONFIG_URL
PUBLIC_ENTITY_CONFIG_URL=$PUBLIC_ENTITY_CONFIG_URL
JWKS_FEDERATION_PUBLIC_FILE=metadata/cieoidc/jwks-federation-public.json
JWKS_RP_JSON_URL=$JWKS_RP_JSON_URL
JWKS_RP_JOSE_URL=$JWKS_RP_JOSE_URL
PUBLIC_JWKS_RP_JSON_URL=$PUBLIC_JWKS_RP_JSON_URL
PUBLIC_JWKS_RP_JOSE_URL=$PUBLIC_JWKS_RP_JOSE_URL
ENTITY_STATEMENT_EXP_EPOCH=$EXP_LINE1
ENTITY_STATEMENT_EXP_UTC=$EXP_LINE2
ENTITY_STATEMENT_EXP_DAYS_REMAINING=$EXP_LINE3
EOF

echo "[OK] Export CIE OIDC completato in metadata/cieoidc/"
echo ""
echo "========================================================"
echo "  Per il portale CIE OIDC usare:"
echo "    File JWT : metadata/cieoidc/entity-configuration.jwt"
echo "    JWKS fed : metadata/cieoidc/jwks-federation-public.json"
echo "    Entity ID: $PUBLIC_COMPONENT_IDENTIFIER"
echo "  Nel form del portale inserire l'Entity ID nel campo"
echo "  'sub' / 'Identificativo Soggetto'."
echo "========================================================"
