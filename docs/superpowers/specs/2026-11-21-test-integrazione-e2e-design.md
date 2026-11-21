# Test di integrazione e end-to-end (modello ComunicaPA) — design (bozza)

Data: 2026-11-21 · Stato: **decisioni prese** (2026-11-21), nessuna implementazione.

## Contesto

Ultimo punto della gap analysis con la baseline CI/CD di ComunicaPA (gli altri: #189–#192).
ComunicaPA ha, oltre agli unit test:

- **`integration-tests`** (solo in merge queue, required check): suite su Postgres/Redis
  veri, immagini prese da `docker-compose.yml` (unica fonte, fissate per digest: il job fallisce
  se non lo sono), migration eseguite dal globalSetup, spec anche in `Europe/Rome`. Gira con
  `!cancelled()`: se il job `images` fallisce, fallisce al primo step invece di risultare
  saltato (un required check saltato varrebbe verde).
- **`e2e`** (in `release.yml`, in parallelo allo smoke, solo se il codice è cambiato: tag,
  rebuild manuale o causa "commit su main"): stack di produzione con i digest firmati + Mailpit,
  dipendenze esterne finte (`LDAP_HOST=mock`), segreti casuali, Playwright in un container
  ufficiale fissato per digest e allineato a `@playwright/test`, due fasi con restart del
  backend in mezzo. `publish` solo se smoke ed e2e sono verdi (o e2e saltato).
- Report di copertura come artifact in coda (fatto in GIL con #189).

## Stato GIL

| Livello | Oggi |
|---|---|
| Unit | PHPUnit in `build-test` (PR + coda), `node --test` per gli script di sicurezza |
| DB | gruppo `db` (24 file) sullo stesso job, MariaDB del compose (`docker-compose.ci.yml`, porta 3307), `DbTestCase` rifiuta la 3306 |
| Stack | `scripts/smoke-stack.sh` nel job `smoke` di `docker-publish.yml` e in `compose-compat.yml`: avvio, health, utente/capability/read-only, worker, assenza di errori nei log. Nessun flusso applicativo |
| HTTP/flussi | nessuno: le route Slim, il sidecar `/api/frontoffice/*`, il CSRF globale, il login e il setup wizard non sono provati end-to-end |

Rischio coperto oggi solo a mano: un cambio che rompe un flusso (frontoffice → sidecar →
GovPay finto, login operatore, creazione pendenza, ricevuta PDF) passa CI, Trivy e smoke.

## Obiettivi

1. **Integrazione (in coda, required check)**: l'applicazione backoffice avviata in-process
   (Slim `App` di `bootstrap/app.php`) su un DB MariaDB vuoto migrato da zero, con GovPay e
   pagoPA finti, che esercita le route reali via richieste PSR-7.
2. **E2E (in `docker-publish.yml`, tra smoke e publish)**: lo stack di produzione con i digest
   firmati, dipendenze esterne finte, Playwright sui flussi principali di operatore e cittadino.
3. Nessun servizio esterno reale, nessun dato reale: fixture sintetiche (CF `RSSMRA80A01H501U`,
   IUV `0000…1`), segreti casuali per run.

## Non-obiettivi

- Test contro GovPay/pagoPA di collaudo (credenziali in CI, dati non deterministici).
- Test di carico, visual regression.
- Sostituire `smoke-stack.sh`: resta il controllo di runtime (utente, capability, read-only).

## Proposta

### A. Dipendenze esterne finte

| Servizio | Finto proposto | Note |
|---|---|---|
| GovPay (Backoffice v1, Pendenze v2, Pagamenti) | **Prism** (`stoplight/prism`, mock dalle spec OpenAPI vendorizzate in `govpay-clients/`) oppure un piccolo server PHP di fixture | Prism risponde con esempi dello schema: deterministico ma povero (nessuno stato). Il server di fixture permette flussi con stato (crea pendenza → leggi pendenza). **Da decidere** |
| pagoPA Checkout (`postCarts`) | stesso meccanismo: risposta 302 con `Location` finta | basta verificare il redirect |
| Biz Events / GPD / @e.bollo | fuori dalla prima fase | lavori del worker: solo integrazione PHP con `MockHandler` Guzzle |
| OIDC cittadini | `mock-oauth2-server` (navikt) o Keycloak in dev mode | serve il flusso Authorization Code + PKCE reale; il tipo `none` copre già l'accesso libero |
| Mail | Mailpit | come ComunicaPA: lettura via API REST |
| App IO | URL configurato verso il finto GovPay/server di fixture | |

URL dei finti nei settings del DB (`SettingsRepository`), impostati dal setup del test, non in `.env`.

### B. Integrazione PHP (`integration-tests`, solo `merge_group`)

- Gruppo PHPUnit `integration` (suite separata), escluso da `build-test`; gira solo nel job `integration-tests`.
- MariaDB: immagine letta da `docker-compose.yml` (come ComunicaPA: il job fallisce se non è
  fissata per digest), DB vuoto, `run-migrations.php` eseguito dal bootstrap del test (verifica
  anche che le migrazioni partano da zero, oggi mai provato in CI).
- App Slim in-process (`$app->handle($request)`), sessione PHP in memoria, `GovPayClientFactory` con
  handler Guzzle finto (hook già esistente per i test: opzione `retry_delay`; da aggiungere un
  `handler` iniettabile).
- Casi minimi: sidecar `/api/frontoffice/*` con/ senza Bearer `MASTER_TOKEN`; CSRF globale su
  POST (form → 303, JSON → 403); `AuthMiddleware` single-session; creazione pendenza con
  risoluzione voci/`iuv_prefix` da DB; checkout carrello (409 su 422 pagoPA, come in
  `checkoutCarrello()`); un giro di ogni lavoro del worker con un batch finto.
- Required check `integration-tests` con lo schema `!cancelled()` di ComunicaPA.

### C. E2E Playwright (job `e2e` in `docker-publish.yml`)

- Progetto compose `gil-e2e` (mai lo stack di sviluppo), `docker-compose.yml` +
  `e2e/docker-compose.e2e.yml` (finti, Mailpit, nessuna porta pubblicata tranne quelle per
  Playwright), immagini = digest firmati del run (come `smoke`).
- `worker` avviato **solo** con il DB e2e (mai con dump di produzione: vincolo già scritto in
  CLAUDE.md per il profilo `worker`).
- Playwright in container ufficiale (`mcr.microsoft.com/playwright:vX-noble@sha256:…`) sulla rete
  dello stack; versione allineata a `@playwright/test` con check in CI (Dependabot non vede
  l'immagine nello script).
- Flussi della prima fase:
  1. setup wizard su DB vuoto → login operatore;
  2. operatore: crea pendenza, la vede in lista, scarica avviso PDF (da finto GovPay);
  3. cittadino (`none`): "Paga un avviso" → carrello → redirect al checkout finto; ritorno
     esito; ricevuta PDF via sidecar;
  4. cittadino OIDC: login sul provider finto, area personale con le proprie pendenze;
  5. mail: notifica ricevuta su Mailpit.
- Quando: tag `v*`, rebuild manuale, rebuild con causa "commit su main" (`code_changed` dal
  rebuild-check); **saltato** sui rebuild per sole patch di sistema/CVE. `publish` richiede
  smoke verde ed e2e verde o saltato.
- harden-runner `block`: allowlist del job da misurare (registry Playwright `*.data.mcr.microsoft.com`,
  npm per il pacchetto e2e).
- Artefatti: report HTML e trace dei soli test falliti, `retention-days: 7`.

## Decisioni (2026-11-21)

1. **Finto GovPay**: server di fixture minimale in PHP, con stato, per i 6–8 endpoint usati dai
   flussi (crea pendenza → leggi pendenza → avviso/ricevuta PDF); Prism dalle spec OpenAPI
   vendorizzate per il resto.
2. **Integrazione**: job separato `integration-tests` in `ci.yml`, **solo `merge_group`**,
   required check proprio con lo schema `!cancelled()` di ComunicaPA. Sulla PR un job
   segnaposto con lo stesso nome (solo `echo`, senza harden-runner, come `image-check.yml`).
3. **OIDC**: fase 2 (flusso 4). La prima fase copre il tipo `none`.
4. **E2E su publish**: report-only per 2 settimane (`continue-on-error`, esito nel summary del
   run) per misurare la flakiness, poi bloccante: `publish` richiede smoke verde ed e2e verde o
   saltato.
5. **Playwright**: pacchetto `e2e/` in TypeScript con `@playwright/test`; ecosistema `npm`
   (directory `/e2e`) aggiunto a Dependabot con cooldown 7 giorni, come gli altri.
6. **E2E solo in `docker-publish.yml`** (tra smoke e publish, sulle immagini firmate), mai in
   `image-check.yml`: la coda non si allunga, un flusso rotto blocca il rilascio, non il merge.
7. **PR leggere: test unit solo in coda.** `build-test` (composer validate/audit, PHPStan,
   PHPUnit unit + gruppo `db`) passa a `merge_group` + schedule; sulla PR un segnaposto
   `build-test` (solo `echo`) tiene soddisfatto il required check. Sulla PR restano `trivy-fs`,
   `semgrep`, `dependency-review`.
   - Conseguenza accettata: un test, PHPStan o un `composer audit` rotti si scoprono solo
     quando la PR entra in coda (scartata, auto-merge da rilanciare). Verifica locale prima del
     push: `php vendor/bin/phpunit` e PHPStan.
   - `dependabot-automerge.yml` non cambia: accoda e la coda esegue i test.

## Piano per fasi

0. `build-test` solo in coda + segnaposto sulla PR (decisione 7): indipendente dal resto,
   può andare per primo.
1. Finti (server di fixture GovPay + Prism, Mailpit) + `e2e/docker-compose.e2e.yml` +
   `e2e/run.sh` eseguibile in locale.
2. Integrazione PHP (B) nel job `integration-tests` in coda, required check (ruleset `8751474`
   aggiornato a mano, `integration_id` 15368).
3. E2E flussi 1–3 e 5 in `docker-publish.yml`, report-only per 2 settimane.
4. OIDC (flusso 4), poi e2e bloccante su publish.

## Rischi

- Flakiness e2e su runner condivisi: retry Playwright 1 in CI, trace sui falliti, timeout espliciti.
- Tempo della pipeline di release (+5–10 min): e2e in parallelo allo smoke, come ComunicaPA.
- Divergenza tra il finto GovPay e quello reale: il finto si basa sulle stesse spec OpenAPI dei
  client generati; un test contrattuale (risposte del finto validate contro lo schema) lo limita.
- Dati sensibili: il DB e2e nasce vuoto per ogni run, `down -v` a fine job.
