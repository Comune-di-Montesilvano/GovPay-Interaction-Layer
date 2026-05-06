ALTER TABLE pendenza_template
    ADD COLUMN IF NOT EXISTS giorni_validita INT UNSIGNED NULL AFTER importo,
    ADD COLUMN IF NOT EXISTS giorni_scadenza INT UNSIGNED NULL AFTER giorni_validita;
