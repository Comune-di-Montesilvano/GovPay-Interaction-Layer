-- 008_user_groups.sql
CREATE TABLE user_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    descrizione TEXT NULL,
    default_id_entrata VARCHAR(128) NULL,
    created_at DATETIME NOT NULL DEFAULT NOW(),
    updated_at DATETIME NOT NULL DEFAULT NOW(),
    UNIQUE KEY uniq_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_group_members (
    group_id INT UNSIGNED NOT NULL,
    user_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_group_tipologie (
    group_id   INT UNSIGNED  NOT NULL,
    id_dominio VARCHAR(64)   NOT NULL,
    id_entrata VARCHAR(128)  NOT NULL,
    PRIMARY KEY (group_id, id_dominio, id_entrata),
    FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_group_templates (
    group_id    INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, template_id),
    FOREIGN KEY (group_id)    REFERENCES user_groups(id)       ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES pendenza_template(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
