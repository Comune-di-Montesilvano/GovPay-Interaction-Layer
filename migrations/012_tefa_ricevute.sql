-- Tabella per lo scanner TEFA: traccia rendicontazioni provincia da analizzare via Biz Events
-- per identificare la quota TEFA e il comune di provenienza.
CREATE TABLE IF NOT EXISTS tefa_ricevute (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_dominio    VARCHAR(20)     NOT NULL COMMENT 'CF provincia (idDominio)',
  anno          YEAR            NOT NULL,
  mese          TINYINT UNSIGNED NOT NULL,
  id_flusso     VARCHAR(100),
  iur           VARCHAR(35)     NOT NULL,
  iuv           VARCHAR(35),
  data_pagamento DATE,
  importo_tefa  DECIMAL(10,2),
  cf_comune     VARCHAR(20)     COMMENT 'NULL fino a enrich completato',
  denominazione_comune VARCHAR(255),
  importo_comune DECIMAL(10,2),
  sorgente      ENUM('govpay','biz_events') DEFAULT NULL,
  stato         ENUM('PENDING','PROCESSED','ERROR','SKIPPED') NOT NULL DEFAULT 'PENDING',
  error_msg     TEXT,
  created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_iur_dominio (iur, id_dominio),
  INDEX idx_stato_id (stato, id),
  INDEX idx_dominio_anno_mese (id_dominio, anno, mese)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
