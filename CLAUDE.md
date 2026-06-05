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

# Accesso DB diretto — backoffice user NON funziona da localhost dentro il container
docker exec gil-db mariadb -uroot -p"$DB_ROOT_PASSWORD" govpay -e "SELECT ..."
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
- **Twig 3**: `{% for item in list if condition %}` rimosso — usare `list|filter(p => condition)` al posto
- **SSL**: `SSL=on` attiva HTTPS diretto su Apache; `SSL=off` per deploy dietro reverse proxy (es. Portainer + Traefik). Usa `SSL_HEADER` per X-Forwarded-Proto in modalità proxy.
- **Autenticazione operatori**: sessione PHP + token GovPay; `sslheader` come metodo auth alternativo
- **Debug**: variabile `APP_DEBUG` nel `.env`; toggle disponibile nell'UI backoffice
- **cURL PHP 8.5**: `curl_close()` deprecated — scrive notice su stdout e rompe `header()`. Usare `unset($ch)` invece.
- **GovPay `tipo_bollo`**: API pagamenti ritorna `'Imposta di bollo'` (stringa), non `'01'` — client generato fallisce deserializzazione e fa raw fallback. Atteso, non bug GIL. `normalizeTipoBolloForBackoffice()` in `PendenzeController` converte; frontoffice usa sempre `'01'` hardcoded.
- **MBT allegato XML**: pendenza pagata con `voci[].riscossioni[tipo='MBT']` contiene `allegato.testo` (base64 XML marca da bollo) — già nei dati della pagina, servire client-side via Blob API senza extra chiamata GovPay.
- **`ObjectSerializer::sanitizeForSerialization`**: ritorna `stdClass`, non `array`. Prima di accedere a chiavi usare `$arr = is_array($raw) ? $raw : (json_decode(json_encode($raw, JSON_UNESCAPED_SLASHES), true) ?: [])`.
- **`app_debug` Twig global**: nei template backoffice usare `{% if app_debug %}`, NON `{% if app.debug == 'true' %}`. Il global è registrato in `web.php` come `$twig->addGlobal('app_debug', $displayErrorDetails)`.
- **Pattern tab Impostazioni GovPay-side**: dati che vivono in GovPay (non in `settings` DB) vengono fetchati in `ImpostazioniController::index()` quando `$tab === 'X'` e passati a Twig. Bottoni di aggiornamento sono AJAX su endpoint dedicati. Non usare `SettingsRepository` per dati GovPay.

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
   - Checkout frontoffice priority: @e.bollo v2 (`PAGOPA_EBOLLO_MODE=v2`) → GovPay checkout (`GOVPAY_CHECKOUT_URL` set) → pagoPA standard. Helper: `frontoffice_resolve_bollo_checkout_url()` in `frontoffice/public/index.php`.

4. **Tab Conti di Accredito (IBAN) in Impostazioni (Giugno 2026)**:
   - Nuovo tab `iban` in `Impostazioni → GovPay → Conti di accredito` per visualizzare, aggiungere, modificare e abilitare/disabilitare IBAN direttamente da GIL.
   - Dati **solo in GovPay**, niente `SettingsRepository`. Client: `GovPay\Backoffice\Api\EntiCreditoriApi` (già in `govpay-clients/`).
   - Pattern server-side: `ImpostazioniController::index()` fetcha GovPay quando `$tab === 'iban'` e passa `iban_list`, `iban_json`, `iban_error` a Twig. Bottone "Aggiorna" è AJAX (`GET /impostazioni/iban/list`).
   - Route: `GET /impostazioni/iban/list`, `POST /impostazioni/iban/save`, `POST /impostazioni/iban/toggle`. Metodi: `ibanList()`, `ibanSave()`, `ibanToggle()` in `ImpostazioniController`.
   - Template: `backoffice/templates/impostazioni/tab-iban.html.twig`. Nav link sotto "Dati dominio" nella sezione GovPay.
   - Disabilita con `abilitato=false` — non esiste endpoint DELETE nell'API GovPay.
   - **Gotcha `ObjectSerializer::sanitizeForSerialization`**: ritorna `stdClass`, non array. Usare `json_decode(json_encode($raw), true)` prima di leggere le chiavi (come in `ConfigurazioneController`).
   - **Gotcha `app_debug` Twig**: nei template è globale `app_debug` (booleano/stringa da `web.php:1129`), NON `app.debug` dal settings array.

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

## Demoni Cron

Tutti i demoni sono loop infiniti con single-instance guard (PID file) e segnale di stop via file `/tmp/`. Gestibili da UI: **Backoffice → Impostazioni → Cron** (start/stop/log/autostart).

Log: `echo` su stdout → catturato da Docker (`docker logs gil-backoffice`).

| Demone | Script | PID file | Stop file | Dipende da |
|---|---|---|---|---|
| Ragioneria | `cron_ragioneria.php` | `/tmp/cron-ragioneria.pid` | `/tmp/cron-stop-ragioneria` | GovPay API |
| Biz scanner | `cron_biz_scanner.php` | `/tmp/cron-biz-scanner.pid` | `/tmp/cron-stop-biz` | Biz Events API |
| TEFA scanner | `cron_tefa_scanner.php` | `/tmp/cron-tefa-scanner.pid` | `/tmp/cron-stop-tefa` | biz_ricevute |
| Mapping L1 | `cron_mapping_pendenze.php` | `/tmp/cron-mapping.pid` | `/tmp/cron-stop-mapping` | biz + (tefa) |
| Mapping L2 | `cron_vocab_mapping.php` | `/tmp/cron-vocab.pid` | `/tmp/cron-stop-vocab` | L1 |
| Pendenze massive | `cron_pendenze_massive.php` | `/tmp/cron-pendenze-massive.pid` | `/tmp/cron-stop-pendenze-massive` | — |
| GovPay debitore | `cron_govpay_debitore_scanner.php` | `/tmp/cron-govpay-debitore.pid` | `/tmp/cron-stop-govpay-debitore` | GovPay API |

### Dettaglio demoni

**`cron_ragioneria.php`** — Sincronizza flussi rendicontazione da GovPay API → `flussi_rendicontazioni`. Prima iterazione: scan completo dalla data configurata (`backoffice.ragioneria_scan_da`); iterazioni successive: finestra scorrevole (ultimo sync − 3 giorni) per evitare scan completo ogni volta. Rescan forzato: creare `/tmp/cron-rescan-ragioneria`.

**`cron_biz_scanner.php`** — Per ogni IUR non-GovPay in `flussi_rendicontazioni` con `biz_stato = 'PENDING'`: chiama pagoPA Biz Events API e salva i dati ricevuta in `biz_ricevute` (`stato = 'PROCESSED'`). Primo motore della pipeline pendenze esterne.

**`cron_tefa_scanner.php`** — Legge `biz_ricevute` (PROCESSED, non ancora in `tefa_ricevute`) e classifica ogni IUR come TEFA (`stato = 'PROCESSED'`) o non-TEFA (`stato = 'SKIPPED'`). Non chiama Biz Events — usa i dati già salvati dal demone Biz. Attivo solo se `tefa_enabled = true`.

**`cron_mapping_pendenze.php`** — Demone L1 mapping. Ogni ciclo: (1) discovery pattern IUV a cascata 5→4→3 char ogni 60s, (2) bulk assign `fornitore` per ogni pattern attivo (longest-prefix-first), (3) segna PENDING rimanenti come `NO_MATCH`. Pausa 15s se nessuna assegnazione, 1s altrimenti.

**`cron_vocab_mapping.php`** — Demone L2 mapping. Prende pendenze con `mapping_stato = 'PROCESSED'` e `vocab_stato = 'PENDING'`. Per ogni pendenza: longest-prefix match sul pattern IUV, poi scan keyword vocab (priorità DESC) sulla descrizione Biz. Assegna `cod_entrata` da keyword o da fallback del pattern. Se nessun match: `vocab_stato = 'NO_MATCH'`.

**`cron_pendenze_massive.php`** — Processa batch di 50 pendenze massive in stato `PENDING` (inserimento massivo da CSV/API). Pausa 30s quando coda vuota.

**`cron_govpay_debitore_scanner.php`** — Per ogni IUR GovPay (`is_govpay=1`) in `flussi_rendicontazioni` senza entry in `biz_ricevute`: chiama GovPay Backoffice API `GET /pendenze/{id_a2a}/{id_pendenza}` e salva `soggettoPagatore.identificativo/anagrafica` + `causale` in `biz_ricevute` come `PROCESSED`. Consente al CSV ragioneria di includere CF/nominativo debitore anche per pendenze interne. Batch da 20 con 1s tra chiamate; 15 min di sleep quando coda vuota. Usa stessa autenticazione GovPay (Basic Auth + mTLS opzionale).

## Mapping Pendenze Esterne (L1 + L2)

Pipeline obbligatoria per ogni pendenza esterna (`is_govpay = 0`) prima che possa essere analizzata nel mapping:

1. **Motore Biz** (`cron_biz_scanner.php`): `biz_ricevute.stato = 'PROCESSED'`
2. **Motore TEFA** (`cron_tefa_scanner.php`, solo se `tefa_enabled = true`): `tefa_ricevute.stato = 'SKIPPED'`
3. **Demone L1** (`cron_mapping_pendenze.php`): assegna `fornitore` via prefisso IUV → `mapping_stato = 'PROCESSED'` oppure `'NO_MATCH'`
4. **Demone L2** (`cron_vocab_mapping.php`): assegna `cod_entrata` via keyword → `vocab_stato = 'PROCESSED'` oppure `'NO_MATCH'`

Una pendenza è `NO_MATCH` solo se ha completato l'intero giro (1 → 2 → 3). Tutte le query di analisi/statistiche usano INNER JOIN con `biz_ricevute` (e `tefa_ricevute` se abilitato) per escludere pendenze non ancora processate dai motori upstream.

### Discovery pattern L1 — logica a cascata

`MappingPendenzeRepository::discoverPatterns()` genera auto-pattern (`is_custom = 0`) a **3 lunghezze**: 5, 4, 3 char.

**Regola di esclusione a cascata:**
- Pattern 5-char: conta tutti gli IUV con quel prefisso
- Pattern 4-char: conta solo IUV dove `LEFT(iuv, 5)` NON è già un pattern 5-char scoperto
- Pattern 3-char: conta solo IUV dove `LEFT(iuv, 5)` e `LEFT(iuv, 4)` NON sono pattern scoperti

Così `transazioni_count` riflette le righe **non coperte da prefissi più lunghi**. La soglia attiva è ≥ 5 transazioni (o `is_custom = 1` per bypass). Il matching nel demone L1 è longest-prefix-first (regole ordinate per `CHAR_LENGTH DESC`): i pattern da 5 char vengono applicati prima, poi i 4-char sui PENDING rimanenti, poi i 3-char.

Non modificare questa logica senza aggiornare anche la soglia e il rendering UI (filtri "5 char / 4 char / 3 char" in `mapping_pendenze.html.twig`).

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