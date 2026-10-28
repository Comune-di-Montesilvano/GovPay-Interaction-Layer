#!/usr/bin/env bash
# scripts/smoke-stack.sh — smoke test dello stack di produzione (docker-compose.yml) con le
# immagini fissate per digest da un file override (docker-compose.smoke.yml, generato dal job
# smoke di docker-publish.yml, mai committato). Avvia lo stack, controlla:
#   - backoffice healthy e /health 200;
#   - frontoffice 200 su /;
#   - query su `settings` (schema init del DB applicato);
#   - nessun `Fatal`/`Parse error` nei log;
# e lo distrugge sempre (down -v) all'uscita.
#
# Isolamento da uno stack di sviluppo sulla stessa macchina:
#   - progetto compose separato (SMOKE_PROJECT, default gil-smoke): volumi e rete propri,
#     `down -v` tocca solo quelli;
#   - env file temporaneo con segreti casuali (mai il .env del repo);
#   - porte host dedicate (SMOKE_BACKOFFICE_PORT/SMOKE_FRONTOFFICE_PORT);
#   - mai docker-compose.override.yml (builda dal sorgente): file -f espliciti.
# I container_name fissi del compose (gil-*) vanno rinominati nell'override, altrimenti
# collidono con lo stack locale: vedi il blocco generato nel job smoke.
#
# Uso locale:
#   SMOKE_FILE=/percorso/docker-compose.smoke.yml scripts/smoke-stack.sh
set -euo pipefail

cd "$(dirname "$0")/.."

SMOKE_FILE=${SMOKE_FILE:-docker-compose.smoke.yml}
PROJECT=${SMOKE_PROJECT:-gil-smoke}
BO_PORT=${SMOKE_BACKOFFICE_PORT:-18443}
FO_PORT=${SMOKE_FRONTOFFICE_PORT:-18444}
TIMEOUT=${SMOKE_TIMEOUT:-300}

[ -f "$SMOKE_FILE" ] || { echo "::error::override $SMOKE_FILE assente"; exit 1; }

ENV_FILE=$(mktemp)
# Stesse variabili del .env di ci.yml piu' quelle obbligatorie del compose; valori casuali
# (APP_ENCRYPTION_KEY esattamente 32 caratteri, come richiesto da Crypto::getKey()).
DB_ROOT_PASSWORD=$(openssl rand -hex 16)
DB_NAME=govpay
{
  echo "DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD"
  echo "DB_NAME=$DB_NAME"
  echo "DB_USER=govpay"
  echo "DB_PASSWORD=$(openssl rand -hex 16)"
  echo "BACKOFFICE_DB_USER=govpay"
  echo "BACKOFFICE_DB_PASSWORD=$(openssl rand -hex 16)"
  echo "FRONTOFFICE_DB_USER=govpay_cittadini"
  echo "FRONTOFFICE_DB_PASSWORD=$(openssl rand -hex 16)"
  echo "APP_ENCRYPTION_KEY=$(openssl rand -hex 16)"
  echo "MASTER_TOKEN=$(openssl rand -hex 24)"
  echo "FRONTOFFICE_LINK_SIGNING_KEY=$(openssl rand -hex 32)"
  echo "SSL=off"
  echo "BACKOFFICE_HTTP_PORT=$BO_PORT"
  echo "FRONTOFFICE_HTTP_PORT=$FO_PORT"
  echo "TRUSTED_PROXIES="
  echo "SENTRY_DSN="
  echo "SENTRY_ENVIRONMENT=smoke"
} > "$ENV_FILE"

COMPOSE=(docker compose -p "$PROJECT" --env-file "$ENV_FILE" -f docker-compose.yml -f "$SMOKE_FILE")

cleanup() {
  local rc=$?
  if [ "$rc" -ne 0 ]; then
    "${COMPOSE[@]}" ps -a || true
    "${COMPOSE[@]}" logs --no-color --tail 100 || true
  fi
  "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  rm -f "$ENV_FILE"
  exit "$rc"
}
trap cleanup EXIT

fail() { echo "::error::$*"; exit 1; }
ok() { echo "OK  $*"; }

"${COMPOSE[@]}" up -d --quiet-pull || fail "avvio stack fallito (immagini o compose)"

# Backoffice healthy (dipende da db healthy: copre anche l'init MariaDB).
bo_id=$("${COMPOSE[@]}" ps -q backoffice)
[ -n "$bo_id" ] || fail "container backoffice non creato"
status=
for ((i = 0; i < TIMEOUT; i += 5)); do
  status=$(docker inspect -f '{{.State.Health.Status}}' "$bo_id" 2>/dev/null || echo missing)
  [ "$status" = healthy ] && break
  [ "$status" = unhealthy ] && break
  sleep 5
done
[ "$status" = healthy ] || fail "backoffice non healthy entro ${TIMEOUT}s (stato: $status)"
ok "db e backoffice healthy"

code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$BO_PORT/health" || true)
[ "$code" = 200 ] || fail "backoffice /health: HTTP $code"
ok "backoffice /health 200"

# Frontoffice senza healthcheck: attesa breve dopo il backoffice.
code=
for ((i = 0; i < 60; i += 3)); do
  code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$FO_PORT/" || true)
  [ "$code" = 200 ] && break
  sleep 3
done
[ "$code" = 200 ] || fail "frontoffice /: HTTP $code"
ok "frontoffice / 200"

"${COMPOSE[@]}" exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME" \
  -e 'SELECT COUNT(*) FROM settings' >/dev/null || fail "query su settings fallita"
ok "schema DB presente (settings)"

if "${COMPOSE[@]}" logs --no-color | grep -E 'Fatal|Parse error'; then
  fail "errori PHP fatali nei log"
fi
ok "nessun Fatal/Parse error nei log"
