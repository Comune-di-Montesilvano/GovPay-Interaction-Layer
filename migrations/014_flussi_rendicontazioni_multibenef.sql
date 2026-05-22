ALTER TABLE flussi_rendicontazioni
  ADD COLUMN is_multibeneficiario TINYINT(1) NULL AFTER id_pendenza;
