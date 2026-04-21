#!/usr/bin/env bash
# renew-spid.sh — rigenera certificati SPID (ROMPE la federazione AgID)
set -euo pipefail

YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${YELLOW}ATTENZIONE: Rinnovare i certificati SPID INTERROMPE la federazione con AgID.${NC}"
echo -e "${YELLOW}Dovrai re-inviare il metadata pubblico ad AgID dopo il rinnovo.${NC}"
echo ""
echo -n "Procedere con il rinnovo dei certificati SPID? [s/N] "
read -r ans
[[ "$ans" =~ ^[sS]$ ]] || { echo "Annullato."; exit 0; }
echo ""

FORCE=1 bash /builder/setup-spid.sh

echo ""
echo "[OK] Certificati SPID rigenerati."
echo ""
echo "Prossimi passi:"
echo "  1. Riavvia SATOSA:"
echo "       docker compose --profile auth-proxy restart auth-proxy"
echo "  2. Esporta il nuovo metadata AgID:"
echo "       docker compose run --rm metadata-builder export-agid"
echo "  3. Invia metadata/agid/satosa_spid_public_metadata.xml ad AgID"
echo "  4. Esegui un backup:"
echo "       docker compose run --rm metadata-builder backup"
