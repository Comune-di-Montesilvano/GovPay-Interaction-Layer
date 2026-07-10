# Motore rendicontazione GovPay — design

Data: 2026-07-10

## Contesto

Il vecchio gestionale (`P:\JOBS\scripts\rendiconta_pendenze.php` + `P:\servizi.comune.montesilvano.pe.it\pagopa\gestionale\script_rendicontazione\*`) leggeva i flussi bancari non rendicontati, per ogni pendenza GovPay decideva se passarla a un gestionale esterno (Geri, Dilazione/Definizione Agevolata) o mandare una mail all'operatore configurato per la tipologia. In GIL questa logica non esiste: le pendenze arrivano indistintamente, senza smarcatura né notifica mirata per tipologia.

Obiettivo: motore che processa i flussi non rendicontati (solo righe `is_govpay=1` di `flussi_rendicontazioni`), instrada ogni pendenza GovPay verso smarcatura automatica, smarcatura manuale operatore, o handler esterno legacy (Geri/Dilazione), e considera un flusso "rendicontato" quando tutte le sue pendenze GovPay sono terminali.

Le pendenze esterne (`is_govpay=0`) restano gestite dalla pipeline L1/L2 esistente (mapping_pendenze), non toccata da questo lavoro.

## Regola di instradamento

Determinata dal prefisso dello IUV:

- **IUV inizia con `GIL`** (prefisso configurabile, default `GIL`) → pendenza creata da GIL stesso, nessun gestionale esterno collegato.
  - Se la tipologia (id_dominio + id_entrata) ha un gruppo operatori associato in `rendicontazione_gruppo_tipologie` → stato `IN_ATTESA_CONFERMA` (serve smarcatura manuale in vista dedicata + mail digest al gruppo).
  - Se nessun gruppo associato → smarco automatico (`GESTITO`, handler `GIL_MANUALE`).
- **IUV non inizia con `GIL`** → pendenza di un gestionale esterno legacy.
  - Match contro `rendicontazione_regole_esterne` (pattern su prefisso IUV o cifra id_app_agid, per dominio):
    - match `GERI` → chiamata webservice legacy (`registra_pagamento_geri` via `http://10.0.117.253/utility/ws_portale.php`).
    - match `DILAZIONE` → update diretto su DB legacy `definizione_agevolata.rate` (marca rata pagata).
    - esito ok → `GESTITO`; esito ko → `ERRORE` (ritentato ai cicli successivi).
  - Nessun match → si assume gestito autonomamente dal software esterno → `GESTITO` subito, handler `AUTO_ESTERNO`.

Handler noti allo scope attuale: solo **Geri** e **Dilazione** (gli altri script legacy — gitt, sportello, massivi, test — sono deprecati o superflui in GIL, non vanno riportati).

## Schema dati

### Estensione `flussi_rendicontazioni` (righe `is_govpay=1`)

```sql
ALTER TABLE flussi_rendicontazioni
  ADD COLUMN rendicontazione_stato ENUM('PENDING','IN_ATTESA_CONFERMA','GESTITO','ERRORE')
      NOT NULL DEFAULT 'PENDING',
  ADD COLUMN rendicontazione_handler VARCHAR(30) NULL,        -- GIL_MANUALE | GERI | DILAZIONE | AUTO_ESTERNO
  ADD COLUMN rendicontazione_note TEXT NULL,                  -- dettaglio errore o esito handler
  ADD COLUMN rendicontazione_confermato_da INT UNSIGNED NULL, -- FK users, valorizzato su conferma manuale
  ADD COLUMN rendicontazione_confermato_at DATETIME NULL,
  ADD COLUMN rendicontazione_notificato TINYINT(1) NOT NULL DEFAULT 0, -- incluso in un digest mail
  ADD INDEX idx_dominio_rend_stato (id_dominio, rendicontazione_stato);
```

Un `id_flusso` è "rendicontato" quando non esistono più righe `is_govpay=1` in stato `PENDING`/`IN_ATTESA_CONFERMA`/`ERRORE` per quel flusso — calcolato a runtime (query, nessun flag ridondante da mantenere in sync). Le righe `is_govpay=0` non entrano in questo conteggio.

### `rendicontazione_gruppo_tipologie`

Tabella dedicata (non riusa `user_group_tipologie`, che serve ai permessi di creazione pendenze — concetti diversi, tenerli separati evita side-effect incrociati). Associazione: gruppo → tipologie di cui è responsabile per la rendicontazione (mail + smarcatura manuale).

```sql
CREATE TABLE rendicontazione_gruppo_tipologie (
  group_id   INT UNSIGNED NOT NULL,
  id_dominio VARCHAR(64)  NOT NULL,
  id_entrata VARCHAR(128) NOT NULL,
  PRIMARY KEY (group_id, id_dominio, id_entrata),
  FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Se una tipologia non ha nessun gruppo associato → smarco automatico, nessuna mail operatore.

**UI**: nessuno screen a parte. Nuova sezione/tab "Rendicontazione" dentro la schermata esistente "modifica gruppo" (accanto a Membri, Tipologie permesso pendenze, Template) — stesso componente checklist tipologie già usato altrove, un solo posto da aprire per gruppo.

### `rendicontazione_regole_esterne`

Pattern di riconoscimento IUV non-GIL → handler.

```sql
CREATE TABLE rendicontazione_regole_esterne (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_dominio     VARCHAR(64) NOT NULL,
  pattern_tipo   ENUM('IUV_PREFIX','ID_APP_AGID') NOT NULL,
  pattern_valore VARCHAR(50) NOT NULL,
  handler        ENUM('GERI','DILAZIONE') NOT NULL,
  attivo         TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Match longest-prefix-first quando più regole si applicano allo stesso dominio.

Connessioni handler (URL Geri, credenziali DB legacy Dilazione) in `settings` (nuova sezione `rendicontazione`), cifrate con `Crypto` come gli altri segreti.

### `rendicontazione_digest_log` (audit, non anti-duplicazione)

```sql
CREATE TABLE rendicontazione_digest_log (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inviato_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  righe_operatore INT UNSIGNED NOT NULL DEFAULT 0,
  righe_admin     INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Motore — `cron_rendicontazione_govpay.php`

Demone standard GIL (PID/stop file in `/tmp`, gestibile da Impostazioni→Cron). Loop a intervallo fisso (non "sleep se coda vuota" come gli altri demoni: i flussi arrivano a orari prevedibili, un intervallo fisso è più adatto).

**Ogni ciclo (`rendicontazione.scan_interval_minuti`, default 15):**

1. `SELECT * FROM flussi_rendicontazioni WHERE is_govpay=1 AND rendicontazione_stato IN ('PENDING','ERRORE')`.
2. Per ogni riga: recupera dettaglio pendenza da GovPay (`GovPayClientFactory`, via `id_pendenza` già salvato dal cron_ragioneria).
3. Applica la regola di instradamento (sopra), aggiorna `rendicontazione_stato`/`rendicontazione_handler`/`rendicontazione_note`.
4. Righe appena diventate `GESTITO`/`IN_ATTESA_CONFERMA`/`ERRORE` in questo ciclo restano con `rendicontazione_notificato=0` (in attesa del prossimo digest).
5. Righe già `IN_ATTESA_CONFERMA` da cicli precedenti non vengono ritoccate dal motore — solo l'operatore le chiude dalla vista.

**Trigger digest (sostituisce l'idea di orari fissi 8/14/20):**

- Contatore in-process `scansioni_senza_novita` (azzerato a ogni riavvio demone — accettabile, ritarda al più un digest).
- Se il ciclo trova righe nuove `PENDING` → contatore a 0.
- Se non ne trova → contatore++.
- Quando contatore ≥ `rendicontazione.scansioni_quiete_soglia` (default 3 = 45 min di quiete) **e** esistono righe con `rendicontazione_notificato=0` → invia i digest (sezione mail sotto), marca quelle righe `notificato=1`, azzera contatore, scrive riga in `rendicontazione_digest_log`.
- Se soglia raggiunta ma nulla da notificare → nessuna mail, contatore continua a salire (nessun cap necessario).

**Retry errori**: righe `ERRORE` rientrano nel prossimo ciclo come le `PENDING` (stessa query). Nessun limite di tentativi in questa fase — restano visibili/filtrabili in dashboard finché non risolte o corrette a mano.

## Vista dedicata "Da confermare"

Route: `GET /rendicontazione/da-confermare` (nuova voce sidebar), azione conferma `POST /rendicontazione/conferma` (bulk).

**Visibilità**: operatore vede solo righe `IN_ATTESA_CONFERMA` delle tipologie associate ai gruppi di cui è membro (join `rendicontazione_gruppo_tipologie` → `user_group_members`). Superadmin vede tutto.

**Contenuto** (paginato, card compatte, stile esistente):
- raggruppata per tipologia poi per flusso
- colonne: IUV, soggetto pagatore (anagrafica + CF), causale, importo, data pagamento
- checkbox riga + "Conferma selezionate" (bulk) oltre a conferma rapida singola
- filtri: tipologia, intervallo data pagamento, ricerca CF/IUV

**Conferma**: `UPDATE ... SET rendicontazione_stato='GESTITO', rendicontazione_confermato_da=<user_id>, rendicontazione_confermato_at=NOW()`.

**Tab "Storico"**: stessa vista, filtro `GESTITO` con `rendicontazione_confermato_da` valorizzato — audit di chi ha confermato cosa e quando.

## UI Admin (Impostazioni → Rendicontazione)

CRUD normale (dato GIL-proprio, non GovPay-side):

- `rendicontazione.iuv_prefix_gil` (default `GIL`)
- `rendicontazione.scan_interval_minuti` (default 15)
- `rendicontazione.scansioni_quiete_soglia` (default 3)
- Toggle "invia mail amministratore per pagamenti auto-gestiti" (`rendicontazione.notifica_admin_auto`) + campo email/i amministratore (separate da `;` — non esiste oggi un flag utente dedicato tipo il vecchio `notifica_pagamenti`)
- CRUD `rendicontazione_regole_esterne`: dominio, pattern (prefisso IUV o cifra id_app), handler (GERI/DILAZIONE), attivo/disattivo
- Config connessione handler: URL webservice Geri, credenziali DB legacy Dilazione (host/user/pass/schema) — cifrate

## Template mail (`MailerService`, pattern `renderEmailBase` esistente)

**a) Digest operatore** — `sendRendicontazioneOperatoreDigest($righe, $destinatari, $gruppoNome)`
- Oggetto: `Nuovi pagamenti da confermare — <tipologia/gruppo>`
- Corpo: elenco pendenze passate a `IN_ATTESA_CONFERMA` non ancora notificate (IUV, soggetto, causale, importo, data pagamento) + link a `/rendicontazione/da-confermare`
- Inviata solo se ci sono righe da notificare per quel gruppo

**b) Digest amministratore** — `sendRendicontazioneAdminDigest($righeGestite, $destinatari)`
- Oggetto: `Riepilogo rendicontazione automatica GovPay — <data>`
- Corpo: tabella righe `GESTITO`/`ERRORE` non ancora notificate, con handler (GIL_MANUALE auto / GERI / DILAZIONE / AUTO_ESTERNO) ed esito; righe in errore evidenziate
- Inviata solo se `rendicontazione.notifica_admin_auto`=on e ci sono righe da notificare

Entrambe: HTML + variante plain-text, stesso stile branding esistente (logo comune, nessuna dipendenza esterna).

## Non-goal espliciti

- Nessuna notifica App IO al cittadino in questo motore (il vecchio script lo faceva, non richiesto qui — eventuale step successivo).
- Nessun supporto per gli handler legacy `gitt`, `sportello`, `massivi`, `test` — deprecati/superflui, solo Geri e Dilazione sono ancora rilevanti.
- Nessuna UI configurabile per la logica interna degli handler Geri/Dilazione in questa fase (pattern di riconoscimento sì, via `rendicontazione_regole_esterne`; la logica di chiamata resta hardcoded per tipo handler).

## Domande aperte per la fase di planning (non bloccanti per lo spec)

- Credenziali/host esatti per la connessione DB legacy `definizione_agevolata` e conferma indirizzo/porta del webservice Geri (`10.0.117.253`) raggiungibili dal container GIL — da verificare in fase di implementazione.
- Formato esatto della risposta del webservice Geri (`registra_pagamento_geri`) per distinguere in modo affidabile successo/errore (il vecchio codice ignora il valore di ritorno con un TODO).
