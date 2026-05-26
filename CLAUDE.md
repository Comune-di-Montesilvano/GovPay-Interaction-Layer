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
| `gil-db` | `db` | MariaDB 11 | DB condiviso, utenti separati backoffice (RW) / frontoffice (RO) |
| `gil-auth-proxy` | `auth-proxy` | Python SATOSA | Proxy SPID/CIE: legge config da backoffice, gestisce SATOSA |
| `gil-auth-proxy-nginx` | `auth-proxy-nginx` | Nginx | Reverse proxy per SATOSA, serve metadata e disco SPID |
| `gil-auth-proxy-db` | `auth-proxy-db` | MongoDB 7 | Backend CIE OIDC (usato da SATOSA) |
| `gil-metadata-builder` | `metadata-builder` | Bash + OpenSSL | Generazione certificati e metadata SPID/CIE (setup iniziale) |

Le chiamate interne tra servizi usano Bearer token (`MASTER_TOKEN`). Segreti sensibili cifrati in DB con `APP_ENCRYPTION_KEY` (32 caratteri).

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

# Daemon ragioneria (sincronizza flussi GovPay → tabella flussi_rendicontazioni)
docker exec -d gil-backoffice php /var/www/html/scripts/cron_ragioneria.php

# Daemon Biz scanner (salva dati ricevuta Biz Events per pendenze non-GovPay → biz_ricevute)
docker exec -d gil-backoffice php /var/www/html/scripts/cron_biz_scanner.php

# Daemon TEFA scanner (classifica IUR come TEFA/non-TEFA da biz_ricevute → tefa_ricevute)
docker exec -d gil-backoffice php /var/www/html/scripts/cron_tefa_scanner.php

# Cron batch pendenze massive
docker exec gil-backoffice php /var/www/html/scripts/cron_pendenze_massive.php

# Daemon gestibili anche da Backoffice → Impostazioni → Cron (start/stop/log/autostart)
```

## Struttura directory

```
app/            Librerie PHP condivise (Config, Database, Security, Services)
backoffice/     Applicazione backoffice (src/, templates/, public/)
frontoffice/    Applicazione frontoffice (locales/, templates/, public/)
auth-proxy/     Proxy SATOSA per SPID/CIE
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

- **`ci.yml`** — push/PR su `main`/`dev`: installa PHP 8.5, avvia solo il container `db` (backoffice/frontoffice non servono), esegue PHPUnit. DB image cachata via `docker save`/`docker load` (chiave: hash di `docker/db/Dockerfile` + `docker/db-init/`).
- **`docker-publish.yml`** — tag `vX.Y.Z` o push su `dev`: job `setup` risolve version-resolver una volta, poi `build-php` e `build-services` parallelizzano su quella output. PHP builds usano `type=gha,mode=max` + fallback `type=registry` sull'immagine `:dev`; services usano `type=gha,mode=min` per non esaurire i 10GB di cache GHA.

Tag immagini: `:vX.Y.Z`, `:X.Y`, `:latest`. `APP_VERSION` nel compose seleziona versione.

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
| @e.bollo (pagoPA) | Acquisto e validazione Marca da Bollo Telematica |
| pagoPA GPD | Gestione posizioni debitorie |
| App IO | Notifiche e pagamenti cittadini |
| SPID / CIE | Autenticazione federata cittadini |

## Nuove Funzionalità Chiave (Maggio 2026)

1. **Riprogettazione UI Backoffice**:
   - **Dashboard in tempo reale**: Grafici combinati Chart.js (trend mensili incassi e transazioni), doughnut split flussi interni (GovPay) vs esterni (Biz Events), e breakdown per tipologia di pendenza (Top 6 predefinita ed espansione a tutte con bottone toggle e animazione client-side).
   - **GIL Services Hub**: Gestione asincrona AJAX dei demoni contabili (`biz`, `tefa`, `ragioneria`, `pendenze-massive`) direttamente dalla home per i superadmin (avvio, arresto e log live).
   - **Gerarchia Visiva**: Sidebar con icone FontAwesome nidificate, testate delle card compatte ed allineate a sinistra con paginazione integrata, e badges di stato a contrasto elevato in tinte pastello con bordi coordinati.
   - **Piani di Rateizzazione Lineari**: Algoritmo automatico in JS che ricalcola scadenze, frequenze di intervallo e importi residui in tempo reale al cambio di qualsiasi campo (redistribuzione progressiva), eliminando tutti i vecchi bottoni "Ricalcola".
   - **Datepicker Contabile**: Premium Date Picker (Litepicker) con input manuale sbloccato (validazione anni bisestili) e pulsanti rapidi preimpostati per ragioneria (*Mese Corrente*, *Mese Precedente*, *Anno Corrente*, *Anno Precedente*, *Azzera*) uniformati su tutte le 6 ricerche/report contabili e disposti in una griglia orizzontale affiancata senza wrapping orizzontale.

2. **Ottimizzazione Mobile & UI Frontoffice**:
   - Hamburger menu a scomparsa per i link principali e selettore lingua compresso in `<select>` nativo su mobile.
   - Rimozione di qualsiasi overflow orizzontale (gap bianchi fissati con `overflow-x: hidden`).
   - Pulsanti SPID e primari isolati graficamente da override dei colori di link, garantendo testo bianco ad alto contrasto (#ffffff) in tutti gli stati (`:hover`, `:focus`, `:active`).
   - Allineamento ed uniformazione della pagina "Paga un avviso" (`avviso.html.twig`) al design system, eliminando clipping di layout.
   - Percorsi di checkout errore/annullamento arricchiti con pulsante "Vai al carrello" e restrizione dell'area personale solo ad utenti loggati.

3. **Marca da Bollo Telematica (@e.bollo)**:
   - Integrazione completa del flusso di inserimento e pagamento del bollo telematico, sia in backoffice che in frontoffice (`bollo.html.twig`, `avviso-bollo.html.twig`).

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

`/api/*` pubblico (autenticazione Bearer `MASTER_TOKEN`) per chiamate interne da `auth-proxy` e `metadata-builder`.

## Migrazioni DB

File SQL in `migrations/` (numerati `003_...sql` → `013_...sql`). Nessun runner automatico — migrazioni applicate manualmente o via `docker/db-init/` al primo avvio del container MariaDB.

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