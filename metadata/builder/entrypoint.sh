#!/usr/bin/env bash
set -euo pipefail

case "${1:-help}" in
  setup)
    bash /builder/setup-spid.sh
    bash /builder/setup-cieoidc.sh
    echo ""
    echo "Setup completato. Prossimi passi:"
    echo "  docker compose --profile iam-proxy up -d"
    echo "  docker compose run --rm metadata-builder export-agid"
    ;;
  setup-spid)        bash /builder/setup-spid.sh ;;
  setup-cieoidc)     bash /builder/setup-cieoidc.sh ;;
  export-agid)       bash /builder/export-agid.sh ;;
  export-cieoidc)    bash /builder/export-cieoidc.sh ;;
  backup)            bash /builder/backup.sh "${2:-}" ;;
  restore)           bash /builder/restore.sh "${2:-}" ;;
  status)            bash /builder/status.sh ;;
  renew-spid)        bash /builder/renew-spid.sh ;;
  renew-cieoidc)     bash /builder/renew-cieoidc.sh ;;
  server)            exec python3 /builder/server.py ;;
  help|-h|--help|*)
    cat <<EOF

Uso: docker compose run --rm metadata-builder <subcomando>

Prima installazione:
  setup           Genera cert SPID + chiavi JWK CIE OIDC
  export-agid     Esporta metadata AgID (richiede profilo iam-proxy up)
  export-cieoidc  Esporta artifact CIE OIDC per onboarding (richiede iam-proxy up)

Operazioni:
  status          Mostra scadenze: cert SPID, SP metadata, Entity Statement CIE OIDC
  backup [dir]    Backup volumi Docker e file locali in ./backup/ (o dir specificata)
  restore <file>  Ripristina da archivio backup

Rinnovo:
  renew-spid      Rigenera cert SPID                (ROMPE federazione AgID)
  renew-cieoidc   Rigenera chiavi JWK CIE OIDC      (ROMPE federazione CIE)

Subcomandi singoli:
  setup-spid      Solo certificati SPID
  setup-cieoidc   Solo chiavi JWK CIE OIDC

EOF
    ;;
esac
