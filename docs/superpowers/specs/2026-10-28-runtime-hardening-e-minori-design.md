# Runtime hardening passo 2 + chiusura minori baseline CI/CD — design

Data: 2026-10-28. Segue `2026-10-27-ci-cd-security-baseline-design.md` (Fasi 1–7 completate,
PR #111–#121). Questa spec copre la Fase 8 (D4 passo 2) e i minori rimandati dalla review
finale, in un unico piano.

## Perché

- Rispetto a ComunicaPA l'unica differenza sostanziale rimasta è a runtime: lì tutti i servizi
  girano con `read_only: true` + tmpfs; in GIL Apache gira ancora come root (porta 80) e il
  filesystem dei container è scrivibile. Un RCE in PHP oggi può scrivere ovunque nel container
  e il master Apache è root.
- I minori rimandati sono piccoli difetti noti (500 al posto di 403, publish non atomico, pin
  non tracciati, tag rc che sposta `latest`): chiuderli tutti insieme evita una coda di PR.

## Contesto deploy (deciso dall'utente)

- Tutte le istanze sono dietro reverse proxy (Portainer + Traefik), con porte host non standard
  da variabile (`BACKOFFICE_HTTP_PORT`/`FRONTOFFICE_HTTP_PORT`, es. 20001/20002). La porta
  interna del container non è configurata da nessuna parte fuori dal compose.
- Lo stack in Portainer legge `docker-compose.yml` **direttamente dal repo git**: le modifiche
  al compose arrivano insieme alle immagini.
- Nessuna istanza imposta `BACKOFFICE_INTERNAL_URL` nel `.env`.
- Le istanze usano `:latest`: il cambio arriva in produzione al primo rebuild dopo il merge.
  Validazione richiesta: test locale con Docker (stack separato) + smoke test del workflow.
  Nessuna istanza di staging.

## Obiettivi

### A. Runtime hardening passo 2 (backoffice, frontoffice)

1. **Non-root, porta interna invariata (80)** *(scelta del designer)*: `USER www-data`
   nell'immagine runtime; Apache resta su **80** dentro il container grazie a
   `sysctls: net.ipv4.ip_unprivileged_port_start: 80` nel compose (sysctl namespaced, solo
   dentro il container, nessuna capability). Motivo: con la porta che cambia (es. 8080) compose
   e immagine dovrebbero arrivare insieme — un compose nuovo con immagine vecchia, o il rollback
   ad `APP_VERSION`/`latest-YYYYMMDD` precedente, lascerebbero il servizio irraggiungibile.
   Tenendo 80: compose nuovo + immagine vecchia funziona (il sysctl è innocuo per Apache root),
   rollback all'immagine precedente funziona col compose nuovo. Unico ordine rotto: immagine
   nuova con compose vecchio (bind 80 negato) — non succede con lo stack Portainer da git,
   che ridistribuisce compose e immagini insieme; il compose va mergiato **prima o insieme**
   all'immagine (stessa PR). Il mapping porte host resta `${..._HTTP_PORT}:80`.
   Per `SSL=on` il vhost resta `*:80` con `SSLEngine on` come oggi.
2. **URL interni**: invariati (`http://backoffice`, `https://backoffice:80`).
3. **Filesystem read-only**: `read_only: true` su backoffice e frontoffice. Scrivibili solo:
   - volumi esistenti (backoffice: `/var/www/cache`, `/var/www/certificate`,
     `/var/www/html/public/img`, `/backups`; frontoffice: nessuno in scrittura);
   - tmpfs per: `/tmp` (PID/stop file demoni cron, upload PHP, restore), `/var/run/apache2`,
     `/var/lock/apache2`, directory sessioni PHP, `storage/logs` e `storage/tmp` di
     backoffice/frontoffice (i log applicativi vanno anche su stdout/Sentry; persi al riavvio —
     accettato).
   L'elenco esatto delle directory scritte lo ricava il builder dal codice e dalla prova a
   runtime: deve risultare **zero** errori di scrittura nei log in tutte le prove del punto D.
4. **Entrypoint (`docker-setup.sh`) compatibile con non-root + read-only**:
   - la copia `frontoffice/public` → `/var/www/html/public` si sposta a **build time** nel
     Dockerfile (stage `runtime-frontoffice`), mai a runtime;
   - via tutti i `chown`/`chmod` a runtime dall'entrypoint (non-root non può; ownership dei
     file d'immagine fissata a build time);
   - **file root nei volumi esistenti** *(scelta del designer)*: finora `docker exec` girava
     come root, quindi in `cache` (log demoni, `.autostart`, export, circuit breaker) e
     `/backups` possono esserci file root non scrivibili da `www-data`. Servizio compose
     one-shot `volume-perms` (stessa immagine backoffice, `user: "0"`, `cap_add: [CHOWN,
     FOWNER, DAC_OVERRIDE]`, `read_only: true`, `restart: "no"`) che fa `chown -R www-data`
     dei 4 volumi backoffice ed esce; `backoffice` dipende da lui con
     `service_completed_successfully`. Idempotente, ad ogni avvio (veloce: pochi file).
   - marker first-run da `/var/www/html/.first_run_done` a un percorso nel volume `cache`
     (oggi va comunque perso a ogni ricreazione del container: comportamento effettivo invariato);
   - certificati self-signed con `SSL=on`: generati in un percorso scrivibile (tmpfs) e passati
     ad Apache via variabile/define; certificati forniti in `/ssl` restano supportati se
     leggibili da `www-data` (nota di rilascio). Tutte le istanze reali sono `SSL=off` dietro
     reverse proxy: `SSL=on` va mantenuto funzionante e provato una volta (D2), senza altro
     investimento.
5. **Capability**: con processi non-root il set `cap_add` di backoffice/frontoffice
   (CHOWN/SETUID/SETGID/DAC_OVERRIDE/FOWNER/KILL) non serve più: rimuoverlo e tenere solo
   `cap_drop: ALL` (le capability per il chown dei volumi stanno solo su `volume-perms`). Se una prova del punto D fallisce senza una capability, riaggiungere solo
   quella con evidenza nel commit.
6. **Demoni cron**: `docker exec` senza `-u` diventa automaticamente `www-data`. Autostart da
   entrypoint e gestione da UI (start/stop/log) devono funzionare. Aggiornare `CLAUDE.md`: la
   sezione su root vs www-data e setgid diventa storica (tenere la regola "mai `/tmp` condiviso
   con sticky bit" solo se ancora pertinente).
7. **Trivy `DS-0002`**: rimuovere l'eccezione dal `.trivyignore` per il `Dockerfile` PHP (ora
   ha `USER`); resta per `docker/db/Dockerfile`.

### B. Runtime hardening db

`read_only: true` anche su `db` con tmpfs per `/run/mysqld` e `/tmp` (datadir è già volume).
Nessun cambio utente (l'entrypoint mariadb scende già a `mysql`). Se `read_only` sul db
richiede percorsi scrivibili non coperti senza modificare l'immagine ufficiale, il builder
documenta il motivo e lascia il db senza `read_only` (non bloccante per il resto della spec).

### C. Minori rimandati

1. **Publish atomico** (`docker-publish.yml`, job `publish`): applicare prima tutti i tag di
   versione delle 3 immagini, poi `latest`/`latest-YYYYMMDD` per ultimi; retry (3 tentativi)
   attorno a ogni `imagetools create`. Mantenere `--prefer-index=false` + controllo digest.
2. **Tag pre-release** (`vX.Y.Z-rc*`, qualsiasi tag semver con suffisso `-`): pipeline
   completa (Trivy, firme, attestazioni, smoke), pubblicano solo il proprio tag
   (`X.Y.Z-rc1`), mai `latest`, `X.Y` né `latest-YYYYMMDD`; niente GitHub Release.
3. **`scripts/verify-images.sh`**: mostrare stderr di cosign in caso di errore (non più
   `2>/dev/null`); se `gh` è presente ma non autenticato, avviso esplicito e fallback alla sola
   firma (non errore silenzioso).
4. **Servizio `verify-images`** nel compose: collegarlo alla rete esistente dello stack
   (`govpay-network`) invece di creare `<project>_default`.
5. **Link firmati** (`App\Security\SignedLink`, `frontoffice_verify_link`): qualsiasi link non
   valido → `false` (pagina 403), mai eccezione/500: `sig`/`expires` non stringa (es. array) e
   chiave mancante inclusi. Test di regressione per ciascun caso.
6. **Pin immagine cosign** (`docker-compose.yml` servizio `verify-images`,
   `scripts/verify-images.sh`): aggiunti a `toolchain-version-check.yml` (stesso schema di
   trivy/semgrep: issue quando esce una release più nuova da ≥7 giorni; job fallisce se i punti
   divergono tra loro).
7. **Code scanning nel ruleset**: regola `code_scanning` solo per **CodeQL** (soglie: alert
   `errors`, security `high_or_higher`), da aggiungere **solo se** CodeQL produce risultati
   anche in `merge_group` (verificare su una PR reale in coda prima di attivarla). Se non li
   produce: non aggiungere, documentare in `CLAUDE.md`. Lo script di modifica del ruleset lo
   lancia l'utente (come nella Fase 7).
8. **Release GitHub firmate** (Scorecard Signed-Releases): sui tag `v*` non pre-release il
   workflow crea la GitHub Release se assente (note generate automaticamente) e allega per ogni
   immagine SBOM CycloneDX e bundle di provenance (`*.intoto.jsonl` / bundle sigstore). Se la
   Release esiste già (creata a mano) allega solo gli asset.
9. **Pulizie**: commento obsoleto `Dockerfile` (ex riga 88), `COMPOSER_VENDOR_DIR` inutilizzato
   nel compose, `actions: read` superfluo in `image-check.yml`, checkout superfluo nel job
   `dependency-review` (se l'action non lo richiede), commento confuso in
   `dependabot-automerge.yml` + `workflow_dispatch`/concurrency "sweep" rimasti senza effetto.

## Non-obiettivi

- Aggiornare harden-runner su ComunicaPA (altro repo: segnalato all'utente a parte).
- Code-Review/Branch-Protection Scorecard (0 approvazioni è scelta deliberata), Fuzzing, badge
  OpenSSF Best Practices (autovalutazione manuale dell'utente).
- Staging o canary: la validazione è locale + smoke del workflow.
- Persistenza dei log applicativi su volume.

## Anti-obiettivi (fallimento anche se "funziona")

- Un'istanza che fa `docker compose pull && up -d` (Portainer da git) con il `.env` attuale
  non torna su con backoffice/frontoffice raggiungibili sulle stesse porte host.
- Frontoffice che non raggiunge il backoffice (pagamenti cittadino bloccati).
- Demoni cron che non partono/si fermano o autostart perso.
- Upload logo/certificati/backup/restore che falliscono per permessi.
- Qualsiasi errore "Read-only file system"/"Permission denied" nei log in uso normale.
- `latest` spostato da un tag rc.

## Vincoli

- Stessi vincoli delle Fasi 1–7: Action pinnate per SHA, harden-runner `block` con allowlist,
  `${VAR:?}` sui segreti, PHPStan 3, semgrep, Trivy bloccanti; commit Conventional, PR in
  merge queue.
- `SSL=on` e `SSL=off` restano entrambi supportati.
- Nessuna variabile `.env` nuova obbligatoria.
- I volumi esistenti delle istanze (creati con root/www-data dagli entrypoint precedenti)
  devono funzionare senza intervento manuale.

## Validazione richiesta (D)

Stack locale separato (`-p gil-hardening2-test`, immagini buildate dal sorgente, container
name sovrascritti, nessun tocco ai container/volumi esistenti dell'utente), su **volumi
inizializzati prima con le immagini `latest` attuali** (simula l'upgrade di un'istanza vera),
poi upgrade alle nuove immagini:

1. avvio, healthcheck verdi, `docker top` mostra Apache non-root;
2. frontoffice → backoffice (pagina che chiama l'API sidecar), con `SSL=off` e `SSL=on`;
3. `restart` dei servizi; ricreazione container (`up -d --force-recreate`);
4. demoni: autostart da file `.autostart`, start/stop via `docker exec` e stop file;
5. upload logo, scrittura in `/var/www/certificate`, backup e restore DB da UI (o i comandi
   che la UI esegue);
6. login backoffice (sessione PHP), import massivo CSV piccolo (scrittura export in cache);
7. grep dei log per "Read-only file system"/"Permission denied" = 0;
8. `scripts/smoke-stack.sh` verde; `verify-images` con `VERIFY_IMAGES=false`;
9. rollback: compose nuovo + immagini `latest` precedenti → servizi su;
10. file root creati apposta nei volumi (log demone, `.autostart`) → dopo l'upgrade
    `volume-perms` li rende scrivibili e il demone riparte.

Esiti registrati nel report del task.

## Lasciato al builder

- Elenco preciso dei tmpfs e loro dimensione/opzioni (`noexec`, `mode`, `uid`).
- Meccanismo per il certificato self-signed con `SSL=on` sotto read-only.
- Come rendere la configurazione Apache in ascolto su 8080 (ports.conf + vhost).
- Ordine dei task e suddivisione in PR (preferenza utente: una PR con più commit).

## Rischi

- Percorsi scritti a runtime non individuati → errori solo in funzioni poco usate (restore,
  export). Mitigazione: grep del codice + prove D5/D6 + log grep D7.
- Istanze con `SSL=on` e certificati montati in `/ssl`: devono restare leggibili da
  `www-data` (chiave `600` root → non leggibile). Il builder verifica e documenta nella nota di
  rilascio.
- Prima immagine non-root su `latest` = deploy in produzione senza staging (accettato
  dall'utente): nota di rilascio con rollback (`APP_VERSION` precedente / `latest-YYYYMMDD`,
  funziona col compose nuovo grazie alla porta invariata — da provare in D).
- Compatibilità di `sysctls` con Portainer/podman: provare in D con Docker Compose v2;
  Portainer usa compose v2.
