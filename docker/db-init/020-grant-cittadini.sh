#!/bin/bash
set -euo pipefail

# Ensures the readonly citizens user exists with SELECT grants on the main schema.
if [ -z "${MYSQL_ROOT_PASSWORD:-}" ]; then
  echo "[grant-cittadini] MYSQL_ROOT_PASSWORD non impostata: salto grant." >&2
  exit 0
fi

DB_NAME="${MYSQL_DATABASE:-govpay}"
CITTADINI_USER="${DB_USER_CITTADINI:-}"
CITTADINI_PASS="${DB_PASSWORD_CITTADINI:-}"

if [ -z "$CITTADINI_USER" ] || [ -z "$CITTADINI_PASS" ]; then
  echo "[grant-cittadini] Variabili DB_USER_CITTADINI / DB_PASSWORD_CITTADINI mancanti: salto." >&2
  exit 0
fi

ESCAPED_PASS="${CITTADINI_PASS//\'/''}"

MYSQL_CLIENT=""
if command -v mariadb >/dev/null 2>&1; then
  MYSQL_CLIENT="$(command -v mariadb)"
elif command -v mysql >/dev/null 2>&1; then
  MYSQL_CLIENT="$(command -v mysql)"
else
  echo "[grant-cittadini] Client mysql/mariadb non trovato: salto." >&2
  exit 0
fi

echo "[grant-cittadini] Applico permessi SELECT per ${CITTADINI_USER} su ${DB_NAME} usando ${MYSQL_CLIENT}..."
$MYSQL_CLIENT -uroot -p"$MYSQL_ROOT_PASSWORD" <<-EOSQL
  CREATE USER IF NOT EXISTS '${CITTADINI_USER}'@'%' IDENTIFIED BY '${ESCAPED_PASS}';
  ALTER USER '${CITTADINI_USER}'@'%' IDENTIFIED BY '${ESCAPED_PASS}';
  GRANT SELECT ON \`${DB_NAME}\`.* TO '${CITTADINI_USER}'@'%';
  -- Al primo avvio (datadir vuota) l'app non ha ancora eseguito le migrazioni:
  -- garantiamo qui l'esistenza della tabella (stessa DDL di migrations/010_rate_limit_buckets.sql,
  -- idempotente: la migration successiva la trova già presente e non fa nulla) prima del
  -- GRANT dedicato sotto, altrimenti la GRANT su tabella inesistente fallisce, lo script
  -- esce (set -e) e il container muore: al riavvio (restart:always) l'init NON viene
  -- rieseguito (datadir non più vuota), quindi il GRANT su rate_limit_buckets resterebbe
  -- mancante per sempre.
  CREATE TABLE IF NOT EXISTS \`${DB_NAME}\`.\`rate_limit_buckets\` (
    bucket_key   VARCHAR(190) NOT NULL PRIMARY KEY,
    window_start INT UNSIGNED NOT NULL,
    count        INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rlb_window (window_start)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  -- Eccezione: tabella di rate limit (sliding window) richiede INSERT/UPDATE/DELETE
  -- per consentire al frontoffice di registrare e ripulire i bucket lato cittadino.
  GRANT SELECT, INSERT, UPDATE, DELETE ON \`${DB_NAME}\`.\`rate_limit_buckets\` TO '${CITTADINI_USER}'@'%';
  FLUSH PRIVILEGES;
EOSQL

echo "[grant-cittadini] Permessi aggiornati."
