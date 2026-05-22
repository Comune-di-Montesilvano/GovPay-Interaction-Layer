ALTER TABLE flussi_rendicontazioni
  ADD COLUMN is_govpay TINYINT(1) NULL AFTER id_pendenza;
