#!/usr/bin/env bash
# status.sh — mostra scadenze cert SPID, SP metadata, Entity Statement CIE OIDC
set -euo pipefail

BOLD='\033[1m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "\n${BOLD}=== Certificato SPID (volume govpay_spid_certs) ===${NC}"
if [[ -f "/certs/cert.pem" ]]; then
  openssl x509 -noout -subject -dates -in /certs/cert.pem 2>/dev/null || echo "  cert.pem non leggibile"
else
  echo -e "  ${YELLOW}cert.pem non trovato — eseguire: docker compose run --rm metadata-builder setup-spid${NC}"
fi

echo -e "\n${BOLD}=== SP Metadata Frontoffice (volume frontoffice_sp_metadata) ===${NC}"
if [[ -f "/sp-metadata/frontoffice_sp.xml" ]]; then
  VALID_UNTIL=$(grep -o 'validUntil="[^"]*"' /sp-metadata/frontoffice_sp.xml 2>/dev/null | head -1 || true)
  if [[ -n "$VALID_UNTIL" ]]; then
    echo "  $VALID_UNTIL"
  else
    echo "  (validUntil non trovato nel metadata)"
  fi
else
  echo -e "  ${YELLOW}frontoffice_sp.xml non trovato — avviare il profilo auth-proxy${NC}"
fi

echo -e "\n${BOLD}=== CIE OIDC Entity Statement ===${NC}"
COMP_ENV="/output/cieoidc/component-values.env"
if [[ -f "$COMP_ENV" ]]; then
  EXP_EPOCH=$(grep "^ENTITY_STATEMENT_EXP_EPOCH=" "$COMP_ENV" | cut -d= -f2 || true)
  EXP_UTC=$(grep "^ENTITY_STATEMENT_EXP_UTC=" "$COMP_ENV" | cut -d= -f2 || true)
  EXP_DAYS=$(grep "^ENTITY_STATEMENT_EXP_DAYS_REMAINING=" "$COMP_ENV" | cut -d= -f2 || true)
  if [[ -n "$EXP_EPOCH" ]]; then
    NOW=$(date +%s)
    if [[ "$EXP_EPOCH" -lt "$NOW" ]]; then
      echo -e "  ${RED}SCADUTO il ${EXP_UTC}${NC}"
    elif [[ "${EXP_DAYS:-999}" -lt 30 ]]; then
      echo -e "  ${YELLOW}Scade il ${EXP_UTC} (${EXP_DAYS} giorni residui) — rinnovo consigliato${NC}"
    else
      echo -e "  ${GREEN}Valido fino al ${EXP_UTC} (${EXP_DAYS} giorni residui)${NC}"
    fi
  else
    echo "  (dati di scadenza non disponibili)"
  fi
else
  echo -e "  ${YELLOW}Non ancora esportato — eseguire: docker compose run --rm metadata-builder export-cieoidc${NC}"
fi

echo ""
