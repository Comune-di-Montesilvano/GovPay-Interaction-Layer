-- Aggiunta indici per ottimizzazione performance dashboard, code scanner e reportistica.
-- Idempotenza gestita a livello di script shell/setup (questo file viene importato una sola volta in produzione).

ALTER TABLE flussi_rendicontazioni ADD INDEX idx_dominio_regolamento (id_dominio, data_regolamento);
ALTER TABLE biz_ricevute ADD INDEX idx_dominio_stato (id_dominio, stato);
ALTER TABLE tefa_ricevute ADD INDEX idx_dominio_stato (id_dominio, stato);
