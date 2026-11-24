#!/usr/bin/env bash
# e2e/run.sh — test end-to-end Playwright sullo stack di produzione (docker-compose.yml) con
# le immagini di E2E_IMAGES_FILE (override con le 3 image@sha256, stesso formato di
# docker-compose.smoke.yml) e dipendenze esterne finte (e2e/docker-compose.e2e.yml).
#
# Isolamento: progetto compose dedicato (E2E_PROJECT, default gil-e2e), env file temporaneo
# fuori dal repo con segreti casuali (mai il .env del repo), nessuna porta host, mai
# docker-compose.override.yml, worker mai avviato, `down -v` sempre all'uscita.
# Exit code = quello di Playwright. Report: e2e/playwright-report, trace dei falliti: e2e/test-results.
#
# Uso:
#   E2E_IMAGES_FILE=/percorso/override-immagini.yml e2e/run.sh
#   E2E_GREP='operatore' E2E_IMAGES_FILE=... e2e/run.sh   # solo i test che corrispondono
#   E2E_UPGRADE_FROM_FILE=/percorso/override-release-precedente.yml E2E_IMAGES_FILE=... e2e/run.sh
#                    # test di aggiornamento: dati creati con la release precedente, poi immagini
#                    # nuove sullo stesso DB (migrazioni, post-deploy-check, backup, flussi @post-upgrade)
#   E2E_KEEP=1 ...   # solo in locale: stack lasciato attivo per il debug (poi
#                    # `docker compose -p gil-e2e down -v`); mai in CI
set -euo pipefail

cd "$(dirname "$0")/.."

IMAGES_FILE=${E2E_IMAGES_FILE:?E2E_IMAGES_FILE obbligatoria (override con le immagini da provare)}
PROJECT=${E2E_PROJECT:-gil-e2e}
TIMEOUT=${E2E_TIMEOUT:-300}
[ -f "$IMAGES_FILE" ] || { echo "::error::override immagini $IMAGES_FILE assente"; exit 1; }

# Versione di @playwright/test = tag dell'immagine Playwright (stesse versioni di browser).
pkg=$(sed -nE 's/^ *"@playwright\/test": *"([0-9]+\.[0-9]+\.[0-9]+)".*/\1/p' e2e/package.json)
img=$(sed -nE 's#^ *image: mcr\.microsoft\.com/playwright:v([0-9]+\.[0-9]+\.[0-9]+)-noble@sha256:[0-9a-f]{64}$#\1#p' e2e/docker-compose.e2e.yml)
[ -n "$pkg" ] && [ "$pkg" = "$img" ] || {
  echo "::error file=e2e/docker-compose.e2e.yml::@playwright/test '$pkg' diverso dall'immagine Playwright '$img'"
  exit 1
}

TMP_DIR=$(mktemp -d)
ENV_FILE="$TMP_DIR/e2e.env"
DB_NAME=govpay
export E2E_ADMIN_PASSWORD
E2E_ADMIN_PASSWORD="E2e-$(openssl rand -hex 12)"
{
  echo "DB_ROOT_PASSWORD=$(openssl rand -hex 16)"
  echo "DB_NAME=$DB_NAME"
  echo "DB_USER=govpay"
  echo "DB_PASSWORD=$(openssl rand -hex 16)"
  echo "BACKOFFICE_DB_USER=govpay"
  echo "BACKOFFICE_DB_PASSWORD=$(openssl rand -hex 16)"
  echo "APP_ENCRYPTION_KEY=$(openssl rand -hex 16)"
  echo "MASTER_TOKEN=$(openssl rand -hex 24)"
  echo "FRONTOFFICE_LINK_SIGNING_KEY=$(openssl rand -hex 32)"
  echo "SSL=off"
  echo "TRUSTED_PROXIES="
  echo "SENTRY_DSN="
  echo "SENTRY_ENVIRONMENT=e2e"
  echo "E2E_ADMIN_PASSWORD=$E2E_ADMIN_PASSWORD"
} > "$ENV_FILE"

COMPOSE=(docker compose -p "$PROJECT" --env-file "$ENV_FILE" -f docker-compose.yml -f "$IMAGES_FILE" -f e2e/docker-compose.e2e.yml)

cleanup() {
  local rc=$?
  if [ "$rc" -ne 0 ]; then
    "${COMPOSE[@]}" ps -a || true
    "${COMPOSE[@]}" logs --no-color --tail 80 backoffice frontoffice fake-govpay || true
  fi
  if [ "${E2E_KEEP:-}" = 1 ] && [ -z "${CI:-}" ]; then
    echo "E2E_KEEP=1: stack $PROJECT lasciato attivo (env in $ENV_FILE); fermarlo con:"
    echo "  docker compose -p $PROJECT --env-file $ENV_FILE -f docker-compose.yml -f $IMAGES_FILE -f e2e/docker-compose.e2e.yml --profile runner down -v"
  else
    "${COMPOSE[@]}" --profile runner down -v --remove-orphans >/dev/null 2>&1 || true
    rm -rf "$TMP_DIR"
  fi
  exit "$rc"
}
trap cleanup EXIT

fail() { echo "::error::$*"; exit 1; }

# Mai `worker`: i flussi non lo usano e lo copre smoke-stack.sh.
STACK=(db backoffice frontoffice fake-govpay oidc prism mailpit)
DB_ROOT_PASSWORD=$(sed -n 's/^DB_ROOT_PASSWORD=//p' "$ENV_FILE")

# $1.. = comando compose (array espanso); attende backoffice healthy (container attuale).
wait_backoffice() {
  local bo_id status=
  bo_id=$("$@" ps -q backoffice)
  [ -n "$bo_id" ] || fail "container backoffice non creato"
  for ((i = 0; i < TIMEOUT; i += 5)); do
    status=$(docker inspect -f '{{.State.Health.Status}}' "$bo_id" 2>/dev/null || echo missing)
    [ "$status" = healthy ] || [ "$status" = unhealthy ] && break
    sleep 5
  done
  [ "$status" = healthy ] || fail "backoffice non healthy entro ${TIMEOUT}s (stato: $status)"
}

sql() { "${COMPOSE[@]}" exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD" "$@"; }

# Tipologia di test (dati sintetici) direttamente nel DB e2e: la sincronizzazione delle entrate
# da GovPay (Configurazione) non e' ancora coperta dal finto. Tabella creata dalle migrazioni
# all'avvio del backoffice (healthy sopra).
seed() {
  sql "$DB_NAME" -e "
    INSERT INTO entrate_tipologie
      (id_dominio, id_entrata, descrizione, iban_accredito, codice_contabilita, tipo_contabilita,
       abilitato_backoffice, effective_enabled, sorgente, updated_at, created_at)
    VALUES ('00000000000', 'TEST_SERVIZIO', 'Servizio di test e2e', 'IT60X0542811101000000123456',
       'TEST_SERVIZIO', '9', 1, 1, 'backoffice', NOW(), NOW())" || fail "seed tipologia di test fallito"
  echo "OK  tipologia di test TEST_SERVIZIO"
}

# playwright <grep o ''> <E2E_UPGRADE>: exit code di Playwright in $rc.
rc=0
playwright() {
  local grep_args=()
  [ -n "$1" ] && grep_args=(--grep "$1")
  rm -rf e2e/playwright-report e2e/test-results
  rc=0
  "${COMPOSE[@]}" --profile runner run --rm -T -e E2E_UPGRADE="$2" playwright \
    sh -c 'npm ci --no-audit --no-fund && npx playwright test "$@"' sh "${grep_args[@]}" || rc=$?
}

if [ -z "${E2E_UPGRADE_FROM_FILE:-}" ]; then
  "${COMPOSE[@]}" up -d --quiet-pull "${STACK[@]}" || fail "avvio stack e2e fallito"
  wait_backoffice "${COMPOSE[@]}"
  echo "OK  stack e2e pronto"
  seed
  playwright "${E2E_GREP:-}" ""
  exit "$rc"
fi

# ── Modalita' upgrade: release precedente → immagini nuove sullo stesso DB ──────────────────
# Fase 1 con E2E_UPGRADE_FROM_FILE (override delle immagini della release precedente), dati
# creati dal wizard e dall'operatore (@pre-upgrade); fase 2: db/backoffice/frontoffice ricreati
# con E2E_IMAGES_FILE (volumi tenuti, finti non ricreati: il loro stato resta), poi migrazioni,
# post-deploy-check, backup pre-migrazione, ripristino del backup e flussi @post-upgrade.
[ -f "$E2E_UPGRADE_FROM_FILE" ] || fail "override release precedente $E2E_UPGRADE_FROM_FILE assente"
OLD=(docker compose -p "$PROJECT" --env-file "$ENV_FILE" -f docker-compose.yml -f "$E2E_UPGRADE_FROM_FILE" -f e2e/docker-compose.e2e.yml)

"${OLD[@]}" up -d --quiet-pull "${STACK[@]}" || fail "avvio stack con la release precedente fallito"
wait_backoffice "${OLD[@]}"
echo "OK  stack con la release precedente pronto"
seed
saved=("${COMPOSE[@]}")
COMPOSE=("${OLD[@]}")
playwright '@pre-upgrade' ""
COMPOSE=("${saved[@]}")
[ "$rc" = 0 ] || fail "flussi @pre-upgrade falliti sulla release precedente (exit $rc)"
echo "OK  dati creati sulla release precedente"

TABLES=(users settings entrate_tipologie gil_jobs)
counts() {
  local db=$1 t out=
  for t in "${TABLES[@]}"; do
    out+="$t=$(sql -N "$db" -e "SELECT COUNT(*) FROM \`$t\`")" || return 1
    out+=" "
  done
  echo "$out"
}
before=$(counts "$DB_NAME") || fail "conteggi pre-upgrade falliti"
echo "OK  conteggi pre-upgrade: $before"

"${COMPOSE[@]}" up -d --no-deps --quiet-pull db backoffice frontoffice || fail "passaggio alle immagini nuove fallito"
wait_backoffice "${COMPOSE[@]}"
bolog=$("${COMPOSE[@]}" logs --no-color backoffice)
grep -q 'migrazioni: ' <<<"$bolog" || fail "runner migrazioni non eseguito dalle immagini nuove"
if grep -E 'ERRORE|❌' <<<"$bolog"; then fail "errori di migrazione nei log del backoffice"; fi
# Il bootstrap del registro (primo avvio con gil_schema_migrations) tollera gli errori per i file
# gia' applicati dal vecchio runner: le migrazioni attuali sono tutte rieseguibili, quindi qui un
# errore tollerato e' una migrazione nuova rotta che in produzione passerebbe inosservata.
if grep -E 'errore tollerato' <<<"$bolog"; then fail "migrazioni con errori tollerati nel bootstrap del registro"; fi
grep -E 'migrazioni: |registro migrazioni|backup pre-migrazione|errore tollerato' <<<"$bolog" | sed 's/^[^|]*| //' || true
echo "OK  backoffice healthy con le immagini nuove, migrazioni senza errori"

pdc_rc=0
"${COMPOSE[@]}" up --no-deps --quiet-pull post-deploy-check >/dev/null 2>&1 || true
pdc_rc=$(docker inspect -f '{{.State.ExitCode}}' "$("${COMPOSE[@]}" ps -aq post-deploy-check)" 2>/dev/null || echo missing)
"${COMPOSE[@]}" logs --no-color post-deploy-check | sed 's/^[^|]*| //'
[ "$pdc_rc" = 0 ] || fail "post-deploy-check fallito dopo l'upgrade (exit $pdc_rc)"
echo "OK  post-deploy-check"

dump=$("${COMPOSE[@]}" exec -T backoffice sh -c 'ls -1 /backups/pre-migrazione/*.sql.gz 2>/dev/null | sort | tail -n 1') || true
[ -n "$dump" ] || fail "nessun backup pre-migrazione in /backups/pre-migrazione"
echo "OK  backup pre-migrazione: $dump"

sql -e "DROP DATABASE IF EXISTS restore_check; CREATE DATABASE restore_check" || fail "creazione restore_check fallita"
"${COMPOSE[@]}" exec -T backoffice sh -c "gzip -dc '$dump'" | sql restore_check || fail "ripristino del backup fallito"
after=$(counts restore_check) || fail "conteggi sul backup ripristinato falliti"
[ "$after" = "$before" ] || fail "backup ripristinato diverso dal DB pre-upgrade: '$after' invece di '$before'"
sql -e "DROP DATABASE restore_check" || true
echo "OK  backup ripristinato: stessi conteggi ($after)"

playwright '@post-upgrade' 1
[ "$rc" = 0 ] && echo "OK  flussi @post-upgrade sulle immagini nuove"
exit "$rc"
