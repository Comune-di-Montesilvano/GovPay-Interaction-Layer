<?php
       
declare(strict_types=1);

// Crea le tabelle base se non esistono. Idempotente.
require __DIR__ . '/../vendor/autoload.php';

use App\Database\Connection;

// Recupera la connessione PDO
try {
    $pdo = Connection::getPDO();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$statements = [
    // users
    "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('superadmin','admin','user') NOT NULL DEFAULT 'user',
        first_name VARCHAR(255) NOT NULL DEFAULT '',
        last_name VARCHAR(255) NOT NULL DEFAULT '',
        is_disabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        disabled_at DATETIME NULL,
        last_login_at DATETIME NULL,
        last_password_change_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // entrate_tipologie
    "CREATE TABLE IF NOT EXISTS entrate_tipologie (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_dominio VARCHAR(64) NOT NULL,
    id_entrata VARCHAR(128) NOT NULL,
    descrizione VARCHAR(255) NULL,
    descrizione_locale VARCHAR(255) NULL,
    descrizione_estesa TEXT NULL,
    iban_accredito VARCHAR(34) NULL,
    codice_contabilita VARCHAR(128) NULL,
    tipo_bollo VARCHAR(16) NULL,
    tipo_contabilita VARCHAR(16) NULL,
        abilitato_backoffice TINYINT(1) NOT NULL DEFAULT 0,
        override_locale TINYINT(1) NULL,
        external_url VARCHAR(500) NULL,
        effective_enabled TINYINT(1) NOT NULL DEFAULT 0,
        sorgente VARCHAR(32) NOT NULL DEFAULT 'backoffice',
        updated_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uniq_dom_entrata (id_dominio, id_entrata)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // tipologie_pagamento_esterne
    "CREATE TABLE IF NOT EXISTS tipologie_pagamento_esterne (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        descrizione VARCHAR(255) NOT NULL,
        descrizione_estesa TEXT NULL,
        url VARCHAR(500) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // pendenza_template
    "CREATE TABLE IF NOT EXISTS pendenza_template (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_dominio VARCHAR(64) NOT NULL,
        titolo VARCHAR(255) NOT NULL,
        id_tipo_pendenza VARCHAR(128) NOT NULL,
        causale VARCHAR(140) NOT NULL,
        importo DECIMAL(10,2) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // pendenza_template_users
    "CREATE TABLE IF NOT EXISTS pendenza_template_users (
        template_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (template_id, user_id),
        FOREIGN KEY (template_id) REFERENCES pendenza_template(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // entrate_tipologie_users
    "CREATE TABLE IF NOT EXISTS entrate_tipologie_users (
        id_dominio VARCHAR(64) NOT NULL,
        id_entrata VARCHAR(128) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (id_dominio, id_entrata, user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // settings — parametri applicativi ex-.env (govpay, pagopa, mail, entity, iam_proxy, ui)
    "CREATE TABLE IF NOT EXISTS settings (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        section     VARCHAR(64)  NOT NULL,
        key_name    VARCHAR(128) NOT NULL,
        value       TEXT         NULL,
        encrypted   TINYINT(1)   NOT NULL DEFAULT 0,
        updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by  VARCHAR(255) NULL,
        UNIQUE KEY uniq_section_key (section, key_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // io_services — servizi App IO configurati dall'ente
    "CREATE TABLE IF NOT EXISTS io_services (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // io_service_tipologie — associazione servizi IO <-> tipologie pagamento
    "CREATE TABLE IF NOT EXISTS io_service_tipologie (
        id_entrata    VARCHAR(128) NOT NULL,
        io_service_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (id_entrata, io_service_id),
        FOREIGN KEY (io_service_id) REFERENCES io_services(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
];

foreach ($statements as $sql) {
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        fwrite(STDERR, "Error executing SQL: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Created tables (if not exists)\n";

// Assicura che la colonna tipo_bollo sia presente (per upgrade di schemi precedenti)
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM entrate_tipologie LIKE 'tipo_bollo'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE entrate_tipologie ADD COLUMN tipo_bollo VARCHAR(16) NULL AFTER codice_contabilita");
        echo "Added column tipo_bollo to entrate_tipologie\n";
    }
} catch (Throwable $e) {
    // non fatale: proseguiamo
}
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM entrate_tipologie LIKE 'tipo_contabilita'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE entrate_tipologie ADD COLUMN tipo_contabilita VARCHAR(16) NULL AFTER tipo_bollo");
        echo "Added column tipo_contabilita to entrate_tipologie\n";
    }
} catch (Throwable $e) {
    // non fatale: proseguiamo
}

// Aggiunge colonna descrizione_locale per personalizzazione descrizione (non toccata dal sync)
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM entrate_tipologie LIKE 'descrizione_locale'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE entrate_tipologie ADD COLUMN descrizione_locale VARCHAR(255) NULL AFTER descrizione");
        echo "Added column descrizione_locale to entrate_tipologie\n";
    }
} catch (Throwable $e) {
    // non fatale
}

// Campo descrizione estesa (testo lungo locale)
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM entrate_tipologie LIKE 'descrizione_estesa'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE entrate_tipologie ADD COLUMN descrizione_estesa TEXT NULL AFTER descrizione_locale");
        echo "Added column descrizione_estesa to entrate_tipologie\n";
    }
} catch (Throwable $e) {
    // non fatale
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM tipologie_pagamento_esterne LIKE 'descrizione_estesa'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE tipologie_pagamento_esterne ADD COLUMN descrizione_estesa TEXT NULL AFTER descrizione");
        echo "Added column descrizione_estesa to tipologie_pagamento_esterne\n";
    }
} catch (Throwable $e) {
    // non fatale
}

// Ensure user disable columns exist for upgraded installs
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_disabled'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_disabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER last_name");
        echo "Added column is_disabled to users\n";
    }
} catch (Throwable $e) {
    // non fatale
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'disabled_at'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE users ADD COLUMN disabled_at DATETIME NULL AFTER updated_at");
        echo "Added column disabled_at to users\n";
    }
} catch (Throwable $e) {
    // non fatale
}

// Aggiunge colonna default_id_entrata per tipologia di default
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'default_id_entrata'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE users ADD COLUMN default_id_entrata VARCHAR(128) NULL AFTER disabled_at");
        echo "Added column default_id_entrata to users\n";
    }
} catch (Throwable $e) {
    // non fatale
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER disabled_at");
        echo "Added column last_login_at to users\n";
    }
} catch (Throwable $e) {
    // non fatale
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_password_change_at'");
    $has = $stmt ? $stmt->fetch() : false;
    if (!$has) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_password_change_at DATETIME NULL AFTER last_login_at");
        echo "Added column last_password_change_at to users\n";
    }
} catch (Throwable $e) {
    // non fatale
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM pendenza_template LIKE 'anno_riferimento'");
    $has = $stmt ? $stmt->fetch() : false;
    if ($has) {
        $pdo->exec("ALTER TABLE pendenza_template DROP COLUMN anno_riferimento");
        echo "Dropped column anno_riferimento from pendenza_template\n";
    }
} catch (Throwable $e) {
    // non fatale
}

// Seeding opzionale: crea superadmin se ADMIN_EMAIL e ADMIN_PASSWORD sono definiti
$adminEmail = getenv('ADMIN_EMAIL') ?: '';
$adminPass = getenv('ADMIN_PASSWORD') ?: '';
if ($adminEmail !== '' && $adminPass !== '') {
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $adminEmail]);
        $exists = $stmt->fetchColumn();
        if (!$exists) {
            $pwdHash = password_hash($adminPass, PASSWORD_BCRYPT);
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $ins = $pdo->prepare('INSERT INTO users (email, password_hash, role, first_name, last_name, created_at, updated_at) VALUES (:email, :pwd, :role, :first, :last, :created, :updated)');
            $ins->execute([
                ':email' => $adminEmail,
                ':pwd' => $pwdHash,
                ':role' => 'superadmin',
                ':first' => getenv('ADMIN_FIRST_NAME') ?: 'Super',
                ':last' => getenv('ADMIN_LAST_NAME') ?: 'Admin',
                ':created' => $now,
                ':updated' => $now,
            ]);
            echo "Seeded superadmin: {$adminEmail}\n";
        } else {
            echo "Superadmin already exists: {$adminEmail}\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Error seeding superadmin: " . $e->getMessage() . "\n");
        // non fatal: proseguire
    }
}

exit(0);
?>
