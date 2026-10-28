-- Migration 035: fix_difetti_demoni
-- Date: 2026-10-28
--
-- Correzioni ai difetti dei demoni emersi dai test di caratterizzazione (Task 8b).
-- Idempotente: run-migrations.php riesegue ogni file a ogni avvio.

-- D2 (GovPay debitore): pendenza non trovata in GovPay (404) salvata con stato dedicato
-- NOT_FOUND invece di PROCESSED senza dati. Mai ritentata (non e' ERROR). I lettori che
-- filtrano b.stato = 'PROCESSED' (CSV ragioneria, filtri CF) gia' ottenevano NULL per queste
-- righe: nessun cambio di output. Le righe 404 storiche restano PROCESSED (non distinguibili).
ALTER TABLE biz_ricevute
  MODIFY COLUMN stato ENUM('PENDING','PROCESSED','ERROR','SKIPPED','NOT_FOUND') NOT NULL DEFAULT 'PENDING';

-- P7 (pendenze massive): la tabella era creata solo da MassivePendenzeRepository::ensureTable().
-- Stessa definizione di ensureTable() (che resta per le immagini vecchie): nessun cambio di
-- comportamento, solo la tabella creata anche dalle migrazioni.
CREATE TABLE IF NOT EXISTS pendenze_massive (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_batch_id VARCHAR(64) NOT NULL,
  riga INT UNSIGNED NOT NULL,
  stato ENUM('PENDING','PROCESSING','SUCCESS','ERROR','PAUSED','CANCELLED','CANCEL_PENDING','CANCEL_PROCESSING') NOT NULL DEFAULT 'PENDING',
  errore TEXT NULL,
  payload_json JSON NULL,
  response_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_batch (file_batch_id),
  INDEX idx_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- N2 (rendicontazione): cap tentativi anche per il ponte DILAZIONE (stesso limite di GERI,
-- rendicontazione.geri_max_tentativi), con contatore dedicato: la colonna GERI non viene
-- rinominata (nessun lettore da migrare, nessun rischio per le immagini vecchie).
ALTER TABLE flussi_rendicontazioni
  ADD COLUMN IF NOT EXISTS rendicontazione_tentativi_dilazione TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER rendicontazione_tentativi_geri;
