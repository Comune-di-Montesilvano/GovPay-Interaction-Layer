ALTER TABLE flussi_rendicontazioni
  ADD COLUMN IF NOT EXISTS rendicontazione_appio_message_id VARCHAR(64) NULL AFTER rendicontazione_appio_stato,
  ADD COLUMN IF NOT EXISTS rendicontazione_appio_inviato_at DATETIME NULL AFTER rendicontazione_appio_message_id;
