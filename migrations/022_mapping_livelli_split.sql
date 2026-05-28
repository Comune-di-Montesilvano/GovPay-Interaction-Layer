-- 022_mapping_livelli_split.sql
-- Separa il mapping in due livelli distinti:
--   L1 (cron_mapping_pendenze): IUV prefix -> fornitore
--   L2 (cron_vocab_mapping):    vocabolario keyword -> cod_entrata

-- 1. Aggiunge colonna stato L2 a flussi_rendicontazioni
ALTER TABLE flussi_rendicontazioni
  ADD COLUMN vocab_stato ENUM('PENDING','PROCESSED','NO_MATCH') NOT NULL DEFAULT 'PENDING' AFTER mapping_stato,
  ADD INDEX idx_dominio_vocab (id_dominio, vocab_stato);

-- Righe già NO_MATCH da L1 → marcate NO_MATCH anche per L2 (non raggiungibili da L2)
UPDATE flussi_rendicontazioni SET vocab_stato = 'NO_MATCH' WHERE mapping_stato = 'NO_MATCH';

-- 2. Nuova tabella vocabolario L2 (figlio di mapping_pendenze_pattern)
CREATE TABLE IF NOT EXISTS mapping_pendenze_vocab (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pattern_iuv VARCHAR(35)  NOT NULL,
  id_dominio  VARCHAR(20)  NOT NULL,
  keyword     VARCHAR(255) NOT NULL,
  cod_entrata VARCHAR(100) NOT NULL,
  priorita    TINYINT UNSIGNED NOT NULL DEFAULT 10,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_keyword_pattern_dominio (pattern_iuv, id_dominio, keyword),
  INDEX idx_dominio_pattern_prio (id_dominio, pattern_iuv, priorita),
  CONSTRAINT fk_vocab_pattern
    FOREIGN KEY (pattern_iuv, id_dominio)
    REFERENCES mapping_pendenze_pattern (pattern_iuv, id_dominio)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Rimuovi tabella desc_regole (sostituita interamente da vocab L2)
DROP TABLE IF EXISTS mapping_pendenze_desc_regole;
