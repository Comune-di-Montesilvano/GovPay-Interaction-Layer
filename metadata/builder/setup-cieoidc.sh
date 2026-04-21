#!/usr/bin/env bash
# setup-cieoidc.sh — genera chiavi JWK CIE OIDC in /output/cieoidc-keys/
set -euo pipefail

KEYS_DIR="/output/cieoidc-keys"
LOCK_FILE="$KEYS_DIR/GENERATED_AT"
FORCE="${FORCE:-0}"

if [[ -f "$LOCK_FILE" && -f "$KEYS_DIR/jwk-federation.json" && "$FORCE" != "1" ]]; then
  echo "[INFO] Chiavi JWK CIE OIDC già presenti (generate il $(cat "$LOCK_FILE"))."
  echo "       Usa FORCE=1 per rigenerare."
  echo ""
  echo "       ATTENZIONE: rigenerare le chiavi dopo la federazione ROMPE la"
  echo "       federazione CIE OIDC finché l'Entity Statement non è scaduto."
  exit 0
fi

echo "[INFO] Generazione chiavi JWK CIE OIDC (RSA 2048-bit)..."
mkdir -p "$KEYS_DIR"

python3 /builder/gen-jwk.py "$KEYS_DIR"

GENERATED_NOW="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "$GENERATED_NOW" > "$LOCK_FILE"

echo ""
echo "[OK] Chiavi salvate in metadata/cieoidc-keys/"
echo ""
echo "========================================================"
echo "  IMPORTANTE — Le chiavi sono ora bloccate."
echo "  NON rigenerare dopo la federazione CIE OIDC."
echo "  Prossimi passi:"
echo "    1. docker compose --profile auth-proxy up -d"
echo "    2. docker compose run --rm metadata-builder export-cieoidc"
echo "       (esporta per onboarding al portale CIE OIDC)"
echo "========================================================"
