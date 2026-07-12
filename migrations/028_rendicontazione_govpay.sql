-- migrations/028_rendicontazione_govpay.sql
-- Motore rendicontazione GovPay: stato per riga, responsabilita' gruppo/tipologia,
-- regole di riconoscimento handler esterni, log digest.

ALTER TABLE flussi_rendicontazioni
  ADD COLUMN IF NOT EXISTS rendicontazione_stato ENUM('PENDING','IN_ATTESA_CONFERMA','GESTITO','ERRORE')
      NOT NULL DEFAULT 'PENDING' AFTER mapping_stato,
  ADD COLUMN IF NOT EXISTS rendicontazione_handler VARCHAR(30) NULL AFTER rendicontazione_stato,
  ADD COLUMN IF NOT EXISTS rendicontazione_note TEXT NULL AFTER rendicontazione_handler,
  ADD COLUMN IF NOT EXISTS rendicontazione_confermato_da INT UNSIGNED NULL AFTER rendicontazione_note,
  ADD COLUMN IF NOT EXISTS rendicontazione_confermato_at DATETIME NULL AFTER rendicontazione_confermato_da,
  ADD COLUMN IF NOT EXISTS rendicontazione_notificato TINYINT(1) NOT NULL DEFAULT 0 AFTER rendicontazione_confermato_at,
  ADD COLUMN IF NOT EXISTS rendicontazione_appio_stato ENUM('NON_APPLICABILE','INVIATO','ERRORE')
      NOT NULL DEFAULT 'NON_APPLICABILE' AFTER rendicontazione_notificato;

ALTER TABLE flussi_rendicontazioni
  ADD INDEX IF NOT EXISTS idx_dominio_rend_stato (id_dominio, rendicontazione_stato);

CREATE TABLE IF NOT EXISTS rendicontazione_gruppo_tipologie (
  group_id   INT UNSIGNED NOT NULL,
  id_dominio VARCHAR(64)  NOT NULL,
  id_entrata VARCHAR(128) NOT NULL,
  modalita   ENUM('SOLO_NOTIFICA','NOTIFICA_E_SMARCATURA') NOT NULL DEFAULT 'NOTIFICA_E_SMARCATURA',
  PRIMARY KEY (group_id, id_dominio, id_entrata),
  FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rendicontazione_regole_esterne (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_dominio     VARCHAR(64) NOT NULL,
  pattern_tipo   ENUM('IUV_PREFIX','ID_APP_AGID') NOT NULL,
  pattern_valore VARCHAR(50) NOT NULL,
  handler        ENUM('GERI','DILAZIONE') NOT NULL,
  attivo         TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_dominio_attivo (id_dominio, attivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rendicontazione_digest_log (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inviato_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  righe_operatore INT UNSIGNED NOT NULL DEFAULT 0,
  righe_admin     INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
