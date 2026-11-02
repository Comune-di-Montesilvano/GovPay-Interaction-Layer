#!/bin/bash
set -euo pipefail

# Directory applicativa effettiva nel runtime
APP_SUITE="${APP_SUITE:-backoffice}"
APP_DIR="/var/www/html"
if [ "$APP_SUITE" = "frontoffice" ]; then
  APP_DIR="/var/www/html/frontoffice"
fi
cd "$APP_DIR"

# Composer gira solo a build time (stage vendor_builder del Dockerfile): a runtime nessun
# download. Backoffice e frontoffice richiedono entrambi /var/www/html/vendor dall'immagine.
if [ ! -f /var/www/html/vendor/autoload.php ]; then
  echo "❌ vendor/ assente nell'immagine: ricostruire l'immagine (composer gira solo a build time)" >&2
  exit 1
fi

# Rileva openssl per eventuale generazione certificati
if command -v openssl >/dev/null 2>&1; then
  OPENSSL_BIN="$(command -v openssl)"
else
  OPENSSL_BIN=""
  echo "⚠️  openssl non trovato: salterò la generazione certificati self-signed." >&2
fi

# === Controllo file TLS forniti via env (GOVPAY) o fallback su directory certificate ===
# Se l'utente ha impostato le variabili GOVPAY_TLS_CERT e GOVPAY_TLS_KEY nel file .env,
# verifichiamo che siano entrambe presenti e che i file esistano nel container.
if [ -n "${GOVPAY_TLS_CERT:-}" ] || [ -n "${GOVPAY_TLS_KEY:-}" ]; then
  # Entrambe devono essere valorizzate
  if [ -z "${GOVPAY_TLS_CERT:-}" ] || [ -z "${GOVPAY_TLS_KEY:-}" ]; then
    echo "❌ Errore: hanno valore solo una delle variabili GOVPAY_TLS_CERT / GOVPAY_TLS_KEY. Entrambe devono essere impostate insieme." >&2
    echo "Valori correnti: GOVPAY_TLS_CERT='${GOVPAY_TLS_CERT:-}' GOVPAY_TLS_KEY='${GOVPAY_TLS_KEY:-}'" >&2
    exit 1
  fi

  # Controlla che i file esistano
  if [ ! -f "${GOVPAY_TLS_CERT}" ] || [ ! -f "${GOVPAY_TLS_KEY}" ]; then
    echo "⚠️ Avviso: uno o entrambi i file TLS specificati non esistono nel container:" >&2
    [ ! -f "${GOVPAY_TLS_CERT}" ] && echo "  - Cert mancante: ${GOVPAY_TLS_CERT}" >&2 || true
    [ ! -f "${GOVPAY_TLS_KEY}" ] && echo "  - Key mancante: ${GOVPAY_TLS_KEY}" >&2 || true
    # Proviamo il fallback in /var/www/certificate
    FB_CERT="/var/www/certificate/certificate.cer"
    FB_KEY="/var/www/certificate/private_key.key"
    if [ -f "${FB_CERT}" ] && [ -f "${FB_KEY}" ]; then
      echo "✅ Trovati certificati fallback in ${FB_CERT} e ${FB_KEY}; li userò al posto di quelli configurati." >&2
      GOVPAY_TLS_CERT="${FB_CERT}"
      GOVPAY_TLS_KEY="${FB_KEY}"
    else
      echo "⚠️ Nessun certificato GovPay disponibile; proseguo comunque (alcune funzionalità GovPay potrebbero non funzionare)." >&2
      unset GOVPAY_TLS_CERT GOVPAY_TLS_KEY
    fi
  else
    echo "✅ Trovati file TLS per GovPay forniti via env: ${GOVPAY_TLS_CERT} , ${GOVPAY_TLS_KEY}"
  fi
else
  # Se non sono state fornite variabili env, verifichiamo la presenza di eventuali certificati
  # nella cartella /var/www/certificate/ (copia della cartella `certificate/` del progetto)
  CERT_FALLBACK="/var/www/certificate/certificate.cer"
  KEY_FALLBACK="/var/www/certificate/private_key.key"
  if [ -f "${CERT_FALLBACK}" ] && [ -f "${KEY_FALLBACK}" ]; then
  echo "✅ Trovati certificati GovPay in /var/www/certificate/. Uso: ${CERT_FALLBACK}, ${KEY_FALLBACK}"
  GOVPAY_TLS_CERT="${CERT_FALLBACK}"
  GOVPAY_TLS_KEY="${KEY_FALLBACK}"
  fi
fi

# === SSL (SSL=on): certificati /ssl/server.{crt,key} se forniti e leggibili, altrimenti
# self-signed in /tmp (tmpfs: il filesystem del container e' read-only e il processo e'
# www-data, /ssl non e' scrivibile). Apache li legge da GIL_SSL_CERT/GIL_SSL_KEY.
SSL_ON="${SSL:-on}"
GIL_SSL_CERT=/ssl/server.crt
GIL_SSL_KEY=/ssl/server.key
if [ "$SSL_ON" = "on" ] && { [ ! -s "$GIL_SSL_CERT" ] || [ ! -r "$GIL_SSL_CERT" ] || [ ! -s "$GIL_SSL_KEY" ] || [ ! -r "$GIL_SSL_KEY" ]; }; then
  if [ -e "$GIL_SSL_KEY" ] && [ ! -r "$GIL_SSL_KEY" ]; then
    echo "⚠️  /ssl/server.key non leggibile da $(id -un) (uid $(id -u)): rendere leggibile la chiave montata (es. chown 33 o chmod 640 gruppo www-data)" >&2
  fi
  if [ -n "${SKIP_SELF_SIGNED:-}" ]; then
    echo "❌ SSL=on, certificati /ssl assenti o non leggibili e SKIP_SELF_SIGNED impostato" >&2
    exit 1
  fi
  [ -n "$OPENSSL_BIN" ] || { echo "❌ SSL=on senza certificati e senza openssl" >&2; exit 1; }
  SSL_TMP=/tmp/gil-ssl
  mkdir -p "$SSL_TMP" && chmod 700 "$SSL_TMP"
  echo "⚙️  Certificati SSL mancanti o non leggibili: genero self-signed in $SSL_TMP ..."
  if ! "$OPENSSL_BIN" req -x509 -nodes -days 365 -newkey rsa:2048 \
      -keyout "$SSL_TMP/server.key" -out "$SSL_TMP/server.crt" -subj "/CN=localhost" >/dev/null 2>&1; then
    echo "❌ Errore: generazione certificati fallita" >&2
    exit 1
  fi
  GIL_SSL_CERT="$SSL_TMP/server.crt"
  GIL_SSL_KEY="$SSL_TMP/server.key"
  echo "✅ Certificati self-signed creati."
fi
export GIL_SSL_CERT GIL_SSL_KEY

# Frontoffice: la pubblicazione di frontoffice/public in /var/www/html/public avviene a build
# time (Dockerfile, stage runtime-frontoffice). Nessun chown/chmod qui: il processo e' www-data e
# senza capability. Volumi creati da root (es. `docker cp`, bind mount, restore come root) vanno
# sistemati a mano: `chown -R 33:33 <path>`. Qui solo un avviso se una radice non e' scrivibile.
if [ "$APP_SUITE" != "frontoffice" ]; then
  for d in /var/www/cache /var/www/certificate /var/www/html/public/img /backups; do
    if [ ! -w "$d" ]; then
      echo "⚠️  $d non scrivibile da $(id -un) (chown -R 33:33 sul volume)" >&2
    fi
  done
fi

echo "--- Setup completato. Eseguo controllo first-run DB ---"

if [ "$APP_SUITE" != "frontoffice" ]; then
  # First-run marker
  FIRST_RUN_MARKER="/var/www/cache/.first_run_done"
  if [ -f "$FIRST_RUN_MARKER" ]; then
    echo "ℹ️  First-run DB già eseguito (marker presente)."
  else
    echo "⚙️  Primo avvio: creo tabelle richieste via PHP (bin/first_run_create_tables.php)"
    if command -v php >/dev/null 2>&1; then
      if [ -f "/var/www/html/bin/first_run_create_tables.php" ]; then
        # Wait for DB to be reachable with a short timeout
        DB_HOST=${DB_HOST:-db}
        DB_PORT=${DB_PORT:-3306}
        WAIT_DB_TIMEOUT=${DB_WAIT_TIMEOUT:-30}
        echo "Attendo DB ${DB_HOST}:${DB_PORT} per fino a ${WAIT_DB_TIMEOUT}s..."
        i=0
        until php -r "try { new PDO('mysql:host=${DB_HOST};port=${DB_PORT};', '${DB_USER:-govpay}', '${DB_PASSWORD:-}', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>2]); exit(0);} catch (Throwable \$e) { exit(1);}" >/dev/null 2>&1; do
          i=$((i+1))
          if [ "$i" -ge "$WAIT_DB_TIMEOUT" ]; then
            echo "⚠️ DB non raggiungibile dopo ${WAIT_DB_TIMEOUT}s: salto creazione tabelle in questo avvio." >&2
            break
          fi
          sleep 1
        done

        if [ "$i" -lt "$WAIT_DB_TIMEOUT" ]; then
          echo "Eseguo: php bin/first_run_create_tables.php"
          if ! php /var/www/html/bin/first_run_create_tables.php; then
            echo "⚠️ Errore: creazione tabelle fallita. Continuerò senza bloccare il container." >&2
          else
            touch "$FIRST_RUN_MARKER" || echo "⚠️  Impossibile creare marker $FIRST_RUN_MARKER" >&2
          fi
        fi
      else
        echo "ℹ️  Nessun script di creazione tabelle (bin/first_run_create_tables.php) trovato; salto." >&2
      fi
    else
      echo "⚠️  PHP CLI non disponibile: non posso creare tabelle (bin/first_run_create_tables.php mancante)." >&2
      # Non interrompiamo qui: proviamo le migrazioni SQL più sotto
    fi
  fi
fi

if [ "$APP_SUITE" != "frontoffice" ]; then
  # Additional SQL migrations runner: execute SQL files in migrations/ using mysql client if available
  MIG_DIR="/var/www/html/migrations"
  if [ -d "$MIG_DIR" ] && [ "$(ls -A "$MIG_DIR" | wc -l)" -gt 0 ]; then
    echo "--- Trovate migrazioni SQL in $MIG_DIR ---"
    DB_HOST=${DB_HOST:-db}
    DB_PORT=${DB_PORT:-3306}
    DB_NAME=${DB_NAME:-govpay}
    DB_USER=${DB_USER:-govpay}
    DB_PASS=${DB_PASSWORD:-}

    # Preferiamo usare il client mysql se disponibile
    if command -v mysql >/dev/null 2>&1; then
      echo "Eseguo migrazioni via client mysql..."
      for f in $(ls -1 "$MIG_DIR"/*.sql 2>/dev/null | sort); do
        echo "Eseguo $f"
        if ! mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" ${DB_PASS:+-p$DB_PASS} "$DB_NAME" < "$f"; then
          echo "⚠️  Fallito l'import di $f via mysql client; proseguo." >&2
        fi
      done

    # Fallback: PHP CLI può eseguire gli SQL
    elif command -v php >/dev/null 2>&1; then
      echo "Eseguo migrazioni via fallback PHP..."
      php -r '
        $dir = getenv("PWD") . "/migrations";
        $files = glob($dir."/*.sql");
        if(!$files) { exit(0); }
        $host = getenv("DB_HOST") ?: "db";
        $port = getenv("DB_PORT") ?: "3306";
        $db = getenv("DB_NAME") ?: "govpay";
        $user = getenv("DB_USER") ?: "govpay";
        $pass = getenv("DB_PASSWORD") ?: "";
        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
        try {
          $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
          sort($files);
          foreach($files as $f) {
            echo "Eseguo PHP $f\n";
            try {
              $sql = file_get_contents($f);
              $pdo->exec($sql);
            } catch (Throwable $e) {
              echo "⚠️ Importazione fallita per $f via PHP: ". $e->getMessage() ."; proseguo.\n";
            }
          }
        } catch (Throwable $e) {
          echo "⚠️ Connessione DB fallita: ". $e->getMessage() ."\n";
          echo "⚠️ Il DB potrebbe non essere ancora pronto. Continuo avvio.\n";
          exit(0);
        }
      '
    else
      echo "⚠️  Nessun metodo disponibile per eseguire migrazioni SQL (mysql client e PHP mancanti)." >&2
    fi
  fi
fi

# I lavori periodici (ex demoni cron_*.php) girano nel container `worker`, non qui: nessun avvio
# da .autostart. I file daemon-<slug>.autostart restano nel volume cache e il worker li importa
# una sola volta nei flag gil_jobs.enabled.

echo "--- Avvio Apache. ---"

# Se nessun comando passato, avvia apache2-foreground di default
# Se è stato passato un comando custom (debug), eseguilo direttamente
if [ "$#" -gt 0 ]; then
  exec "$@"
fi

# Avvio standard Apache (sorgendo envvars per definire APACHE_RUN_DIR etc.)
if [ -f /etc/apache2/envvars ]; then
  # shellcheck disable=SC1091
  . /etc/apache2/envvars
fi

# Watchdog configurazione frontoffice: rileva cambi in /api/frontoffice/config
# e invia SIGUSR1 ad Apache (graceful reload) senza riavviare il container.
# Attivo solo nel container frontoffice se MASTER_TOKEN è configurato.
if [ "$APP_SUITE" = "frontoffice" ] && [ -n "${MASTER_TOKEN:-}" ]; then
  (
    if [ -n "${BACKOFFICE_INTERNAL_URL:-}" ]; then
      BACKOFFICE_URL="${BACKOFFICE_INTERNAL_URL}"
    elif [ "${SSL:-off}" = "on" ]; then
      BACKOFFICE_URL="https://backoffice:80"
    else
      BACKOFFICE_URL="http://backoffice"
    fi
    WATCHDOG_INTERVAL="${FRONTOFFICE_WATCHDOG_INTERVAL:-300}"
    LAST_HASH=""
    # Aspetta che backoffice sia pronto (max 60s)
    for i in $(seq 1 12); do
      if curl -sf -o /dev/null -H "Authorization: Bearer ${MASTER_TOKEN}" \
          "${BACKOFFICE_URL}/api/frontoffice/config" >/dev/null 2>&1; then
        break
      fi
      sleep 5
    done
    while true; do
      sleep "${WATCHDOG_INTERVAL}"
      CONFIG=$(curl -sf -H "Authorization: Bearer ${MASTER_TOKEN}" \
        "${BACKOFFICE_URL}/api/frontoffice/config" 2>/dev/null) || continue
      HASH=$(printf '%s' "${CONFIG}" | md5sum | cut -d' ' -f1)
      if [ "${HASH}" != "${LAST_HASH}" ]; then
        LAST_HASH="${HASH}"
        # Graceful reload Apache: ricarica config senza interrompere connessioni attive
        if [ -f /var/run/apache2/apache2.pid ]; then
          kill -USR1 "$(cat /var/run/apache2/apache2.pid)" 2>/dev/null || true
        fi
      fi
    done
  ) &
fi

if [ "$SSL_ON" = "on" ]; then
  exec apache2 -DFOREGROUND -D SSL_ON
else
  exec apache2 -DFOREGROUND
fi
