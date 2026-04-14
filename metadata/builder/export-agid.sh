#!/usr/bin/env bash
# export-agid.sh — esporta metadata pubblico SATOSA SPID per AgID
# Curla auth-proxy-nginx via rete Docker interna con Host header corretto.
set -euo pipefail

trap 'echo "[FATAL] Errore di sistema nello script export-agid.sh (riga $LINENO, exit $?)" >&2; exit 1' ERR

SATOSA_NGINX_HOSTNAME="${SATOSA_NGINX_HOSTNAME:-auth-proxy-nginx}"
SATOSA_URL_INTERNAL="http://${SATOSA_NGINX_HOSTNAME}/spidSaml2/metadata"
OUTPUT="/output/agid/satosa_spid_public_metadata.xml"
LAST_ERR=""
LAST_HTTP=""
LAST_URL=""
MAX_ATTEMPTS="${SATOSA_EXPORT_MAX_ATTEMPTS:-20}"
SLEEP_SECONDS="${SATOSA_EXPORT_RETRY_SLEEP_SECONDS:-3}"
SATOSA_PUBLIC_BASE_URL="${IAM_PROXY_PUBLIC_BASE_URL:-}"
SATOSA_HOST_HEADER="${SATOSA_INTERNAL_HOST_HEADER:-}"
VERIFY_PUBLIC="${SATOSA_VERIFY_PUBLIC_METADATA:-1}"
SATOSA_PUBLIC_METADATA_URL="${SATOSA_PUBLIC_METADATA_URL:-}"

if [ -z "$SATOSA_HOST_HEADER" ] && [ -n "$SATOSA_PUBLIC_BASE_URL" ]; then
  SATOSA_HOST_HEADER="$(echo "$SATOSA_PUBLIC_BASE_URL" | sed -E 's#^[a-zA-Z]+://([^/:]+).*$#\1#')"
fi

if [ -z "$SATOSA_PUBLIC_METADATA_URL" ] && [ -n "$SATOSA_PUBLIC_BASE_URL" ]; then
  SATOSA_PUBLIC_METADATA_URL="${SATOSA_PUBLIC_BASE_URL%/}/spidSaml2/metadata"
fi

if [ -z "$SATOSA_HOST_HEADER" ] && [ -n "$SATOSA_PUBLIC_METADATA_URL" ]; then
  SATOSA_HOST_HEADER="$(echo "$SATOSA_PUBLIC_METADATA_URL" | sed -E 's#^[a-zA-Z]+://([^/:]+).*$#\1#')"
fi

# Costruisci CURL_OPTS in forma robusta
CURL_OPTS="-sSf --connect-timeout 5 --max-time 10"

echo "[DEBUG] Configurazione: SATOSA_NGINX_HOSTNAME=$SATOSA_NGINX_HOSTNAME OUTPUT=$OUTPUT CURL_OPTS='$CURL_OPTS'" >&2
echo "[DEBUG] Internal URL: $SATOSA_URL_INTERNAL (Host header: ${SATOSA_HOST_HEADER:-none})" >&2
echo "[DEBUG] Public URL: ${SATOSA_PUBLIC_METADATA_URL:-none}" >&2
mkdir -p /output/agid || {
  echo "[ERROR] Impossibile creare directory /output/agid. Verifica permessi e mount del volume." >&2
  exit 1
}
echo "[DEBUG] Directory /output/agid pronta" >&2

echo "[INFO] Attendo che SATOSA sia disponibile..."
for i in $(seq 1 "$MAX_ATTEMPTS"); do
  TMP_ERR="/tmp/export-agid-curl.err.$$"
  TMP_OUT="/tmp/export-agid-curl.out.$$"

  HTTP_CODE="curl-fail"
  LAST_ERR=""

  # Tentativo interno principale: auth-proxy-nginx con Host header (obbligatorio)
  if [ -n "$SATOSA_HOST_HEADER" ]; then
    HTTP_CODE=$( \
      curl $CURL_OPTS -H "Host: $SATOSA_HOST_HEADER" -w "%{http_code}" "$SATOSA_URL_INTERNAL" -o "$TMP_OUT" 2>"$TMP_ERR" \
      | tail -c 3 \
    ) || HTTP_CODE="curl-fail"
    LAST_URL="$SATOSA_URL_INTERNAL"
  else
    echo "[DEBUG] Tentativo interno saltato: SATOSA_HOST_HEADER non configurato" >&2
  fi

  # Fallback finale: endpoint pubblico reverse proxy (se configurato)
  if [ "$HTTP_CODE" = "curl-fail" ] || [ "$HTTP_CODE" = "400" ] || [ "$HTTP_CODE" = "404" ] || [ "$HTTP_CODE" = "503" ]; then
    if [ -n "$SATOSA_PUBLIC_METADATA_URL" ]; then
      HTTP_CODE=$( \
        curl -sSfL --connect-timeout 5 --max-time 15 -w "%{http_code}" "$SATOSA_PUBLIC_METADATA_URL" -o "$TMP_OUT" 2>"$TMP_ERR" \
        | tail -c 3 \
      ) || HTTP_CODE="curl-fail"
      LAST_URL="$SATOSA_PUBLIC_METADATA_URL"
    fi
  fi
  
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

    if [ "$VERIFY_PUBLIC" = "1" ] || [ "$VERIFY_PUBLIC" = "true" ]; then
      if [ -n "$SATOSA_PUBLIC_METADATA_URL" ]; then
        TMP_PUBLIC="/tmp/export-agid-public.$$"
        TMP_PUBLIC_ERR="/tmp/export-agid-public.err.$$"
        PUBLIC_HTTP=$(curl -sSfL --connect-timeout 5 --max-time 15 -w "%{http_code}" "$SATOSA_PUBLIC_METADATA_URL" -o "$TMP_PUBLIC" 2>"$TMP_PUBLIC_ERR" | tail -c 3) || PUBLIC_HTTP="curl-fail"
        if [ "$PUBLIC_HTTP" = "200" ] && [ -s "$TMP_PUBLIC" ]; then
          echo "[OK] Verifica pubblicazione reverse proxy: ${SATOSA_PUBLIC_METADATA_URL} (HTTP 200)"
        else
          PUBLIC_ERR="$(cat "$TMP_PUBLIC_ERR" 2>/dev/null | tr '\n' '|' | sed 's/|$//' || true)"
          echo "[WARN] Metadata esportato internamente, ma endpoint pubblico non verificato: url=${SATOSA_PUBLIC_METADATA_URL} http=${PUBLIC_HTTP} err=${PUBLIC_ERR}"
        fi
        rm -f "$TMP_PUBLIC" "$TMP_PUBLIC_ERR"
      else
        echo "[WARN] Verifica endpoint pubblico saltata: SATOSA_PUBLIC_METADATA_URL/IAM_PROXY_PUBLIC_BASE_URL non impostati"
      fi
    fi

    echo "[OK] Metadata esportato: metadata/agid/satosa_spid_public_metadata.xml"
    echo ""
    echo "  Invia questo file ad AgID per l'attestazione SPID."
    rm -f "$TMP_ERR"
    exit 0
  fi
  
  if [ "$HTTP_CODE" != "unknown" ]; then
    echo "  Tentativo $i/$MAX_ATTEMPTS (${SLEEP_SECONDS}s) — url=$LAST_URL http_code=$HTTP_CODE curl_err=${LAST_ERR:0:60}"
  else
    echo "  Tentativo $i/$MAX_ATTEMPTS (${SLEEP_SECONDS}s) — url=$LAST_URL curl_fail, err=${LAST_ERR:0:60}"
  fi
  rm -f "$TMP_ERR" "$TMP_OUT"
  sleep "$SLEEP_SECONDS"
done

echo "[ERROR] SATOSA non risponde. Ultimo URL=${LAST_URL}. Ultimo HTTP code=${LAST_HTTP}. Ultimo errore curl: ${LAST_ERR}. Verifica SATOSA_INTERNAL_HOST_HEADER e IAM_PROXY_PUBLIC_BASE_URL nel metadata-builder." >&2
exit 1
