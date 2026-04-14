#!/usr/bin/env bash
# backup.sh — backup volumi Docker e file locali metadata
set -euo pipefail

DEST="${1:-/backup}"
TS=$(date +%Y%m%d_%H%M%S)
mkdir -p "$DEST"

echo "Backup in: $DEST"

echo ""
echo "1/3 Certificati SPID (volume govpay_spid_certs → /certs)"
if [[ -f "/certs/cert.pem" ]]; then
  tar czf "$DEST/spid_certs_${TS}.tar.gz" -C /certs .
  echo "    OK: spid_certs_${TS}.tar.gz"
else
  echo "    SKIP: /certs/cert.pem non trovato (volume vuoto o non montato)"
fi

echo ""
echo "2/3 SP Metadata Frontoffice (volume frontoffice_sp_metadata → /sp-metadata)"
if compgen -G "/sp-metadata/*" > /dev/null 2>&1; then
  tar czf "$DEST/frontoffice_sp_metadata_${TS}.tar.gz" -C /sp-metadata .
  echo "    OK: frontoffice_sp_metadata_${TS}.tar.gz"
else
  echo "    SKIP: /sp-metadata vuoto (avviare il profilo auth-proxy prima)"
fi

echo ""
echo "3/3 File locali (cieoidc-keys, agid, cieoidc)"
tar czf "$DEST/metadata_local_${TS}.tar.gz" \
  --ignore-failed-read \
  -C / \
  output/cieoidc-keys output/agid output/cieoidc 2>/dev/null || true
echo "    OK: metadata_local_${TS}.tar.gz"

echo ""
echo "Backup completato:"
ls -lh "$DEST"/*"${TS}"*.tar.gz 2>/dev/null || true
