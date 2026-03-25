<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Config;

use App\Database\Connection;
use App\Security\Crypto;

/**
 * Gestisce la tabella `settings` nel DB MariaDB.
 * Contiene tutti i parametri applicativi (~60 variabili ex-.env):
 * entity, backoffice, frontoffice, govpay, pagopa, iam_proxy, ui.
 *
 * I valori con encrypted=1 vengono cifrati/decifrati tramite Crypto.
 */
class SettingsRepository
{
    /** @var array<string, array<string, string|null>>|null */
    private static ?array $cache = null;

    /** @var array<string, bool>|null flag encrypted per chiave */
    private static ?array $encryptedFlags = null;

    // -------------------------------------------------------------------------
    // LETTURA
    // -------------------------------------------------------------------------

    /**
     * Legge un singolo valore.
     * @param string $section  es. "govpay"
     * @param string $key      es. "pendenze_url"
     * @param mixed  $default
     */
    public static function get(string $section, string $key, mixed $default = null): mixed
    {
        self::ensureLoaded();

        $cacheKey = "{$section}.{$key}";
        if (!array_key_exists($cacheKey, self::$cache)) {
            return $default;
        }

        $value = self::$cache[$cacheKey];
        if ($value === null || $value === '') {
            return $default;
        }

        // Decifra se necessario
        if (self::$encryptedFlags[$cacheKey] ?? false) {
            try {
                return Crypto::decrypt($value);
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * Ritorna tutti i valori di una sezione come array key => value (decifrati).
     */
    public static function getSection(string $section): array
    {
        self::ensureLoaded();

        $prefix = "{$section}.";
        $result = [];
        foreach (self::$cache as $cacheKey => $value) {
            if (!str_starts_with($cacheKey, $prefix)) {
                continue;
            }
            $key = substr($cacheKey, strlen($prefix));
            if ($value !== null && $value !== '' && (self::$encryptedFlags[$cacheKey] ?? false)) {
                try {
                    $value = Crypto::decrypt($value);
                } catch (\Throwable) {
                    // restituisce valore grezzo
                }
            }
            $result[$key] = $value;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // SCRITTURA
    // -------------------------------------------------------------------------

    /**
     * Scrive/aggiorna un singolo valore.
     */
    public static function set(string $section, string $key, ?string $value, bool $encrypted = false, ?string $updatedBy = null): void
    {
        $pdo = Connection::getPDO();

        $storedValue = $value;
        if ($encrypted && $value !== null && $value !== '') {
            $storedValue = Crypto::encrypt($value);
        }

        $sql = "INSERT INTO settings (section, key_name, value, encrypted, updated_by)
                VALUES (:section, :key, :value, :encrypted, :updated_by)
                ON DUPLICATE KEY UPDATE value = :value2, encrypted = :encrypted2, updated_by = :updated_by2";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':section'      => $section,
            ':key'          => $key,
            ':value'        => $storedValue,
            ':encrypted'    => $encrypted ? 1 : 0,
            ':updated_by'   => $updatedBy,
            ':value2'       => $storedValue,
            ':encrypted2'   => $encrypted ? 1 : 0,
            ':updated_by2'  => $updatedBy,
        ]);

        // Invalida cache
        self::$cache = null;
        self::$encryptedFlags = null;
    }

    /**
     * Salva un'intera sezione con upsert batch.
     * $data = ['key_name' => ['value' => '...', 'encrypted' => false]]
     * oppure semplicemente ['key_name' => 'valore'] (encrypted = false implicito)
     */
    public static function setSection(string $section, array $data, ?string $updatedBy = null): void
    {
        $pdo = Connection::getPDO();

        $pdo->beginTransaction();
        try {
            foreach ($data as $key => $item) {
                if (is_array($item)) {
                    $value = $item['value'] ?? null;
                    $encrypted = (bool)($item['encrypted'] ?? false);
                } else {
                    $value = $item;
                    $encrypted = false;
                }

                $storedValue = $value;
                if ($encrypted && $value !== null && $value !== '') {
                    $storedValue = Crypto::encrypt((string)$value);
                }

                $sql = "INSERT INTO settings (section, key_name, value, encrypted, updated_by)
                        VALUES (:section, :key, :value, :encrypted, :updated_by)
                        ON DUPLICATE KEY UPDATE value = :value2, encrypted = :encrypted2, updated_by = :updated_by2";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':section'      => $section,
                    ':key'          => (string)$key,
                    ':value'        => $storedValue,
                    ':encrypted'    => $encrypted ? 1 : 0,
                    ':updated_by'   => $updatedBy,
                    ':value2'       => $storedValue,
                    ':encrypted2'   => $encrypted ? 1 : 0,
                    ':updated_by2'  => $updatedBy,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Invalida cache
        self::$cache = null;
        self::$encryptedFlags = null;
    }

    // -------------------------------------------------------------------------
    // UTILITY
    // -------------------------------------------------------------------------

    /**
     * True se la tabella settings esiste e ha almeno una riga.
     */
    public static function isBootstrapped(): bool
    {
        try {
            $pdo = Connection::getPDO();
            $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
            return $stmt && (int)$stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * True se la tabella settings esiste (anche se vuota).
     */
    public static function tableExists(): bool
    {
        try {
            $pdo = Connection::getPDO();
            $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
            return $stmt && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Invalida la cache in memoria (chiamare dopo una setSection se si vuole rileggere subito).
     */
    public static function flush(): void
    {
        self::$cache = null;
        self::$encryptedFlags = null;
    }

    /**
     * Esporta tutte le impostazioni come array (valori decifrati).
     * Usato dal sistema di backup.
     */
    public static function exportAll(): array
    {
        self::ensureLoaded();
        $result = [];

        foreach (self::$cache as $cacheKey => $value) {
            [$section, $key] = explode('.', $cacheKey, 2);
            $encrypted = self::$encryptedFlags[$cacheKey] ?? false;
            $plainValue = $value;

            if ($encrypted && $value !== null && $value !== '') {
                try {
                    $plainValue = Crypto::decrypt($value);
                } catch (\Throwable) {
                    // usa valore grezzo
                }
            }

            $result[$section][$key] = $plainValue;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // PRIVATE
    // -------------------------------------------------------------------------

    private static function ensureLoaded(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];
        self::$encryptedFlags = [];

        try {
            $pdo = Connection::getPDO();
            $stmt = $pdo->query("SELECT section, key_name, value, encrypted FROM settings");
            if (!$stmt) {
                return;
            }
            while ($row = $stmt->fetch()) {
                $cacheKey = $row['section'] . '.' . $row['key_name'];
                self::$cache[$cacheKey] = $row['value'];
                self::$encryptedFlags[$cacheKey] = (bool)$row['encrypted'];
            }
        } catch (\Throwable) {
            // DB non disponibile → cache rimane vuota
        }
    }
}
