#!/usr/bin/env bash
# export-agid.sh — esporta metadata pubblico SATOSA SPID per AgID
# Curla auth-proxy-nginx via rete Docker interna (servizio deve essere up)
set -euo pipefail

SATOSA_HOSTNAME="${SATOSA_HOSTNAME:-auth-proxy-nginx}"
SATOSA_URL="http://${SATOSA_HOSTNAME}/spidSaml2/metadata"
OUTPUT="/output/agid/satosa_spid_public_metadata.xml"
mkdir -p /output/agid

echo "[INFO] Attendo che ${SATOSA_HOSTNAME} sia disponibile..."
for i in $(seq 1 40); do
  if curl -sf "$SATOSA_URL" -o "$OUTPUT" 2>/dev/null; then
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
