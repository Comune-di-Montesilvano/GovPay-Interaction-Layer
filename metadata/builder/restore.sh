#!/usr/bin/env bash
# restore.sh — ripristina backup metadata e volumi Docker
set -euo pipefail

FILE="${1:-}"
if [[ -z "$FILE" ]]; then
  echo "Uso: docker compose run --rm metadata-builder restore <file.tar.gz>" >&2
  echo ""
  echo "Archivi disponibili in backup/:"
  ls /backup/*.tar.gz 2>/dev/null || echo "  (nessuno trovato)"
  exit 1
fi

# Supporta path assoluto o relativo a /backup
if [[ "$FILE" != /* ]]; then
  FILE="/backup/$FILE"
fi

if [[ ! -f "$FILE" ]]; then
  echo "[ERROR] File non trovato: $FILE" >&2
  exit 1
fi

BASENAME="$(basename "$FILE")"

echo "File: $BASENAME"
echo ""

case "$BASENAME" in
  spid_certs_*)
    echo "ATTENZIONE: sovrascriverà i certificati SPID nel volume govpay_spid_certs."
    echo -n "Confermi? [s/N] "
    read -r ans
    [[ "$ans" =~ ^[sS]$ ]] || { echo "Annullato."; exit 0; }
    tar xzf "$FILE" -C /certs
    echo "[OK] Certificati SPID ripristinati."
    echo "     Riavvia: docker compose --profile auth-proxy restart auth-proxy"
    ;;
  frontoffice_sp_metadata_*)
    echo "ATTENZIONE: sovrascriverà il SP metadata nel volume frontoffice_sp_metadata."
    echo -n "Confermi? [s/N] "
    read -r ans
    [[ "$ans" =~ ^[sS]$ ]] || { echo "Annullato."; exit 0; }
    tar xzf "$FILE" -C /sp-metadata
    echo "[OK] SP metadata ripristinato."
    echo "     Riavvia: docker compose --profile auth-proxy restart auth-proxy"
    ;;
  metadata_local_*)
    echo "ATTENZIONE: sovrascriverà metadata/cieoidc-keys, metadata/agid, metadata/cieoidc."
    echo -n "Confermi? [s/N] "
    read -r ans
    [[ "$ans" =~ ^[sS]$ ]] || { echo "Annullato."; exit 0; }
    tar xzf "$FILE" -C /
    echo "[OK] File locali ripristinati."
    ;;
  *)
    echo "[ERROR] Nome file non riconosciuto." >&2
    echo "        Usa archivi creati da: docker compose run --rm metadata-builder backup" >&2
    exit 1
    ;;
esac
