-- Seed default GovPay auth settings (idempotente: INSERT IGNORE non sovrascrive valori salvati via UI)
INSERT IGNORE INTO settings (section, key_name, value, encrypted, updated_by)
VALUES
  ('govpay', 'authentication_method', 'sslheader',                               0, 'migration'),
  ('govpay', 'tls_cert_path',         '/var/www/certificate/certificate.cer',    0, 'migration'),
  ('govpay', 'tls_key_path',          '/var/www/certificate/private_key.key',    0, 'migration');
