-- Migrazione per aggiungere indici aggiuntivi utili a ottimizzare il caricamento dashboard.
-- Idempotenza gestita a livello di script setup.

ALTER TABLE flussi_rendicontazioni ADD INDEX IF NOT EXISTS idx_dominio_is_govpay_iur (id_dominio, is_govpay, iur);
ALTER TABLE flussi_rendicontazioni ADD INDEX IF NOT EXISTS idx_dominio_regolamento_flusso_cov (id_dominio, data_regolamento, id_flusso, ragione_psp, is_govpay, importo);
ALTER TABLE biz_ricevute ADD INDEX IF NOT EXISTS idx_dominio_stato_iur (id_dominio, stato, iur);
