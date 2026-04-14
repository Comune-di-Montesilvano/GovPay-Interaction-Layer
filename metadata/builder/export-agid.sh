#!/usr/bin/env bash
# export-agid.sh — esporta metadata pubblico SATOSA SPID per AgID
# Curla SATOSA direttamente via rete Docker interna (service auth-proxy)
set -euo pipefail

trap 'echo "[FATAL] Errore di sistema nello script export-agid.sh (riga $LINENO, exit $?)" >&2; exit 1' ERR

SATOSA_HOSTNAME="${SATOSA_INTERNAL_HOSTNAME:-auth-proxy}"
SATOSA_SCHEME="${SATOSA_INTERNAL_SCHEME:-http}"
SATOSA_PORT="${SATOSA_INTERNAL_PORT:-10000}"
SATOSA_URL="${SATOSA_SCHEME}://${SATOSA_HOSTNAME}:${SATOSA_PORT}/spidSaml2/metadata"
OUTPUT="/output/agid/satosa_spid_public_metadata.xml"
LAST_ERR=""
LAST_HTTP=""
MAX_ATTEMPTS="${SATOSA_EXPORT_MAX_ATTEMPTS:-20}"
SLEEP_SECONDS="${SATOSA_EXPORT_RETRY_SLEEP_SECONDS:-3}"

# Costruisci CURL_OPTS in forma robusta
CURL_OPTS="-sf --connect-timeout 5 --max-time 10"
if [ "${SATOSA_SCHEME}" = "https" ]; then
  CURL_OPTS="${CURL_OPTS} -k"
fi

echo "[DEBUG] Configurazione: SATOSA_HOSTNAME=$SATOSA_HOSTNAME SATOSA_SCHEME=$SATOSA_SCHEME SATOSA_PORT=$SATOSA_PORT OUTPUT=$OUTPUT CURL_OPTS='$CURL_OPTS'" >&2
mkdir -p /output/agid || {
  echo "[ERROR] Impossibile creare directory /output/agid. Verifica permessi e mount del volume." >&2
  exit 1
}
echo "[DEBUG] Directory /output/agid pronta" >&2

echo "[INFO] Attendo che ${SATOSA_HOSTNAME} sia disponibile su ${SATOSA_URL}..."
for i in $(seq 1 "$MAX_ATTEMPTS"); do
  TMP_ERR="/tmp/export-agid-curl.err.$$"
  TMP_OUT="/tmp/export-agid-curl.out.$$"
  
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
      rm -f "$TMP_ERR" "$TMP_OUT"
      exit 1
    fi
    if command -v xmllint >/dev/null 2>&1; then
      xmllint --format "$OUTPUT" -o "$OUTPUT" 2>/dev/null || true
    fi
    echo "[OK] Metadata esportato: metadata/agid/satosa_spid_public_metadata.xml"
    echo ""
    echo "  Invia questo file ad AgID per l'attestazione SPID."
    rm -f "$TMP_ERR"
    exit 0
  fi
  
  if [ "$HTTP_CODE" != "unknown" ]; then
    echo "  Tentativo $i/$MAX_ATTEMPTS (${SLEEP_SECONDS}s) — http_code=$HTTP_CODE curl_err=${LAST_ERR:0:60}"
  else
    echo "  Tentativo $i/$MAX_ATTEMPTS (${SLEEP_SECONDS}s) — curl_fail, err=${LAST_ERR:0:60}"
  fi
  rm -f "$TMP_ERR" "$TMP_OUT"
  sleep "$SLEEP_SECONDS"
done

echo "[ERROR] ${SATOSA_HOSTNAME}:${SATOSA_PORT} non risponde su ${SATOSA_URL}. Ultimo HTTP code=${LAST_HTTP}. Ultimo errore curl: ${LAST_ERR}. Verifica che auth-proxy sia raggiungibile e /spidSaml2/metadata ritorni 200." >&2
exit 1
