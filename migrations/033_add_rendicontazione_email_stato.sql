-- Migration 033: add rendicontazione_email_stato to flussi_rendicontazioni
ALTER TABLE flussi_rendicontazioni
  ADD COLUMN IF NOT EXISTS rendicontazione_email_stato ENUM('PENDING','NON_APPLICABILE','INVIATO','ERRORE') NOT NULL DEFAULT 'PENDING' AFTER rendicontazione_appio_inviato_at,
  ADD COLUMN IF NOT EXISTS rendicontazione_email_inviata_at DATETIME NULL AFTER rendicontazione_email_stato;
