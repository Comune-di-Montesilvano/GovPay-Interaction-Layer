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
