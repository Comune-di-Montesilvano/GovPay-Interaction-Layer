#!/usr/bin/env bash
# setup-spid.sh — genera certificati SPID nel volume /certs
set -euo pipefail

CERTS_DIR="/certs"
FORCE="${FORCE:-0}"

if [[ -s "$CERTS_DIR/cert.pem" && -s "$CERTS_DIR/privkey.pem" && "$FORCE" != "1" ]]; then
  echo "[INFO] Certificati SPID già presenti in $CERTS_DIR."
  echo "       Usa FORCE=1 per rigenerare."
  exit 0
fi

# Variabili richieste da spid-gencert-public.sh (mappate da SPID_CERT_*)
export COMMON_NAME="${SPID_CERT_COMMON_NAME:?Imposta SPID_CERT_COMMON_NAME in .auth-proxy.env}"
export DAYS="${SPID_CERT_DAYS:-365}"
export ENTITY_ID="${SPID_CERT_ENTITY_ID:-${FRONTOFFICE_PUBLIC_BASE_URL:-https://127.0.0.1:8444}/saml/sp}"
export KEY_LEN="${SPID_CERT_KEY_SIZE:-3072}"
export LOCALITY_NAME="${SPID_CERT_LOCALITY_NAME:?Imposta SPID_CERT_LOCALITY_NAME in .auth-proxy.env}"
export ORGANIZATION_IDENTIFIER="${SPID_CERT_ORG_ID:?Imposta SPID_CERT_ORG_ID in .auth-proxy.env}"
export ORGANIZATION_NAME="${SPID_CERT_ORG_NAME:-$COMMON_NAME}"

echo "[INFO] Generazione certificati SPID..."
echo "       Common Name:  $COMMON_NAME"
echo "       Org ID:       $ORGANIZATION_IDENTIFIER"
echo "       Entity ID:    $ENTITY_ID"
echo "       Validità:     ${DAYS} giorni"
echo ""

mkdir -p "$CERTS_DIR"
chmod 755 "$CERTS_DIR" 2>/dev/null || true
cd "$CERTS_DIR"
bash /builder/spid-gencert-public.sh

# Rinomina nei nomi attesi da SATOSA/iam-proxy-italia
cp crt.pem cert.pem
cp key.pem privkey.pem
chmod 644 ./*.pem
# Permessi 775 per permettere a www-data (e altri utenti del gruppo) di scrivere nella directory
chmod 775 "$CERTS_DIR" 2>/dev/null || true
# Fallback con chmod 777 se 775 non riesce (per ambienti con mount options restrittive)
if [ ! -w "$CERTS_DIR" ]; then
  echo "[WARN] Directory non scrivibile dopo chmod 775, provo chmod 777..."
  chmod 777 "$CERTS_DIR" 2>/dev/null || true
fi
rm -f crt.pem key.pem csr.pem

echo ""
echo "[OK] Certificati generati:"
echo "       /certs/cert.pem     (certificato pubblico)"
echo "       /certs/privkey.pem  (chiave privata)"
echo "       Permessi: $(stat -c '%a' "$CERTS_DIR") (directory)"
