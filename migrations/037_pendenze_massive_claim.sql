-- Migration 037: pendenze_massive_claim
-- Date: 2026-11-01
--
-- Pendenze massive come messaggi Messenger (worker): claim atomico per riga.
-- Idempotente: run-migrations.php riesegue ogni file a ogni avvio.
--
-- claimed_at  : istante del claim (PENDING -> PROCESSING / CANCEL_PENDING -> CANCEL_PROCESSING),
--               rinfrescato a ogni tentativo di elaborazione. Claim piu' vecchi di 1 h senza
--               esito vengono rimessi in coda dal lavoro `pendenze-massive`. NULL = riga mai
--               presa dal worker (import, o elaborata dal vecchio demone).
-- claim_token : identificativo del claim portato dal messaggio ProcessMassiveRow; un messaggio
--               con token diverso da quello della riga e' un duplicato/obsoleto (no-op).
-- Colonne nullable senza default applicativo: le immagini vecchie (INSERT senza queste colonne,
-- ensureTable() che modifica solo `stato`) continuano a funzionare.
ALTER TABLE pendenze_massive
  ADD COLUMN IF NOT EXISTS claimed_at DATETIME NULL AFTER response_json,
  ADD COLUMN IF NOT EXISTS claim_token CHAR(32) NULL AFTER claimed_at;
