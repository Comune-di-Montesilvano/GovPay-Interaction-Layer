-- Tipologie di entrata personalizzate per il mapping pendenze esterne.
-- Permettono di definire codici tipologia non presenti in entrate_tipologie.
CREATE TABLE IF NOT EXISTS mapping_tipologie_custom (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_dominio  VARCHAR(20) NOT NULL,
  cod_entrata VARCHAR(100) NOT NULL,
  descrizione VARCHAR(255) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dom_cod (id_dominio, cod_entrata),
  INDEX idx_dominio (id_dominio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
