#!/bin/sh
set -euo pipefail

CERT_DIR="/etc/nginx/certs"
CRT="$CERT_DIR/server.crt"
KEY="$CERT_DIR/server.key"

mkdir -p "$CERT_DIR"

if [ ! -f "$CRT" ] || [ ! -f "$KEY" ]; then
  echo "[ssl-init] Generating self-signed certificate in $CERT_DIR"
  apk add --no-cache openssl >/dev/null 2>&1
  HOST="${NGINX_HOST:-localhost}"
  openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -subj "/CN=${HOST}" \
    -keyout "$KEY" -out "$CRT"
  chmod 600 "$KEY"
else
  echo "[ssl-init] Certificate already exists"
fi
