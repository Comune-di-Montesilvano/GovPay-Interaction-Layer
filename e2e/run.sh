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
"${COMPOSE[@]}" up -d --quiet-pull db backoffice frontoffice fake-govpay oidc prism mailpit \
  || fail "avvio stack e2e fallito"

bo_id=$("${COMPOSE[@]}" ps -q backoffice)
[ -n "$bo_id" ] || fail "container backoffice non creato"
status=
for ((i = 0; i < TIMEOUT; i += 5)); do
  status=$(docker inspect -f '{{.State.Health.Status}}' "$bo_id" 2>/dev/null || echo missing)
  [ "$status" = healthy ] || [ "$status" = unhealthy ] && break
  sleep 5
done
[ "$status" = healthy ] || fail "backoffice non healthy entro ${TIMEOUT}s (stato: $status)"
echo "OK  stack e2e pronto"

# Tipologia di test (dati sintetici) direttamente nel DB e2e: la sincronizzazione delle entrate
# da GovPay (Configurazione) non e' ancora coperta dal finto. Tabella creata dalle migrazioni
# all'avvio del backoffice (healthy sopra).
DB_ROOT_PASSWORD=$(sed -n 's/^DB_ROOT_PASSWORD=//p' "$ENV_FILE")
"${COMPOSE[@]}" exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME" -e "
  INSERT INTO entrate_tipologie
    (id_dominio, id_entrata, descrizione, iban_accredito, codice_contabilita, tipo_contabilita,
     abilitato_backoffice, effective_enabled, sorgente, updated_at, created_at)
  VALUES ('00000000000', 'TEST_SERVIZIO', 'Servizio di test e2e', 'IT60X0542811101000000123456',
     'TEST_SERVIZIO', '9', 1, 1, 'backoffice', NOW(), NOW())" || fail "seed tipologia di test fallito"
echo "OK  tipologia di test TEST_SERVIZIO"

rm -rf e2e/playwright-report e2e/test-results
rc=0
grep_args=()
[ -n "${E2E_GREP:-}" ] && grep_args=(--grep "$E2E_GREP")
"${COMPOSE[@]}" --profile runner run --rm -T playwright \
  sh -c 'npm ci --no-audit --no-fund && npx playwright test "$@"' sh "${grep_args[@]}" || rc=$?
exit "$rc"
