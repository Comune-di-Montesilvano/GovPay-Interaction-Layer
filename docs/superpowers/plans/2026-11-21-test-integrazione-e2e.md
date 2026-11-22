# Test di integrazione ed e2e — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** PR leggere (unit solo in coda), test di integrazione PHP in coda su DB vuoto con GovPay
finto, e2e Playwright sulle immagini firmate in `docker-publish.yml`, OIDC finto in fase 2.

**Architecture:** un solo GovPay/pagoPA finto (router PHP con stato su file, `php -S`) usato sia
dall'integrazione (avviato dal test case sull'host) sia dall'e2e (container `php:cli` che monta
`tests/Fakes/`); le route senza fixture vanno a Prism se `PRISM_URL` è impostato, altrimenti 404
registrato. L'integrazione esegue l'app Slim in-process; l'e2e avvia lo stack di produzione con
i digest del run e un runner Playwright in container sulla stessa rete.

**Tech Stack:** PHP 8.5, PHPUnit 13, Slim 4, MariaDB (immagine `docker/db/Dockerfile`), Prism,
Mailpit, `@playwright/test` (TypeScript), mock-oauth2-server, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-21-test-integrazione-e2e-design.md` (sezione "Decisioni").

## Global Constraints

- Fixture **sempre sintetiche**: CF `RSSMRA80A01H501U`, IUV `0000…1`, domini/email `@example.test`; mai dati di produzione (repo pubblico).
- Segreti casuali per run (`openssl rand`), mai il `.env` del repo; env file fuori dalla workspace caricata negli artifact.
- Immagini di terze parti (php, Prism, Mailpit, Playwright, mock-oauth2-server) fissate `tag@sha256:…`.
- Tutte le Action pinnate per SHA (`# vX`); ogni job con `timeout-minutes`; harden-runner `egress-policy: block` con allowlist sui job nuovi di `docker-publish.yml`.
- Job segnaposto (PR) senza harden-runner, solo `echo` (CLAUDE.md: job tutto saltato con harden-runner può restare appeso).
- Progetto compose `gil-e2e`, mai lo stack di sviluppo, mai `docker-compose.override.yml`; `down -v` sempre.
- Worker **non** avviato in e2e (non serve ai flussi; lo copre `smoke-stack.sh`).
- `nosemgrep` / `@phpstan-ignore-next-line` sulla riga esatta, con motivazione.
- Commit Conventional, trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; PR con `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
- Worktree: `../gil-wt-e2e-impl` (vendor copiato), rimosso dopo l'ultimo merge.

## Review Focus

1. **PR senza check required**: se il segnaposto `build-test`/`integration-tests` non gira su un tipo di evento PR (Dependabot, PR da fork, `synchronize`), la PR non entra in coda → Task 1 verifica i check su una PR reale e su una Dependabot.
2. **Migrazioni che falliscono in silenzio**: `run-migrations.php` stampa `ERRORE` ed esce 0 → Task 3 `MigrationsFromScratchTest` fallisce su qualsiasi `ERRORE` al primo avvio.
3. **Stato del finto che trapela tra test**: un test che dipende dall'ordine → Task 2/3 `reset` in `setUp`, test `testStateIsolatedAfterReset`.
4. **Rebuild per sole CVE**: e2e saltato non deve bloccare `publish` (né ora né dopo il passaggio a bloccante) → Task 7 verifica con `dry_run` + Task 10 condizione `success || skipped`.
5. **Redirect verso pagoPA reale**: il checkout finto deve restituire sempre un `Location` sul finto → Task 2 `testCartsLocationPointsToFake`; Task 7 allowlist egress senza host pagoPA (un redirect reale fallirebbe bloccato).

---

## PR A — PR leggere (fase 0)

### Task 1: `build-test` solo in coda + segnaposto sulla PR

**Files:**
- Modify: `.github/workflows/ci.yml` (job `build-test`, nuovo job `build-test-placeholder`)
- Modify: `CLAUDE.md` (sezione CI/CD, voce `ci.yml` e "Merge queue")
- Create: `docs/superpowers/plans/2026-11-21-test-integrazione-e2e.md` (questo piano)

**Interfaces:**
- Produces: check `build-test` sempre presente sulle PR (segnaposto) e reale in `merge_group`/`schedule`/`workflow_dispatch`.

- [ ] **Step 1:** `build-test.if` → `github.event_name != 'push' && github.event_name != 'pull_request'`; aggiornare il commento (unit, PHPStan, composer audit solo in coda e giornaliero).
- [ ] **Step 2:** nuovo job `build-test-placeholder` con `name: build-test`, `if: github.event_name == 'pull_request'`, `timeout-minutes: 2`, un solo step `run: echo "build-test reale in merge queue (ci.yml)"`, senza harden-runner, commento che rimanda a `image-check.yml`.
- [ ] **Step 3:** `actionlint .github/workflows/ci.yml` (via `docker run --rm -v "$(pwd)://repo" -w //repo rhysd/actionlint:<versione pinnata>`); atteso: nessun output.
- [ ] **Step 4:** CLAUDE.md: `build-test` solo coda/giornaliero, segnaposto sulla PR, "verifica locale prima del push: `php vendor/bin/phpunit` e PHPStan".
- [ ] **Step 5:** commit, push, PR. Verifica: `gh pr checks <N>` mostra `build-test` pass in < 1 min sulla PR; dopo `gh pr merge <N> --auto` la run `merge_group` mostra `build-test` reale (durata minuti).
- [ ] **Step 6:** verifica Dependabot: alla prossima PR Dependabot `gh pr checks` mostra `build-test` pass (segnaposto). Se manca: aggiungere il tipo di evento mancante, non togliere il required check.

---

## PR B — finto GovPay + integrazione in coda (fasi 1 e 2)

### Task 2: GovPay/pagoPA finto con stato

**Files:**
- Create: `tests/Fakes/GovPay/router.php` (entry `php -S`), `tests/Fakes/GovPay/FakeState.php`, `tests/Fakes/GovPay/fixtures/avviso.pdf`, `tests/Fakes/GovPay/fixtures/ricevuta.pdf` (PDF minimi sintetici, < 2 KB)
- Create: `tests/Support/FakeGovPayServer.php`
- Test: `tests/Fakes/FakeGovPayServerTest.php`

**Interfaces:**
- Produces:
  - `FakeGovPayServer::start(): self` (porta libera, `php -S 127.0.0.1:<porta> router.php`, attende `GET /__fake/health` 200, stato in `sys_get_temp_dir()/gil-fake-<porta>.json`), `->baseUrl(): string`, `->reset(): void`, `->requests(): list<array{method:string,path:string,body:mixed}>`, `->unmatched(): list<string>`, `->setPaid(string $idDominio, string $iuv): void`, `->failNext(string $method, string $pathRegex, int $status, array $body): void`, `->stop(): void`.
  - Prefissi URL (un finto per tutte le basi dei settings): `/backoffice` (`govpay.backoffice_url`), `/pendenze` (`govpay.pendenze_url`), `/pagamenti` (`govpay.pagamenti_url`), `/checkout` (`pagopa.checkout_ec_base_url`).
  - Endpoint con stato (path relativi al prefisso, verificare ognuno contro la spec in `govpay-clients/`/`pagopa-clients/` e contro il client che lo chiama): `PUT /pendenze/{idA2A}/{idPendenza}` (assegna `numeroAvviso` `3` + IUV `00000000000000001` incrementale, `stato` `NON_ESEGUITA`), `GET /pendenze/{idA2A}/{idPendenza}`, `GET /pendenze` (filtro `idDebitore`, paginazione `risultati`/`numPagine`), `GET /pendenze/byAvviso/{idDominio}/{numeroAvviso}`, avviso PDF, ricevuta RT PDF (solo dopo `setPaid`, prima 404), `POST /carts` pagoPA (302, `Location: <baseUrl>/__checkout/<id>`; `GET /__checkout/<id>` = pagina HTML con link ai `returnUrls` del carrello).
  - Controllo: `POST /__fake/reset`, `GET /__fake/requests`, `GET /__fake/unmatched`, `POST /__fake/paid`, `POST /__fake/fail-next`.
  - Route non gestite: con env `PRISM_URL` inoltro a Prism (stesso metodo/path/body, risposta così com'è), altrimenti `404 {"fake":"unmatched"}`; in entrambi i casi la riga `METODO /path` va in `unmatched`.

- [ ] **Step 1: test che falliscono** in `FakeGovPayServerTest` (avvio in `setUpBeforeClass`, `reset` in `setUp`):
  - `testPutThenGetPendenza`: PUT `/backoffice/pendenze/A2A-TEST/PND-1` con CF `RSSMRA80A01H501U` → 201; GET stesso path → 200, `numeroAvviso` di 18 cifre che inizia con `3`, `stato` `NON_ESEGUITA`.
  - `testByAvvisoAndListByDebitore`: dopo il PUT, `byAvviso` e `GET /pendenze?idDebitore=RSSMRA80A01H501U` la trovano; un altro CF → lista vuota.
  - `testRicevutaOnlyAfterPaid`: RT → 404, `setPaid` → 200 `application/pdf` che inizia con `%PDF`.
  - `testCartsLocationPointsToFake`: POST `/checkout/carts` → 302, `Location` inizia con `baseUrl()`; la pagina `__checkout` contiene `returnOkUrl` del body.
  - `testFailNextReturnsConfiguredError`: `failNext('POST', '#/carts$#', 422, [...])` → 422 una volta, poi 302.
  - `testUnmatchedIsRecorded`: `GET /backoffice/inesistente` → 404 e `unmatched()` = `['GET /backoffice/inesistente']`.
  - `testStateIsolatedAfterReset`: PUT, `reset`, GET → 404.
- [ ] **Step 2:** `vendor/bin/phpunit tests/Fakes` → FAIL (classe mancante).
- [ ] **Step 3:** implementare `FakeState` (JSON su file con `flock`, scrittura via rename) e `router.php`; `FakeGovPayServer` con `proc_open` argv (niente shell), `stop()` in `tearDownAfterClass` e `register_shutdown_function`.
- [ ] **Step 4:** `vendor/bin/phpunit tests/Fakes` → PASS; `vendor/bin/phpstan analyse tests/Fakes tests/Support` → nessun errore (se `phpstan.neon` non include `tests/`, ok).
- [ ] **Step 5:** commit `test: aggiungi GovPay/pagoPA finto con stato`.

### Task 3: base integrazione + test sui flussi backoffice

**Files:**
- Create: `tests/Integration/Support/IntegrationTestCase.php`
- Test: `tests/Integration/MigrationsFromScratchTest.php`, `SidecarAuthTest.php`, `CsrfIntegrationTest.php`, `SingleSessionTest.php`, `SidecarPendenzaTest.php`, `CheckoutConflictTest.php`
- Modify: `phpunit.xml` (nessuna suite nuova: il gruppo basta), `tests/bootstrap.php` se serve l'autoload di `Tests\Integration\Support\`

**Interfaces:**
- Consumes: `FakeGovPayServer` (Task 2), `Tests\Support\DbTestCase` (schema da `first_run_create_tables.php` + `run-migrations.php`, rifiuto porta 3306).
- Produces: `abstract class IntegrationTestCase extends DbTestCase` con `#[Group('integration')]` su ogni sottoclasse (PHPUnit 13 non eredita i gruppi; anche `#[Group('db')]`), `protected static FakeGovPayServer $fake`, `protected function request(string $method, string $path, array $body = [], array $headers = []): ResponseInterface` (app Slim nuova per richiesta da `backoffice/src/bootstrap/app.php` + `routes/web.php`, handler globali ripristinati dopo il boot con `restore_error_handler()`/`restore_exception_handler()`, sessione mantenuta tra richieste con `session.use_cookies=0` e lo stesso `session_id`), `protected function loginOperatore(string $ruolo = 'admin'): void` (utente sintetico `operatore@example.test` in `users`, GET `/login` per il token, POST `/login`), `protected function csrfToken(): string`. `setUp` imposta via `putenv` `APP_ENCRYPTION_KEY` (32 caratteri casuali), `MASTER_TOKEN`, `FRONTOFFICE_LINK_SIGNING_KEY` e via `SettingsRepository::set` le URL dei prefissi del finto, `entity.id_dominio` `00000000000`, `govpay.authentication_method` `basic`, utente/password finti; poi `SettingsRepository::flush()` e `$fake->reset()`.

- [ ] **Step 1: test che falliscono:**
  - `MigrationsFromScratchTest::testFirstRunHasNoErrors`: su un database nuovo `gil_mig_<rand>` (CREATE/DROP con lo stesso utente del gruppo `db`), esegue i due script e asserisce exit 0 e `assertStringNotContainsString('ERRORE', $stdout)`.
  - `SidecarAuthTest`: `GET /api/frontoffice/tipologie` senza `Authorization` → 401; Bearer errato → 401; Bearer `MASTER_TOKEN` → 200 con `success` true. Nessun 403/303 CSRF su `POST /api/frontoffice/rate-limit/check` con Bearer valido.
  - `CsrfIntegrationTest`: loggato, `POST /impostazioni/generale/save` senza token come form → 303 con `Location` same-origin; con `Accept: application/json` + `X-Requested-With: XMLHttpRequest` → 403 JSON con `success` false; con `csrf_token` valido → non 403/303-CSRF.
  - `SingleSessionTest::testSecondLoginInvalidatesFirst`: login in sessione A, login stesso utente in sessione B, richiesta `/` con A → 302 `/login`; con B → 200.
  - `SidecarPendenzaTest::testCreatePendenzaResolvesVociFromDb`: tipologia + template sintetici in DB (`entrate`, `pendenza_templates`: usare le colonne reali), `POST /api/frontoffice/pendenze` con Bearer → `success` true; `$fake->requests()` contiene una PUT `/backoffice/pendenze/...` con le voci del template e l'importo atteso; `unmatched()` vuoto.
  - `CheckoutConflictTest::testPagoPa422Becomes409`: `failNext('POST', '#/carts$#', 422, ['title' => 'Invalid payment notice data'])`, `POST /api/frontoffice/carrello/checkout` → `error_status` 409 (corpo HTTP 200 secondo il pattern sidecar, header `X-App-Error-Status: 409`).
  - ~~`JobsAgainstFakeTest`~~ tolto in esecuzione: i test dei lavori in `tests/Services/Jobs/` usano gia' lo stack reale di `GovPayClientFactory` con il finto solo al confine HTTP (`FakeGovPayPendenzeController::factoryClient`); rifarli contro il fixture server non aggiunge copertura. `SidecarPendenzaTest` copre anche l'errore GovPay 422 → `error_status` 422.
- [ ] **Step 2:** con il DB di test attivo (`docker compose -f docker-compose.yml -f docker-compose.ci.yml up -d db`, `.env` fittizio come in `ci.yml`), `DB_HOST=127.0.0.1 DB_PORT=3307 DB_NAME=appdb DB_USER=appuser DB_PASSWORD=apppass BACKOFFICE_DB_USER=appuser php -d extension=pdo_mysql -d extension=curl vendor/bin/phpunit --group integration` → FAIL (classe base mancante).
- [ ] **Step 3:** implementare `IntegrationTestCase`. Ogni endpoint GovPay che compare in `unmatched()` va aggiunto al finto (Task 2, con test) e non stubbato nel test.
- [ ] **Step 4:** stesso comando → PASS; poi `vendor/bin/phpunit --exclude-group integration` → PASS (nessuna regressione, il gruppo resta fuori da `build-test`).
- [ ] **Step 5:** se `MigrationsFromScratchTest` trova `ERRORE` reali: correggere la migrazione colpevole nello stesso PR (commit separato `fix(migrations): …`), non indebolire il test.
- [ ] **Step 6:** commit `test: integrazione backoffice in-process con GovPay finto`.

### Task 4: job `integration-tests` in coda

**Files:**
- Create: `.github/actions/php-db-setup/action.yml` (composite: `.env` fittizio, setup-php 8.5 con `tools: composer:2.11.1`, cache composer, `composer install`, cache/load immagine DB, `up db`, attesa healthy) estratto dagli step di `build-test`
- Modify: `.github/workflows/ci.yml` (`build-test` usa la composite e lancia `phpunit --exclude-group integration --coverage-text`; nuovo job `integration-tests` + `integration-tests-placeholder`)
- Modify: `CLAUDE.md` (required check, comando locale del gruppo `integration`)

**Interfaces:**
- Consumes: gruppo `integration` (Task 3).
- Produces: check `integration-tests` (reale in `merge_group`/`schedule`/`workflow_dispatch`, segnaposto su PR).

- [ ] **Step 1:** composite action; lo step "Composer CI allineato al Dockerfile" resta in `build-test` (non duplicarlo). Il controllo di coerenza composer deve continuare a leggere `tools: composer:` nel file dove ora sta (aggiornare il `sed` se si sposta nella composite).
- [ ] **Step 2:** `integration-tests`: `if: github.event_name != 'push' && github.event_name != 'pull_request'`, `timeout-minutes: 20`, harden-runner `audit` come `build-test`, composite, `vendor/bin/phpunit --group integration`, `down` in `if: always()`. Nessun `needs`: lo schema `!cancelled()` della spec non serve (vale solo per job con dipendenze); scriverlo nel commento.
- [ ] **Step 3:** `integration-tests-placeholder` (`name: integration-tests`, solo PR, `echo`, senza harden-runner).
- [ ] **Step 4:** actionlint → nessun output; push; sulla PR `integration-tests` e `build-test` pass rapidi; `gh workflow run ci.yml --ref <branch>` → `integration-tests` reale verde (log con `--group integration`, N test, 0 skipped del gruppo).
- [ ] **Step 5:** commit, PR B, merge in coda.
- [ ] **Step 6 (dopo il merge, non prima: altrimenti le PR in coda senza il job restano bloccate):** aggiungere `integration-tests` ai required check del ruleset `8751474` con `integration_id` 15368 (`gh api repos/Comune-di-Montesilvano/GovPay-Interaction-Layer/rulesets/8751474` → modificare `required_status_checks` → `gh api -X PUT … --input`). Verifica: `gh api …/rulesets/8751474 --jq '.rules[]|select(.type=="required_status_checks")|.parameters.required_status_checks[].context'` contiene `integration-tests`. CLAUDE.md elenca il nuovo nome.

---

## PR C — e2e Playwright in `docker-publish.yml` (fase 3, report-only)

### Task 5: stack e2e eseguibile in locale

**Files:**
- Create: `e2e/package.json` (`@playwright/test` versione esatta, `typescript`), `e2e/package-lock.json`, `e2e/tsconfig.json`, `e2e/playwright.config.ts`, `e2e/docker-compose.e2e.yml`, `e2e/run.sh`, `e2e/.gitignore` (`node_modules/`, `playwright-report/`, `test-results/`)
- Modify: `.github/dependabot.yml` (ecosistema `npm`, directory `/e2e`, cooldown 7, `ignore: @playwright/test` — lo aggiorna Task 8)

**Interfaces:**
- Consumes: `tests/Fakes/GovPay/router.php` (Task 2).
- Produces: `e2e/run.sh` con env `E2E_IMAGES_FILE` (override compose con le 3 `image@sha256`, stesso formato di `docker-compose.smoke.yml`), `E2E_PROJECT` (default `gil-e2e`), `E2E_GREP` (filtro Playwright opzionale); exit code = quello di Playwright; report in `e2e/playwright-report/`. Hostname di rete usati dai test: `backoffice`, `frontoffice`, `fake-govpay:8080`, `prism:4010`, `mailpit:8025` (SMTP `mailpit:1025`).

- [ ] **Step 1:** `docker-compose.e2e.yml`: servizi `fake-govpay` (`php:8.5-cli-alpine@sha256:…`, `php -S 0.0.0.0:8080 /fakes/router.php`, `./tests/Fakes/GovPay:/fakes:ro`, `PRISM_URL=http://prism:4010`), `prism` (`stoplight/prism:<5.x>@sha256:…`, `mock -h 0.0.0.0` sulla spec GovPay Backoffice in `govpay-clients/`, montata read-only), `mailpit` (`axllent/mailpit:<v>@sha256:…`), `playwright` (`mcr.microsoft.com/playwright:v<stessa versione di package.json>-noble@sha256:…`, `profiles: [runner]`, `./e2e:/e2e`, `working_dir: /e2e`, `command: sh -c "npm ci && npx playwright test"`); `ports: !reset []` su backoffice/frontoffice; `container_name` rinominati `gil-e2e-*` (come smoke).
- [ ] **Step 2:** `run.sh` (modello `scripts/smoke-stack.sh`): `set -euo pipefail`; controllo che la versione in `package.json` coincida col tag dell'immagine Playwright (altrimenti `::error::` ed exit 1); env file in `mktemp -d` fuori dal repo con segreti `openssl rand`; `trap` con `down -v --remove-orphans`; `up -d db backoffice frontoffice fake-govpay prism mailpit` (mai `worker`); attesa backoffice healthy (timeout 300 s); `run --rm playwright`.
- [ ] **Step 3:** `playwright.config.ts`: `retries: process.env.CI ? 1 : 0`, `workers: 1`, `fullyParallel: false`, `trace: 'retain-on-failure'`, `reporter: [['list'], ['html', { open: 'never' }]]`, progetti `backoffice` (`baseURL http://backoffice`) e `frontoffice` (`baseURL http://frontoffice`), timeout esplicito 30 s per test, 10 s per `expect`.
- [ ] **Step 4:** test vuoto `e2e/tests/00-health.spec.ts` (`GET /health` 200 sul backoffice, `/` 200 sul frontoffice, `GET http://fake-govpay:8080/__fake/health` 200, `GET http://mailpit:8025/api/v1/info` 200). Verifica locale: immagini da `docker compose -f docker-compose.yml -f docker-compose.build.yml build`, override con i digest locali (`docker image inspect --format '{{.Id}}'` va bene in locale), `E2E_IMAGES_FILE=… e2e/run.sh` → 4 test pass, dopo l'uscita `docker ps -a --filter name=gil-e2e` vuoto e `docker volume ls --filter name=gil-e2e` vuoto.
- [ ] **Step 5:** commit `test(e2e): stack e2e con finti e runner Playwright`.

### Task 6: flussi e2e 1, 2, 3, 5

**Files:**
- Create: `e2e/tests/01-setup.spec.ts`, `02-operatore.spec.ts`, `03-cittadino.spec.ts`, `05-mail.spec.ts`, `e2e/tests/support/fake.ts` (`resetFake()`, `setPaid(idDominio, iuv)`, `unmatched()` via `request` su `fake-govpay`), `e2e/tests/support/mailpit.ts` (`waitForMail(to: string, timeoutMs = 15000)`)

**Interfaces:**
- Consumes: stack Task 5, endpoint di controllo del finto (Task 2).
- Produces: file eseguiti in ordine (prefisso numerico, `workers: 1`); lo stato tra file passa dal DB dello stack (stesso run), credenziali operatore costanti in `support/constants.ts` (`operatore@example.test`, password generata all'avvio del run e passata via env `E2E_ADMIN_PASSWORD` da `run.sh`).

- [ ] **Step 1: 01-setup**: wizard `/setup` step 1→7 sul DB vuoto con valori sintetici (ente "Comune di Esempio", id dominio `00000000000`, URL GovPay = `http://fake-govpay:8080/backoffice` ecc., SMTP `mailpit:1025`); fine → login operatore → dashboard visibile. Poi Impostazioni → Frontoffice: autenticazione `none`, checkout pagoPA `http://fake-govpay:8080/checkout`. Campi e selettori presi dai template `backoffice/templates/setup/step*.html.twig`; usare `getByLabel`/`getByRole`, non CSS fragili.
- [ ] **Step 2: 02-operatore**: crea pendenza (CF `RSSMRA80A01H501U`, email `cittadino@example.test`, importo 10,00) → compare in lista → "Scarica avviso" restituisce un download `application/pdf`.
- [ ] **Step 3: 03-cittadino**: frontoffice "Paga un avviso" col numero avviso della pendenza (letto da `GET /__fake/requests` o dalla lista) → carrello → checkout: la pagina finale è `http://fake-govpay:8080/__checkout/…`; click "esito OK" → pagina esito del frontoffice; `setPaid` → ricevuta PDF scaricabile dal link pubblico firmato.
- [ ] **Step 4: 05-mail**: `waitForMail('cittadino@example.test')` trova la notifica di creazione pendenza (oggetto non vuoto, nessun indirizzo reale nei destinatari).
- [ ] **Step 5:** in ogni file `afterAll`: `expect(await unmatched()).toEqual([])` (un endpoint GovPay non previsto è un fallimento, non rumore: aggiungerlo al finto con test, Task 2).
- [ ] **Step 6:** `e2e/run.sh` in locale → tutti pass, due run consecutive verdi (nessuna dipendenza da stato residuo).
- [ ] **Step 7:** commit `test(e2e): flussi setup, operatore, cittadino e mail`.

### Task 7: job `e2e` in `docker-publish.yml` (report-only)

**Files:**
- Modify: `.github/workflows/docker-publish.yml` (output `code_changed` in `setup`, nuovo job `e2e`)
- Modify: `CLAUDE.md` (pipeline: e2e parallelo a smoke, report-only fino al Task 9)

**Interfaces:**
- Consumes: artifact `digest-*` (come `smoke`), `e2e/run.sh` (Task 5).
- Produces: `needs.setup.outputs.code_changed` (`true` su tag `v*`, su `workflow_dispatch` con `rebuild_latest`, su schedule se `reason` contiene `commit su main`; altrimenti `false`); job `e2e`.

- [ ] **Step 1:** in `setup` uno step `id: code` che calcola `code_changed` dalle regole sopra; esposto in `outputs`.
- [ ] **Step 2:** job `e2e`: `needs: [setup, build-php, build-services]`, `if: needs.setup.outputs.skip != 'true' && needs.setup.outputs.code_changed == 'true'`, `continue-on-error: true` (report-only), `timeout-minutes: 30`, `permissions: contents: read, packages: read`; harden-runner `block` con allowlist `github.com:443 api.github.com:443 ghcr.io:443 pkg-containers.githubusercontent.com:443 mcr.microsoft.com:443 *.data.mcr.microsoft.com:443 registry-1.docker.io:443 auth.docker.io:443 production.cloudflare.docker.com:443 registry.npmjs.org:443` (da confermare col log del post-step harden-runner al primo giro, nessun host pagoPA/GovPay); download digest, login GHCR, generazione override (stessa logica di `smoke`, `gil-e2e-*`), `CI=1 E2E_IMAGES_FILE=… e2e/run.sh`; `upload-artifact` di `e2e/playwright-report` + `e2e/test-results` con `if: failure()` e `retention-days: 7`; step finale `if: always()` che scrive esito e link artifact in `$GITHUB_STEP_SUMMARY`.
- [ ] **Step 3:** `publish` e `report-failure` **non** cambiano (report-only: e2e fuori da `needs`).
- [ ] **Step 4:** actionlint → nessun output. `gh workflow run docker-publish.yml --ref <branch> -f dry_run=true` → `setup` verde, `e2e` saltato (dry_run = `skip`).
- [ ] **Step 5:** commit, PR C, merge in coda. Dopo il merge: `gh workflow run docker-publish.yml -f rebuild_latest=true` → `e2e` gira, report nel summary; se fallisce per egress bloccato aggiungere l'host e rilanciare.

### Task 8: Playwright nel controllo versioni toolchain

**Files:**
- Modify: `.github/workflows/toolchain-version-check.yml` (gamba `playwright`), script di aggiornamento usato dalle altre gambe (stesso file/funzioni di trivy/semgrep/cosign)
- Test: test `node --test` esistenti in `scripts/security/test/` se la gamba passa da uno script lì

**Interfaces:**
- Produces: PR automatica che aggiorna insieme `e2e/package.json`, `e2e/package-lock.json` e `image:` del servizio `playwright` (tag `v<X.Y.Z>-noble` + digest), stessa regola "release ≥ 7 giorni".

- [ ] **Step 1:** test della gamba (versione corrente letta da `package.json`, ultima release `microsoft/playwright` via `gh api` con `GITHUB_TOKEN`, digest del tag `-noble` da `mcr.microsoft.com`) sul modello dei test esistenti → FAIL.
- [ ] **Step 2:** implementare; il job fallisce se `package.json` e tag immagine divergono (come trivy/semgrep).
- [ ] **Step 3:** test → PASS; `gh workflow run toolchain-version-check.yml --ref <branch>` → gamba `playwright` verde.
- [ ] **Step 4:** commit (nello stesso PR C se ancora aperto, altrimenti PR dedicato).

---

## PR D — OIDC finto (fase 4, prima parte)

### Task 9: flusso 4 cittadino OIDC

**Files:**
- Modify: `e2e/docker-compose.e2e.yml` (servizio `oidc`: `ghcr.io/navikt/mock-oauth2-server:<v>@sha256:…`, issuer `http://oidc:8080/default`, utente con claim CF `RSSMRA80A01H501U`)
- Create: `e2e/tests/04-oidc.spec.ts`
- Modify: `e2e/run.sh` (`up -d` include `oidc`), `.github/workflows/docker-publish.yml` (allowlist invariata: immagine da ghcr)

**Interfaces:**
- Consumes: stack Task 5; il backchannel del frontoffice e il browser Playwright risolvono lo stesso hostname `oidc` (stessa rete), quindi issuer/redirect coerenti senza riscritture.

- [ ] **Step 1:** test `04-oidc`: Impostazioni → Frontoffice tipo OIDC con issuer/client finti; login dal frontoffice → form del provider finto (login `RSSMRA80A01H501U`) → area personale con la pendenza creata in `02-operatore`; logout → area personale non accessibile; a fine file ripristino tipo `none`.
- [ ] **Step 2:** `e2e/run.sh` locale → tutti pass, due run consecutive.
- [ ] **Step 3:** commit, PR D, merge; `rebuild_latest=true` → e2e verde nel summary.

---

## PR E — e2e bloccante (fase 4, seconda parte)

### Task 10: passaggio a bloccante — **non prima del 2026-12-05**

Condizione (verificata, non presunta): ≥ 2 settimane dal merge di PR C e nessun fallimento e2e nelle run `docker-publish.yml` del periodo che non fosse un bug reale (`gh run list --workflow docker-publish.yml --json databaseId,conclusion,createdAt` + esito del job `e2e`); un test instabile si sistema prima, non si mette in quarantena.

**Files:**
- Modify: `.github/workflows/docker-publish.yml`, `CLAUDE.md`, spec (stato "bloccante dal …")

- [ ] **Step 1:** togliere `continue-on-error` da `e2e`; `publish.needs` += `e2e`; `publish.if` = `needs.setup.outputs.skip != 'true' && needs.smoke.result == 'success' && (needs.e2e.result == 'success' || needs.e2e.result == 'skipped')` (con `always()` davanti, altrimenti un `needs` saltato salta anche `publish`); `report-failure.needs` += `e2e`.
- [ ] **Step 2:** actionlint; `gh workflow run docker-publish.yml -f rebuild_latest=true` → e2e verde → publish gira. Rebuild per sole patch di sistema (schedule con causa apt) → e2e `skipped`, publish gira lo stesso (Review Focus 4).
- [ ] **Step 3:** commit, PR E, merge; rimuovere il worktree `../gil-wt-e2e-impl` e il branch locale.

---

## Stato al 2026-11-22

| PR | Contenuto | Stato |
|---|---|---|
| #201 | A — `build-test` solo in coda + segnaposto | mergiata |
| #202 | B — finto GovPay, integrazione in coda (`integration-tests` required) | mergiata |
| #205 | fix wizard setup (CSRF su installazione nuova, trovato dall'e2e) | mergiata |
| #206 | C — e2e Playwright flussi 1/2/3/5, job `e2e` report-only, gamba toolchain playwright | mergiata |
| #209 | fix open redirect `return_to` login/logout (trovato dall'e2e OIDC) | mergiata |
| #210 | D — flusso 4 OIDC | mergiata |
| #211 | `AutostartImporterTest` salta pulito senza DB | mergiata |
| #212 | egress job e2e: `production.cloudfront.docker.com` (primo giro reale, run 44998465924) | in coda |

Deviazioni:
- Task 3: niente `JobsAgainstFakeTest`.
- Task 9: IdP finto PHP (`tests/Fakes/Oidc`) al posto di mock-oauth2-server: il frontoffice usa path fissi `/OIDC/authorization|token|userinfo`, mock-oauth2-server espone `/authorize`.
- Task 6: tipologia `TEST_SERVIZIO` seminata in DB da `run.sh` (sincronizzazione entrate da GovPay non coperta dal finto).
- Fix emersi: cache config frontoffice incompleta (in #206), `FRONTOFFICE_CONFIG_CACHE_TTL` (in #210).

Da fare:
- Primo giro reale del job `e2e` (run 44998465924, 2026-11-22): fallito al pull per egress bloccato → #212. Nuovo giro dopo il merge (`rebuild_latest` o prossimo tag `v*`): possibili altri host (npm, MCR); aggiungerli dall'output harden-runner.
- Task 10 (PR E) non prima del 2026-12-05.
