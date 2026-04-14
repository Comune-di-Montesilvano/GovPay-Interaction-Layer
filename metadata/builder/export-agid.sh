#!/usr/bin/env bash
# export-agid.sh — esporta metadata pubblico SATOSA SPID per AgID
# Curla auth-proxy-nginx via rete Docker interna (servizio deve essere up)
set -euo pipefail

SATOSA_HOSTNAME="${SATOSA_HOSTNAME:-auth-proxy-nginx}"
SATOSA_SCHEME="${SATOSA_INTERNAL_SCHEME:-http}"
SATOSA_URL="${SATOSA_SCHEME}://${SATOSA_HOSTNAME}/spidSaml2/metadata"
CURL_OPTS="-sf$( [ "${SATOSA_SCHEME}" = "https" ] && echo "k" ) --connect-timeout 5 --max-time 10"
OUTPUT="/output/agid/satosa_spid_public_metadata.xml"
LAST_ERR=""
LAST_HTTP=""
mkdir -p /output/agid

echo "[INFO] Attendo che ${SATOSA_HOSTNAME} sia disponibile su ${SATOSA_URL}..."
for i in $(seq 1 40); do
  TMP_ERR="/tmp/export-agid-curl.err"
  HTTP_CODE="$(curl $CURL_OPTS -w "%{http_code}" "$SATOSA_URL" -o "$OUTPUT" 2>"$TMP_ERR" || true)"
  LAST_HTTP="$HTTP_CODE"
  LAST_ERR="$(tr '\n' ' ' < "$TMP_ERR" 2>/dev/null || true)"
  if [ "$HTTP_CODE" = "200" ] && [ -s "$OUTPUT" ]; then
    xmllint --format "$OUTPUT" -o "$OUTPUT" 2>/dev/null || true
    echo "[OK] Metadata esportato: metadata/agid/satosa_spid_public_metadata.xml"
    echo ""
    echo "  Invia questo file ad AgID per l'attestazione SPID."
    rm -f "$TMP_ERR"
    exit 0
  fi
  if [ -n "$LAST_ERR" ]; then
    echo "  Tentativo $i/40 (3s) — http=${HTTP_CODE} err=${LAST_ERR}"
  else
    echo "  Tentativo $i/40 (3s) — http=${HTTP_CODE}"
  fi
  rm -f "$TMP_ERR"
  sleep 3
done

echo "[ERROR] ${SATOSA_HOSTNAME} non risponde correttamente su ${SATOSA_URL}. Ultimo HTTP=${LAST_HTTP:-n/a}. Ultimo errore curl: ${LAST_ERR:-n/a}. Verificare che auth-proxy-nginx sia avviato e che /spidSaml2/metadata risponda 200." >&2
exit 1
