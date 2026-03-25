<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Config;

/**
 * Legge config.json dal volume gil_config.
 * Contiene solo i parametri di bootstrap (credenziali DB, encryption key, chiavi SATOSA, master_token).
 * Tutti gli altri parametri applicativi sono in tabella settings → SettingsRepository.
 */
class ConfigLoader
{
    private const CONFIG_PATH_ENV = 'CONFIG_PATH';
    private const CONFIG_PATH_DEFAULT = '/config/config.json';

    private static ?array $config = null;

    private static function getConfigPath(): string
    {
        return getenv(self::CONFIG_PATH_ENV) ?: self::CONFIG_PATH_DEFAULT;
    }

    private static function load(): void
    {
        if (self::$config !== null) {
            return;
        }

        $path = self::getConfigPath();

        if (!file_exists($path)) {
            self::$config = [];
            return;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            self::$config = [];
            return;
        }

        $decoded = json_decode($raw, true);
        self::$config = is_array($decoded) ? $decoded : [];
    }

    /**
     * Legge un valore da config.json usando dot notation (es. "db.password", "app.encryption_key").
     * Se il valore non è presente, ritorna il fallback da getenv(), poi $default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        // Dot notation traversal
        $parts = explode('.', $key);
        $value = self::$config;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                $value = null;
                break;
            }
            $value = $value[$part];
        }

        if ($value !== null && $value !== '') {
            return $value;
        }

        // Fallback: ENV_KEY derivato dalla dot notation (es. db.password → DB_PASSWORD)
        $envKey = strtoupper(str_replace('.', '_', $key));
        $envVal = getenv($envKey);
        if ($envVal !== false && $envVal !== '') {
            return $envVal;
        }

        return $default;
    }

    /**
     * Ritorna true se config.json esiste e setup_complete = true.
     */
    public static function isSetupComplete(): bool
    {
        self::load();

        if (empty(self::$config)) {
            return false;
        }

        return (bool)(self::$config['setup_complete'] ?? false);
    }

    /**
     * Forza il reload di config.json dal disco (usato dopo che il wizard scrive il file).
     */
    public static function reload(): void
    {
        self::$config = null;
        self::load();
    }

    /**
     * Ritorna true se il file config.json esiste fisicamente.
     */
    public static function configFileExists(): bool
    {
        return file_exists(self::getConfigPath());
    }

    /**
     * Scrive config.json sul volume (usato dal wizard al completamento).
     * @throws \RuntimeException se la scrittura fallisce
     */
    public static function write(array $config): void
    {
        $path = self::getConfigPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            throw new \RuntimeException("Directory config non trovata: {$dir}");
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Impossibile serializzare config.json: ' . json_last_error_msg());
        }

        $written = file_put_contents($path, $json, LOCK_EX);
        if ($written === false) {
            throw new \RuntimeException("Impossibile scrivere config.json in: {$path}");
        }

        // Permessi restrittivi
        @chmod($path, 0600);

        self::reload();
    }
}
