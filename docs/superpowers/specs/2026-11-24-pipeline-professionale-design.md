# Pipeline professionale: upgrade, deploy verificato, accessibilità/DAST, igiene

Data: 2026-11-24. Stato: bozza da approvare.

## Intento

Portare la pipeline da "build e test solidi" a "rilascio in produzione verificabile": gli
incidenti più probabili oggi stanno nel passaggio da una versione all'altra (migrazioni su DB
esistente, compose nuovo + immagini) e nel deploy (nessuna verifica dopo, nessun backup prima,
firma cosign mai verificata). Il portale è della PA: accessibilità e postura web dell'app in
esecuzione vanno misurate, non presunte.

Decisioni dell'utente: niente ambiente di staging (nessun GovPay di collaudo disponibile);
al suo posto test di upgrade in CI + verifica automatica dopo il deploy. Verifica post-deploy
= servizio one-shot nel compose (opzione 1). Revisione obbligatoria del codice esclusa.

## Goals

- A. Ogni tag prova l'aggiornamento dalla release precedente su un DB con dati, e il ripristino del backup.
- B. In produzione: backup automatico prima di migrazioni nuove, migrazione fallita = backoffice fermo,
  firma immagini verificata di default, esito del deploy visibile in Portainer e GlitchTip.
- C. axe (WCAG 2.1 AA) e ZAP baseline sullo stack e2e.
- D. Changelog unico (GitHub Release), soglia di copertura sulle righe modificate, test di carico ripetibile.

## Non-goals

- Staging permanente, deploy automatico da CI, rollback automatico (migrazioni irreversibili: decide una persona).
- Review obbligatoria / CODEOWNERS per path.
- Test di carico contro la produzione o contro GovPay reale.

## Anti-goals (fallimento anche se "funziona")

- Un deploy che si blocca per un falso positivo della verifica (rete, Sigstore lento) senza dire perché e come uscirne.
- Backup che riempiono il disco dell'host senza limite.
- Dump con dati di produzione che finiscono in CI, log o artifact.
- Il compose nuovo che rompe le immagini già rilasciate (compose-compat deve restare verde con l'ultima release).

## Vincoli

- Compose compatibile con Podman (Portainer) e con le immagini dell'ultima release (`compose-compat.yml`).
- Hardening invariato: www-data, `cap_drop: ALL`, `read_only`, tmpfs `mode=1777`.
- harden-runner `egress-policy: block`: ogni host nuovo in allowlist, per job.
- Fixture sintetiche; nessun segreto o PII in log/artifact/Sentry.
- Action pinnate per SHA, immagini per digest, versioni esatte.

## Approcci considerati

**Verifica dopo il deploy** (scelta: 1)
1. Servizio one-shot `post-deploy-check` nel compose: nessun accesso dall'esterno, esito in Portainer + GlitchTip. **Scelto.**
2. Workflow GitHub sull'URL pubblico: richiede produzione raggiungibile da internet (backoffice non lo è).
3. Script manuale: dipende dalla memoria di chi fa il deploy.

**Staging** vs **upgrade in CI + verifica post-deploy**: scelto il secondo (nessun ambiente di collaudo, costo di manutenzione).
Non copre differenze dell'host reale né integrazione con GovPay/pagoPA veri.

## Design

### A. Test di upgrade (CI, `docker-publish.yml`)

- Nuovo job `upgrade`, parallelo a `e2e`, stessa condizione (`setup.code_changed`), **bloccante** (nei `needs` di `publish`,
  `success` o `skipped`, come e2e). Release precedente = tag `v*` non pre-release più recente diverso da quello in build
  (sul rebuild di latest: l'ultimo tag). Nessuna release precedente risolvibile → job fallisce (non salta in silenzio).
- Flusso, un solo progetto compose, volumi conservati:
  1. stack e2e con le immagini della release precedente; e2e completo (crea configurazione e dati sintetici);
  2. `up -d` con le 3 immagini nuove per digest (db compreso), servizi finti (fake-govpay, oidc, mailpit) non ricreati
     così il loro stato resta;
  3. verifiche: backoffice healthy, log migrazioni senza errori, `post-deploy-check` (B2) esce 0, backup pre-migrazione
     presente (B1);
  4. e2e "post-upgrade": sottoinsieme che non rifà il wizard (spec marcate `@upgrade`: login operatore, pendenza creata al
     passo 1 ancora visibile, pagamento cittadino, OIDC);
  5. restore: il dump pre-migrazione si carica in un MariaDB usa-e-getta (immagine db precedente) e ha le stesse tabelle
     e gli stessi conteggi di righe su un insieme fisso di tabelle del DB prima del passo 2.
- `e2e/run.sh` esteso con una modalità upgrade (variabile d'ambiente), non uno script parallelo.

### B. Rilascio in produzione

**B1. Registro migrazioni + backup + migrazione bloccante**
- Tabella `gil_schema_migrations` (nome file, checksum sha256, applicata_il). Creata dal runner se assente.
- All'avvio del backoffice il runner (uno solo: `backoffice/bin/run-migrations.php`, `docker-setup.sh` lo chiama al posto
  dei due loop attuali mysql/PHP):
  - calcola i file non registrati; un file registrato con checksum diverso NON si riesegue (migrazione già applicata
    modificata = errore di sviluppo): solo warning a log e in `post-deploy-check`;
  - se ce ne sono e la tabella `users` ha almeno una riga (installazione nuova = niente da salvare): dump gzip in
    `/backups/pre-migrazione/<timestamp>-<versione>.sql.gz` (volume `gil_backups` esistente), poi rotazione: tengo gli
    ultimi `MIGRATION_BACKUP_KEEP` (default 3);
  - backup fallito → nessuna migrazione, exit ≠ 0;
  - esegue i file in ordine; un file fallito → stop, exit ≠ 0, nessuna registrazione di quel file.
- `docker-setup.sh`: exit ≠ 0 del runner → il container esce con errore (niente Apache): backoffice mai healthy,
  frontoffice/worker non partono (`depends_on`), Portainer mostra il deploy fallito. Messaggio a log con causa e come
  uscirne (ripristinare `APP_VERSION` precedente; dump in `gil_backups/pre-migrazione`).
- Primo avvio con il registro (tabella assente, "bootstrap"): backup, poi esecuzione di tutti i file **tollerando gli
  errori come oggi** (alcuni file già applicati falliscono per scelta alla riesecuzione, cfr. `MigrationsFromScratchTest`),
  registrazione di tutti, riga di log con gli errori tollerati. Dal giro successivo regola stretta.
- Dump: stesso formato del backup manuale (dump PDO di `BackupController`, estratto in una classe condivisa).
- Escape hatch documentato: `MIGRATION_BACKUP=skip` (env) per un riavvio d'emergenza con disco pieno; mai default.
- Il backup compare nella lista di Backoffice → Backup (stesso volume) se il formato lo consente; altrimenti solo su volume
  (scelta del builder, documentata).

**B2. `post-deploy-check`**
- Servizio compose one-shot, immagine backoffice di `APP_VERSION`, `restart: "no"`, hardening comune, `depends_on`
  backoffice e frontoffice `service_healthy`. Env: `APP_VERSION`, `MASTER_TOKEN`, `SENTRY_DSN`, `SENTRY_ENVIRONMENT`.
- Comando `php bin/post-deploy-check` con guardia `sh -c` per immagini senza lo script (stampa "immagine senza
  post-deploy-check, nessuna verifica" ed esce 0, come la guardia del worker).
- Controlli (ognuno una riga OK/ERRORE a log, mai segreti/PII):
  1. backoffice: endpoint interno nuovo `GET /api/deploy-check` (Bearer `MASTER_TOKEN`, in allowlist `CsrfMiddleware`):
     versione (`VERSION_INFO.json`), DB raggiungibile, tutti i file migrazione registrati con checksum uguale;
  2. frontoffice: endpoint nuovo `GET /health` con versione; frontoffice → backoffice con `MASTER_TOKEN` funzionante
     (riusa una chiamata leggera esistente, es. config);
  3. versioni backoffice e frontoffice uguali tra loro e, se `APP_VERSION` è un semver, uguali ad `APP_VERSION`.
- Esito: exit 0 / 1, riga finale riassuntiva; con `SENTRY_DSN` un errore va a GlitchTip (suite `post-deploy-check`).
  Non ferma nulla: lo stack è già su; segnala.
- `scripts/smoke-stack.sh` (smoke e compose-compat) verifica che esca 0 con le immagini nuove e la riga di guardia con quelle vecchie.

**B3. Firma obbligatoria**
- `VERIFY_IMAGES: ${VERIFY_IMAGES:-true}` nel compose; `.env.example` e CLAUDE.md aggiornati; nota nella release:
  l'host deve raggiungere `ghcr.io`, `tuf-repo-cdn.sigstore.dev`, `rekor.sigstore.dev`; fallimento → messaggio che dice
  di mettere `VERIFY_IMAGES=false` solo per sbloccare un'emergenza.
- Il messaggio d'errore di `verify-images` distingue rete non raggiungibile da firma assente.
- **Da confermare dall'utente** prima del merge: i due host di produzione raggiungono quegli host.

### C. Accessibilità e DAST (stack e2e, `docker-publish.yml`)

- axe: `@axe-core/playwright` (versione esatta) in `e2e/`, spec `07-accessibilita` su pagine frontoffice (home, paga avviso,
  dettaglio avviso, carrello, area personale dopo OIDC, pagine errore) e backoffice (login, dashboard, lista pendenze,
  nuova pendenza). Tag `wcag2a, wcag2aa, wcag21a, wcag21aa`. Report: violazioni per pagina nel summary del job + JSON artifact.
- ZAP baseline (solo passivo, nessun attacco attivo): immagine `ghcr.io/zaproxy/zaproxy` per digest, contro frontoffice e
  login backoffice sulla rete dello stack; aggiornamento add-on disattivato (egress bloccato); `e2e/zap-rules.tsv` per
  regole ignorate, ognuna con motivo. Report HTML/JSON artifact.
- Entrambi **report-only** all'introduzione (job/step `continue-on-error`, fuori dai needs di publish). Correzione delle
  violazioni e passaggio a bloccante: lavoro successivo, con l'utente, a baseline nota.

### D. Igiene

- Changelog: rimuovere `.github/CHANGELOG.md` (fermo alla 1.0.0beta); fonte unica = GitHub Release `--generate-notes`;
  `.github/release.yml` con categorie (Funzionalità, Correzioni, Sicurezza, Dipendenze: label `dependencies` di dependabot)
  e un workflow leggero che etichetta le PR dal prefisso Conventional Commit del titolo (feat/fix/security/chore...).
  README: link alle Release.
- Copertura sulle righe modificate: `diff-cover` (versione esatta) sul `coverage/clover.xml` già prodotto in `build-test`;
  confronto con `merge_group.base_sha` (in coda) o base della PR; soglia **70%** sulle righe PHP modificate (mia scelta:
  alta abbastanza da contare, bassa abbastanza per controller con molto I/O); PR senza righe PHP → passa. Bloccante in
  `build-test` (required check già esistente, nessun nuovo check nel ruleset).
- Test di carico: k6 (immagine per digest), script in `load/` (home frontoffice, ricerca avviso, lista pendenze cittadino,
  login backoffice) contro lo stack e2e con servizi finti; workflow `load-test.yml` solo `workflow_dispatch`; soglie
  p95 e tasso d'errore come `thresholds` k6, report nel summary. Misura GIL, non GovPay. Mai contro la produzione.

## Ordine e consegna

Una PR per parte (o più, se grandi), nell'ordine: **B1** (il test di upgrade lo usa) → **B2** → **A** → **B3** → **C** → **D**.
B3 solo dopo la conferma sugli host. Ogni parte aggiorna CLAUDE.md.

## Lasciato al builder

- Formato esatto delle righe di log, nomi delle spec `@upgrade`, tabelle usate per il confronto del restore.
- Come risolvere la release precedente (`gh release list` / `git tag`), purché sia deterministico e loggato.
- Pagine axe in più se ovvie; soglie k6 iniziali (da tarare sul primo giro).
