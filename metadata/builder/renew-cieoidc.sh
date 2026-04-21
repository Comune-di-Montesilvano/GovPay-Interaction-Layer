#!/usr/bin/env bash
# renew-cieoidc.sh — rigenera chiavi JWK CIE OIDC (ROMPE la federazione CIE)
set -euo pipefail

RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${RED}ATTENZIONE: Rinnovare le chiavi JWK CIE OIDC ROMPE la federazione CIE OIDC.${NC}"
echo -e "${RED}Eseguire SOLO quando l'Entity Statement è scaduto o si sta pianificando un rinnovo.${NC}"
echo ""
echo -n "Scrivi esattamente 'SI VOGLIO RINNOVARE' per confermare: "
read -r ans
[[ "$ans" == "SI VOGLIO RINNOVARE" ]] || { echo "Annullato."; exit 0; }
echo ""

echo "Rigenero chiavi JWK CIE OIDC..."
FORCE=1 bash /builder/setup-cieoidc.sh

echo ""
echo "[OK] Chiavi CIE OIDC rinnovate."
echo ""
echo "Prossimi passi:"
echo "  1. Riavvia i container auth-proxy:"
echo "       docker compose --profile auth-proxy restart"
echo "  2. Esporta i nuovi artifact CIE OIDC:"
echo "       docker compose run --rm -e FORCE=1 metadata-builder export-cieoidc"
echo "  3. Completa l'onboarding sul portale CIE per sviluppatori"
echo "  4. Attendi la propagazione della federazione (fino a 24h)"
echo "  5. Esegui un backup:"
echo "       docker compose run --rm metadata-builder backup"
