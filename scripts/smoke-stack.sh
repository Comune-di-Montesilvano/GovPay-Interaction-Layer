#!/usr/bin/env bash
# scripts/smoke-stack.sh — smoke test dello stack di produzione (docker-compose.yml) con le
# immagini fissate per digest da un file override (docker-compose.smoke.yml, generato dal job
# smoke di docker-publish.yml, mai committato). Avvia lo stack, controlla:
#   - backoffice healthy e /health 200;
#   - frontoffice 200 su /;
#   - query su `settings` (schema init del DB applicato);
#   - worker (servizio `worker`, stessa immagine del backoffice) healthy, nessun riavvio,
#     www-data senza capability ne' porte; immagine nuova: riga di avvio + marker
#     `__import__` in gil_jobs; immagine vecchia (senza bin/worker): attesa inerte;
#   - nessun `Fatal`/`Parse error` nei log;
# con porte privilegiate riservate a root+NET_BIND_SERVICE come sull'host di produzione.
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
# SMOKE_WORKER: `new` (default, immagini appena buildate: bin/worker obbligatorio), `legacy`
# (immagine senza bin/worker) o `auto` (decide dall'immagine: compose-compat con le release).
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
WORKER_MODE=${SMOKE_WORKER:-new}
case "$WORKER_MODE" in new | legacy | auto) ;; *) echo "::error::SMOKE_WORKER=$WORKER_MODE (new|legacy|auto)"; exit 1 ;; esac

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

# Porte privilegiate come in produzione: Docker >=20.10 mette ip_unprivileged_port_start=0 nel
# netns del container e cosi' nasconde un NET_BIND_SERVICE mancante (Apache bind :80 negato in
# prod, incidente 2026-10-28). Qui si rimette il default del kernel (1024).
PORTS_FILE=$(mktemp --suffix=.yml)
cat > "$PORTS_FILE" <<'EOF'
services:
  backoffice:
    sysctls:
      net.ipv4.ip_unprivileged_port_start: 1024
  frontoffice:
    sysctls:
      net.ipv4.ip_unprivileged_port_start: 1024
EOF

COMPOSE=(docker compose -p "$PROJECT" --env-file "$ENV_FILE" -f docker-compose.yml -f "$SMOKE_FILE" -f "$PORTS_FILE")

cleanup() {
  local rc=$?
  if [ "$rc" -ne 0 ]; then
    "${COMPOSE[@]}" ps -a || true
    "${COMPOSE[@]}" logs --no-color --tail 100 || true
  fi
  "${COMPOSE[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
  rm -f "$ENV_FILE" "$PORTS_FILE"
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

# Worker: avviato da `up -d` dopo backoffice healthy (depends_on).
wk_id=$("${COMPOSE[@]}" ps -q worker)
[ -n "$wk_id" ] || fail "container worker non creato"
# bin/worker cercato nell'immagine (container usa-e-getta), non nel container del worker: un
# worker in crash loop non risponde a exec. Path dentro `sh -c`: git-bash (Windows) riscrive gli
# argomenti che iniziano con / e test -f fallirebbe in silenzio.
wk_image=$(docker inspect -f '{{.Config.Image}}' "$wk_id")
# Esito di docker run distinto da quello di test: 0 = file presente, 1 = assente (test -f),
# altro (125/126/127, immagine non avviabile) = errore docker, non "senza bin/worker".
rc=0
docker run --rm --network none --entrypoint sh "$wk_image" -c 'test -f /var/www/html/bin/worker' || rc=$?
case "$rc" in
  0) has_bin=1 ;;
  1) has_bin=0 ;;
  *) fail "docker run su $wk_image fallito (exit $rc): impossibile verificare bin/worker" ;;
esac
if [ "$WORKER_MODE" = auto ]; then
  if [ "$has_bin" = 1 ]; then WORKER_MODE=new; else WORKER_MODE=legacy; fi
fi
if [ "$WORKER_MODE" = new ] && [ "$has_bin" = 0 ]; then fail "immagine backoffice senza bin/worker"; fi
if [ "$WORKER_MODE" = legacy ] && [ "$has_bin" = 1 ]; then fail "immagine con bin/worker, atteso legacy"; fi
echo "worker: modalita' $WORKER_MODE"

# Healthcheck (PID 1 = worker o attesa immagini vecchie, interval 30s): healthy = vivo da almeno
# un intervallo. Un crash loop (exit + restart unless-stopped) non arriva mai a healthy.
status=
for ((i = 0; i < 120; i += 5)); do
  status=$(docker inspect -f '{{.State.Health.Status}}' "$wk_id" 2>/dev/null || echo missing)
  [ "$status" = healthy ] && break
  [ "$status" = unhealthy ] && break
  sleep 5
done
[ "$status" = healthy ] || fail "worker non healthy entro 120s (stato: $status)"
ok "worker healthy"

# Hardening come da compose (C5): www-data, cap_drop ALL senza cap_add, nessuna porta.
user=$(docker inspect -f '{{.Config.User}}' "$wk_id")
[ "$user" = www-data ] || fail "worker user=$user, atteso www-data"
uid=$("${COMPOSE[@]}" exec -T worker sh -c "awk '/^Uid:/ {print \$2}' /proc/1/status")
[ "$uid" = 33 ] || fail "worker PID 1 uid=$uid, atteso 33 (www-data)"
caps=$(docker inspect -f '{{json .HostConfig.CapAdd}}|{{json .HostConfig.CapDrop}}|{{json .HostConfig.PortBindings}}' "$wk_id")
[[ "$caps" =~ ^(null|\[\])\|\[\"ALL\"\]\|(null|\{\})$ ]] || fail "worker capability/porte inattese: $caps"
ok "worker www-data, nessuna capability, nessuna porta"

if [ "$WORKER_MODE" = new ]; then
  # Riga di avvio, poi attesa DB + import .autostart (volume cache vuoto: 0 lavori, solo marker).
  found=
  for ((i = 0; i < 60; i += 3)); do
    n=$("${COMPOSE[@]}" exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME" -N \
      -e "SELECT COUNT(*) FROM gil_jobs WHERE name='__import__' AND autostart_imported=1" 2>/dev/null || true)
    [ "$n" = 1 ] && { found=1; break; }
    sleep 3
  done
  # Log letti in una variabile: `grep -q` in pipe con pipefail puo' fallire per SIGPIPE.
  wlog=$("${COMPOSE[@]}" logs --no-color worker)
  grep -q '\[worker\] avvio worker (' <<<"$wlog" || fail "riga di avvio del worker assente nei log"
  [ -n "$found" ] || fail "marker __import__ assente in gil_jobs (worker non ha raggiunto il DB?)"
  if grep -E '\[worker\] ERRORE' <<<"$wlog"; then fail "errori del worker nei log"; fi
  ok "worker avviato: riga di avvio e marker __import__ in gil_jobs"
else
  wlog=$("${COMPOSE[@]}" logs --no-color worker)
  grep -q 'immagine senza bin/worker, nessuna azione' <<<"$wlog" || fail "riga di attesa (immagine vecchia) assente"
  ok "worker inerte con immagine senza bin/worker"
fi

# Ultimo controllo dopo tutti gli altri: nessun riavvio dall'avvio (crash loop).
restarts=$(docker inspect -f '{{.RestartCount}}' "$wk_id")
running=$(docker inspect -f '{{.State.Running}}' "$wk_id")
[ "$restarts" = 0 ] && [ "$running" = true ] || fail "worker riavviato ($restarts) o fermo (running=$running)"
ok "worker in esecuzione, 0 riavvii"

if "${COMPOSE[@]}" logs --no-color | grep -E 'Fatal|Parse error'; then
  fail "errori PHP fatali nei log"
fi
ok "nessun Fatal/Parse error nei log"
