-- Migrazione 007: tabelle io_services e io_service_tipologie
-- Idempotente: usa CREATE TABLE IF NOT EXISTS

CREATE TABLE IF NOT EXISTS io_services (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome               VARCHAR(255) NOT NULL,
    descrizione        TEXT         NULL,
    id_service         VARCHAR(255) NOT NULL UNIQUE,
    api_key_primaria   VARCHAR(512) NOT NULL,
    api_key_secondaria VARCHAR(512) NULL,
    codice_catalogo    VARCHAR(255) NULL,
    is_default         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_service_tipologie (
    id_entrata    VARCHAR(128) NOT NULL,
    io_service_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (id_entrata, io_service_id),
    FOREIGN KEY (io_service_id) REFERENCES io_services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
