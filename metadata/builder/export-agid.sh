#!/usr/bin/env bash
# export-agid.sh — esporta metadata pubblico SATOSA SPID per AgID
# Curla satosa-nginx via rete Docker interna (servizio deve essere up)
set -euo pipefail

SATOSA_URL="http://satosa-nginx/spidSaml2/metadata"
OUTPUT="/output/agid/satosa_spid_public_metadata.xml"
mkdir -p /output/agid

echo "[INFO] Attendo che satosa-nginx sia disponibile..."
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

echo "[ERROR] satosa-nginx non risponde." >&2
echo "        Verificare che il profilo iam-proxy sia avviato:" >&2
echo "          docker compose --profile iam-proxy up -d" >&2
exit 1
