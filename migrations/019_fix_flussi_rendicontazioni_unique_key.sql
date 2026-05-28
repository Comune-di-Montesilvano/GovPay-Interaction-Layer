-- Migration 019: Correzione chiave univoca uq_iur_dominio per supportare pagamenti multi-voce
-- 1. Imposta a 1 eventuali valori NULL nella colonna indice per evitare errori durante l'alter table
UPDATE flussi_rendicontazioni SET indice = 1 WHERE indice IS NULL;

-- 2. Modifica la colonna indice in NOT NULL DEFAULT 1
ALTER TABLE flussi_rendicontazioni MODIFY COLUMN indice SMALLINT NOT NULL DEFAULT 1;

-- 3. Rimuove la chiave univoca esistente
ALTER TABLE flussi_rendicontazioni DROP KEY uq_iur_dominio;

-- 4. Ricrea la chiave univoca includendo la colonna indice
ALTER TABLE flussi_rendicontazioni ADD UNIQUE KEY uq_iur_dominio (iur, id_dominio, indice);
