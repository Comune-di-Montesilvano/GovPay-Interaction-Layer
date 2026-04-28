<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Auth;

use App\Database\Connection;
use PDO;

class UserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getPDO();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, password_hash, role, first_name, last_name, is_disabled, disabled_at, last_login_at, last_password_change_at, created_at, updated_at FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function insertUser(string $email, string $password, string $role = 'superadmin', string $firstName = '', string $lastName = '', bool $disabled = false): int
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        $stmt = $this->pdo->prepare('INSERT INTO users (email, password_hash, role, first_name, last_name, is_disabled, created_at, updated_at, disabled_at) VALUES (:email, :password_hash, :role, :first_name, :last_name, :is_disabled, NOW(), NOW(), :disabled_at)');
        $stmt->execute([
            ':email' => $email,
            ':password_hash' => $hash,
            ':role' => $role,
            ':first_name' => $firstName,
            ':last_name' => $lastName,
            ':is_disabled' => $disabled ? 1 : 0,
            ':disabled_at' => $disabled ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** @return array<int, array{id:int,email:string,role:string,created_at:string,updated_at:string}> */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, email, role, first_name, last_name, is_disabled, disabled_at, last_login_at, last_password_change_at, created_at, updated_at FROM users ORDER BY is_disabled ASC, email ASC');
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, password_hash, role, first_name, last_name, is_disabled, disabled_at, last_login_at, last_password_change_at, created_at, updated_at, default_id_entrata FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Imposta la tipologia di pendenza di default per un utente.
     */
    public function setDefaultTipologia(int $id, ?string $idEntrata): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET default_id_entrata = :entrata, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':entrata' => $idEntrata, ':id' => $id]);
    }

    public function updatePasswordById(int $id, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash, last_password_change_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    public function updateLastLoginAt(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function updateUser(int $id, string $email, string $role, string $firstName = '', string $lastName = ''): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET email = :email, role = :role, first_name = :first_name, last_name = :last_name, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':email' => $email, ':role' => $role, ':first_name' => $firstName, ':last_name' => $lastName, ':id' => $id]);
    }

    public function setDisabled(int $id, bool $disabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_disabled = :is_disabled, disabled_at = :disabled_at, updated_at = NOW() WHERE id = :id');
        $disabledAt = $disabled ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null;
        $stmt->execute([
            ':is_disabled' => $disabled ? 1 : 0,
            ':disabled_at' => $disabledAt,
            ':id' => $id,
        ]);
    }

    public function countByRole(string $role, bool $includeDisabled = true): int
    {
        $sql = 'SELECT COUNT(*) as c FROM users WHERE role = :role';
        $params = [':role' => $role];
        if (!$includeDisabled) {
            $sql .= ' AND (is_disabled IS NULL OR is_disabled = 0)';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Password reset tokens
    // -------------------------------------------------------------------------

    /**
     * Genera un token sicuro per il reset password, lo salva (hash) nel DB
     * e restituisce il token in chiaro da includere nell'URL dell'email.
     * Il token scade dopo $ttlMinutes minuti.
     */
    public function createPasswordResetToken(string $email, int $ttlMinutes = 60): string
    {
        // Pulizia token scaduti o già usati
        $this->deleteExpiredTokens();

        // Invalida eventuali token precedenti per la stessa email
        $stmt = $this->pdo->prepare('DELETE FROM password_resets WHERE email = :email');
        $stmt->execute([':email' => $email]);

        // Genera token crittograficamente sicuro
        $token = bin2hex(random_bytes(32)); // 64 caratteri hex
        $hash  = hash('sha256', $token);
        $expires = (new \DateTimeImmutable())->modify("+{$ttlMinutes} minutes")->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (email, token_hash, expires_at) VALUES (:email, :hash, :expires)'
        );
        $stmt->execute([':email' => $email, ':hash' => $hash, ':expires' => $expires]);

        return $token;
    }

    /**
     * Trova un token valido (non scaduto, non usato) e restituisce la riga
     * o null se non valido.
     */
    public function findValidResetToken(string $token): ?array
    {
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM password_resets
             WHERE token_hash = :hash
               AND expires_at > NOW()
               AND used_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Segna il token come usato (monouso).
     */
    public function consumeResetToken(string $token): void
    {
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE token_hash = :hash'
        );
        $stmt->execute([':hash' => $hash]);
    }

    /**
     * Rimuove i token scaduti o già usati.
     */
    public function deleteExpiredTokens(): void
    {
        $this->pdo->exec(
            'DELETE FROM password_resets WHERE expires_at <= NOW() OR used_at IS NOT NULL'
        );
    }
}
