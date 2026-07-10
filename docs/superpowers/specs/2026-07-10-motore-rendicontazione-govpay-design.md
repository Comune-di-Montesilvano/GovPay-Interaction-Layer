# Motore rendicontazione GovPay — design

Data: 2026-07-10

## Contesto

Il vecchio gestionale (`P:\JOBS\scripts\rendiconta_pendenze.php` + `P:\servizi.comune.montesilvano.pe.it\pagopa\gestionale\script_rendicontazione\*`) leggeva i flussi bancari non rendicontati, per ogni pendenza GovPay decideva se passarla a un gestionale esterno (Geri, Dilazione/Definizione Agevolata) o mandare una mail all'operatore configurato per la tipologia. In GIL questa logica non esiste: le pendenze arrivano indistintamente, senza smarcatura né notifica mirata per tipologia.

Obiettivo: motore che processa i flussi non rendicontati (solo righe `is_govpay=1` di `flussi_rendicontazioni`), instrada ogni pendenza GovPay verso smarcatura automatica, smarcatura manuale operatore, o handler esterno legacy (Geri/Dilazione), e considera un flusso "rendicontato" quando tutte le sue pendenze GovPay sono terminali.

Le pendenze esterne (`is_govpay=0`) restano gestite dalla pipeline L1/L2 esistente (mapping_pendenze), non toccata da questo lavoro.

**Scope temporale**: il motore processa/ritenta solo righe con `data_pagamento >= oggi - rendicontazione.max_giorni_retry` (default 7 giorni). Oltre questa finestra alcuni flussi restano semplicemente non rendicontabili (dato mancante/corrotto a monte, IUV non risolvibile) — il motore smette di ritentarli per non restare bloccato all'infinito su casi persi. Le righe fuori finestra restano visibili in stato `PENDING`/`ERRORE` per audit manuale, ma non entrano più nella coda del motore né nel conteggio "scansioni senza novità".

## Regola di instradamento

Determinata dal prefisso dello IUV:

- **IUV inizia con `GIL`** (prefisso configurabile, default `GIL`) → pendenza creata da GIL stesso, nessun gestionale esterno collegato. Cerca associazione in `rendicontazione_gruppo_tipologie` per (id_dominio, id_entrata):
  - nessuna riga → smarco automatico (`GESTITO`, handler `GIL_MANUALE`), nessuna mail.
  - riga con `modalita='SOLO_NOTIFICA'` → smarco automatico (`GESTITO`), ma la pendenza finisce comunque nel digest mail al gruppo (informativo, nessuna azione richiesta).
  - riga con `modalita='NOTIFICA_E_SMARCATURA'` → stato `IN_ATTESA_CONFERMA` (serve smarcatura manuale in vista dedicata + mail digest al gruppo).
- **IUV non inizia con `GIL`** → pendenza di un gestionale esterno legacy.
  - Match contro `rendicontazione_regole_esterne` (pattern su prefisso IUV o cifra id_app_agid, per dominio):
    - match `GERI` o `DILAZIONE` → chiamata HTTP al **ponte legacy** (unico punto di atterraggio, vedi sotto), che internamente smista verso il connector Geri o verso l'update diretto su `definizione_agevolata.rate` (entrambi locali a quel server, GIL non vi accede più direttamente).
    - esito ok → `GESTITO`; esito ko → `ERRORE` (ritentato ai cicli successivi).
  - Nessun match → si assume gestito autonomamente dal software esterno → `GESTITO` subito, handler `AUTO_ESTERNO`.

Handler noti allo scope attuale: solo **Geri** e **Dilazione** (gli altri script legacy — gitt, sportello, massivi, test — sono deprecati o superflui in GIL, non vanno riportati).

### Ponte legacy (unico punto di atterraggio)

Il DB `definizione_agevolata` non è raggiungibile da GIL, e non ha senso avere due integrazioni dirette separate (Geri webservice + Dilazione DB). Si costruisce invece un unico bridge HTTP su `servizi.comune.montesilvano.pe.it` (stesso server/rete di entrambi i legacy), che espone un solo endpoint verso cui GIL parla come client.

**Lato GIL (client, nel repo)**: `app/Services/LegacyRendicontazioneBridgeClient.php` — POST JSON verso `rendicontazione.bridge_url`, header auth con `rendicontazione.bridge_token` (cifrato in settings, come gli altri segreti). Un'unica chiamata sostituisce sia il vecchio webservice Geri sia l'accesso diretto al DB Dilazione.

Contratto richiesta:
```json
{
  "handler": "GERI" | "DILAZIONE",
  "iuv": "...",
  "id_atto": "...",            // documento->identificativo
  "data_pagamento": "YYYY-MM-DD",
  "importo": 123.45,
  "rata": "1"                  // opzionale
}
```
Contratto risposta:
```json
{ "esito": true, "messaggio": "" }
```

**Lato server legacy (script, fuori dal repo/build GIL)**: piccolo script PHP (`rendicontazione_bridge.php`) scritto come parte di questo lavoro ma **copiato a mano** sul server `servizi.comune.montesilvano.pe.it` (non fa parte della pipeline Docker/CI di GIL). Valida il token, poi:
- `handler=GERI` → richiama `BackOffice::registra_versamento_geri()` (connector esistente, locale a quel server).
- `handler=DILAZIONE` → update diretto locale su `definizione_agevolata.rate` (stessa logica di `dilazione.php` oggi, ma richiamata via HTTP invece che in-process dal vecchio script).
- Risponde con `{esito, messaggio}`.

Verrà tenuto nel repo GIL sotto `scripts/legacy-bridge/rendicontazione_bridge.php` per versionamento, ma il deploy su quel server resta manuale.

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
  ADD COLUMN rendicontazione_appio_stato ENUM('NON_APPLICABILE','INVIATO','ERRORE')
      NOT NULL DEFAULT 'NON_APPLICABILE',                       -- esito notifica App IO cittadino (best-effort, non retry)
  ADD INDEX idx_dominio_rend_stato (id_dominio, rendicontazione_stato);
```

Un `id_flusso` è "rendicontato" quando non esistono più righe `is_govpay=1` in stato `PENDING`/`IN_ATTESA_CONFERMA`/`ERRORE` per quel flusso — calcolato a runtime (query, nessun flag ridondante da mantenere in sync). Le righe `is_govpay=0` non entrano in questo conteggio.

**Nota bug GovPay**: l'azione "rendiconta flusso" su GovPay a volte aggiorna correttamente le pendenze (PAGATO→INCASSATO) ma non il flag `rendicontato` del flusso stesso, che resta "no" — bug lato GovPay, non GIL. Il nostro completamento è calcolato esclusivamente sulle righe `flussi_rendicontazioni`/`rendicontazione_stato` (dato GIL-proprio), mai sul flag `rendicontato` di GovPay: il bug non blocca né falsa il motore. `cron_ragioneria.php` non legge/filtra su quel flag (verificato), quindi il ciclo di sync continua a portare gli aggiornamenti (es. INCASSATO) indipendentemente da esso.

### `rendicontazione_gruppo_tipologie`

Tabella dedicata (non riusa `user_group_tipologie`, che serve ai permessi di creazione pendenze — concetti diversi, tenerli separati evita side-effect incrociati). Associazione: gruppo → tipologie di cui è responsabile per la rendicontazione (mail + smarcatura manuale).

```sql
CREATE TABLE rendicontazione_gruppo_tipologie (
  group_id   INT UNSIGNED NOT NULL,
  id_dominio VARCHAR(64)  NOT NULL,
  id_entrata VARCHAR(128) NOT NULL,
  modalita   ENUM('SOLO_NOTIFICA','NOTIFICA_E_SMARCATURA') NOT NULL DEFAULT 'NOTIFICA_E_SMARCATURA',
  PRIMARY KEY (group_id, id_dominio, id_entrata),
  FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Se una tipologia non ha nessuna riga associata → smarco automatico, nessuna mail operatore. Se associata, la `modalita` decide se serve solo informare il gruppo (`SOLO_NOTIFICA`, smarco resta automatico) o se serve anche la conferma manuale in vista (`NOTIFICA_E_SMARCATURA`).

**UI**: nessuno screen a parte. Nuova sezione/tab "Rendicontazione" dentro la schermata esistente "modifica gruppo" (accanto a Membri, Tipologie permesso pendenze, Template) — stesso componente checklist tipologie già usato altrove, con in più un select modalità per ogni tipologia spuntata (uno stesso gruppo può avere Solo Notifica su una tipologia e Notifica+Smarcatura su un'altra). Un solo posto da aprire per gruppo.

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

Connessione al ponte legacy (`rendicontazione.bridge_url`, `rendicontazione.bridge_token`) in `settings` (nuova sezione `rendicontazione`), cifrata con `Crypto` come gli altri segreti — unica config di connessione, condivisa da entrambi gli handler GERI/DILAZIONE.

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
- `rendicontazione.max_giorni_retry` (default 7) — finestra temporale oltre la quale il motore smette di ritentare una riga
- Toggle "invia mail amministratore per pagamenti auto-gestiti" (`rendicontazione.notifica_admin_auto`) + campo email/i amministratore (separate da `;` — non esiste oggi un flag utente dedicato tipo il vecchio `notifica_pagamenti`)
- CRUD `rendicontazione_regole_esterne`: dominio, pattern (prefisso IUV o cifra id_app), handler (GERI/DILAZIONE), attivo/disattivo
- Config ponte legacy: `rendicontazione.bridge_url`, `rendicontazione.bridge_token` — cifrato, unico per entrambi gli handler

## Template mail (`MailerService`, pattern `renderEmailBase` esistente)

**a) Digest operatore** — `sendRendicontazioneOperatoreDigest($righeDaConfermare, $righeInformative, $destinatari, $gruppoNome)`
- Oggetto: `Nuovi pagamenti — <tipologia/gruppo>`
- Corpo in due sezioni: "Da confermare" (righe `NOTIFICA_E_SMARCATURA` finite `IN_ATTESA_CONFERMA`, con link a `/rendicontazione/da-confermare`) e "Registrati automaticamente" (righe `SOLO_NOTIFICA`, già `GESTITO`, solo informative)
- Inviata solo se almeno una delle due sezioni ha righe da notificare per quel gruppo

**b) Digest amministratore** — `sendRendicontazioneAdminDigest($righeGestite, $destinatari)`
- Oggetto: `Riepilogo rendicontazione automatica GovPay — <data>`
- Corpo: tabella righe `GESTITO`/`ERRORE` non ancora notificate, con handler (GIL_MANUALE auto / GERI / DILAZIONE / AUTO_ESTERNO) ed esito; righe in errore evidenziate
- Inviata solo se `rendicontazione.notifica_admin_auto`=on e ci sono righe da notificare

Entrambe: HTML + variante plain-text, stesso stile branding esistente (logo comune, nessuna dipendenza esterna).

## Notifica App IO al cittadino

Quando una riga `is_govpay=1` raggiunge `GESTITO` (qualunque handler: GIL_MANUALE auto/manuale, GERI, DILAZIONE, AUTO_ESTERNO), si tenta una notifica App IO al cittadino, riusando la stessa logica già in uso in `PendenzeController` per la creazione pendenza:

- Servizio App IO: `IoServiceRepository::getTipologiaService($idEntrata) ?? findDefault()` — stessa risoluzione servizio/tipologia già usata oggi, nessuna logica nuova.
- Invio: `AppIoService::sendMessage()` con oggetto/markdown che riportano tipologia, importo, data pagamento.
- Link nel messaggio: stesso meccanismo di `buildPdfDownloadUrl()` (link firmato HMAC via `buildSignedLinkParams`), ma verso `/link/ricevuta` invece di `/link/avviso` (endpoint già esistente lato frontoffice, vedi `GET /api/frontoffice/ricevuta/{idDominio}/{iuv}/{ccp}`).
- Solo se soggetto pagatore è persona fisica (`tipo='F'`) con CF valorizzato — stesso vincolo già applicato altrove, nessun controllo di "registrazione App IO" preventivo (come per rateizzazioni: si chiama `sendMessage` e si legge l'esito OK/KO dalla risposta).
- Tentativo one-shot alla transizione a `GESTITO` (non bloccante, non rientra nel retry loop del motore): esito tracciato su nuova colonna `rendicontazione_appio_stato ENUM('NON_APPLICABILE','INVIATO','ERRORE') NOT NULL DEFAULT 'NON_APPLICABILE'` su `flussi_rendicontazioni`, solo per dedup/audit — un fallimento qui non riporta la riga a `ERRORE` (il pagamento resta comunque rendicontato).

## Non-goal espliciti

- Nessun supporto per gli handler legacy `gitt`, `sportello`, `massivi`, `test` — deprecati/superflui, solo Geri e Dilazione sono ancora rilevanti.
- Nessuna UI per modificare la logica interna degli handler Geri/Dilazione (resta hardcoded per tipo handler) — solo pattern di riconoscimento (`rendicontazione_regole_esterne`) e connessione al ponte (`bridge_url`/`bridge_token`) sono configurabili da UI.

## Esito Geri lato bridge — approccio "best effort"

Il vecchio codice (`geri.php`) ignora già oggi il valore di ritorno di `registra_versamento_geri()` (TODO mai risolto) — non esiste quindi un contratto noto per un esito affidabile lato Geri. Per questa fase: la chiamata dal bridge al connector Geri si considera riuscita se non ci sono errori di rete/HTTP (stesso livello di fiducia del vecchio sistema, "ci fidiamo sia stata registrata"). `DILAZIONE` invece ha un esito verificabile (update diretto sulla riga `rate`, righe affette > 0 = successo) e lo userà.

Rafforzare l'esito Geri (es. verifica successiva via query di stato) è un miglioramento futuro possibile: il filesystem completo del server legacy è consultabile in `Y:\` per eventualmente costruire qualcosa di più solido lato `ws_portale.php`, ma non rientra in questa fase.

## Domande aperte per la fase di planning (non bloccanti per lo spec)

- Meccanismo di auth del bridge (token statico in header vs altro) e come/dove viene copiato in produzione sul server legacy — deploy manuale, da coordinare a parte dalla pipeline GIL.
