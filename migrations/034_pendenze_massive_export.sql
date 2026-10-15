-- Migration 034: pendenze_massive_export
-- Date: 2026-10-15
--
-- Job asincroni di export ZIP avvisi PDF per batch pendenze massive.
-- La tabella è creata anche a runtime da MassivoExportRepository::ensureTable().
CREATE TABLE IF NOT EXISTS pendenze_massive_export (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_batch_id VARCHAR(64) NOT NULL,
  stato ENUM('PENDING','RUNNING','DONE','ERROR') NOT NULL DEFAULT 'PENDING',
  totale INT UNSIGNED NOT NULL DEFAULT 0,
  processati INT UNSIGNED NOT NULL DEFAULT 0,
  errori INT UNSIGNED NOT NULL DEFAULT 0,
  file_path VARCHAR(255) NULL,
  messaggio TEXT NULL,
  created_by VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_export_batch (file_batch_id),
  INDEX idx_export_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
