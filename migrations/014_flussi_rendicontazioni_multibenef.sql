ALTER TABLE flussi_rendicontazioni
  ADD COLUMN IF NOT EXISTS is_multibeneficiario TINYINT(1) NULL AFTER id_pendenza;
