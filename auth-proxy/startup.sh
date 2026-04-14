#!/usr/bin/env bash
# startup.sh — eseguito da auth-proxy (gil-auth-proxy) all'avvio.
# Sostituisce sync-iam-proxy: applica envsubst sui template, inietta chiavi JWK CIE OIDC,
# applica patch di configurazione, poi avvia SATOSA via entrypoint.sh.
# Il progetto SATOSA è già in /satosa_proxy/ (baked nell'immagine Docker).

set -euo pipefail

SATOSA_PROXY="/satosa_proxy"
TEMPLATES="/builder/templates"
CIEOIDC_KEYS="/cieoidc-keys"
SATOSA_STATIC="/satosa-static"   # volume condiviso con auth-proxy-nginx

is_true() {
  case "${1:-}" in
    1|true|TRUE|True|yes|YES|Yes|on|ON|On) return 0 ;;
    *) return 1 ;;
  esac
}

# ── Fetch runtime config from backoffice ─────────────────────────────────────
# Tutte le variabili SATOSA/CIE OIDC/ENABLE_* provengono esclusivamente dal DB
# del backoffice tramite GET /api/auth-proxy/env. MASTER_TOKEN è obbligatorio.
if [ -n "${BACKOFFICE_INTERNAL_URL:-}" ]; then
  _BO_URL="${BACKOFFICE_INTERNAL_URL}"
else
  # Regola unica: SSL=on -> HTTPS interno; altrimenti (anche assente) -> HTTP.
  if [ "${SSL:-off}" = "on" ]; then
    _BO_URL="https://backoffice:80"
  else
    _BO_URL="http://backoffice"
  fi
fi

if [ -z "${MASTER_TOKEN:-}" ]; then
  echo "[startup] ERRORE: MASTER_TOKEN non impostato. Variabile obbligatoria." >&2
  exit 1
fi

echo "[startup] Fetch configurazione da ${_BO_URL}/api/auth-proxy/env ..."
_MAX_ATTEMPTS=10
_ATTEMPT=0
_CONF=""
while [ "$_ATTEMPT" -lt "$_MAX_ATTEMPTS" ]; do
  _CONF=$(curl -sf -k --max-time 10 \
    -H "Authorization: Bearer ${MASTER_TOKEN}" \
    "${_BO_URL}/api/auth-proxy/env" 2>/dev/null) && break
  _ATTEMPT=$((_ATTEMPT + 1))
  echo "[startup] Backoffice non ancora pronto (tentativo ${_ATTEMPT}/${_MAX_ATTEMPTS}), attendo 5s..."
  sleep 5
done

if [ -z "${_CONF:-}" ]; then
  echo "[startup] ERRORE: impossibile raggiungere il backoffice dopo ${_MAX_ATTEMPTS} tentativi." >&2
  exit 1
fi

# Esporta ogni chiave come variabile d'ambiente
eval "$(echo "$_CONF" | python3 -c "
import json, sys, shlex
d = json.load(sys.stdin)
for k, v in d.items():
    if isinstance(v, str) and v:
        print('export {}={}'.format(k, shlex.quote(v)))
")"

# Hardening: evita crash SATOSA su !ENV quando redirect errore non e valorizzato.
if [ -z "${SATOSA_UNKNOW_ERROR_REDIRECT_PAGE:-}" ]; then
  if [ -n "${FRONTOFFICE_PUBLIC_BASE_URL:-}" ]; then
    SATOSA_UNKNOW_ERROR_REDIRECT_PAGE="${FRONTOFFICE_PUBLIC_BASE_URL%/}/accesso-negato"
  else
    SATOSA_UNKNOW_ERROR_REDIRECT_PAGE="https://127.0.0.1:8444/accesso-negato"
    echo "[startup] WARN: FRONTOFFICE_PUBLIC_BASE_URL non impostato, uso fallback di sicurezza per SATOSA_UNKNOW_ERROR_REDIRECT_PAGE"
  fi
  export SATOSA_UNKNOW_ERROR_REDIRECT_PAGE
fi

# Hardening: evita crash SATOSA su !ENV quando SATOSA_DISCO_SRV non è valorizzato.
if [ -z "${SATOSA_DISCO_SRV:-}" ]; then
  if [ -n "${SATOSA_BASE:-}" ]; then
    SATOSA_DISCO_SRV="${SATOSA_BASE%/}/static/disco.html"
    echo "[startup] SATOSA_DISCO_SRV non configurato, uso default: ${SATOSA_DISCO_SRV}"
  else
    SATOSA_DISCO_SRV="https://127.0.0.1/static/disco.html"
    echo "[startup] WARN: SATOSA_BASE non impostato, uso fallback di sicurezza per SATOSA_DISCO_SRV"
  fi
  export SATOSA_DISCO_SRV
fi

# Garantisce che TUTTE le variabili !ENV nei YAML SATOSA siano presenti nell'ambiente.
# SATOSA crasha con "Cannot construct value: None" se una variabile è assente.
# Una stringa vuota '' è accettata da SATOSA; None no.
# Questo blocco copre le variabili configurabili dall'utente (org/contact/UI)
# che il backoffice omette dalla risposta quando non valorizzate nel DB.
for _v in \
  SATOSA_ORGANIZATION_DISPLAY_NAME_EN SATOSA_ORGANIZATION_DISPLAY_NAME_IT \
  SATOSA_ORGANIZATION_NAME_EN         SATOSA_ORGANIZATION_NAME_IT \
  SATOSA_ORGANIZATION_URL_EN          SATOSA_ORGANIZATION_URL_IT \
  SATOSA_CONTACT_PERSON_GIVEN_NAME    SATOSA_CONTACT_PERSON_EMAIL_ADDRESS \
  SATOSA_CONTACT_PERSON_TELEPHONE_NUMBER SATOSA_CONTACT_PERSON_FISCALCODE \
  SATOSA_CONTACT_PERSON_IPA_CODE      SATOSA_CONTACT_PERSON_MUNICIPALITY \
  SATOSA_UI_DISPLAY_NAME_EN           SATOSA_UI_DISPLAY_NAME_IT \
  SATOSA_UI_DESCRIPTION_EN            SATOSA_UI_DESCRIPTION_IT \
  SATOSA_UI_INFORMATION_URL_EN        SATOSA_UI_INFORMATION_URL_IT \
  SATOSA_UI_PRIVACY_URL_EN            SATOSA_UI_PRIVACY_URL_IT \
  SATOSA_UI_LOGO_URL                  SATOSA_BASE_STATIC; do
  if ! [[ -v "$_v" ]]; then
    export "$_v="
    echo "[startup] WARN: $_v non configurato nel DB, impostato a stringa vuota"
  fi
done

# Default numerici per logo — pysaml2 serializza width/height come XML integer:
# una stringa vuota '' causa un errore 400 su GET /Saml2IDP/metadata.
: "${SATOSA_UI_LOGO_WIDTH:=200}"
: "${SATOSA_UI_LOGO_HEIGHT:=60}"
export SATOSA_UI_LOGO_WIDTH SATOSA_UI_LOGO_HEIGHT

echo "[startup] Configurazione runtime applicata."
# ─────────────────────────────────────────────────────────────────────────────

# ── Default MongoDB backend CIE OIDC ─────────────────────────────────────────
# Derivati dalle variabili infrastrutturali MONGODB_* (da docker-compose).
# Non configurabili da UI backoffice: usano lo stesso auth-proxy-db dello stack.
: "${MONGO_CIE_OIDC_BACKEND_HOST:=mongodb://${MONGODB_HOST:-auth-proxy-db}:${MONGODB_PORT:-27017}/?authSource=admin}"
: "${MONGO_CIE_OIDC_BACKEND_DB_NAME:=${MONGODB_DB:-satosa}}"
: "${MONGO_CIE_OIDC_BACKEND_AUTH_COLLECTION:=cie_oidc_authz}"
: "${MONGO_CIE_OIDC_BACKEND_TOKEN_COLLECTION:=cie_oidc_token}"
: "${MONGO_CIE_OIDC_BACKEND_USER_COLLECTION:=cie_oidc_user}"
: "${MONGODB_CIE_OIDC_BACKEND_USERNAME:=${MONGODB_USERNAME:-satosa_user}}"
: "${MONGODB_CIE_OIDC_BACKEND_PASSWORD:=${MONGODB_PASSWORD:-satosa_password}}"
export MONGO_CIE_OIDC_BACKEND_HOST MONGO_CIE_OIDC_BACKEND_DB_NAME \
       MONGO_CIE_OIDC_BACKEND_AUTH_COLLECTION MONGO_CIE_OIDC_BACKEND_TOKEN_COLLECTION \
       MONGO_CIE_OIDC_BACKEND_USER_COLLECTION MONGODB_CIE_OIDC_BACKEND_USERNAME \
       MONGODB_CIE_OIDC_BACKEND_PASSWORD
# ─────────────────────────────────────────────────────────────────────────────

# Valuta feature flags dal config iniziale (usato dal boot e dall'avvio iniziale watchdog)
_enable_spid=$(echo "$_CONF" | python3 -c "
import json, sys
try:
    d = json.loads(sys.stdin.read())
    v = str(d.get('ENABLE_SPID', 'false')).lower()
    print('true' if v in ('1','true','yes','on') else 'false')
except Exception:
    print('false')
" 2>/dev/null || echo "false")
_enable_cie=$(echo "$_CONF" | python3 -c "
import json, sys
try:
    d = json.loads(sys.stdin.read())
    v = str(d.get('ENABLE_CIE_OIDC', 'false')).lower()
    print('true' if v in ('1','true','yes','on') else 'false')
except Exception:
    print('false')
" 2>/dev/null || echo "false")

# ── Supervisore SATOSA: funzioni watchdog ─────────────────────────────────────
# Il container rimane sempre in esecuzione (restart: always in docker-compose).
# SATOSA è gestito internamente: avviato/fermato in base alla config backoffice.
_WATCHDOG_INTERVAL="${IAM_WATCHDOG_INTERVAL:-300}"
_SATOSA_PID=""

satosa_running() { [ -n "${_SATOSA_PID:-}" ] && kill -0 "$_SATOSA_PID" 2>/dev/null; }

stop_satosa() {
  if satosa_running; then
    echo "[watchdog] SIGTERM a SATOSA (PID=$_SATOSA_PID)..."
    _pgid=$(ps -o pgid= -p "$_SATOSA_PID" 2>/dev/null | tr -d ' ')
    [ -n "$_pgid" ] && kill -TERM -- "-$_pgid" 2>/dev/null || kill -TERM "$_SATOSA_PID" 2>/dev/null || true
    wait "$_SATOSA_PID" 2>/dev/null || true
    _SATOSA_PID=""
    echo "[watchdog] SATOSA fermato."
  fi
}

setup_satosa() {
  # Verifica chiavi PKI SATOSA — fail-fast se mancanti (return 1 per gestione watchdog)
  local _pki_key="${SATOSA_PRIVATE_KEY:-./pki/privkey.pem}"
  local _pki_cert="${SATOSA_PUBLIC_KEY:-./pki/cert.pem}"
  [[ "$_pki_key"  != /* ]] && _pki_key="$SATOSA_PROXY/$_pki_key"
  [[ "$_pki_cert" != /* ]] && _pki_cert="$SATOSA_PROXY/$_pki_cert"
  if [ ! -f "$_pki_key" ] || [ ! -f "$_pki_cert" ]; then
    echo "[startup] ERRORE: Chiavi PKI SATOSA non trovate." >&2
    echo "[startup]   Atteso: $_pki_key" >&2
    echo "[startup]   Atteso: $_pki_cert" >&2
    echo "[startup] Generare i certificati SPID dal backoffice: Impostazioni → Login Proxy → Certificati SPID" >&2
    return 1
  fi
  echo "[startup] Chiavi PKI verificate: $_pki_key, $_pki_cert"
  # Garantisce attraversamento directory + leggibilita PKI
  # (startup gira come root; SATOSA gira come utente non-root).
  local _pki_dir
  _pki_dir="$(dirname "$_pki_key")"
  chmod 755 "$SATOSA_PROXY" "$_pki_dir" 2>/dev/null || true
  chmod 644 "$_pki_key" "$_pki_cert" 2>/dev/null || true
  if ! test -r "$_pki_key" || ! test -r "$_pki_cert"; then
    echo "[startup] ERRORE: permessi PKI insufficienti dopo hardening." >&2
    ls -ld "$SATOSA_PROXY" "$_pki_dir" >&2 || true
    ls -l "$_pki_key" "$_pki_cert" >&2 || true
    return 1
  fi

# Versione dell'immagine (se non passata, usa unknown)
: "${APP_VERSION:=unknown}"
export APP_VERSION

echo "[startup] auth-proxy startup — applicazione configurazione... (v${APP_VERSION:-unknown})"

# ── i18n wallets ─────────────────────────────────────────────────────────────
echo "[startup] Generazione wallets i18n JSON..."
mkdir -p "$SATOSA_PROXY/static/locales/it" "$SATOSA_PROXY/static/locales/en"
envsubst < "$TEMPLATES/wallets-it.json.template" > "$SATOSA_PROXY/static/locales/it/wallets.json"
envsubst < "$TEMPLATES/wallets-en.json.template" > "$SATOSA_PROXY/static/locales/en/wallets.json"

# ── spid_base.html ───────────────────────────────────────────────────────────
echo "[startup] Generazione spid_base.html..."
envsubst < "$TEMPLATES/spid_base_override.html.template" > "$SATOSA_PROXY/templates/spid_base.html"

# ── wallets-config.json ──────────────────────────────────────────────────────
echo "[startup] Generazione wallets-config.json..."
mkdir -p "$SATOSA_PROXY/static/config"
envsubst < "$TEMPLATES/wallets-config.json.template" > "$SATOSA_PROXY/static/config/wallets-config.json"

# ── disco.html ───────────────────────────────────────────────────────────────
echo "[startup] Generazione disco.html..."
envsubst '${APP_LOGO_SRC} ${APP_LOGO_TYPE} ${APP_ENTITY_NAME} ${APP_ENTITY_URL} ${FRONTOFFICE_PUBLIC_BASE_URL} ${SATOSA_UI_LEGAL_URL_IT} ${SATOSA_UI_PRIVACY_URL_IT} ${SATOSA_UI_ACCESSIBILITY_URL_IT} ${SATOSA_ORGANIZATION_URL_IT} ${SATOSA_ORGANIZATION_DISPLAY_NAME_IT} ${CIE_OIDC_PROVIDER_URL} ${APP_VERSION}' \
  < "$TEMPLATES/disco.static.html.template" > "$SATOSA_PROXY/static/disco.html"
is_true "${ENABLE_SPID:-true}"         || sed -i '/SPID_BLOCK_START/,/SPID_BLOCK_END/d'            "$SATOSA_PROXY/static/disco.html"
is_true "${SATOSA_USE_DEMO_SPID_IDP:-}" || sed -i '/SPID_DEMO_START/,/SPID_DEMO_END/d'             "$SATOSA_PROXY/static/disco.html"
is_true "${SATOSA_USE_SPID_VALIDATOR:-}" || sed -i '/SPID_VALIDATOR_START/,/SPID_VALIDATOR_END/d'  "$SATOSA_PROXY/static/disco.html"
is_true "${ENABLE_CIE_OIDC:-}"          || sed -i '/CIE_OIDC_BLOCK_START/,/CIE_OIDC_BLOCK_END/d'  "$SATOSA_PROXY/static/disco.html"
is_true "${ENABLE_IT_WALLET:-}"          || sed -i '/IT_WALLET_BLOCK_START/,/IT_WALLET_BLOCK_END/d' "$SATOSA_PROXY/static/disco.html"
is_true "${ENABLE_IDEM:-}"               || sed -i '/IDEM_BLOCK_START/,/IDEM_BLOCK_END/d'           "$SATOSA_PROXY/static/disco.html"
is_true "${ENABLE_EIDAS:-}"              || sed -i '/EIDAS_BLOCK_START/,/EIDAS_BLOCK_END/d'         "$SATOSA_PROXY/static/disco.html"

# ── demo SPID IdP metadata ───────────────────────────────────────────────────
if is_true "${SATOSA_USE_DEMO_SPID_IDP:-}"; then
  DEMO_FILE="$SATOSA_PROXY/metadata/idp/demo-spid.xml"
  if [ ! -f "$DEMO_FILE" ]; then
    echo "[startup] Scaricamento metadata demo SPID IdP..."
    curl -sSL --max-time 30 "https://demo.spid.gov.it/metadata.xml" -o "$DEMO_FILE" 2>/dev/null \
      && echo "[startup] Metadata demo SPID scaricati" \
      || echo "[startup] WARNING: impossibile scaricare metadata demo SPID"
  fi
  mkdir -p "$SATOSA_PROXY/static/config"
  envsubst < "$TEMPLATES/wallets-spid-demo-override.json.template" > "$SATOSA_PROXY/static/config/wallets-spid-demo-override.json"
else
  rm -f "$SATOSA_PROXY/static/config/wallets-spid-demo-override.json"
fi

# ── SPID validator metadata ──────────────────────────────────────────────────
if is_true "${SATOSA_USE_SPID_VALIDATOR:-}"; then
  VALIDATOR_FILE="$SATOSA_PROXY/metadata/idp/spid-validator.xml"
  if [ ! -f "$VALIDATOR_FILE" ]; then
    echo "[startup] Scaricamento metadata SPID validator..."
    VALIDATOR_URL="${SATOSA_SPID_VALIDATOR_METADATA_URL:-https://validator.spid.gov.it/metadata.xml}"
    curl -sSL --max-time 30 "$VALIDATOR_URL" -o "$VALIDATOR_FILE" 2>/dev/null \
      && echo "[startup] Metadata SPID validator scaricati" \
      || echo "[startup] WARNING: impossibile scaricare metadata SPID validator"
  fi
fi

# ── cieoidc_backend.yaml ─────────────────────────────────────────────────────
echo "[startup] Generazione cieoidc_backend.yaml..."

# Auto-fetch trust mark se non già impostato
if [ -z "${CIE_OIDC_TRUST_MARK:-}" ]; then
  echo "[startup] Auto-fetch CIE OIDC trust mark dalla registry..."
  _FETCH_URL="https://oidc.registry.servizicie.interno.gov.it/fetch?iss=https://oidc.registry.servizicie.interno.gov.it&sub=${CIE_OIDC_CLIENT_ID:-}"
  _TM_RAW=$(curl -sf --max-time 15 "$_FETCH_URL" 2>/dev/null | python3 -c "
import sys, base64, json
jws = sys.stdin.read().strip()
parts = jws.split('.')
if len(parts) < 2: sys.exit(0)
pl = parts[1] + '==' * (-len(parts[1]) % 4)
payload = json.loads(base64.urlsafe_b64decode(pl))
tms = payload.get('trust_marks', [])
if not tms: sys.exit(0)
tm = tms[0]
if isinstance(tm, dict):
    tm_id = tm.get('id', ''); tm_jwt = tm.get('trust_mark', '')
else:
    p2 = tm.split('.'); pl2 = p2[1] + '==' * (-len(p2[1]) % 4)
    d2 = json.loads(base64.urlsafe_b64decode(pl2))
    tm_id = d2.get('id', ''); tm_jwt = tm
if tm_id and tm_jwt: print(tm_id + '|' + tm_jwt)
" 2>/dev/null) || true
  if [ -n "${_TM_RAW:-}" ]; then
    CIE_OIDC_TRUST_MARK_ID="${_TM_RAW%%|*}"
    CIE_OIDC_TRUST_MARK="${_TM_RAW##*|}"
    echo "[startup] Trust mark ottenuto (id: $CIE_OIDC_TRUST_MARK_ID)"
  else
    echo "[startup] WARNING: impossibile ottenere trust mark dalla registry"
    CIE_OIDC_TRUST_MARK_ID="${CIE_OIDC_TRUST_MARK_ID:-}"
    CIE_OIDC_TRUST_MARK="${CIE_OIDC_TRUST_MARK:-}"
  fi
else
  CIE_OIDC_TRUST_MARK_ID=$(python3 -c "
import sys, base64, json
tm = '''${CIE_OIDC_TRUST_MARK}'''
p = tm.split('.'); pl = p[1] + '==' * (-len(p[1]) % 4)
d = json.loads(base64.urlsafe_b64decode(pl)); print(d.get('id', ''))
" 2>/dev/null) || CIE_OIDC_TRUST_MARK_ID=""
  echo "[startup] Usando trust mark configurato manualmente (id: $CIE_OIDC_TRUST_MARK_ID)"
fi
export CIE_OIDC_TRUST_MARK CIE_OIDC_TRUST_MARK_ID

# Default CIE OIDC values
: "${CIE_OIDC_CLIENT_NAME:=${APP_ENTITY_NAME:-}}"
: "${CIE_OIDC_ORGANIZATION_NAME:=${APP_ENTITY_NAME:-}}"
: "${CIE_OIDC_HOMEPAGE_URI:=${APP_ENTITY_URL:-}}"
: "${CIE_OIDC_POLICY_URI:=${SATOSA_UI_LEGAL_URL_IT:-}}"
: "${CIE_OIDC_LOGO_URI:=${SATOSA_UI_LOGO_URL:-}}"
: "${CIE_OIDC_CONTACT_EMAIL:=${SATOSA_CONTACT_PERSON_EMAIL_ADDRESS:-}}"
export CIE_OIDC_CLIENT_NAME CIE_OIDC_ORGANIZATION_NAME CIE_OIDC_HOMEPAGE_URI
export CIE_OIDC_POLICY_URI CIE_OIDC_LOGO_URI CIE_OIDC_CONTACT_EMAIL

mkdir -p "$SATOSA_PROXY/conf/backends"
envsubst < "$TEMPLATES/cieoidc_backend.override.yaml.template" > "$SATOSA_PROXY/conf/backends/cieoidc_backend.yaml"

# Inject JWK keys
JWK_FED="$CIEOIDC_KEYS/jwk-federation.json"
JWK_SIG="$CIEOIDC_KEYS/jwk-core-sig.json"
JWK_ENC="$CIEOIDC_KEYS/jwk-core-enc.json"

if [ -f "$JWK_FED" ] && [ -f "$JWK_SIG" ] && [ -f "$JWK_ENC" ]; then
  echo "[startup] Iniezione chiavi JWK CIE OIDC..."
  python3 - "$SATOSA_PROXY/conf/backends/cieoidc_backend.yaml" "$JWK_FED" "$JWK_SIG" "$JWK_ENC" <<'PY'
import sys, json
from pathlib import Path

yaml_path = Path(sys.argv[1])
paths = {
    "__CIE_OIDC_JWK_FEDERATION_YAML__": Path(sys.argv[2]),
    "__CIE_OIDC_JWK_CORE_SIG_YAML__":   Path(sys.argv[3]),
    "__CIE_OIDC_JWK_CORE_ENC_YAML__":   Path(sys.argv[4]),
}
FIELD_ORDER = ["use", "alg", "kty", "kid", "e", "n", "d", "p", "q", "dp", "dq", "qi"]

def jwk_to_yaml_block(jwk, indent):
    keys = sorted(jwk.keys(), key=lambda k: (FIELD_ORDER.index(k) if k in FIELD_ORDER else 99, k))
    lines = []
    for i, key in enumerate(keys):
        val = json.dumps(jwk[key])
        prefix = " " * indent + "- " if i == 0 else " " * (indent + 2)
        lines.append(f"{prefix}{key}: {val}")
    return "\n".join(lines)

content = yaml_path.read_text(encoding="utf-8")
for placeholder, jwk_path in paths.items():
    jwk = json.loads(jwk_path.read_text(encoding="utf-8"))
    new_lines = []
    for line in content.splitlines():
        stripped = line.lstrip()
        if stripped.startswith(f"- {placeholder}") or stripped == f"- {placeholder}":
            indent = len(line) - len(line.lstrip())
            new_lines.append(jwk_to_yaml_block(jwk, indent))
        else:
            new_lines.append(line)
    content = "\n".join(new_lines)

remaining = [p for p in paths if p in content]
if remaining:
    print(f"[ERROR] Placeholder JWKS non sostituiti: {remaining}", file=sys.stderr)
    sys.exit(1)
yaml_path.write_text(content + "\n", encoding="utf-8")
print(f"[OK] JWKS iniettati in {yaml_path}")
PY
else
  echo "[startup] WARNING: chiavi JWK CIE OIDC non trovate in $CIEOIDC_KEYS — configurazione CIE OIDC incompleta"
fi

# ── patch proxy_conf.yaml ────────────────────────────────────────────────────
PROXY_CONF="$SATOSA_PROXY/proxy_conf.yaml"
if [ -f "$PROXY_CONF" ]; then
  echo "[startup] Applicazione patch proxy_conf.yaml..."
  if ! is_true "${ENABLE_CIE_OIDC:-}"; then
    sed -i 's|^  - "conf/backends/cieoidc_backend.yaml"|  # - "conf/backends/cieoidc_backend.yaml"  # Disabled (set ENABLE_CIE_OIDC=true)|' "$PROXY_CONF"
    sed -i 's|^  - "conf/backends/pyeudiw_backend.yaml"|  # - "conf/backends/pyeudiw_backend.yaml"  # Disabled|' "$PROXY_CONF"
    sed -i 's|^  - "conf/backends/saml2_backend.yaml"|  # - "conf/backends/saml2_backend.yaml"  # Disabled|' "$PROXY_CONF"
  fi
  is_true "${SATOSA_DISABLE_PYEUDIW_BACKEND:-}" && \
    sed -i 's|^  - "conf/backends/pyeudiw_backend.yaml"|  # - "conf/backends/pyeudiw_backend.yaml"  # Disabled by SATOSA_DISABLE_PYEUDIW_BACKEND|' "$PROXY_CONF" || true
  is_true "${SATOSA_DISABLE_CIEOIDC_BACKEND:-}" && \
    sed -i 's|^  - "conf/backends/cieoidc_backend.yaml"|  # - "conf/backends/cieoidc_backend.yaml"  # Disabled by SATOSA_DISABLE_CIEOIDC_BACKEND|' "$PROXY_CONF" || true
  ! is_true "${ENABLE_IT_WALLET:-}" && \
    sed -i 's|^  - "conf/frontends/openid4vci_frontend.yaml"|  # - "conf/frontends/openid4vci_frontend.yaml"  # Disabled (ENABLE_IT_WALLET!=true)|' "$PROXY_CONF" || true
  ! is_true "${ENABLE_OIDCOP:-}" && \
    sed -i 's|^  - "conf/frontends/oidcop_frontend.yaml"|  # - "conf/frontends/oidcop_frontend.yaml"  # Disabled (ENABLE_OIDCOP!=true)|' "$PROXY_CONF" || true
fi

# ── patch spidsaml2_backend.yaml (ACS index) ─────────────────────────────────
SPID_BACKEND="$SATOSA_PROXY/conf/backends/spidsaml2_backend.yaml"
if [ -f "$SPID_BACKEND" ]; then
  ACS_INDEX="${SATOSA_FICEP_DEFAULT_ACS_INDEX:-0}"
  echo "[startup] Setting spidSaml2 ficep_default_acs_index=$ACS_INDEX..."
  sed -i.bak \
    -e "s|^\([[:space:]]*ficep_default_acs_index:[[:space:]]*\).*|\1$ACS_INDEX|" \
    -e "s|^\([[:space:]]*acs_index:[[:space:]]*\).*|\1$ACS_INDEX|" \
    "$SPID_BACKEND"
  rm -f "$SPID_BACKEND.bak"
  grep -q "^[[:space:]]*acs_index:" "$SPID_BACKEND" || \
    sed -i "/^[[:space:]]*ficep_default_acs_index:/a\    acs_index: $ACS_INDEX" "$SPID_BACKEND"
fi

# ── patch attribute maps ──────────────────────────────────────────────────────
URI_MAP="$SATOSA_PROXY/attributes-map/satosa_spid_uri_hybrid.py"
[ -f "$URI_MAP" ] && ! grep -q '"mail": "mail"' "$URI_MAP" && \
  sed -i '/"mobilePhone": "mobilePhone",/a\    "mail": "mail",' "$URI_MAP" || true

BASIC_MAP="$SATOSA_PROXY/attributes-map/satosa_spid_basic.py"
[ -f "$BASIC_MAP" ] && ! grep -q '"mail",' "$BASIC_MAP" && \
  sed -i '/"mobilePhone",/a\    "mail",' "$BASIC_MAP" || true

INTERNAL_ATTRS="$SATOSA_PROXY/internal_attributes.yaml"
if [ -f "$INTERNAL_ATTRS" ]; then
  sed -i 's|saml: \[mail, email\]|saml: [email, mail]|g' "$INTERNAL_ATTRS"
  sed -i 's|saml: \[mail\]|saml: [email, mail]|g' "$INTERNAL_ATTRS"
fi

# ── patch target_based_routing.yaml ──────────────────────────────────────────
ROUTING="$SATOSA_PROXY/conf/microservices/target_based_routing.yaml"
if [ -f "$ROUTING" ]; then
  echo "[startup] Patch target_based_routing.yaml..."
  if ! is_true "${ENABLE_CIE_OIDC:-}"; then
    sed -i 's|^  default_backend: Saml2$|  default_backend: spidSaml2|' "$ROUTING"
    is_true "${SATOSA_USE_DEMO_SPID_IDP:-}" || sed -i '/"https:\/\/demo\.spid\.gov\.it": "spidSaml2"/d' "$ROUTING"
    is_true "${SATOSA_USE_SPID_VALIDATOR:-}" || sed -i '/"https:\/\/validator\.spid\.gov\.it": "spidSaml2"/d' "$ROUTING"
  fi
  if is_true "${ENABLE_CIE_OIDC:-}"; then
    CIE_ISSUER="${CIE_OIDC_PROVIDER_URL:-}"
    if [ -n "$CIE_ISSUER" ]; then
      sed -i '/"CieOidcRp"/d' "$ROUTING"
      sed -i "/\"wallet\": \"OpenID4VP\"/a\\    \"$CIE_ISSUER\": \"CieOidcRp\"" "$ROUTING"
    fi
  fi
fi

# ── copia static files nel volume condiviso con auth-proxy-nginx ─────────────
echo "[startup] Copia static files in $SATOSA_STATIC..."
mkdir -p "$SATOSA_STATIC"
cp -r "$SATOSA_PROXY/static/." "$SATOSA_STATIC/"

# ── frontoffice SP metadata ───────────────────────────────────────────────────
# Genera il metadata SP SAML2 del frontoffice se assente o scaduto, poi lo copia
# nel path SATOSA. La generazione avviene qui (Python stdlib) senza container separati.
_SP_META_SRC="/frontoffice-sp/frontoffice_sp.xml"
_SP_META_DST="$SATOSA_PROXY/metadata/sp/frontoffice_sp.xml"
_SP_CERT_PATH="${SATOSA_PUBLIC_KEY:-./pki/cert.pem}"
# Rendi il path assoluto se relativo
if [[ "$_SP_CERT_PATH" != /* ]]; then
  _SP_CERT_PATH="$SATOSA_PROXY/$_SP_CERT_PATH"
fi

ensure_sp_metadata() {
  local force="${1:-0}"
  python3 - "$_SP_META_SRC" "$_SP_META_DST" "$_SP_CERT_PATH" \
    "${FRONTOFFICE_PUBLIC_BASE_URL:-}" \
    "${FRONTOFFICE_SAML_SP_METADATA_VALIDITY_DAYS:-730}" \
    "${FRONTOFFICE_SAML_SP_METADATA_CACHE_DURATION_SECONDS:-604800}" \
    "$force" <<'PY'
import sys, os, re, datetime, shutil
from pathlib import Path

src       = Path(sys.argv[1])
dst       = Path(sys.argv[2])
cert_path = Path(sys.argv[3])
base_url  = sys.argv[4].rstrip('/')
validity_days = int(sys.argv[5])
cache_secs    = int(sys.argv[6])
force         = sys.argv[7] == '1'

def is_valid(path):
    try:
        import xml.etree.ElementTree as ET
        root = ET.parse(str(path)).getroot()
        valid_until = root.get('validUntil', '')
        if not valid_until:
            return False
        # Parse ISO 8601 (Z suffix)
        dt = datetime.datetime.fromisoformat(valid_until.replace('Z', '+00:00'))
        return dt > datetime.datetime.now(datetime.timezone.utc)
    except Exception:
        return False

if not force and src.exists() and is_valid(src):
    print('[startup] frontoffice_sp.xml presente e valido — copio in metadata/sp/')
    dst.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(str(src), str(dst))
    sys.exit(0)

if not base_url:
    print('[startup] WARN: FRONTOFFICE_PUBLIC_BASE_URL non impostato, metadata SP non generato')
    # copia quello esistente se c'è
    if src.exists():
        dst.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(str(src), str(dst))
    sys.exit(1)

print(f'[startup] Generazione frontoffice_sp.xml (base_url={base_url})...')

entity_id = f'{base_url}/saml/sp'
acs_url   = f'{base_url}/spid/callback'
slo_url   = f'{base_url}/logout'

now        = datetime.datetime.now(datetime.timezone.utc)
valid_until = (now + datetime.timedelta(days=validity_days)).strftime('%Y-%m-%dT%H:%M:%SZ')
cache_dur  = f'PT{cache_secs}S'

cert_b64 = ''
if cert_path.exists():
    pem = cert_path.read_text(encoding='utf-8')
    # Estrae solo il contenuto base64 (rimuove header/footer e newline)
    cert_b64 = re.sub(r'-----[^-]+-----', '', pem).replace('\n', '').replace('\r', '').strip()

xml = f'''<?xml version="1.0" encoding="UTF-8"?>
<md:EntityDescriptor
  xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
  xmlns:ds="http://www.w3.org/2000/09/xmldsig#"
  entityID="{entity_id}"
  validUntil="{valid_until}"
  cacheDuration="{cache_dur}">
  <md:SPSSODescriptor
    AuthnRequestsSigned="true"
    WantAssertionsSigned="true"
    protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
    <md:KeyDescriptor use="signing">
      <ds:KeyInfo>
        <ds:X509Data>
          <ds:X509Certificate>{cert_b64}</ds:X509Certificate>
        </ds:X509Data>
      </ds:KeyInfo>
    </md:KeyDescriptor>
    <md:SingleLogoutService
      Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
      Location="{slo_url}"/>
    <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:transient</md:NameIDFormat>
    <md:AssertionConsumerService
      Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
      Location="{acs_url}"
      index="0"
      isDefault="true"/>
  </md:SPSSODescriptor>
</md:EntityDescriptor>
'''

src.parent.mkdir(parents=True, exist_ok=True)
src.write_text(xml, encoding='utf-8')
dst.parent.mkdir(parents=True, exist_ok=True)
shutil.copy2(str(src), str(dst))
print(f'[startup] frontoffice_sp.xml generato (entityID={entity_id}, validUntil={valid_until})')
PY
}

ensure_sp_metadata 0

# Watcher: controlla la scadenza ogni FRONTOFFICE_SP_METADATA_CHECK_INTERVAL_SECONDS
(while true; do
  sleep "${FRONTOFFICE_SP_METADATA_CHECK_INTERVAL_SECONDS:-21600}"
  ensure_sp_metadata 0 || true
done) &

# ── patch_saml2.py ────────────────────────────────────────────────────────────
if [ -f "$SATOSA_PROXY/patch_saml2.py" ]; then
  echo "[startup] Applicazione patch_saml2.py..."
  python3 "$SATOSA_PROXY/patch_saml2.py"
fi

  echo "[startup] Configurazione SATOSA completata."
} # fine setup_satosa()

start_satosa() {
  setup_satosa || { echo "[watchdog] Setup SATOSA fallito — verrà riprovato al prossimo ciclo." >&2; return 1; }
  /bin/bash "$SATOSA_PROXY/entrypoint.sh" &
  _SATOSA_PID=$!
  echo "[watchdog] SATOSA avviato (PID=$_SATOSA_PID)"
}

fetch_conf() {
  curl -sf -k --max-time 10 \
    -H "Authorization: Bearer ${MASTER_TOKEN}" \
    "${_BO_URL}/api/auth-proxy/env" 2>/dev/null
}

auth_on() {
  local _conf="$1" _s _c
  _s=$(printf '%s' "$_conf" | python3 -c "
import json,sys
d=json.load(sys.stdin)
v=str(d.get('ENABLE_SPID','false')).lower()
print('true' if v in ('1','true','yes','on') else 'false')
" 2>/dev/null || echo "false")
  _c=$(printf '%s' "$_conf" | python3 -c "
import json,sys
d=json.load(sys.stdin)
v=str(d.get('ENABLE_CIE_OIDC','false')).lower()
print('true' if v in ('1','true','yes','on') else 'false')
" 2>/dev/null || echo "false")
  [ "$_s" = "true" ] || [ "$_c" = "true" ]
}

# ── Avvio iniziale ────────────────────────────────────────────────────────────
if [ "$_enable_spid" = "true" ] || [ "$_enable_cie" = "true" ]; then
  echo "[startup] Auth abilitato al boot — avvio SATOSA iniziale..."
  start_satosa || true
else
  echo "[startup] IAM Proxy non abilitato al boot. In standby (poll ogni ${_WATCHDOG_INTERVAL}s)."
fi

# ── Loop watchdog ─────────────────────────────────────────────────────────────
echo "[watchdog] Watchdog attivo (poll ogni ${_WATCHDOG_INTERVAL}s)."
while true; do
  sleep "$_WATCHDOG_INTERVAL"

  # Rileva crash spontaneo di SATOSA
  if [ -n "${_SATOSA_PID:-}" ] && ! satosa_running; then
    echo "[watchdog] SATOSA (PID=$_SATOSA_PID) terminato inaspettatamente."
    _SATOSA_PID=""
  fi

  _NEW=$(fetch_conf) || {
    echo "[watchdog] Fetch config fallito — stato invariato"
    continue
  }

  if auth_on "$_NEW"; then
    if ! satosa_running; then
      echo "[watchdog] Auth abilitato, SATOSA non in esecuzione — avvio..."
      eval "$(printf '%s' "$_NEW" | python3 -c "
import json,sys,shlex
for k,v in json.load(sys.stdin).items():
    if isinstance(v,str) and v and k.replace('_','').replace('-','').isalnum() and k[0].isalpha():
        print('export {}={}'.format(k,shlex.quote(v)))
")"
      start_satosa || true
    else
      echo "[watchdog] Auth OK, SATOSA in esecuzione (PID=$_SATOSA_PID)"
    fi
  else
    if satosa_running; then
      echo "[watchdog] Auth disabilitato dal backoffice — arresto SATOSA..."
      stop_satosa
      echo "[watchdog] In standby. SATOSA ripartirà quando auth sarà ri-abilitato."
    else
      echo "[watchdog] Auth disabilitato, SATOSA già fermo — standby"
    fi
  fi
done
