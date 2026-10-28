# Demoni → Symfony Messenger + Scheduler — design

Data: 2026-10-28. Precede `2026-10-28-runtime-hardening-e-minori-design.md` (che va aggiornata
dopo questa: niente più `docker exec`, PID/stop file, `.autostart`, servizio `volume-perms`).

## Perché

Gli 8 demoni (`scripts/cron_*.php`) funzionano e non hanno avuto incidenti, ma sono scritti
male: loop infiniti con `sleep`, logica dentro script lunghi (ragioneria 580 righe, massive
345), single-instance via PID file in `/tmp`, stop via file, avvio con `docker exec` o file
`.autostart` letti dall'entrypoint, processi che convivono con Apache nel container web, file
creati a volte da root a volte da `www-data`. Obiettivo: un sistema standard, leggibile e
testato, **a comportamento invariato**.

Problemi concreti segnalati dall'utente:
- **pausa/riparti lenta**: oggi il demone vede lo stop file solo tra un ciclo e l'altro (fino a
  15–30 min di sleep);
- **log poco utili**: troppo ridondanti (riga per riga).

## Obiettivi

1. **Test di caratterizzazione prima di tutto.** Prima di toccare un demone, la sua logica
   attuale è coperta da test PHPUnit che girano in CI sul MariaDB reale già avviato dal job
   `build-test`, con GovPay / pagoPA Biz Events / ponte legacy / App IO / mail simulati.
   La logica oggi inline negli script (ragioneria, pendenze massive, mapping L1/L2, …) viene
   prima estratta in servizi `App\Services\...` testabili **senza cambiarla** (stesso SQL, stessi
   stati, stesse transizioni), poi i test fissano il comportamento. Gli stessi test devono
   restare verdi dopo la migrazione.
2. **Symfony Messenger + Symfony Scheduler** (componenti standalone, nessun framework):
   - lavori periodici (ragioneria, biz scanner, tefa scanner, mapping L1, mapping L2, GovPay
     debitore, rendicontazione GovPay, pendenze massive "poll") → messaggi ricorrenti dello
     Scheduler, stessi intervalli di oggi: 15 min "coda vuota", ragioneria 30 min,
     rendicontazione `rendicontazione.scan_interval_minuti` (default 15); quando un giro trova
     lavoro, il successivo parte subito (equivalente dell'attuale pausa breve "1s"), fino a coda
     vuota;
   - pendenze massive: ogni riga/pendenza da creare è un messaggio in coda con retry automatico
     e backoff; i falliti finiscono nella coda "failed" consultabile e rilanciabile;
   - trasporto **Doctrine/DBAL su MariaDB** *(scelta del designer: niente Redis, nessun
     container in più; tabella `messenger_messages` con migrazione)*;
   - nessuna esecuzione concorrente dello stesso lavoro periodico (equivalente del PID guard):
     lock (Symfony Lock su DB o `GET_LOCK` MariaDB).
3. **Container `worker`** nel compose: stessa immagine backoffice, comando consumer
   (`messenger:consume` scheduler + code), utente `www-data`, `restart: unless-stopped`,
   `depends_on: db` healthy, stesse variabili/volumi necessari al backoffice, hardening del
   compose attuale. Il container web resta solo Apache.
   Rimossi: `docker exec` per i demoni, PID file, stop file, `/tmp/cron-rescan-ragioneria`
   (sostituito da un'azione), file `.autostart` e loop in `docker-setup.sh`.
4. **Pagina Impostazioni → Cron (struttura attuale, va bene)**, con:
   - **abilita/disabilita** per lavoro = flag in DB letto dal worker; effetto entro **pochi
     secondi** (il lavoro in corso finisce il batch corrente e non ne inizia un altro). Il flag
     sostituisce anche l'autostart (abilitato = gira, anche dopo un riavvio);
   - **esegui ora** per lavoro *(aggiunta del designer, accettata)*;
   - **rescan ragioneria** (oggi file `/tmp/cron-rescan-ragioneria`) come azione;
   - stato: ultima esecuzione (inizio, durata, esito), prossima esecuzione prevista;
   - **log utili**: una riga di riepilogo per esecuzione (inizio, durata, elaborati / ok /
     saltati / errori) e sotto solo gli errori con dettaglio (identificativo — IUV/IUR/id —
     e motivo). Dettaglio riga per riga solo con `APP_DEBUG` attivo. Storico limitato
     (retention decisa dal builder, es. ultimi 30 giorni o N esecuzioni per lavoro);
   - **pendenze massive**: lista lavori falliti con "riprova" *(aggiunta del designer,
     accettata)*;
   - gli errori continuano ad andare su Sentry/GlitchTip (tag `suite` = nome lavoro, come oggi
     `cron-<slug>`).
5. **Dashboard home "GIL Services Hub"** (oggi start/stop/log dei demoni per superadmin) usa lo
   stesso modello (abilita/disabilita, stato, log) — niente più comandi di processo.
6. **Migrazione delle istanze esistenti**: al primo avvio del nuovo sistema, i lavori con file
   `.autostart` presente in `/var/www/cache` risultano **abilitati**, gli altri disabilitati;
   nessun intervento manuale. I vecchi file (`.autostart`, log `daemon-*.log`) possono restare
   nel volume, ignorati.

## Dati di produzione per i test

L'utente fornisce un dump del DB di produzione per scoprire casistiche reali. Regole:
- caricato solo in un MariaDB locale usa-e-getta (container/progetto separato), mai nel repo,
  in CI, nei log, in issue o in Sentry;
- dai casi trovati si scrivono **fixture sintetiche** (CF generati validi, nomi finti, IUV/IUR
  inventati con la stessa struttura: prefissi, lunghezze, aux digit, pattern TEFA, ecc.);
- a fine lavoro container, volume e file del dump vengono cancellati.

## Non-obiettivi

- Cambiare cosa fanno i demoni (query, regole di routing, soglie, stati, email, App IO): solo
  struttura, esecuzione e osservabilità.
- Redis/BullMQ o code esterne.
- Hardening runtime (spec successiva), salvo che il worker nasce già `www-data`.
- Correggere bug trovati dai test di caratterizzazione: si fissano come "difetto noto" nel test
  e si segnalano all'utente (stessa prassi di `MassivoCsvParser`).

## Anti-obiettivi

- Un lavoro che gira due volte in parallelo (doppie registrazioni GERI, doppie email/App IO).
- Un'istanza aggiornata in cui i lavori che giravano prima risultano fermi.
- Perdita di righe di pendenze massive in coda durante l'aggiornamento (righe `PENDING` nella
  tabella attuale vanno processate dal nuovo sistema).
- Pausa che non ferma davvero (il lavoro continua con nuovi batch).
- Log ancora più rumorosi o errori che spariscono da Sentry.

## Vincoli

- PHP 8.5, Slim 4, PSR-4 `App\`, PHPStan livello 3, semgrep, convenzioni `CLAUDE.md`
  (`Logger::getInstance()`, niente `curl_close`, ecc.).
- Nuove dipendenze Composer: `symfony/messenger`, `symfony/scheduler`,
  `symfony/doctrine-messenger` (o trasporto DBAL equivalente), `symfony/lock` se usato,
  `symfony/console` per il comando consumer; `composer audit` e dependency-review verdi.
- Migrazione SQL numerata in `migrations/` + `docker/db-init/` per le nuove tabelle
  (messaggi, stato/storico esecuzioni, flag abilitazione).
- Validazione: suite PHPUnit verde (caratterizzazione prima/dopo), stack locale con worker:
  abilita/disabilita (tempo misurato), esegui ora, riavvio worker, kill del worker durante un
  batch (nessun doppio effetto, ripresa), pendenze massive con un errore forzato → failed →
  riprova; smoke test del workflow aggiornato per includere il worker.

## Decisioni del designer (dopo builder check)

1. **Pendenze massive**: la colonna stato della tabella attuale (`PENDING`, `CANCEL_PENDING`,
   errori, …) resta **l'unica fonte di verità** per UI batch e CSV. Un lavoro periodico
   "accoda" trasforma le righe `PENDING`/`CANCEL_PENDING` in messaggi (una riga = un
   messaggio) marcandole in lavorazione nella stessa transazione (dedupe: mai due messaggi per
   la stessa riga). L'handler esegue la riga e scrive l'esito sulla riga. Retry Messenger con
   backoff solo per errori transitori (rete/5xx GovPay); esaurito il retry la riga va nello
   stato di errore già esistente. "Riprova" in UI = rimettere la riga in `PENDING`. Il failed
   transport di Messenger non è esposto in UI. Upgrade: le righe `PENDING` esistenti vengono
   prese dal primo giro "accoda".
2. **Esito uniforme** `JobResult {processed, ok, skipped, errors, hadWork}` per ogni lavoro
   periodico; `hadWork` = c'è stato lavoro utile nel giro (es. mapping L1: assegnazioni > 0).
   `hadWork` → giro successivo subito, altrimenti intervallo "coda vuota". Cadenze speciali
   attuali mantenute (mapping L1: discovery ogni 60 s).
3. **Nessun retry Messenger per i lavori periodici** (`max_retries=0`): un giro fallito si
   registra (log + Sentry) e il prossimo parte all'intervallo normale; i retry restano quelli
   delle macchine a stati esistenti (ragioneria, biz, rendicontazione con
   `max_giorni_retry`/`geri_max_tentativi`). Nessun rischio di doppio invio GERI aggiunto.
4. **Flag abilitazione, stato, storico**: tabelle nuove dedicate (non `settings`, per evitare la
   cache di `Config`); permessi come oggi (superadmin per Cron e Services Hub).
5. **Schema**: migrazione numerata in `migrations/` + `docker/db-init/`. Le migrazioni sono già
   applicate automaticamente da `docker-setup.sh` all'avvio del backoffice; il worker dipende
   da `backoffice` healthy, quindi trova le tabelle. `auto_setup` del trasporto disattivato.
6. **Import `.autostart`**: il worker monta `cache` (rw); al primo avvio, se il marker di
   import in DB non c'è, abilita i lavori con file `.autostart` e scrive il marker
   (idempotente: un lavoro disabilitato dopo non viene riabilitato).
7. **Dipendenze**: componenti standard — `symfony/messenger`, `symfony/scheduler`,
   `symfony/doctrine-messenger` (+ `doctrine/dbal`), `symfony/lock` con store DBAL (lock come
   riga con TTL, non `GET_LOCK`), `symfony/console`. Niente trasporto scritto a mano: l'utente
   vuole un sistema standard.
8. **Garanzie**: at-least-once, protezione = lock + macchine a stati esistenti; obiettivo
   "mai peggio di oggi" (anche oggi un kill a metà batch ha lo stesso rischio). Nessuna
   garanzia exactly-once verso il ponte GERI oltre al cap tentativi esistente.
9. **Pagina Cron**: `set-scan-date` e `reset-range` restano (stessa funzione, nuova
   implementazione). Log di esecuzione in DB; stdout del worker = solo righe di riepilogo e
   errori (visibili con `docker logs`).
10. **Test/CI**: Guzzle `MockHandler` (già presente) per GovPay/Biz/ponte/App IO; schema dei
    test caricato da `docker/db-init/` completo. Lo smoke test del workflow aggiunge il
    servizio `worker` (stessa immagine backoffice per digest).

## Lasciato al builder

- Ordine di migrazione dei lavori (uno per commit/task, il più semplice per primo) e
  struttura dei servizi estratti.
- Formato tabelle stato/storico, retention, UI di dettaglio.
- Meccanismo di lock e come il worker rilegge i flag (polling breve, messaggio di controllo).
- Granularità dei messaggi per i lavori periodici (un messaggio per giro vs per batch).

## Rischi

- Caratterizzazione incompleta di logica non coperta → regressioni silenziose. Mitigazione:
  casi dal dump di produzione, confronto risultati vecchio/nuovo sullo stesso DB locale
  (stesso input → stesse righe aggiornate) prima di eliminare gli script.
- Durata di un giro > intervallo → sovrapposizioni: il lock deve impedirle.
- Upgrade con demoni vecchi ancora in esecuzione nel container web: alla ricreazione del
  container spariscono; finestra breve senza doppio effetto (lock + stati DB idempotenti).
