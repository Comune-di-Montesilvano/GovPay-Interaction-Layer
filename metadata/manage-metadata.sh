#!/usr/bin/env bash
# manage-metadata.sh — gestione certificati, metadata SPID e chiavi CIE OIDC
# Uso: bash metadata/manage-metadata.sh <subcomando> [opzioni]
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="$PROJECT_DIR/backup"

# ── Nomi volumi Docker ───────────────────────────────────────────────────────

# Volume certificati SPID (esterno, può essere personalizzato in .iam-proxy.env)
SPID_CERTS_VOLUME="govpay_spid_certs"
if [[ -f "$PROJECT_DIR/.iam-proxy.env" ]]; then
  _v=$(grep -E '^SPID_CERTS_DOCKER_VOLUME=' "$PROJECT_DIR/.iam-proxy.env" 2>/dev/null \
       | head -1 | cut -d= -f2 | tr -d '"' | tr -d "'" | tr -d '[:space:]' || true)
  [[ -n "$_v" ]] && SPID_CERTS_VOLUME="$_v"
fi

# Volume SP metadata (interno, prefissato dal nome progetto Docker Compose)
_find_sp_metadata_volume() {
  docker volume ls --format '{{.Name}}' 2>/dev/null \
    | grep '_frontoffice_sp_metadata$' | head -1 || true
}
SP_METADATA_VOLUME=$(_find_sp_metadata_volume)
if [[ -z "$SP_METADATA_VOLUME" ]]; then
  _proj=$(basename "$PROJECT_DIR" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]-')
  SP_METADATA_VOLUME="${_proj}_frontoffice_sp_metadata"
fi

# Nome container frontoffice
FRONTOFFICE_CONTAINER="govpay-interaction-frontoffice"

# ── Colori ───────────────────────────────────────────────────────────────────
RED='\033[0;31m'
YELLOW='\033[1;33m'
GREEN='\033[0;32m'
BOLD='\033[1m'
NC='\033[0m'

err()  { echo -e "${RED}ERRORE:${NC} $*" >&2; }
warn() { echo -e "${YELLOW}ATTENZIONE:${NC} $*"; }
ok()   { echo -e "${GREEN}✓${NC} $*"; }
hdr()  { echo -e "\n${BOLD}$*${NC}"; }

_confirm() {
  local msg="${1:-Confermi?}"
  read -r -p "$msg [s/N] " ans
  [[ "$ans" =~ ^[sS]$ ]]
}

# ── STATUS ───────────────────────────────────────────────────────────────────
cmd_status() {
  hdr "=== Certificato SPID (volume: $SPID_CERTS_VOLUME) ==="
  if docker volume inspect "$SPID_CERTS_VOLUME" &>/dev/null; then
    docker run --rm \
      -v "${SPID_CERTS_VOLUME}:/data" \
      alpine sh -c \
        "apk add --no-cache openssl -q 2>/dev/null; \
         openssl x509 -noout -subject -dates -in /data/cert.pem 2>/dev/null \
         || echo 'cert.pem non trovato nel volume'"
  else
    warn "Volume $SPID_CERTS_VOLUME non trovato (eseguire setup-sp.sh prima)"
  fi

  hdr "=== SP Metadata Frontoffice (volume: $SP_METADATA_VOLUME) ==="
  if docker volume inspect "$SP_METADATA_VOLUME" &>/dev/null; then
    docker run --rm \
      -v "${SP_METADATA_VOLUME}:/data" \
      alpine sh -c \
        "grep -o 'validUntil=\"[^\"]*\"' /data/frontoffice_sp.xml 2>/dev/null \
         | head -1 || echo 'frontoffice_sp.xml non trovato (avviare il profilo iam-proxy)'"
  else
    warn "Volume $SP_METADATA_VOLUME non trovato (avviare con --profile iam-proxy)"
  fi

  hdr "=== CIE OIDC Entity Statement ==="
  local comp_env="$SCRIPT_DIR/cieoidc/component-values.env"
  if [[ -f "$comp_env" ]]; then
    grep 'ENTITY_STATEMENT_EXP' "$comp_env" || warn "Variabili di scadenza non trovate in component-values.env"
  else
    warn "Non ancora esportato — eseguire: bash metadata/export-cieoidc.sh"
  fi

  echo ""
}

# ── BACKUP ───────────────────────────────────────────────────────────────────
cmd_backup() {
  local DEST="${1:-$BACKUP_DIR}"
  local TS; TS=$(date +%Y%m%d_%H%M%S)
  mkdir -p "$DEST"

  echo "Backup in: $DEST"

  hdr "1/3 Certificati SPID (volume: $SPID_CERTS_VOLUME)"
  if docker volume inspect "$SPID_CERTS_VOLUME" &>/dev/null; then
    docker run --rm \
      -v "${SPID_CERTS_VOLUME}:/data" \
      -v "${DEST}:/backup" \
      alpine tar czf "/backup/spid_certs_${TS}.tar.gz" -C /data .
    ok "spid_certs_${TS}.tar.gz"
  else
    warn "Volume $SPID_CERTS_VOLUME non trovato — skipped"
  fi

  hdr "2/3 SP Metadata Frontoffice (volume: $SP_METADATA_VOLUME)"
  if docker volume inspect "$SP_METADATA_VOLUME" &>/dev/null; then
    docker run --rm \
      -v "${SP_METADATA_VOLUME}:/data" \
      -v "${DEST}:/backup" \
      alpine tar czf "/backup/frontoffice_sp_metadata_${TS}.tar.gz" -C /data .
    ok "frontoffice_sp_metadata_${TS}.tar.gz"
  else
    warn "Volume $SP_METADATA_VOLUME non trovato — skipped"
  fi

  hdr "3/3 File locali (cieoidc-keys, agid, cieoidc)"
  (cd "$PROJECT_DIR" && tar czf "${DEST}/metadata_local_${TS}.tar.gz" \
    --ignore-failed-read \
    metadata/cieoidc-keys metadata/agid metadata/cieoidc 2>/dev/null) || true
  ok "metadata_local_${TS}.tar.gz"

  echo ""
  echo "Backup completato:"
  ls -lh "${DEST}"/*"${TS}"*.tar.gz 2>/dev/null || true
}

# ── RESTORE ──────────────────────────────────────────────────────────────────
cmd_restore() {
  local FILE="${1:-}"
  [[ -n "$FILE" ]] || { err "Uso: $0 restore <file.tar.gz>"; exit 1; }
  [[ -f "$FILE" ]] || { err "File non trovato: $FILE"; exit 1; }

  local ABS_FILE; ABS_FILE="$(cd "$(dirname "$FILE")" && pwd)/$(basename "$FILE")"
  local BDIR; BDIR="$(dirname "$ABS_FILE")"

  case "$(basename "$ABS_FILE")" in
    spid_certs_*)
      warn "Sovrascriverà i certificati SPID nel volume $SPID_CERTS_VOLUME"
      _confirm || { echo "Annullato."; exit 0; }
      docker run --rm \
        -v "${SPID_CERTS_VOLUME}:/data" \
        -v "${BDIR}:/backup" \
        alpine tar xzf "/backup/$(basename "$ABS_FILE")" -C /data
      ok "Certificati SPID ripristinati in $SPID_CERTS_VOLUME"
      echo "Riavvia: docker compose --profile iam-proxy restart iam-proxy-italia"
      ;;
    frontoffice_sp_metadata_*)
      warn "Sovrascriverà SP metadata nel volume $SP_METADATA_VOLUME"
      _confirm || { echo "Annullato."; exit 0; }
      docker run --rm \
        -v "${SP_METADATA_VOLUME}:/data" \
        -v "${BDIR}:/backup" \
        alpine tar xzf "/backup/$(basename "$ABS_FILE")" -C /data
      ok "SP metadata ripristinato in $SP_METADATA_VOLUME"
      echo "Riavvia: docker compose --profile iam-proxy restart iam-proxy-italia"
      ;;
    metadata_local_*)
      warn "Sovrascriverà metadata/cieoidc-keys, metadata/agid, metadata/cieoidc in $PROJECT_DIR"
      _confirm || { echo "Annullato."; exit 0; }
      tar xzf "$ABS_FILE" -C "$PROJECT_DIR"
      ok "File locali ripristinati"
      ;;
    *)
      err "Nome file non riconosciuto. Usa archivi creati da: $0 backup"
      exit 1
      ;;
  esac
}

# ── RENEW-SP-METADATA ────────────────────────────────────────────────────────
cmd_renew_sp_metadata() {
  echo "Genera un nuovo SP metadata senza interrompere la federazione corrente."
  echo "Il metadata attivo rimane invariato finché non lo sostituisci esplicitamente."
  echo ""

  if ! docker ps --format '{{.Names}}' | grep -q "^${FRONTOFFICE_CONTAINER}$"; then
    err "Container $FRONTOFFICE_CONTAINER non in esecuzione."
    echo "Avviare prima: docker compose --profile iam-proxy up -d"
    exit 1
  fi

  docker exec "$FRONTOFFICE_CONTAINER" bash /scripts/ensure-sp-metadata.sh --new

  echo ""
  ok "Nuovo metadata generato nel volume $SP_METADATA_VOLUME"
  echo ""
  echo "Per attivare il nuovo metadata (richiede restart di iam-proxy-italia):"
  echo "  docker exec $FRONTOFFICE_CONTAINER bash /scripts/ensure-sp-metadata.sh --force"
  echo "  docker compose --profile iam-proxy restart iam-proxy-italia"
}

# ── RENEW-SPID ───────────────────────────────────────────────────────────────
cmd_renew_spid() {
  warn "Rinnovare i certificati SPID INTERROMPE la federazione con AgID."
  warn "Dovrai re-inviare il metadata pubblico ad AgID dopo il rinnovo."
  echo ""
  _confirm "Procedere con il rinnovo dei certificati SPID?" || { echo "Annullato."; exit 0; }
  echo ""

  bash "$SCRIPT_DIR/setup-sp.sh" --force

  echo ""
  ok "Certificati SPID rigenerati."
  echo ""
  echo "Prossimi passi:"
  echo "  1. Invia a AgID: metadata/agid/satosa_spid_public_metadata.xml"
  echo "  2. Riavvia: docker compose --profile iam-proxy restart iam-proxy-italia"
  echo "  3. Backup:  bash metadata/manage-metadata.sh backup"
}

# ── RENEW-CIEOIDC ────────────────────────────────────────────────────────────
cmd_renew_cieoidc() {
  warn "Rinnovare le chiavi JWK CIE OIDC ROMPE la federazione CIE."
  warn "Eseguire SOLO quando l'Entity Statement è scaduto o si sta pianificando un rinnovo."
  echo ""
  echo -n "Scrivi esattamente 'SI VOGLIO RINNOVARE' per confermare: "
  read -r ans
  [[ "$ans" == "SI VOGLIO RINNOVARE" ]] || { echo "Annullato."; exit 0; }
  echo ""

  echo "Rigenero chiavi JWK CIE OIDC..."
  bash "$SCRIPT_DIR/setup-cie-oidc.sh" --force --i-know-what-i-am-doing

  echo ""
  echo "Esporto nuovi artifact CIE OIDC..."
  bash "$SCRIPT_DIR/export-cieoidc.sh" --force

  echo ""
  ok "Chiavi CIE OIDC rinnovate. Artifact esportati in metadata/cieoidc/"
  echo ""
  echo "Prossimi passi:"
  echo "  1. Completa l'onboarding sul portale CIE per sviluppatori"
  echo "  2. Riavvia: docker compose --profile iam-proxy restart"
  echo "  3. Attendi la propagazione della federazione (fino a 24h)"
  echo "  4. Backup: bash metadata/manage-metadata.sh backup"
}

# ── HELP ─────────────────────────────────────────────────────────────────────
cmd_help() {
  cat <<EOF

Uso: bash metadata/manage-metadata.sh <subcomando> [opzioni]

Subcomandi:
  status              Mostra scadenze: cert SPID, SP metadata, Entity Statement CIE OIDC
  backup [dir]        Backup volumi Docker e file locali (default: backup/)
  restore <file>      Ripristina da archivio backup
  renew-sp-metadata   Pre-genera nuovo SP metadata (senza interrompere la federazione)
  renew-spid          Rigenera certificati SPID + metadata AgID  (ROMPE federazione SPID)
  renew-cieoidc       Rigenera chiavi JWK CIE OIDC               (ROMPE federazione CIE)
  help                Mostra questo messaggio

Volumi Docker rilevati:
  SPID certs:   $SPID_CERTS_VOLUME
  SP metadata:  $SP_METADATA_VOLUME

Esempi:
  bash metadata/manage-metadata.sh status
  bash metadata/manage-metadata.sh backup
  bash metadata/manage-metadata.sh backup /mnt/backup/govpay
  bash metadata/manage-metadata.sh restore backup/spid_certs_20250101_120000.tar.gz
  bash metadata/manage-metadata.sh renew-sp-metadata

EOF
}

# ── MAIN ─────────────────────────────────────────────────────────────────────
case "${1:-help}" in
  status)            cmd_status ;;
  backup)            cmd_backup "${2:-}" ;;
  restore)           cmd_restore "${2:-}" ;;
  renew-sp-metadata) cmd_renew_sp_metadata ;;
  renew-spid)        cmd_renew_spid ;;
  renew-cieoidc)     cmd_renew_cieoidc ;;
  help|-h|--help)    cmd_help ;;
  *)
    err "Subcomando sconosciuto: ${1}"
    cmd_help
    exit 1
    ;;
esac
