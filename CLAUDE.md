# CLAUDE.md

Guida per Claude Code (claude.ai/code) su questo repository.

# GovPay Interaction Layer (GIL)

Piattaforma containerizzata per pagamenti pagoPA, sviluppata per Comune di Montesilvano. Integra GovPay, pagoPA Checkout, App IO, SPID/CIE.

## Architettura

Multi-container Docker. Container principali:

| Container | Service | Stack | Scopo |
|---|---|---|---|
| `gil-backoffice` | `backoffice` | PHP 8.5 + Slim 4 + Apache | Interfaccia operatori: pendenze, rendicontazione, ricevute |
| `gil-frontoffice` | `frontoffice` | PHP 8.5 + Slim 4 + Apache | Portale cittadino: visualizza e paga pendenze |
| `gil-db` | `db` | MariaDB 10.x | DB condiviso, utenti separati backoffice (RW) / frontoffice (RO) |
| `gil-auth-proxy` | `auth-proxy` | Python SATOSA | Proxy SPID/CIE: legge config da backoffice, gestisce SATOSA |
| `gil-auth-proxy-nginx` | `auth-proxy-nginx` | Nginx | Reverse proxy per SATOSA, serve metadata e disco SPID |
| `gil-auth-proxy-db` | `auth-proxy-db` | MongoDB 7 | Backend CIE OIDC (usato da SATOSA) |
| `gil-metadata-builder` | `metadata-builder` | Bash + OpenSSL | Generazione certificati e metadata SPID/CIE (setup iniziale) |

Backoffice → master via Bearer token (`MASTER_TOKEN`). Segreti sensibili cifrati in DB con `APP_ENCRYPTION_KEY` (32 caratteri).

## Comandi principali

```bash
# Avvio sviluppo locale
cp .env.example .env   # configura solo le variabili di bootstrap
docker compose up -d --build

# Produzione (immagini pre-built da GHCR)
docker compose pull && docker compose up -d

# Tutti i servizi (backoffice, frontoffice, auth-proxy, db, metadata-builder)
# partono sempre. SPID/CIE si abilita via Backoffice → Impostazioni → Login Proxy.

# Esegui test PHP
docker compose -f docker-compose.yml -f docker-compose.ci.yml up --build --abort-on-container-exit

# Cron batch pendenze massive
docker exec gil-backoffice php /var/www/html/scripts/cron_pendenze_massive.php
```

## Struttura directory

```
app/            Librerie PHP condivise (Config, Database, Security, Services)
backoffice/     Applicazione backoffice (src/, templates/, public/)
frontoffice/    Applicazione frontoffice (locales/, templates/, public/)
master/         Servizio Python master (routers/, services/, auth.py)
iam-proxy/      Proxy SATOSA per SPID/CIE
docker/db/      Dockerfile MariaDB + schema iniziale
migrations/     Migrazioni SQL
scripts/        Script batch/cron PHP
govpay-clients/ Client API GovPay generati
pagopa-clients/ Client API pagoPA generati
ssl/            Certificati TLS server
certificate/    Certificati mTLS client GovPay
metadata/       Metadata SPID/CIE
```

## Configurazione

### Bootstrap (`.env`)

Solo variabili necessarie all'avvio. Template: `.env.example`.

Obbligatorie prima del primo avvio:

```bash
DB_ROOT_PASSWORD, BACKOFFICE_DB_PASSWORD, FRONTOFFICE_DB_PASSWORD
APP_ENCRYPTION_KEY   # esattamente 32 caratteri
MASTER_TOKEN         # token Bearer interno

openssl rand -hex 24   # MASTER_TOKEN
openssl rand -hex 16   # APP_ENCRYPTION_KEY
```

### Configurazione applicativa (DB → UI)

Tutto il resto (GovPay, pagoPA, SPID/CIE, entità, App IO, mail, branding) si configura via **Backoffice → Impostazioni**, salvato in tabella `settings`. Nessuna variabile `.env` aggiuntiva.

Accesso in codice: `Config::get('ENV_KEY')` — priorità: DB (`SettingsRepository`) → `config.json` → default.

## CI/CD

**GitHub Actions** (`.github/workflows/`):

- **`ci.yml`** — push/PR su `main`/`dev`: installa PHP 8.5, avvia stack Docker, esegue PHPUnit
- **`docker-publish.yml`** — tag `vX.Y.Z` o push su `dev`: builda e pubblica 7 immagini su `ghcr.io/comune-di-montesilvano/`

Tag immagini: `:vX.Y.Z`, `:X.Y`, `:latest`. `GIL_IMAGE_TAG` nel compose seleziona versione.

## Convenzioni di sviluppo

- **Branch principale**: `main` (production-ready); sviluppo attivo su `dev`
- **PHP**: PSR-4 autoloading via Composer; namespace `App\` per librerie condivise
- **Routing**: Slim 4 con middleware per autenticazione e CSRF
- **Template**: Twig 3 con estensioni custom; i18n via file JSON in `locales/`
- **SSL**: `SSL=on` attiva HTTPS diretto su Apache; `SSL=off` per deploy dietro reverse proxy (es. Portainer + Traefik). Usa `SSL_HEADER` per X-Forwarded-Proto in modalità proxy.
- **Autenticazione operatori**: sessione PHP + token GovPay; `sslheader` come metodo auth alternativo
- **Debug**: variabile `APP_DEBUG` nel `.env`; toggle disponibile nell'UI backoffice

## Integrazioni esterne

| Servizio | Uso |
|---|---|
| GovPay | Core pagamenti, rendicontazione, ricevute |
| pagoPA Checkout | Gateway pagamento online |
| pagoPA Biz Events | Recupero ricevute |
| pagoPA GPD | Gestione posizioni debitorie |
| App IO | Notifiche e pagamenti cittadini |
| SPID / CIE | Autenticazione federata cittadini |

## Configurazione: DB vs .env

`App\Config\Config::get('ENV_KEY')` punto unico di lettura. Priorità:
1. Tabella `settings` in DB (sezioni: `entity`, `backoffice`, `frontoffice`, `govpay`, `pagopa`, `iam_proxy`, `ui`) — valori sensibili cifrati con `APP_ENCRYPTION_KEY` via `App\Security\Crypto`
2. `config.json` (bootstrap keys, letto da `ConfigLoader`)
3. Default come secondo argomento

~60 variabili ex-.env ora in DB. Obbligatorie in `.env` solo variabili bootstrap (credenziali DB, `MASTER_TOKEN`, `APP_ENCRYPTION_KEY`).

## Autoloading e namespace PHP

Namespace `App\` mappato su **due** source roots:
- `app/` — librerie condivise (Config, Database, Security, Services, Logger)
- `backoffice/src/` — controller, middleware, auth backoffice

Frontoffice non usa Composer/autoload proprio: carica via `require` le classi condivise da `app/`.

## Flusso request backoffice

```
index.php → bootstrap/app.php → Slim App
  Middleware stack (LIFO order):
    ErrorMiddleware (aggiunta per ultima, eseguita per prima)
    CurrentPathMiddleware (popola current_user da sessione)
    SessionMiddleware → FlashMiddleware → SetupMiddleware → AuthMiddleware
  → Route → Controller → GovPay/pagoPA client (via vendor/)
```

`/api/*` pubblico (autenticazione Bearer `MASTER_TOKEN`) per chiamate interne da `master` e `iam-proxy`.

## Master service (FastAPI)

`master/` espone API interne consumate dal backoffice via `App\Services\PortainerClient`. Routers principali: `backup`, `config`, `containers`, `health`, `iam_proxy`. Autenticazione via `MASTER_TOKEN` in `auth.py`.

## Migrazioni DB

File SQL in `migrations/` (numerati es. `003_...sql`). Nessun runner automatico — migrazioni applicate manualmente o via `docker/db-init/` al primo avvio del container MariaDB.

## Test

Nessun `phpunit.xml` nella root — test esistenti nei client generati (`govpay-clients/`, `pagopa-clients/`). Per aggiungere test applicativi: creare `phpunit.xml` nella root, target su `tests/`.

```bash
# Esegui test su singolo client generato
cd govpay-clients/generated-clients/pendenze-v2/pendenze-client && vendor/bin/phpunit
```

## Generazione API client

Client PHP in `govpay-clients/` e `pagopa-clients/` sono **generati** (OpenAPI Generator). Non modificare a mano — rigenera dalla spec OpenAPI se necessario. Referenziati come `path` repository in `composer.json`.

## Convenzioni comunicazione e commit

Sessione usa **caveman mode** (plugin `caveman`). Regole attive:

- Risposte brevi, frammenti OK, no articoli/filler
- **Commit: usa sempre `/caveman:caveman-commit` per generare il messaggio, poi esegui `git commit`**
- Conventional Commits (`feat/fix/refactor/...`), imperativo, ≤72 char subject, body solo se non ovvio dal diff
- No "Generated with Claude Code", no emoji nei commit salvo convenzione progetto
- `/caveman:compress <file>` per comprimere file `.md` di memoria/note

Livelli: `lite` | `full` (default) | `ultra`. Cambia con `/caveman lite|full|ultra`. Disattiva con `stop caveman` / `normal mode`.