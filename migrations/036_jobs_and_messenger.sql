-- Migration 036: jobs_and_messenger
-- Date: 2026-10-28
--
-- Tabelle del framework job (demoni cron -> Symfony Messenger/Scheduler).
-- Idempotente: run-migrations.php riesegue ogni file a ogni avvio.

-- Flag abilitazione + stato per lavoro periodico (sostituisce PID/stop file/.autostart).
-- Riga marker name='__import__' (autostart_imported=1): import una tantum dei file .autostart.
CREATE TABLE IF NOT EXISTS gil_jobs (
  name VARCHAR(64) NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  next_run_at DATETIME NULL,
  last_started_at DATETIME NULL,
  last_finished_at DATETIME NULL,
  last_status ENUM('ok','error','skipped') NULL,
  autostart_imported TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Storico esecuzioni (una riga per esecuzione, contatori sommati su tutti i batch).
-- error_message: classe eccezione + messaggio breve, mai corpi di risposta upstream.
CREATE TABLE IF NOT EXISTS gil_job_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job VARCHAR(64) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NOT NULL,
  batches INT UNSIGNED NOT NULL DEFAULT 0,
  processed INT UNSIGNED NOT NULL DEFAULT 0,
  ok INT UNSIGNED NOT NULL DEFAULT 0,
  skipped INT UNSIGNED NOT NULL DEFAULT 0,
  errors INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('ok','error') NOT NULL,
  manual TINYINT(1) NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  INDEX idx_job_started (job, started_at),
  INDEX idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Primi 50 errori per esecuzione: ref = IUV/IUR/id, mai dati personali.
CREATE TABLE IF NOT EXISTS gil_job_run_errors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT UNSIGNED NOT NULL,
  ref VARCHAR(64) NOT NULL,
  reason VARCHAR(512) NOT NULL,
  INDEX idx_run (run_id),
  CONSTRAINT fk_gil_job_run_errors_run FOREIGN KEY (run_id) REFERENCES gil_job_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trasporto Doctrine di symfony/doctrine-messenger (auto_setup disattivato).
-- DDL generato da Connection::configureSchema() della libreria (doctrine/dbal 4.5, MariaDB):
-- non modificare a mano colonne/indici.
CREATE TABLE IF NOT EXISTS messenger_messages (
  id BIGINT AUTO_INCREMENT NOT NULL,
  body LONGTEXT NOT NULL,
  headers LONGTEXT NOT NULL,
  queue_name VARCHAR(190) NOT NULL,
  created_at DATETIME NOT NULL,
  available_at DATETIME NOT NULL,
  delivered_at DATETIME DEFAULT NULL,
  INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Store DBAL di symfony/lock (DoctrineDbalStore, lock = riga con TTL).
-- DDL generato da DoctrineDbalStore::configureSchema(): non modificare a mano.
CREATE TABLE IF NOT EXISTS lock_keys (
  key_id VARCHAR(64) NOT NULL,
  key_token VARCHAR(44) NOT NULL,
  key_expiration INT UNSIGNED NOT NULL,
  PRIMARY KEY (key_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
