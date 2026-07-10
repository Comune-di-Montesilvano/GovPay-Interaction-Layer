-- migrations/030_rendicontazione_appio_pending_state.sql
-- rendicontazione_appio_stato (028) defaultava a NON_APPLICABILE per OGNI riga, comprese
-- quelle mai passate da tentaNotificaAppIo() (righe IN_ATTESA_CONFERMA non ancora confermate
-- manualmente). Questo rende NON_APPLICABILE ambiguo: "tentato, non applicabile" oppure
-- "mai tentato" sono indistinguibili, impedendo una guardia di idempotenza corretta in
-- RendicontazioneEngineService::tentaNotificaAppIo()/tentaNotificaAppIoPerRiga() (rischio di
-- doppia notifica App IO al cittadino su submit duplicati/stale di conferma manuale, vedi
-- RendicontazioneController::conferma() + RendicontazioneRepository::confermaRigheScoped()).
--
-- Aggiunge lo stato PENDING (nuovo DEFAULT) = "mai tentato". Le righe gia' GESTITO hanno per
-- costruzione gia' attraversato tentaNotificaAppIo() almeno una volta (chiamata sincrona subito
-- dopo markGestito() nel ciclo automatico, o via tentaNotificaAppIoPerRiga() dalla conferma
-- manuale), quindi il loro valore attuale (NON_APPLICABILE/INVIATO/ERRORE) e' un esito reale e
-- non va toccato dal backfill. Solo le righe non ancora GESTITO (mai processate) vengono
-- retrodatate a PENDING.

ALTER TABLE flussi_rendicontazioni
  MODIFY COLUMN rendicontazione_appio_stato ENUM('PENDING','NON_APPLICABILE','INVIATO','ERRORE')
      NOT NULL DEFAULT 'PENDING';

UPDATE flussi_rendicontazioni
   SET rendicontazione_appio_stato = 'PENDING'
 WHERE rendicontazione_stato <> 'GESTITO'
   AND rendicontazione_appio_stato = 'NON_APPLICABILE';
