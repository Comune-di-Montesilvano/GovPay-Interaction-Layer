# Pipeline professionale — piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** registro migrazioni con backup e blocco su errore, verifica automatica post-deploy, test di upgrade in CI,
firma obbligatoria, axe + ZAP, changelog/copertura/carico.

**Architecture:** logica in classi PHP testabili (`app/Database`, `app/Services/Deploy`) con script `backoffice/bin/*`
sottili; compose con un servizio one-shot nuovo; `e2e/run.sh` esteso con modalità (upgrade, zap, load) invece di script
paralleli; job CI nuovi in `docker-publish.yml` come l'e2e.

**Tech Stack:** PHP 8.5, Slim 4, MariaDB 11, Docker Compose (compat Podman), Playwright TS, @axe-core/playwright,
OWASP ZAP baseline, k6, diff-cover, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-11-24-pipeline-professionale-design.md`

## Global Constraints

- Compose compatibile con Podman e con le immagini dell'ultima release (`compose-compat.yml` verde).
- Hardening servizi: `*hardening` (no-new-privileges, `cap_drop: ALL`), `user: www-data` dove PHP, `read_only: true`, tmpfs `mode=1777`.
- harden-runner `egress-policy: block`: ogni host nuovo in allowlist del job che lo usa.
- Action per SHA (`# vX`), immagini per digest, pacchetti a versione esatta (≥ 14 giorni dal rilascio).
- Fixture sintetiche; mai segreti/PII/URL con token in log, artifact, Sentry.
- `Logger::getInstance()->...`, mai statico. `unset($ch)`, mai `curl_close()`.
- PHPStan livello 3 pulito; semgrep pulito.
- Commit Conventional, trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; PR chiusa da `🤖 Generated with [Claude Code](https://claude.com/claude-code)`; una PR per parte, via merge queue (`gh pr merge N --auto`).
- Ogni parte aggiorna CLAUDE.md (sezione pertinente).

## Review Focus

1. DB esistente con migrazioni che falliscono alla riesecuzione (stato reale di prod) → il primo avvio col registro NON deve bloccare (bootstrap tollerante). Test: Task 2 `testBootstrapTolleraErroriERegistraTutto`.
2. Disco `/backups` non scrivibile o pieno → nessuna migrazione, messaggio con la via d'uscita (`MIGRATION_BACKUP=skip`). Test: Task 2 `testBackupFallitoBloccaMigrazioni`.
3. `APP_VERSION=latest` (default compose) → post-deploy-check non deve fallire sul confronto versione. Test: Task 4 `testAppVersionLatestNonConfronta`.
4. Compose nuovo + immagini 1.11.3 (senza `post-deploy-check.php`) → guardia, exit 0, deploy non rotto. Test: Task 5 smoke `auto` con immagini vecchie (compose-compat).
5. `SSL=on` (HTTPS interno self-signed su :80) → post-deploy-check raggiunge backoffice/frontoffice. Test: Task 4 `testUrlInterniConSslOn`.

---

## Parte B1 — PR "feat(db): registro migrazioni, backup pre-migrazione, stop su errore"

### Task 1: `DatabaseDumper` estratto da `BackupController`

**Files:**
- Create: `app/Database/DatabaseDumper.php`
- Modify: `backoffice/src/Controllers/BackupController.php` (`runMysqldump()` delega)
- Test: `tests/Database/DatabaseDumperTest.php` (gruppo `db`)

**Interfaces:**
- Produces: `App\Database\DatabaseDumper::dumpToGzip(\PDO $pdo, string $targetFile): void` — stesso formato attuale
  (FK off, `DROP TABLE IF EXISTS`, DDL, `INSERT IGNORE` a batch di 500, tabelle con FK in fondo); lancia `\RuntimeException`
  su errore e rimuove il file parziale.

- [ ] Test `testRoundTripRipristinaTabelleERighe`: crea 2 tabelle sintetiche (una con FK verso l'altra, una riga con NULL e
  apici), dump, drop, ripristino eseguendo il SQL decompresso con `PDO::exec`, assert stesse righe e valori.
- [ ] Test `testFileNonScrivibileLanciaEccezione`: target in directory inesistente → `RuntimeException`, nessun file creato.
- [ ] Run: `DB_HOST=127.0.0.1 DB_PORT=3307 ... vendor/bin/phpunit tests/Database/DatabaseDumperTest.php` → FAIL (classe assente).
- [ ] Implementa spostando il corpo di `runMysqldump()`; `runMysqldump()` resta (stessa firma, `?string`) e chiama il dumper su un file temporaneo, `null` + warning su eccezione.
- [ ] Run test + `tests/` completo → PASS. Commit.

### Task 2: `MigrationRunner` con registro e backup

**Files:**
- Create: `app/Database/MigrationRunner.php`, `app/Database/MigrationResult.php`, `app/Database/MigrationFailedException.php`, `app/Database/PreMigrationBackup.php`
- Modify: `backoffice/bin/run-migrations.php` (diventa un wrapper)
- Test: `tests/Database/MigrationRunnerTest.php`, `tests/Database/PreMigrationBackupTest.php` (gruppo `db`)

**Interfaces:**
- Produces:
  - Tabella `gil_schema_migrations(filename VARCHAR(255) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL)`, creata dal runner (`CREATE TABLE IF NOT EXISTS`), non da un file di migrazione.
  - `new MigrationRunner(\PDO $pdo, string $dir, ?\Closure $backup, \Closure $log)`; `$backup(): string` ritorna il path del dump, `null` = backup disattivato; `$log(string $line): void`.
  - `MigrationRunner::status(): array{pending: list<string>, mismatched: list<string>}` (nomi file, ordine naturale; registro assente = tutti pending).
  - `MigrationRunner::run(): MigrationResult` — readonly `int $applied`, `bool $bootstrap`, `?string $backupFile`, `list<string> $tolerated` (solo bootstrap), `list<string> $mismatched`. Lancia `MigrationFailedException` (messaggio = file + errore DB, o causa backup) in modalità stretta.
  - `PreMigrationBackup::create(\PDO $pdo, string $dir, string $version, int $keep): string` — file `<Ymd-His>-<versione sanificata [A-Za-z0-9._-]>.sql.gz`, crea `$dir` se manca, poi tiene solo i `$keep` più recenti `*.sql.gz` di `$dir`.
- Regole (spec B1): backup solo se `pending` non vuoto **e** `users` esiste con ≥ 1 riga; backup fallito → eccezione prima di qualsiasi file; modalità stretta = stop al primo file fallito, file non registrato; bootstrap = registro assente all'inizio di `run()`.

- [ ] Test (dir migrazioni sintetica in tmp, file `001_a.sql`…):
  - `testDbNuovoApplicaTuttoSenzaBackup` (users vuota → `$backup` mai chiamato, `applied` = n, secondo `run()` → 0).
  - `testSoloFileNuoviConBackup` (registro con 001, aggiungo 002 → backup chiamato una volta, applica solo 002).
  - `testFileFallitoBloccaENonRegistra` (002 SQL invalido, 003 valido → eccezione con "002", 003 non applicato, 002 non registrato).
  - `testBackupFallitoBloccaMigrazioni` (closure lancia → eccezione, nessun file applicato).
  - `testBootstrapTolleraErroriERegistraTutto` (registro assente, users con 1 riga, 001 fallisce → nessuna eccezione, `bootstrap` true, `tolerated` = [001], tutti registrati, backup fatto).
  - `testChecksumDiversoNonRiesegue` (file registrato modificato → non rieseguito, in `mismatched`, warning a log).
  - `PreMigrationBackupTest::testRotazioneTieneGliUltimiN` (4 file preesistenti, keep 3 → restano i 3 più recenti incluso il nuovo) e `testVersioneSanificata` (`1.12.0/../x` → nessun separatore nel nome).
- [ ] Run → FAIL. Implementa. Run → PASS.
- [ ] `run-migrations.php`: legge env `MIGRATION_BACKUP` (`skip` → `$backup = null` + riga "backup pre-migrazione DISATTIVATO (MIGRATION_BACKUP=skip)"), `MIGRATION_BACKUP_DIR` (default `/backups/pre-migrazione`), `MIGRATION_BACKUP_KEEP` (default 3), versione da `Config::getVersion()`. Exit 0 / 1. Su errore stampa su stderr causa + "Per tornare indietro: APP_VERSION precedente; dump in <dir>". Righe: `migrazioni: N da applicare`, `backup pre-migrazione: <file> (<KB> KB)`, `- <file>: OK|ERRORE <msg>`, bootstrap `registro migrazioni creato: N file, K errori tollerati: <lista>`.
- [ ] Commit.

### Task 3: avvio backoffice bloccante + test di integrazione

**Files:**
- Modify: `docker-setup.sh` (blocco migrazioni ~155-205), `tests/Integration/MigrationsFromScratchTest.php`, `.env.example`, `docker-compose.yml` (env backoffice: `MIGRATION_BACKUP`, `MIGRATION_BACKUP_KEEP` passate se valorizzate), CLAUDE.md (sezione "Migrazioni DB")

- [ ] `MigrationsFromScratchTest`: dopo il primo giro assert `gil_schema_migrations` contiene tutti i file di `migrations/`; secondo giro di `run-migrations.php` → exit 0, output contiene `migrazioni: 0 da applicare`. Run → FAIL prima, PASS dopo Task 2 + questo.
- [ ] `docker-setup.sh`, condizione `APP_SUITE = backoffice` (oggi `!= frontoffice`: il worker parte dopo backoffice healthy e non deve migrare né fare backup): sostituisci i due loop (mysql client / PHP inline) con `php /var/www/html/bin/run-migrations.php || { echo "❌ Migrazioni fallite: backoffice NON avviato (vedi sopra)" >&2; exit 1; }`. Il DB è atteso già dalla sezione first-run; se `first_run` ha saltato per timeout, il runner fallisce la connessione → exit 1 (il container riparte per restart policy: corretto).
- [ ] Locale: build immagini, `scripts/smoke-stack.sh` → PASS; log backoffice contiene `registro migrazioni creato` al primo avvio su volume nuovo? (DB nuovo → users vuota → nessun backup, ma bootstrap true). Secondo `restart backoffice` → `migrazioni: 0 da applicare`.
- [ ] CLAUDE.md: registro, regola stretta, bootstrap, backup, env, via d'uscita; correggere "Nessun runner automatico".
- [ ] Commit, PR, coda.
- [ ] **Gate prima del rilascio che contiene B1** (utente): grep nei log backoffice di entrambi gli ambienti di `Fallito l'import` / `Importazione fallita`; se presenti, sistemare quel file prima del tag. Il bootstrap li tollera comunque, ma restano da capire.

## Parte B2 — PR "feat(deploy): post-deploy-check"

### Task 4: endpoint di verifica + `DeployCheck`

**Files:**
- Create: `app/Services/Deploy/DeployCheck.php`, `app/Services/Deploy/CheckResult.php`, `backoffice/src/Controllers/DeployCheckController.php`
- Modify: `backoffice/src/routes/web.php` (gruppo `/api/internal` con `BearerTokenMiddleware(false)`), `backoffice/src/Middleware/CsrfMiddleware.php` (`'/api/internal/*'` in `EXCLUDED_PATHS` con motivo), `frontoffice/public/index.php` (route `/health/deploy`)
- Test: `tests/Services/Deploy/DeployCheckTest.php`, `tests/Security/CsrfMiddlewareTest.php` (se esiste: caso nuovo)

**Interfaces:**
- Consumes: `MigrationRunner::status()` (Task 2), `Config::getVersionInfo()`.
- Produces:
  - `GET /api/internal/deploy-check` (Bearer) → 200 `{"success":true,"version":"…","commit":"…","db":true,"migrations":{"pending":[],"mismatched":[]}}`; DB giù → `db:false` (non 500).
  - frontoffice `GET /health/deploy` (Bearer `MASTER_TOKEN`, `hash_equals`; senza/errato → 401 senza corpo utile) → `{"version":"…","commit":"…","backoffice":true|false}`; `backoffice` = esito di una `frontoffice_backoffice_api('GET', '/api/frontoffice/config')`.
  - `new DeployCheck(string $backofficeUrl, string $frontofficeUrl, string $token, string $appVersion, \Closure $httpGet)`; `$httpGet(string $url, string $token): array{status:int, body:string}`; `run(): list<CheckResult>`; `CheckResult` readonly `string $name, bool $ok, string $detail`.
  - Controlli, in ordine: `backoffice` (200 + JSON), `db`, `migrazioni` (pending vuoto = ok; mismatched → ok ma detail di warning), `frontoffice`, `frontoffice→backoffice`, `versioni` (bo = fo; se `$appVersion` è semver `^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$` anche = `APP_VERSION`, con `v` iniziale tolto da entrambi).
  - `DeployCheck::internalUrl(string $service, string $ssl): string` — `SSL=on` → `https://<service>:80`, altrimenti `http://<service>`.

- [ ] Test con `$httpGet` finto: `testTuttoOk`, `testMigrazioniPendentiFalliscono`, `testVersioniDiverseFalliscono`, `testAppVersionLatestNonConfronta`, `testBackofficeIrraggiungibile` (status 0 → un solo risultato ko "backoffice", gli altri controlli dipendenti saltati con detail "non verificato"), `testUrlInterniConSslOn`, `testDetailSenzaToken` (nessun `CheckResult::detail` contiene il token).
- [ ] Run → FAIL. Implementa. Run → PASS. PHPStan. Commit.

### Task 5: servizio compose `post-deploy-check`

**Files:**
- Create: `backoffice/bin/post-deploy-check.php`
- Modify: `docker-compose.yml`, `scripts/smoke-stack.sh`, CLAUDE.md (compose/hardening + CI)

**Interfaces:**
- Consumes: `DeployCheck` (Task 4), `SentryReporter::init('post-deploy-check')`.
- Produces: servizio `post-deploy-check` — immagine backoffice `${APP_VERSION:-latest}`, `*hardening`, `user: www-data`, `read_only`, tmpfs `/tmp`, `restart: "no"`, `depends_on: backoffice/frontoffice: service_healthy`, env `APP_VERSION`, `MASTER_TOKEN`, `SSL`, `SENTRY_DSN`, `SENTRY_ENVIRONMENT`; `entrypoint: ["/bin/sh","-c"]` con guardia: script assente → `post-deploy-check: immagine senza bin/post-deploy-check.php, nessuna verifica` ed exit 0; altrimenti `exec php bin/post-deploy-check.php`.
- Output: una riga per controllo `OK|ERRORE <nome>: <detail>`, finale `post-deploy-check: OK (<versione>)` o `post-deploy-check: FALLITO (<n> errori)`; exit 0/1; su fallimento un `captureMessage` Sentry con i nomi dei controlli falliti (mai detail con URL/corpi).

- [ ] `smoke-stack.sh`: dopo stack healthy attendi l'uscita del container `post-deploy-check` (≤ 120 s); immagine nuova → exit 0 e riga `post-deploy-check: OK`; modalità `auto` con immagine senza script → riga di guardia, exit 0. Stampa i log del servizio se fallisce.
- [ ] Locale: smoke con immagini del branch → PASS; smoke `auto` con immagini 1.11.3 → guardia → PASS; prova negativa: `docker compose exec db` + `DELETE FROM gil_schema_migrations LIMIT 1` e `up post-deploy-check` → exit 1, riga `ERRORE migrazioni`.
- [ ] CLAUDE.md. Commit, PR, coda.

## Parte A — PR "ci(upgrade): test di aggiornamento dalla release precedente"

### Task 6: modalità upgrade in `e2e/run.sh` + spec post-upgrade + job CI

**Files:**
- Modify: `e2e/run.sh`, `e2e/playwright.config.ts`, `e2e/tests/01-setup.spec.ts`, `e2e/tests/02-operatore.spec.ts` (tag `@pre-upgrade` nel titolo), `.github/workflows/docker-publish.yml`, CLAUDE.md, piano/spec stato
- Create: `e2e/tests/08-upgrade.spec.ts`, `scripts/ci/previous-release.sh`

**Interfaces:**
- Consumes: `post-deploy-check` (Task 5), backup in `/backups/pre-migrazione` (Task 2), causale/ID della pendenza creata in 02 (esportarli da `e2e/tests/support/` come costante condivisa).
- Produces:
  - `E2E_UPGRADE_FROM_FILE=<override immagini vecchie>`: con questa variabile `run.sh` esegue: fase 1 stack con le immagini vecchie, seed, Playwright `--grep @pre-upgrade`; snapshot `COUNT(*)` di `users`, `settings`, `entrate_tipologie`, `gil_jobs`; fase 2 `up -d db backoffice frontoffice` con `E2E_IMAGES_FILE` (stesso progetto, volumi tenuti, finti non ricreati), attesa healthy, `up post-deploy-check` exit 0, almeno un `*.sql.gz` in `/backups/pre-migrazione`, restore del più recente in DB `restore_check` (root, sullo stesso server) con stessi conteggi della snapshot, poi Playwright con `E2E_UPGRADE=1` sul solo `08-upgrade`. Ogni passo una riga `OK  …` o `fail`.
  - `playwright.config.ts`: `08-upgrade.spec.ts` ignorato (`testIgnore`) se `E2E_UPGRADE` ≠ `1`; `run.sh` passa la variabile al servizio `playwright`.
  - `08-upgrade.spec.ts` (`@upgrade`): login operatore con la password di fase 1, la pendenza di 02 è in lista e il dettaglio si apre; cittadino paga l'avviso di quella pendenza (riusa helper di 03); login OIDC (riusa 04).
  - `scripts/ci/previous-release.sh <tag-corrente|"">` → stampa la versione senza `v` della release non pre-release più recente diversa dal tag corrente (`gh release list --exclude-pre-releases --exclude-drafts --json tagName --limit 20`); nessuna → exit 1 con `::error::`.
  - Job `upgrade` in `docker-publish.yml`: `needs: [setup, build-php, build-services]`, stessa `if` di `e2e`, stessa allowlist + `api.github.com:443`, `GH_TOKEN`, genera due override (vecchie per tag GHCR `:<ver>`, nuove per digest come `e2e`), `E2E_PROJECT=gil-upgrade`. `publish.needs` += `upgrade` con `(needs.upgrade.result == 'success' || needs.upgrade.result == 'skipped')`; `report-failure.needs` += `upgrade`. Summary: release di partenza + esito passi.

- [ ] Locale: override vecchie = 1.11.3, nuove = immagini del branch (B1+B2 inclusi) → `E2E_UPGRADE_FROM_FILE=… E2E_IMAGES_FILE=… bash e2e/run.sh` verde; log contiene `registro migrazioni creato` (bootstrap) e `backup pre-migrazione:`.
- [ ] Prova negativa locale: aggiungi una migrazione SQL rotta alle immagini nuove → run fallisce su "backoffice non healthy" con log migrazione. Rimuovila.
- [ ] e2e normale (senza upgrade) ancora 8/8 (08 ignorato).
- [ ] actionlint. Commit, PR, coda. Primo giro reale al tag successivo.

## Parte B3 — PR "feat(compose): verifica firme attiva di default"

**Gate: conferma dell'utente che entrambi gli host di produzione raggiungono `ghcr.io`, `tuf-repo-cdn.sigstore.dev`, `rekor.sigstore.dev`. Senza, non iniziare.**

### Task 7: `VERIFY_IMAGES` default `true`

**Files:**
- Modify: `docker-compose.yml` (`${VERIFY_IMAGES:-true}`, commento), `.env.example`, `ci.yml` (.env generato: `VERIFY_IMAGES=false`), `scripts/smoke-stack.sh` (`VERIFY_IMAGES=false`: digest non ancora taggati), `e2e/run.sh` (`VERIFY_IMAGES=false`), `.github/workflows/compose-compat.yml` (`VERIFY_IMAGES=true`: verifica reale delle firme dell'ultima release + allowlist Sigstore), CLAUDE.md

- [ ] Messaggio di `verify-images` in errore: se l'output di cosign contiene `no such host`, `dial tcp`, `timeout` o `connection refused` → `Verifica firme: rete verso Sigstore/GHCR non raggiungibile`; altrimenti `Verifica firme: firma assente o non valida per <APP_VERSION>`; entrambe seguite da `Emergenza: VERIFY_IMAGES=false nello stack, poi riattivare`.
- [ ] compose-compat verde (verifica vera con `VERIFY_IMAGES=true`); smoke ed e2e verdi.
- [ ] Note di rilascio della versione: riga "Verifica firme attiva di default" con host richiesti. Commit, PR, coda.

## Parte C — PR "test(e2e): accessibilità axe e ZAP baseline (report-only)"

### Task 8: axe + ZAP

**Files:**
- Modify: `e2e/package.json`/lock (`@axe-core/playwright` versione esatta), `e2e/docker-compose.e2e.yml` (servizio `zap`, profilo `zap`, immagine `ghcr.io/zaproxy/zaproxy` per digest), `e2e/run.sh` (`E2E_ZAP=1`), `.github/workflows/docker-publish.yml` (job `e2e`: `E2E_ZAP=1`, summary, artifact), CLAUDE.md
- Create: `e2e/tests/07-accessibilita.spec.ts`, `e2e/zap-rules.tsv`

**Interfaces:**
- `07-accessibilita`: per pagina un test che esegue `new AxeBuilder({ page }).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze()`, allega il JSON (`testInfo.attach`) e aggiunge righe a `test-results/axe-summary.md` (pagina, n. violazioni per impatto, id regole). **Nessuna asserzione sulle violazioni** (report-only); fallisce solo se la pagina non si carica. Pagine: frontoffice `/`, `/guida`, paga avviso, `/pagamento-spontaneo`, carrello, `/profile` dopo OIDC; backoffice `/login`, dashboard, lista pendenze, nuova pendenza (login con helper di 02).
- ZAP: dopo Playwright, se `E2E_ZAP=1`, `zap-baseline.py -t http://frontoffice/ ` e poi `-t http://backoffice/login`, `-I` (non fallire su warning), `-c /zap/wrk/zap-rules.tsv`, `-z "-silent"` (nessuna richiesta verso internet), report `e2e/zap-report/{frontoffice,backoffice}.{html,json}`. L'esito di ZAP non cambia l'exit code di `run.sh`. Ogni riga di `zap-rules.tsv` ha un commento con il motivo.
- Job `e2e`: summary con tabella axe e conteggio alert ZAP per rischio; artifact `axe-zap-<run_id>` 7 giorni.

- [ ] Locale `E2E_ZAP=1 … run.sh` → 8 + pagine axe passano, `axe-summary.md` e i report ZAP presenti; nessuna richiesta esterna dal container ZAP (harden-runner in CI lo conferma: zero host bloccati nuovi oltre a quelli in allowlist).
- [ ] Commit, PR, coda. Riportare all'utente la baseline (violazioni axe per impatto, alert ZAP) per decidere correzioni e passaggio a bloccante.

## Parte D — PR "chore(ci): changelog dalle release, copertura sulle righe modificate, test di carico"

### Task 9: changelog e note di rilascio

**Files:**
- Delete: `.github/CHANGELOG.md`
- Create: `.github/release.yml`, `.github/workflows/pr-labels.yml`
- Modify: README (link "Novità: GitHub Releases"), CLAUDE.md (sezione Release)

- [ ] `release.yml`: categorie in ordine `Sicurezza` [security], `Funzionalità` [enhancement], `Correzioni` [bug], `Dipendenze` [dependencies], `Altro` [`*`]; nessun autore escluso.
- [ ] `pr-labels.yml`: `pull_request_target` `[opened, edited]`, **nessun checkout**, `permissions: pull-requests: write`, harden-runner block + `api.github.com:443`; dal titolo: `^feat` → enhancement, `^fix` → bug, titolo con `security` o tipo `sec`/`fix(security)` → security in più; `gh pr edit --add-label`. Etichette create una volta con `gh label create` (se assenti).
- [ ] Verifica: PR di prova con titolo `feat: …` riceve `enhancement`. `gh api repos/{o}/{r}/releases/generate-notes -f tag_name=v-test -f target_commitish=main` mostra le categorie. Commit.

### Task 10: copertura sulle righe modificate

**Files:**
- Modify: `.github/workflows/ci.yml` (job `build-test`), CLAUDE.md (CI)
- Create: `scripts/ci/requirements-diffcover.txt` (`diff-cover==<versione esatta>` con `--hash`)

- [ ] Solo `merge_group`: checkout con `fetch-depth: 0` (o fetch esplicito di `merge_group.base_sha`), `pip install --require-hashes -r scripts/ci/requirements-diffcover.txt`, `diff-cover coverage/clover.xml --compare-branch=${{ github.event.merge_group.base_sha }} --fail-under=70 --markdown-report coverage/diff.md` limitato a `app/`, `backoffice/src/`, `backoffice/bin/`, `scripts/` (opzione include/exclude della versione scelta); `coverage/diff.md` nel summary. Nessuna riga PHP modificata → passa. Allowlist harden-runner: `pypi.org:443`, `files.pythonhosted.org:443`.
- [ ] Limite noto documentato in CLAUDE.md: la copertura viene solo da `build-test` (gruppo `integration` escluso) → codice esercitato solo da test di integrazione conta come scoperto.
- [ ] Verifica in coda su questa stessa PR (righe PHP: nessuna → passa) e con una PR di prova con una funzione non testata → fallisce. Commit.

### Task 11: test di carico k6

**Files:**
- Create: `load/scenari.js`, `.github/workflows/load-test.yml`
- Modify: `e2e/docker-compose.e2e.yml` (servizio `k6`, profilo `load`, `grafana/k6` per digest), `e2e/run.sh` (`E2E_LOAD=1`), CLAUDE.md

- [ ] `scenari.js`: scenari frontoffice `/`, ricerca avviso (numero avviso della pendenza di 02), `/pagamento-spontaneo` GET, backoffice `/login` GET; `thresholds`: `http_req_failed: ['rate<0.01']`, `http_req_duration: ['p(95)<800']` (iniziali, da tarare); VU e durata da env `K6_VUS` (default 10), `K6_DURATION` (default `2m`).
- [ ] `E2E_LOAD=1`: dopo Playwright (`--grep @pre-upgrade` basta per avere dati) esegue k6; exit code di k6 = exit di `run.sh` in questa modalità; summary k6 in `e2e/load-report/summary.json`.
- [ ] `load-test.yml`: solo `workflow_dispatch` (input `version` default `latest`, `vus`, `duration`), override immagini per tag GHCR, harden-runner come e2e, summary con p95/errori. Mai URL esterni: il target è solo lo stack.
- [ ] Run manuale una volta, riportare numeri. Commit, PR, coda.

---

## Ordine di esecuzione

B1 (Task 1-3) → B2 (4-5) → A (6) → [gate host] B3 (7) → C (8) → D (9-11). Un rilascio (bump patch o minor) dopo A, con il
gate B1 sui log di produzione; il primo tag dopo A fa girare `upgrade` in CI su 1.11.3 → nuova.
