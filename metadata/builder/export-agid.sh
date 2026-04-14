#!/usr/bin/env bash
# export-agid.sh — esporta metadata pubblico SATOSA SPID per AgID
# Curla auth-proxy-nginx via rete Docker interna (servizio deve essere up)
set -u pipefail

trap 'echo "[FATAL] Errore di sistema nello script export-agid.sh (riga $LINENO): $?" >&2; exit 1' ERR

SATOSA_HOSTNAME="${SATOSA_HOSTNAME:-auth-proxy-nginx}"
SATOSA_SCHEME="${SATOSA_INTERNAL_SCHEME:-http}"
SATOSA_URL="${SATOSA_SCHEME}://${SATOSA_HOSTNAME}/spidSaml2/metadata"
CURL_OPTS="-sf$( [ "${SATOSA_SCHEME}" = "https" ] && echo "k" ) --connect-timeout 5 --max-time 10"
OUTPUT="/output/agid/satosa_spid_public_metadata.xml"
LAST_ERR=""
LAST_HTTP=""

echo "[DEBUG] Configurazione: SATOSA_HOSTNAME=$SATOSA_HOSTNAME SATOSA_SCHEME=$SATOSA_SCHEME OUTPUT=$OUTPUT" >&2
if ! mkdir -p /output/agid 2>&1; then
  echo "[ERROR] Impossibile creare directory /output/agid. Verifica permessi e mount del volume." >&2
  exit 1
fi
echo "[DEBUG] Directory /output/agid pronta" >&2

echo "[INFO] Attendo che ${SATOSA_HOSTNAME} sia disponibile su ${SATOSA_URL}..."
for i in $(seq 1 40); do
  TMP_ERR="/tmp/export-agid-curl.err.$$"
  TMP_OUT="/tmp/export-agid-curl.out.$$"
  TMP_CODE="/tmp/export-agid-curl.code.$$"
  
  # Esegui curl: stdout=file, stderr=errlog, -w code scritto su stdout dopo body
  HTTP_CODE=$( \
    curl $CURL_OPTS -w "%{http_code}" "$SATOSA_URL" -o "$TMP_OUT" 2>"$TMP_ERR" \
    | tail -c 3 \
  ) || HTTP_CODE="curl-fail"
  
  LAST_HTTP="${HTTP_CODE:-unknown}"
  LAST_ERR="$(cat "$TMP_ERR" 2>/dev/null | tr '\n' '|' | sed 's/|$//' || echo 'N/A')"
  
  if [ "$HTTP_CODE" = "200" ] && [ -s "$TMP_OUT" ]; then
    if ! mv "$TMP_OUT" "$OUTPUT" 2>&1; then
      echo "[ERROR] Impossibile salvare file in $OUTPUT. Verifica permessi." >&2
      rm -f "$TMP_ERR" "$TMP_OUT" "$TMP_CODE"
      exit 1
    fi
    if command -v xmllint >/dev/null 2>&1; then
      xmllint --format "$OUTPUT" -o "$OUTPUT" 2>/dev/null || true
    fi
    echo "[OK] Metadata esportato: metadata/agid/satosa_spid_public_metadata.xml"
    echo ""
    echo "  Invia questo file ad AgID per l'attestazione SPID."
    rm -f "$TMP_ERR" "$TMP_CODE"
    exit 0
  fi
  
  if [ "$HTTP_CODE" != "unknown" ]; then
    echo "  Tentativo $i/40 (3s) — http_code=$HTTP_CODE curl_err=${LAST_ERR:0:60}"
  else
    echo "  Tentativo $i/40 (3s) — curl_fail, err=${LAST_ERR:0:60}"
  fi
  rm -f "$TMP_ERR" "$TMP_OUT" "$TMP_CODE"
  sleep 3
done

echo "[ERROR] ${SATOSA_HOSTNAME} non risponde su ${SATOSA_URL}. Ultimo HTTP code=${LAST_HTTP}. Ultimo errore curl: ${LAST_ERR}. Verifica che auth-proxy-nginx sia raggiungibile e /spidSaml2/metadata ritorni 200." >&2
exit 1
