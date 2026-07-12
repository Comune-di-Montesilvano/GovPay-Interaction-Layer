ALTER TABLE entrate_tipologie
ADD COLUMN IF NOT EXISTS importo_prefissato DECIMAL(10,2) NULL AFTER iuv_prefix,
ADD COLUMN IF NOT EXISTS importo_bloccato TINYINT(1) NOT NULL DEFAULT 0 AFTER importo_prefissato;
