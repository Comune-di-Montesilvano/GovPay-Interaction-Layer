-- migrations/029_rendicontazione_tentativi.sql
-- Contatore tentativi Geri per riga: il connettore legacy Geri non ha segnale
-- affidabile di successo/fallimento (best effort, vedi design doc), quindi si
-- limita il retry automatico oltre una soglia per evitare doppie registrazioni.

ALTER TABLE flussi_rendicontazioni
  ADD COLUMN IF NOT EXISTS rendicontazione_tentativi_geri TINYINT UNSIGNED NOT NULL DEFAULT 0
      AFTER rendicontazione_appio_stato;
