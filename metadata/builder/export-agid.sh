#!/usr/bin/env bash
# export-agid.sh — esporta metadata pubblico SATOSA SPID per AgID
# Curla auth-proxy-nginx via rete Docker interna (servizio deve essere up)
set -euo pipefail

SATOSA_HOSTNAME="${SATOSA_HOSTNAME:-auth-proxy-nginx}"
SATOSA_SCHEME="$( [ "${SSL:-off}" = "on" ] && echo "https" || echo "http" )"
SATOSA_URL="${SATOSA_SCHEME}://${SATOSA_HOSTNAME}/spidSaml2/metadata"
CURL_OPTS="-sf$( [ "${SSL:-off}" = "on" ] && echo "k" )"
OUTPUT="/output/agid/satosa_spid_public_metadata.xml"
mkdir -p /output/agid

echo "[INFO] Attendo che ${SATOSA_HOSTNAME} sia disponibile..."
for i in $(seq 1 40); do
  if curl $CURL_OPTS "$SATOSA_URL" -o "$OUTPUT" 2>/dev/null; then
    xmllint --format "$OUTPUT" -o "$OUTPUT" 2>/dev/null || true
    echo "[OK] Metadata esportato: metadata/agid/satosa_spid_public_metadata.xml"
    echo ""
    echo "  Invia questo file ad AgID per l'attestazione SPID."
    exit 0
  fi
  echo "  Tentativo $i/40 (3s)..."
  sleep 3
done

echo "[ERROR] ${SATOSA_HOSTNAME} non risponde. Verificare che il servizio auth-proxy sia avviato." >&2
exit 1
