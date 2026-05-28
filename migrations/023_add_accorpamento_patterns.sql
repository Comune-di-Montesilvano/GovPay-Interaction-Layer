-- Migrazione: Aggiunta colonna accorpato_a per accorpamento pattern L1
-- Data: 2026-05-28

ALTER TABLE mapping_pendenze_pattern DROP COLUMN IF EXISTS accorpato_a;
ALTER TABLE mapping_pendenze_pattern DROP INDEX IF EXISTS idx_accorpato;

ALTER TABLE mapping_pendenze_pattern
  ADD COLUMN accorpato_a VARCHAR(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER cod_entrata;

ALTER TABLE mapping_pendenze_pattern
  ADD INDEX idx_accorpato (accorpato_a, id_dominio);

ALTER TABLE mapping_pendenze_pattern
  ADD CONSTRAINT fk_pattern_accorpato FOREIGN KEY (accorpato_a, id_dominio)
    REFERENCES mapping_pendenze_pattern (pattern_iuv, id_dominio)
    ON DELETE CASCADE;
