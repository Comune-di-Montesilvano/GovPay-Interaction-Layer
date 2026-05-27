ALTER TABLE users
    ADD COLUMN IF NOT EXISTS session_token VARCHAR(64) NULL DEFAULT NULL
    AFTER last_password_change_at;
