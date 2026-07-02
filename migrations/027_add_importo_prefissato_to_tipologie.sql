ALTER TABLE entrate_tipologie
ADD COLUMN importo_prefissato DECIMAL(10,2) NULL AFTER iuv_prefix,
ADD COLUMN importo_bloccato TINYINT(1) NOT NULL DEFAULT 0 AFTER importo_prefissato;
