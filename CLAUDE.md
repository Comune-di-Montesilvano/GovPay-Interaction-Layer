# GovPay Interaction Layer (GIL)

Piattaforma containerizzata per la gestione dei pagamenti pagoPA, sviluppata per il Comune di Montesilvano. Integra GovPay, pagoPA Checkout, App IO e SPID/CIE.

## Architettura

Applicazione multi-container Docker. I container principali sono:

| Container | Stack | Scopo |
|---|---|---|
| `backoffice` | PHP 8.5 + Slim 4 + Apache | Interfaccia operatori: pendenze, rendicontazione, ricevute |
| `frontoffice` | PHP 8.5 + Slim 4 + Apache | Portale cittadino per visualizzare e pagare pendenze |
| `master` | Python (FastAPI) | Orchestrazione, configurazione, backup, comunicazione interna |
| `db` | MariaDB 10.x | Database condiviso con utenti separati per backoffice (RW) e frontoffice (RO) |
| `satosa` / `iam-proxy-nginx` | Python SATOSA + Nginx | Proxy SPID/CIE (profilo opzionale `iam-proxy`) |

La comunicazione backoffice → master avviene via Bearer token (`MASTER_TOKEN`). I segreti sensibili (chiavi App IO, ecc.) sono cifrati in DB con `APP_ENCRYPTION_KEY` (32 caratteri).

## Comandi principali

```bash
# Avvio sviluppo locale
cp .env.example .env   # personalizza le variabili
docker compose up -d --build

# Produzione (immagini pre-built da GHCR)
docker compose pull && docker compose up -d

# Con IAM Proxy SPID/CIE abilitato
COMPOSE_PROFILES=iam-proxy docker compose up -d

# Esegui test PHP
docker compose -f docker-compose.yml -f docker-compose.ci.yml up --build --abort-on-container-exit

# Cron batch pendenze massive
docker exec govpay-interaction-backoffice php /var/www/html/scripts/cron_pendenze_massive.php
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

- **`.env`** — variabili di deploy (non versionato). Template: `.env.example`
- **`.iam-proxy.env`** — configurazione SATOSA/SPID (opzionale). Template: `.iam-proxy.env.example`
- **`docker-compose.yml`** — definizione servizi
- **`docker-compose.override.yml`** — override sviluppo locale

Variabili obbligatorie prima del primo avvio: `DB_ROOT_PASSWORD`, `BACKOFFICE_DB_PASSWORD`, `FRONTOFFICE_DB_PASSWORD`, `MASTER_TOKEN`, `APP_ENCRYPTION_KEY`.

Generazione valori sicuri:
```bash
openssl rand -hex 24   # MASTER_TOKEN
openssl rand -hex 16   # APP_ENCRYPTION_KEY (esattamente 32 chars)
```

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
