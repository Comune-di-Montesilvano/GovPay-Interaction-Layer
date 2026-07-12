-- Migration 032: add rendicontazione_regolarizzato to flussi_rendicontazioni and notifica_tutte_rendicontazioni to users
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS notifica_tutte_rendicontazioni TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER default_id_entrata;

ALTER TABLE flussi_rendicontazioni
  ADD COLUMN IF NOT EXISTS rendicontazione_regolarizzato TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER rendicontazione_appio_stato;
