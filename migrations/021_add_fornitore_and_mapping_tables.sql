-- Migrazione: Aggiunta colonne per mapping e tabelle regole pendenze esterne
-- Data: 2026-05-28

-- 1. Aggiunta colonne a flussi_rendicontazioni
ALTER TABLE flussi_rendicontazioni
  ADD COLUMN IF NOT EXISTS fornitore VARCHAR(255) NULL AFTER id_pendenza,
  ADD COLUMN IF NOT EXISTS mapping_stato ENUM('PENDING', 'PROCESSED', 'NO_MATCH') NOT NULL DEFAULT 'PENDING' AFTER fornitore;

-- Aggiunta indice per ottimizzare la selezione del demone
ALTER TABLE flussi_rendicontazioni
  ADD INDEX IF NOT EXISTS idx_dominio_mapping (id_dominio, mapping_stato);

-- 2. Creazione tabella per i pattern IUV rilevati/configurati (Livello 1)
CREATE TABLE IF NOT EXISTS mapping_pendenze_pattern (
  pattern_iuv       VARCHAR(35) NOT NULL,
  id_dominio        VARCHAR(20) NOT NULL,
  fornitore         VARCHAR(255) NULL,
  cod_entrata       VARCHAR(100) NULL,
  is_custom         TINYINT NOT NULL DEFAULT 0,
  transazioni_count INT UNSIGNED NOT NULL DEFAULT 0,
  importo_totale    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (pattern_iuv, id_dominio),
  INDEX idx_dominio_fornitore (id_dominio, fornitore)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Creazione tabella sotto-regole basate su descrizione (Livello 2)
CREATE TABLE IF NOT EXISTS mapping_pendenze_desc_regole (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pattern_iuv  VARCHAR(35) NOT NULL,
  id_dominio   VARCHAR(20) NOT NULL,
  pattern_desc VARCHAR(255) NOT NULL,
  cod_entrata  VARCHAR(100) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pattern_desc_dominio (pattern_iuv, id_dominio, pattern_desc),
  CONSTRAINT fk_mapping_desc_pattern FOREIGN KEY (pattern_iuv, id_dominio) 
    REFERENCES mapping_pendenze_pattern (pattern_iuv, id_dominio) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
