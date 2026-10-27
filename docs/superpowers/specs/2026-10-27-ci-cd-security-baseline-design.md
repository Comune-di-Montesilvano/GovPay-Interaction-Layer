# Allineamento alla baseline di sicurezza CI/CD (modello ComunicaPA) — design

Data: 2026-10-27 · Rev. 2 (dopo review indipendente)

## Contesto

ComunicaPA documenta una baseline di sicurezza della supply chain
([`docs/ci-cd-security-baseline.md`](https://github.com/Comune-di-Montesilvano/ComunicaPA/blob/main/docs/ci-cd-security-baseline.md))
con checklist per la gap analysis di altri repo. GIL ha già una parte consistente (action per
SHA, Trivy bloccante sulle immagini, Trivy fs, semgrep, composer audit, dependabot con cooldown
e auto-merge via GitHub App, `concurrency`/`timeout-minutes`, rebuild settimanale di `latest`).
Questo documento misura i gap (stato rilevato il 2026-10-27 via `gh api` e lettura dei
workflow) e definisce come chiuderli, in fasi indipendenti.

Principi adottati così come sono: nessuna fiducia implicita, fallire chiusi, un solo cancello
senza bypass, tutto fissato per SHA/digest/versione, privilegio minimo, ritardo deliberato sulle
novità, provare ciò che si pubblica, costo proporzionato. Quest'ultimo pesa di più qui: team di
1-2 maintainer, quindi si preferisce il meccanismo più semplice che chiude il gap, salvo dove
ComunicaPA ha già una soluzione collaudata da riusare così com'è (firma, harden-runner).

## Gap analysis

Legenda: ✅ fatto · ⚠️ parziale · ❌ mancante · n.a. non applicabile. (★) = massimo valore per lo sforzo.

### Repository

| Voce | Stato | Rilevato |
|---|---|---|
| ★ `GITHUB_TOKEN` default read, non approva PR | ✅ | `default_workflow_permissions: read`, `can_approve_pull_request_reviews: false` |
| Secret scanning + push protection | ❌ | entrambi `disabled` (repo pubblico: gratuiti) |
| Private vulnerability reporting + `SECURITY.md` | ⚠️ | `SECURITY.md` presente (solo email), PVR `enabled: false` |
| Dependabot security updates | ✅ | `enabled` |
| `allow_auto_merge` | ✅ | `true` |
| CodeQL / SAST su PR e main | ⚠️ | semgrep su PR (diff-aware, bloccante) e main; CodeQL `not-configured`. CodeQL non supporta PHP ma copre `actions` e `javascript` (presenti) |
| `sha_pinning_required` + action consentite in elenco | ❌ | `allowed_actions: all`, `sha_pinning_required: false` |
| Branch cancellati dopo merge, solo squash | ❌ | `delete_branch_on_merge: false` (21 branch orfani oltre `main`/`dev`), merge/rebase/squash tutti abilitati |
| `CODEOWNERS`, template PR | ❌ | assenti |

### Ruleset / protezione

| Voce | Stato | Rilevato |
|---|---|---|
| ★ main: PR obbligatoria, no force-push/delete, nessun bypass | ⚠️ | ruleset "Main Branch": `deletion`, `non_fast_forward`, bypass vuoto — **manca regola `pull_request`** (push diretto su main possibile se i check passano) |
| ★ Required check test + sicurezza | ⚠️ | solo `build-test`, `trivy-fs`, **senza `integration_id`** (qualsiasi app/utente con `statuses: write` può postarli verdi); `semgrep` non richiesto |
| Merge queue + trigger `merge_group` | ❌ | `strict: true` al posto della coda; nessun workflow ascolta `merge_group` |
| Regola code scanning | ❌ | assente |
| Tag release protetti | ❌ | nessun ruleset su `refs/tags/v*` — chiunque con write crea/sposta un tag e innesca `docker-publish.yml` |
| Review seconda persona | ❌ | accettato come gap residuo (vedi sotto) |
| Branch `dev` | ❌ | nessuna protezione, pubblica `:dev` via `workflow_run`, ma fermo dal 2026-05-07 (vedi D1) |

### Workflow

| Voce | Stato | Rilevato |
|---|---|---|
| ★ `permissions` minimi in testa, scritture nei job | ⚠️ | `docker-publish.yml` dichiara `packages`/`security-events: write` a livello workflow; `publiccode-validate.yml` `pull-requests: write` in testa; `semgrep.yml` `security-events: write` in testa; `claude.yml` senza blocco top-level |
| ★ Action per SHA | ⚠️ | tutte le `uses:` per SHA ✅ (nested di `trivy-action`, `claude-code-action` già per SHA); **`container: image: semgrep/semgrep` senza tag né digest**; `publiccode-parser-action` è una Docker action con `FROM italia/publiccode-parser-go:v5.4.3` senza digest (fuori dal nostro controllo) |
| `persist-credentials: false` | ❌ | nessun checkout lo imposta |
| `pull_request_target`, input via `env:` | ✅ | nessun `pull_request_target`; input passati via `env:` |
| `harden-runner` | ❌ | assente |
| `timeout-minutes` + `concurrency` | ✅ | ovunque, `cancel-in-progress: false` su publish |
| Niente run duplicati | ⚠️ | `ci.yml` e `semgrep.yml` girano su push `main` **e** su PR — doppio run per ogni merge (ma il push su main serve all'analisi code scanning, vedi D2) |

### Dipendenze

| Voce | Stato | Rilevato |
|---|---|---|
| ★ Lockfile congelato | ⚠️ | `npm ci` nel Dockerfile ✅; `composer install` usa `composer.lock` ✅ ma su lock disallineato a `composer.json` emette solo un warning |
| ★ Nessuna risoluzione dipendenze a runtime | ❌ | `docker-setup.sh:108-129` esegue `composer update --no-dev` all'avvio se manca `composer.lock` o se `installed.json` è più vecchio di `composer.json`; `Dockerfile:73` copia composer nell'immagine runtime. Aggira lock, audit e Trivy e scarica da rete in produzione |
| ★ Audit bloccante + dependency-review su PR | ⚠️ | `composer audit --no-dev` bloccante ✅; Trivy fs bloccante copre già `composer.lock` e `package-lock.json` ✅; **nessuna `dependency-review-action`** |
| Dependabot tutti gli ecosistemi + cooldown | ⚠️ | actions, composer, docker (×2), npm con cooldown ✅; manca `docker-compose`; immagine `semgrep` non tracciata |
| Immagini base per digest, tool a versione fissa | ⚠️ | Trivy pinnato + check settimanale ✅; solo per tag: `FROM node:lts-trixie-slim`, `FROM composer:2`, `COPY --from=composer:2` (`Dockerfile:73`), `FROM php:8.5-apache-trixie`, `FROM mariadb:12.3`; composer di `setup-php` in `ci.yml` senza versione |
| Scansione segreti/misconfig | ✅ | Trivy fs `vuln,secret,misconfig` bloccante + semgrep `p/secrets` |

### Release

| Voce | Stato | Rilevato |
|---|---|---|
| ★ Build in CI | ✅ | `docker-publish.yml` |
| ★ Scansione artefatto bloccante | ⚠️ | Trivy bloccante per digest ✅, **ma dopo il push con i tag**: su tag `v*` l'immagine è già `:vX.Y.Z`/`:X.Y`/`:latest` quando Trivy fallisce; nel rebuild schedulato `latest-YYYYMMDD` è pushato prima dello scan (solo `latest` promosso dopo) |
| Origine della release verificata | ❌ | un tag `v*` può puntare a un commit fuori da `main` (il commento in `docker-publish.yml` assume il contrario, nulla lo verifica) e pubblica `latest`; `workflow_dispatch` da qualsiasi branch pubblica `:<branch>` con `packages: write` senza CI |
| Build immagine prima del merge | ❌ | immagini costruite solo su tag/schedule: PR dependabot docker/npm vanno in auto-merge senza build né Trivy image |
| Push per digest, tag a fine pipeline | ❌ | vedi sopra |
| Firma + provenance + SBOM | ❌ | `provenance: false`, `sbom: false`, nessuna firma |
| Smoke test stack prima del tag | ❌ | CI avvia solo `db` per PHPUnit; mai lo stack con le immagini finali |
| Pulizia artefatti orfani | ❌ | nessun cleanup GHCR |

### Deploy e runtime

| Voce | Stato | Rilevato |
|---|---|---|
| Verifica firme prima dell'avvio | ❌ | dipende dalla firma |
| Rollback documentato | ⚠️ | `latest-YYYYMMDD` esiste solo per i rebuild schedulati; migrazioni DB non reversibili; nulla documentato |
| ★ Non-root, `cap_drop: ALL`, `no-new-privileges` | ❌ | nessuna opzione in `docker-compose.yml`; Apache master root (DS-0002 documentato in `.trivyignore`) |
| Filesystem read-only + tmpfs/volumi | ❌ | assente |
| Segreti obbligatori con errore esplicito | ⚠️ | `${DB_ROOT_PASSWORD}`, `${MASTER_TOKEN}` ecc. senza `:?` — variabile mancante = stringa vuota silenziosa (eccetto `Crypto::getKey()`) |
| Dipendenze tra servizi | ⚠️ | `db` senza healthcheck, backoffice non dipende da db: `docker-setup.sh:194-216` aspetta 30s e poi salta la creazione tabelle |

### Governance

| Voce | Stato | Rilevato |
|---|---|---|
| `CONTRIBUTING.md` con policy test, template PR | ⚠️ | `CONTRIBUTING.md` generico, senza policy test; template PR assente |
| Scorecard | ❌ | assente |
| Controllo periodico alert aperti | ❌ | semgrep/Trivy caricano SARIF, nessuno controlla lo stock di alert su main |
| Test property-based su input non fidato | ❌ | candidati: validazione CF/importo frontoffice, token HMAC link pubblici, parsing CSV pendenze massive |
| Procedura break-glass | ❌ | non serve oggi (bypass possibile di fatto), diventa necessaria con PR obbligatoria + nessun bypass |

## Decisioni di design

### D1 — Flusso branch: solo `main`, `dev` dismesso

Rilevato: `dev` è fermo al 2026-05-07, nessun commit avanti a `main`; le ultime 15+ PR
(#96–#110) sono tutte su `main`. Il flusso reale è già quello di ComunicaPA (feature branch → PR
→ main), CLAUDE.md è disallineato. Quindi, **in un'unica PR** (le parti dipendono l'una dall'altra):
- eliminare il ramo `workflow_run` su `dev` di `docker-publish.yml` (codice morto);
- `cache-from` che puntano a `:dev` → sostituire con le cache registry `*-buildcache:main`/`php-common-cache:main` già esistenti;
- `ci.yml`/`semgrep.yml`: togliere `dev` dai trigger;
- aggiornare CLAUDE.md ("sviluppo attivo su `dev`", sezione `docker-publish.yml`);
- dopo il merge: cancellare il branch `dev`; tag immagine `:dev` su GHCR cancellato (verificare prima che nessuna istanza usi `APP_VERSION=dev`).

### D2 — Merge queue al posto di `strict`

`strict: true` costringe a `update-branch` manuale o via bot (`dependabot-automerge.yml`
job `update-behind`) e rigira la CI per ogni PR. Con la merge queue:
- tutti i workflow con check richiesti aggiungono trigger `merge_group`;
- push su `main` **resta** per i job che caricano SARIF (`trivy-fs`, semgrep): senza un'analisi
  sul branch di default gli alert code scanning su main diventano stantii e il confronto PR/main
  si degrada. Dal push su main si toglie solo `build-test`;
- `dependabot-automerge.yml`: `gh pr merge --auto --squash` mette la PR in coda; job
  `update-behind` + schedule diventano superflui e si rimuovono (verificare su una PR reale che
  il token App possa accodare);
- job `trivy-image (backoffice|frontoffice|db)` in un nuovo `image-check.yml`, su `merge_group`:
  build locale (no push) + Trivy bloccante, richiesto con il nome di matrice esatto. Sulla PR
  non costruisce: il job gira ma salta gli step (un job matrice saltato per `if:` non crea i
  check `nome (valore)` e blocca la PR);
- `dependency-review` gira solo su `pull_request`: sul `merge_group` il job esiste con `if:` a
  livello di job (non matrice) e risulta skipped = verde;
- timeout check della coda: misurare prima una run a cache fredda; partire da 90 min.

### D3 — Release: push per digest, tag solo a fine pipeline

Ristrutturazione di `docker-publish.yml`:

```
setup (verifica origine) → build ×3 (push-by-digest, nessun tag)
      → Trivy bloccante per digest → SBOM CycloneDX (Trivy) → attest-build-provenance + attest-sbom
      → cosign sign keyless (digest)
      → smoke: compose di produzione con i 3 digest → scripts/smoke-stack.sh
      → publish ×3: imagetools create -t <tag> per vX.Y.Z / X.Y / latest / latest-YYYYMMDD
```

- **Verifica origine** in `setup`: su tag, fallire se `git merge-base --is-ancestor "$sha" origin/main`
  è falso; `workflow_dispatch` consentito solo da `refs/heads/main`.
- **Build**: `docker/build-push-action` con
  `outputs: type=image,name=ghcr.io/<org>/govpay-interaction-layer-<img>,push-by-digest=true,name-canonical=true,push=true`
  e senza `tags:`. `provenance: false`/`sbom: false` restano: provenance e SBOM li producono le
  action `attest-*`, così non nascono index con manifest figli non taggati.
- **Firma + attestazioni, come ComunicaPA**:
  - `actions/attest-build-provenance` + `actions/attest-sbom` (attestazioni GitHub, legate al
    digest); SBOM CycloneDX generato con Trivy (già pinnato, `format: cyclonedx`);
  - `sigstore/cosign-installer` + `cosign sign --yes "$IMAGE@$DIGEST"` (keyless, identità OIDC
    del workflow, log Rekor). Serve perché la verifica al deploy gira con la sola immagine
    cosign, senza `gh` né token.
- **Verifica manuale** `scripts/verify-images.sh <tag>` (copia adattata da ComunicaPA): `cosign verify`
  dall'immagine ufficiale `ghcr.io/sigstore/cosign/cosign` fissata per digest (nessuna
  installazione), identità
  `^https://github.com/Comune-di-Montesilvano/GovPay-Interaction-Layer/\.github/workflows/docker-publish\.yml@refs/(tags/v.*|heads/main)$`,
  issuer `https://token.actions.githubusercontent.com`; se `gh` è presente verifica anche
  `gh attestation verify --signer-workflow … --predicate-type` provenance SLSA e CycloneDX.
  Supporta `CONTAINER_CLI=podman`.
- **Publish**: riusa le liste tag di `metadata-action` di oggi, spostate nel job finale; con
  `latest-YYYYMMDD` incluso, nessun tag esiste prima di Trivy + smoke.
- Permessi `packages: write`, `id-token: write`, `attestations: write` solo nei job che servono.

**Smoke test** (`scripts/smoke-stack.sh`): `docker compose -f docker-compose.yml -f docker-compose.smoke.yml`
esplicito (mai l'override locale, che builda dal sorgente). `docker-compose.smoke.yml` fissa
`image: …@sha256:…` per ogni servizio (`APP_VERSION` è una sola variabile per tre immagini).
Prerequisito: healthcheck su `db` e `depends_on: db: condition: service_healthy` sul backoffice
(oggi assenti, anche utile in produzione). Controlli: backoffice healthy (`/health`), frontoffice
200 su `/`, query su `settings` (schema init applicato), nessun `Fatal`/`Parse error` nei log.

**Cleanup GHCR** (`ghcr-cleanup.yml` settimanale): cancellare i digest **non referenziati da
alcun tag o index**, più i loro tag di attestazione `sha256-<digest>.*` (GHCR non ha la referrers
API: le attestazioni sono tag e il filtro "untagged" non le toccherebbe mai). Esclusi
esplicitamente i package `*-cache`/`*-buildcache`/`php-common-cache`. Primo giro in dry-run.

### D4 — Runtime hardening a due passi

**Passo 1** (basso rischio):
- `security_opt: [no-new-privileges:true]` su tutti i servizi;
- `cap_drop: [ALL]` + `cap_add`:
  - backoffice/frontoffice (Apache master root, worker `www-data`, `docker-setup.sh` fa
    `chown`/`chmod` su dir non di root, cron via `docker exec` scrivono in dir setgid 2775):
    `CHOWN, SETUID, SETGID, DAC_OVERRIDE, FOWNER, KILL` (`KILL` serve al master per segnalare i
    worker `www-data`; `NET_BIND_SERVICE` superfluo su Docker ≥ 20.10);
  - db: `CHOWN, SETUID, SETGID, DAC_OVERRIDE, FOWNER`, da verificare con restart su datadir esistente;
- healthcheck `db` + `depends_on` (vedi D3);
- segreti obbligatori con `${VAR:?messaggio}`. **Cambio incompatibile**: aggiornare nella stessa
  PR il `.env` generato in `ci.yml` (compose interpola tutto il file anche con `up db`) e
  segnalarlo nelle release note (istanze con variabili vuote non ripartono).

Test: `docker compose stop`/restart (nessun worker orfano), demoni cron avviabili da UI,
backup/restore, upload logo, volumi copiati da un'istanza reale.

**Passo 2** (rischio medio, spec separata): Apache non-root su porta 8080 (`USER www-data`),
`read_only: true` + tmpfs `/tmp`, `/run/apache2`, `/var/run`, `/var/lock`. Prerequisiti:
composer fuori dal runtime (Fase 3), `upload_tmp_dir` su volume (upload fino a 256M non in RAM),
demoni cron non più root, `docker-setup.sh` senza scritture fuori dai volumi. Test su
Portainer/podman se usati dagli enti.

### D5 — CodeQL dove supportato, semgrep resta per PHP

CodeQL default setup, suite `extended`, linguaggi `actions` + `javascript-typescript`
(asset JS backoffice). Ruleset `code_scanning`: CodeQL alert `errors`, sicurezza `high_or_higher`.
Semgrep resta bloccante diff-aware sulle PR (copre PHP).

### D6 — harden-runner: `block` su tutta la release, `audit` altrove (come ComunicaPA)

`step-security/harden-runner` primo step di ogni job. Stesso schema di ComunicaPA `release.yml`:

- **`docker-publish.yml`: `egress-policy: block` su ogni job** (setup, build, smoke, publish),
  con `allowed-endpoints` per job — è il punto dove un'esfiltrazione o un download malevolo
  finisce dentro un'immagine firmata. Il blocco vale anche dentro i container di `docker build`.
  Endpoint infrastruttura Actions consentiti in automatico dall'agent.
- **Altri workflow: `egress-policy: audit`** (CI, semgrep, check settimanali, automerge).
- Escluso il job semgrep (`container:`, harden-runner non supportato).

Allowlist di partenza (da ComunicaPA, adattata a GIL: niente pypi/alpine, in più packagist):

| Job | Host |
|---|---|
| setup | `github.com`, `api.github.com` |
| build | `github.com`, `api.github.com`, `release-assets.githubusercontent.com`, `objects.githubusercontent.com`, `codeload.github.com` (dist composer), `*.githubapp.com`, `ghcr.io`, `pkg-containers.githubusercontent.com`, `registry-1.docker.io`, `auth.docker.io`, `production.cloudfront.docker.com`, `production.cloudflare.docker.com`, `mirror.gcr.io`, `registry.npmjs.org`, `repo.packagist.org`, `deb.debian.org:80`/`:443`, `check.trivy.dev`, `fulcio.sigstore.dev`, `rekor.sigstore.dev`, `timestamp.sigstore.dev`, `tuf-repo-cdn.sigstore.dev` (tutti `:443` salvo indicato) |
| smoke | `github.com`, `api.github.com`, `ghcr.io`, `pkg-containers.githubusercontent.com`, Docker Hub (`registry-1`, `auth`, CDN cloudfront/cloudflare) |
| publish | `github.com`, `api.github.com`, `ghcr.io`, `pkg-containers.githubusercontent.com` — niente `setup-buildx` (scaricherebbe BuildKit da Docker Hub): `imagetools create` usa il buildx del runner |

Procedura (commento sopra ogni step, come ComunicaPA): release fallita per "blocked" → host nel
log del post-step harden-runner o nella pagina insights della run, aggiungerlo se legittimo.
Prima dell'attivazione: una run `workflow_dispatch` in `audit` per confermare l'elenco dal log
DNS, poi `block`. Il rebuild settimanale schedulato è il primo a fallire su un cambio upstream:
il fallimento lascia `latest` invariato (nessun tag prima di Trivy+smoke), quindi è sicuro.

## Piano per fasi

Ogni fase è una PR (o solo impostazioni) indipendente, in ordine di valore/sforzo. Prima di ogni
modifica a impostazioni/ruleset: snapshot JSON (`gh api …` > file) per poter ripristinare.

### Fase 1 — Impostazioni repo + prerequisito squash

Ordine obbligato:
1. **PR**: `dependabot-automerge.yml` passa da `--merge` a `--squash`; `SECURITY.md` cita la
   segnalazione privata GitHub accanto all'email. Mergiare prima di tutto il resto.
2. Snapshot di repo settings e ruleset "Main Branch".
3. Secret scanning + push protection on.
4. Private vulnerability reporting on.
5. `delete_branch_on_merge: true`; solo squash (`allow_merge_commit/rebase: false`).
6. Cancellazione dei 21 branch orfani (verificato: PR già MERGED o 0 commit avanti a main; `dev` escluso, va con D1).
7. CodeQL default setup (D5).
8. Ruleset `release-tags` su `refs/tags/v*`: `creation`, `update`, `deletion`, `non_fast_forward`, bypass ruolo admin.
9. Ruleset "Main Branch": aggiungere `pull_request` (0 approvazioni, thread risolti, solo squash);
   `integration_id: 15368` (GitHub Actions) su `build-test`/`trivy-fs`.
10. Documentare la procedura break-glass (vedi sotto).

### Fase 2 — Hygiene workflow (1 PR)

1. `permissions: contents: read` in testa ovunque; scritture spostate nei job (`docker-publish.yml`, `semgrep.yml`, `publiccode-validate.yml`, `claude.yml`).
2. `persist-credentials: false` su tutti gli `actions/checkout` (verificare `claude.yml`: la action gestisce credenziali proprie).
3. `semgrep/semgrep` pinnato `:<versione>@sha256:…`; `trivy-version-check.yml` → `toolchain-version-check.yml` controlla anche semgrep.
4. `FROM ...:tag@sha256:…` e `COPY --from=composer:2@sha256:…` nei due Dockerfile (dependabot aggiorna il digest); `tools: composer:2.x.y` in `setup-php`.
5. Dependabot: ecosistema `docker-compose`.
6. `harden-runner` su tutti i job tranne semgrep: `audit` ovunque, `block` con allowlist per job in `docker-publish.yml` (D6, prima una run dispatch in audit).
7. Rimuovere step "Debug struttura client generati" da `ci.yml`.
8. Dismissione `dev` (D1).
9. Dopo il merge (impostazioni): `allowed_actions: selected` con elenco (`actions/*`, `github/*`,
   `docker/*`, `aquasecurity/trivy-action`, `aquasecurity/setup-trivy`, `shivammathur/setup-php`,
   `anthropics/claude-code-action`, `oven-sh/setup-bun`, `dependabot/fetch-metadata`,
   `italia/publiccode-parser-action`, `step-security/harden-runner`) + `sha_pinning_required: true`.
   Una run manuale di ogni workflow per confermare.

### Fase 3 — Dipendenze (1 PR)

1. Job `dependency-review` (solo PR, `fail-on-severity: high`, `fail-on-scopes: runtime, development`); valutare rumore dai `composer.json` dei client generati.
2. `composer validate --no-check-publish --no-check-all` prima di `composer install` in `ci.yml` e nello stage `vendor_builder` (`--strict` scartato: fallirebbe su `"version"` e vincoli `dev-main` dei path repo). Provare in locale prima.
3. Composer fuori dal runtime: togliere `COPY --from=composer:2` dall'immagine runtime e i rami `composer update`/`install` da `docker-setup.sh`; autoload ottimizzato generato a build time. Se `vendor` o lock mancano a runtime → errore esplicito, non download.
4. Required check: `build-test`, `trivy-fs`, `semgrep`, `dependency-review` (con `integration_id`).

`npm audit` scartato: ridondante con Trivy fs (che scansiona già `package-lock.json`) + dependency-review.

### Fase 4 — Merge queue (1 PR + impostazioni)

D2: trigger `merge_group`, `build-test` tolto dal push su main, `image-check.yml`,
`dependabot-automerge.yml` semplificato, ruleset main con `merge_queue` (squash, ALLGREEN,
timeout misurato, base 90 min), `strict` off, required check aggiunti `trivy-image (…)` ×3.
Regola `code_scanning` attivata qui (CodeQL ha già storico da Fase 1).
Fino all'attivazione: escludere l'ecosistema docker dall'auto-merge (nessuna build pre-merge).

### Fase 5 — Release firmata e provata (1-2 PR)

D3 completo: verifica origine, push-by-digest, Trivy, attestazioni + firma cosign, smoke,
publish, `scripts/verify-images.sh`, `ghcr-cleanup.yml` (rimuove anche i tag firma
`sha256-<digest>.sig`/`.att` dei digest orfani).

Rollback documentato nel README: rollback principale = `APP_VERSION=<vX.Y.Z precedente>`
(`latest-YYYYMMDD` esiste solo per i rebuild schedulati). **Backup DB obbligatorio prima di ogni
upgrade**: le migrazioni non sono reversibili, un downgrade delle immagini app dopo una
migrazione può rompere lo schema; downgrade dell'immagine `db` solo con restore del backup.

### Fase 6 — Runtime hardening passo 1 (1 PR)

D4 passo 1 (incluso aggiornamento `.env` CI e release note).

Più servizio one-shot `verify-images` nel compose (come ComunicaPA): immagine
`ghcr.io/sigstore/cosign/cosign:<v>-dev@sha256:…` (variante con shell busybox), `read_only`,
`restart: "no"`; con `VERIFY_IMAGES=true` esegue `cosign verify` (stessa identità di D3) sulle 3
immagini di `APP_VERSION`, altrimenti esce 0. `backoffice`, `frontoffice`, `db` dipendono da lui
con `condition: service_completed_successfully`: firma assente o tag inesistente → servizi non
avviati. Toggle via **variabile**, non `profiles` (con il profilo una verifica fallita dà solo un
warning e i servizi partono). Default `false` per non rompere le istanze esistenti; documentare
l'attivazione. Le immagini pubblicate prima della Fase 5 non sono firmate: attivare solo dopo il
primo rilascio firmato.

### Fase 7 — Governance

1. `security-alerts.yml` giornaliero: fallisce e apre/aggiorna issue se alert code scanning/dependabot high+ aperti su main.
2. `scorecard.yml` (push main + settimanale), badge README. Badge OpenSSF Best Practices opzionale.
3. `.github/CODEOWNERS`, `.github/pull_request_template.md` (checklist test/sicurezza/migrazioni), `CONTRIBUTING.md` con policy "ogni fix ha un test di regressione".
4. Test property-based con data provider PHPUnit e generatori seedati (nessuna dipendenza nuova) su: validazione CF/importo, verifica token HMAC link pubblici, parsing CSV pendenze massive.

### Fase 8 (spec separata) — Runtime hardening passo 2

D4 passo 2: non-root + `read_only`.

## Procedura break-glass

Con PR obbligatoria, nessun bypass e Trivy fs bloccante, un CVE HIGH fixabile pubblicato
un giorno qualsiasi blocca anche un hotfix non correlato. Procedura (da `docs/` dopo Fase 1):
1. Preferito: aggiungere il CVE a `.trivyignore` con motivazione e scadenza, nella stessa PR dell'hotfix.
2. Ultima risorsa: ruleset "Main Branch" in `enforcement: evaluate` per il tempo del merge,
   poi ripristino da snapshot JSON; annotare nell'issue dell'hotfix (resta nell'audit log).

## Gap residui accettati

1. **Review seconda persona**: team piccolo, 0 approvazioni (scelta già documentata in CLAUDE.md). Mitigazione: 2FA con passkey/chiave hardware sugli account admin.
2. **SLSA L3**: provenance firmata nello stesso job del build; serve workflow riutilizzabile isolato.
3. **Secret scanning `non_provider_patterns`/`validity_checks`**: verificare disponibilità sul repo pubblico; su ComunicaPA richiedono licenza.
4. **`publiccode-parser-action`**: immagine Docker interna non per digest, fuori dal nostro controllo.
5. **`scripts/legacy-bridge/rendicontazione_bridge.php`**: deploy manuale fuori pipeline, nessuna garanzia di integrità sul server legacy.
6. Fuori CI/CD: rotazione segreti `.env` delle istanze, accesso Portainer, backup con ripristino provato.

## Verifica

```sh
R=repos/Comune-di-Montesilvano/GovPay-Interaction-Layer
gh api $R -q '{delete_branch_on_merge,allow_merge_commit,allow_rebase_merge,security_and_analysis}'
gh api $R/actions/permissions                       # sha_pinning_required, allowed_actions
gh api $R/code-scanning/default-setup -q '{state,query_suite,languages}'
gh api $R/private-vulnerability-reporting
gh api $R/rulesets -q '.[] | {id,name,target,enforcement}'
grep -rn "persist-credentials\|harden-runner" .github/workflows | wc -l
grep -n "^FROM\|COPY --from=[a-z]" Dockerfile docker/db/Dockerfile   # tutti con @sha256
grep -n "composer update" docker-setup.sh                             # nessun match
```

## Rischi

- `sha_pinning_required` + `allowed_actions: selected`: un'action nested non in elenco rompe il
  workflow. Nested già verificati per SHA (`setup-trivy`, `actions/cache` in `trivy-action`;
  `oven-sh/setup-bun` in `claude-code-action`). Ogni nuova action va aggiunta all'elenco.
- Merge queue + GitHub App dependabot: il token App deve poter accodare; da provare su una PR reale.
- Push-by-digest: le cache registry `*-buildcache:main`/`php-common-cache:main` sono indipendenti dai tag immagine, nessun impatto sui tempi di build.
- `cap_drop: ALL` può rompere `docker-setup.sh` in modi non ovvi (`chmod … || true` fallisce in silenzio): test con volumi di produzione copiati.
- Composer fuori dal runtime: istanze che oggi si affidano al `composer update` all'avvio (es. `composer.json` montato a mano) smettono di partire — da verificare sulle istanze note prima della Fase 3.
