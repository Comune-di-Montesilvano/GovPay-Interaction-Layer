ALTER TABLE tefa_ricevute
  ADD COLUMN IF NOT EXISTS is_govpay TINYINT(1) NULL AFTER importo_tefa,
  ADD COLUMN IF NOT EXISTS is_multibeneficiario TINYINT(1) NULL AFTER is_govpay;
