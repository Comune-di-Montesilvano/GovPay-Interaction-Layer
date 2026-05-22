ALTER TABLE tefa_ricevute
  ADD COLUMN is_govpay TINYINT(1) NULL AFTER importo_tefa,
  ADD COLUMN is_multibeneficiario TINYINT(1) NULL AFTER is_govpay;
