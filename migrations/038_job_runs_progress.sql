-- Migration 038: job_runs_progress
-- Date: 2026-11-02
--
-- Worker a processi paralleli (un processo per lavoro): la riga di gil_job_runs nasce
-- all'AVVIO del giro (status 'running', finished_at NULL) e viene aggiornata dopo ogni batch
-- (contatori + avanzamento), poi chiusa a fine giro ('ok'/'error'). Righe 'running' lasciate da
-- un processo ucciso vengono chiuse come 'error' al giro successivo dello stesso lavoro.
--
-- total_items     : elementi da elaborare stimati all'avvio (COUNT della coda del lavoro, vedi
--                   CountsRemaining), aggiornato se la coda cresce durante il giro. NULL = non
--                   significativo per il lavoro (es. ragioneria).
-- remaining_items : elementi ancora in coda dopo l'ultimo batch. NULL come sopra.
--
-- Idempotente: run-migrations.php riesegue ogni file a ogni avvio. Compatibile con le immagini
-- precedenti (scrivono solo 'ok'/'error' e sempre finished_at; le colonne nuove sono nullable).
ALTER TABLE gil_job_runs
  MODIFY COLUMN finished_at DATETIME NULL,
  MODIFY COLUMN status ENUM('ok','error','running') NOT NULL,
  ADD COLUMN IF NOT EXISTS total_items INT UNSIGNED NULL AFTER errors,
  ADD COLUMN IF NOT EXISTS remaining_items INT UNSIGNED NULL AFTER total_items;
