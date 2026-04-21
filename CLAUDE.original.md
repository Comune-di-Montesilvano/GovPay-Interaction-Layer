# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# GovPay Interaction Layer (GIL)

Piattaforma containerizzata per la gestione dei pagamenti pagoPA, sviluppata per il Comune di Montesilvano. Integra GovPay, pagoPA Checkout, App IO e SPID/CIE.

## Architettura

Applicazione multi-container Docker. I container principali sono:

| Container | Service | Stack | Scopo |
|---|---|---|---|
| `gil-backoffice` | `backoffice` | PHP 8.5 + Slim 4 + Apache | Interfaccia operatori: pendenze, rendicontazione, ricevute |
| `gil-frontoffice` | `frontoffice` | PHP 8.5 + Slim 4 + Apache | Portale cittadino per visualizzare e pagare pendenze |
| `gil-db` | `db` | MariaDB 10.x | Database condiviso con utenti separati per backoffice (RW) e frontoffice (RO) |
| `gil-auth-proxy` | `auth-proxy` | Python SATOSA | Proxy SPID/CIE: legge config da backoffice, gestisce SATOSA |
| `gil-auth-proxy-nginx` | `auth-proxy-nginx` | Nginx | Reverse proxy per SATOSA, serve metadata e disco SPID |
| `gil-auth-proxy-db` | `auth-proxy-db` | MongoDB 7 | Backend CIE OIDC (usato da SATOSA) |
| `gil-metadata-builder` | `metadata-builder` | Bash + OpenSSL | Generazione certificati e metadata SPID/CIE (setup iniziale) |

Le chiamate interne tra servizi usano Bearer token (`MASTER_TOKEN`). I segreti sensibili (chiavi App IO, ecc.) sono cifrati in DB con `APP_ENCRYPTION_KEY` (32 caratteri).

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

Contiene solo le variabili necessarie all'avvio dei container. Template: `.env.example`.

Obbligatorie prima del primo avvio:

```bash
DB_ROOT_PASSWORD, BACKOFFICE_DB_PASSWORD, FRONTOFFICE_DB_PASSWORD
APP_ENCRYPTION_KEY   # esattamente 32 caratteri
MASTER_TOKEN         # token Bearer interno

openssl rand -hex 24   # MASTER_TOKEN
openssl rand -hex 16   # APP_ENCRYPTION_KEY
```

### Configurazione applicativa (DB → UI)

Tutto il resto (GovPay, pagoPA, SPID/CIE, entità, App IO, mail, branding) si
configura via **Backoffice → Impostazioni** e viene salvato nella tabella `settings`.
Nessuna variabile `.env` aggiuntiva richiesta.

Accesso in codice: `Config::get('ENV_KEY')` — priorità: DB (`SettingsRepository`) → `config.json` → default.

## CI/CD

**GitHub Actions** (`.github/workflows/`):

- **`ci.yml`** — si attiva su push/PR a `main`/`dev`: installa PHP 8.5, avvia lo stack Docker, esegue PHPUnit
- **`docker-publish.yml`** — si attiva su tag `vX.Y.Z` o push a `dev`: builda e pubblica 7 immagini su `ghcr.io/comune-di-montesilvano/`

Tag immagini: `:vX.Y.Z`, `:X.Y`, `:latest`. La variabile `GIL_IMAGE_TAG` nel compose seleziona la versione.

## Convenzioni di sviluppo

- **Branch principale**: `main` (production-ready); sviluppo attivo su `dev`
- **PHP**: PSR-4 autoloading via Composer; namespace `App\` per le librerie condivise
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

`App\Config\Config::get('ENV_KEY')` è il punto unico di lettura. Priorità:
1. Tabella `settings` in DB (sezioni: `entity`, `backoffice`, `frontoffice`, `govpay`, `pagopa`, `iam_proxy`, `ui`) — valori sensibili cifrati con `APP_ENCRYPTION_KEY` via `App\Security\Crypto`
2. `config.json` (bootstrap keys, letto da `ConfigLoader`)
3. Default passato come secondo argomento

~60 variabili ex-.env ora vivono in DB. Le uniche variabili che restano obbligatorie in `.env` sono quelle di bootstrap (credenziali DB, `MASTER_TOKEN`, `APP_ENCRYPTION_KEY`).

## Autoloading e namespace PHP

Il namespace `App\` è mappato su **due** source roots:
- `app/` — librerie condivise (Config, Database, Security, Services, Logger)
- `backoffice/src/` — controller, middleware, auth backoffice

Il frontoffice non usa Composer/autoload proprio: carica direttamente via `require` le classi condivise da `app/`.

## Flusso request backoffice

```
index.php → bootstrap/app.php → Slim App
  Middleware stack (LIFO order):
    ErrorMiddleware (aggiunta per ultima, eseguita per prima)
    CurrentPathMiddleware (popola current_user da sessione)
    SessionMiddleware → FlashMiddleware → SetupMiddleware → AuthMiddleware
  → Route → Controller → GovPay/pagoPA client (via vendor/)
```

`/api/*` è pubblico (autenticazione Bearer `MASTER_TOKEN`) per chiamate interne da `iam-proxy` e `metadata-builder`.

## Migrazioni DB

File SQL in `migrations/` (numerati es. `003_...sql`). Non c'è runner automatico — le migrazioni vengono applicate manualmente o tramite `docker/db-init/` al primo avvio del container MariaDB.

## Test

Nessun `phpunit.xml` nel progetto root — i test esistenti sono nei client generati (`govpay-clients/`, `pagopa-clients/`). Per aggiungere test applicativi, creare `phpunit.xml` nella root e target su `tests/`.

```bash
# Esegui test su singolo client generato
cd govpay-clients/generated-clients/pendenze-v2/pendenze-client && vendor/bin/phpunit
```

## Generazione API client

I client PHP in `govpay-clients/` e `pagopa-clients/` sono **generati** (OpenAPI Generator). Non modificarli a mano — rigenera dalla spec OpenAPI se necessario. Sono referenziati come `path` repository in `composer.json`.
