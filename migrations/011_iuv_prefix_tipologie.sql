ALTER TABLE entrate_tipologie
    ADD COLUMN IF NOT EXISTS iuv_prefix VARCHAR(10) DEFAULT NULL AFTER id_entrata;
