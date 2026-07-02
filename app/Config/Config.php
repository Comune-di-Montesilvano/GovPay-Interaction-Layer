<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Config;

/**
 * Facade unificata per leggere i parametri di configurazione.
 *
 * Priorità di lookup:
 *   1. SettingsRepository (tabella DB settings) — per variabili applicative
 *   2. ConfigLoader (config.json) — per variabili di bootstrap
 *   3. $default
 *
 * Mappa da ENV_KEY → (section, key_name) per la risoluzione via DB.
 */
class Config
{
    private const REPOSITORY_URL = 'https://github.com/Comune-di-Montesilvano/GovPay-Interaction-Layer';

    /**
     * Mappa ENV_KEY => ['section' => '...', 'key' => '...']
     * Tutti i parametri che erano in .env e ora risiedono nella tabella settings.
     */
    private const ENV_TO_SETTINGS = [
        // entity
        'APP_ENTITY_IPA_CODE'              => ['section' => 'entity', 'key' => 'ipa_code'],
        'APP_ENTITY_NAME'                  => ['section' => 'entity', 'key' => 'name'],
        'APP_ENTITY_SUFFIX'                => ['section' => 'entity', 'key' => 'suffix'],
        'APP_ENTITY_GOVERNMENT'            => ['section' => 'entity', 'key' => 'government'],
        'APP_ENTITY_URL'                   => ['section' => 'entity', 'key' => 'url'],
        'APP_SUPPORT_EMAIL'                => ['section' => 'entity', 'key' => 'support_email'],
        'APP_SUPPORT_PHONE'                => ['section' => 'entity', 'key' => 'support_phone'],
        'APP_SUPPORT_HOURS'                => ['section' => 'entity', 'key' => 'support_hours'],
        'APP_SUPPORT_LOCATION'             => ['section' => 'entity', 'key' => 'support_location'],
        'ID_DOMINIO'                       => ['section' => 'entity', 'key' => 'id_dominio'],
        'ID_A2A'                           => ['section' => 'entity', 'key' => 'id_a2a'],

        // backoffice
        'BACKOFFICE_PUBLIC_BASE_URL'        => ['section' => 'backoffice', 'key' => 'public_base_url'],
        'APACHE_SERVER_NAME'               => ['section' => 'backoffice', 'key' => 'apache_server_name'],
        'BACKOFFICE_MAILER_DSN'            => ['section' => 'backoffice', 'key' => 'mailer_dsn'],
        'BACKOFFICE_MAILER_FROM_ADDRESS'   => ['section' => 'backoffice', 'key' => 'mailer_from_address'],
        'BACKOFFICE_MAILER_FROM_NAME'      => ['section' => 'backoffice', 'key' => 'mailer_from_name'],

        // frontoffice
        'FRONTOFFICE_PUBLIC_BASE_URL'       => ['section' => 'frontoffice', 'key' => 'public_base_url'],
        'FRONTOFFICE_AUTH_PROXY_TYPE'      => ['section' => 'frontoffice', 'key' => 'auth_proxy_type'],

        // govpay
        'GOVPAY_PENDENZE_URL'              => ['section' => 'govpay', 'key' => 'pendenze_url'],
        'GOVPAY_PAGAMENTI_URL'             => ['section' => 'govpay', 'key' => 'pagamenti_url'],
        'GOVPAY_RAGIONERIA_URL'            => ['section' => 'govpay', 'key' => 'ragioneria_url'],
        'GOVPAY_BACKOFFICE_URL'            => ['section' => 'govpay', 'key' => 'backoffice_url'],
        'GOVPAY_PENDENZE_PATCH_URL'        => ['section' => 'govpay', 'key' => 'pendenze_patch_url'],
        'GOVPAY_CHECKOUT_URL'              => ['section' => 'govpay', 'key' => 'checkout_url'],
        'AUTHENTICATION_GOVPAY'            => ['section' => 'govpay', 'key' => 'authentication_method'],
        'GOVPAY_USER'                      => ['section' => 'govpay', 'key' => 'user'],
        'GOVPAY_PASSWORD'                  => ['section' => 'govpay', 'key' => 'password'],
        'GOVPAY_TLS_CERT'                  => ['section' => 'govpay', 'key' => 'tls_cert_path'],
        'GOVPAY_TLS_KEY'                   => ['section' => 'govpay', 'key' => 'tls_key_path'],

        // pagopa
        'PAGOPA_CHECKOUT_EC_BASE_URL'      => ['section' => 'pagopa', 'key' => 'checkout_ec_base_url'],
        'PAGOPA_CHECKOUT_SUBSCRIPTION_KEY' => ['section' => 'pagopa', 'key' => 'checkout_subscription_key'],
        'PAGOPA_CHECKOUT_COMPANY_NAME'     => ['section' => 'pagopa', 'key' => 'checkout_company_name'],
        'PAGOPA_CHECKOUT_RETURN_OK_URL'    => ['section' => 'pagopa', 'key' => 'checkout_return_ok_url'],
        'PAGOPA_CHECKOUT_RETURN_CANCEL_URL'=> ['section' => 'pagopa', 'key' => 'checkout_return_cancel_url'],
        'PAGOPA_CHECKOUT_RETURN_ERROR_URL' => ['section' => 'pagopa', 'key' => 'checkout_return_error_url'],
        'PAGOPA_EBOLLO_BASE_URL'           => ['section' => 'pagopa', 'key' => 'ebollo_base_url'],
        'PAGOPA_EBOLLO_MODE'               => ['section' => 'pagopa', 'key' => 'ebollo_mode'],
        'PAGOPA_EBOLLO_SUBSCRIPTION_KEY'   => ['section' => 'pagopa', 'key' => 'ebollo_subscription_key'],
        'PAGOPA_EBOLLO_SUBSCRIPTION_KEY_SECONDARY' => ['section' => 'pagopa', 'key' => 'ebollo_subscription_key_secondary'],
        'PAGOPA_EBOLLO_ID_CI_SERVICE'      => ['section' => 'pagopa', 'key' => 'ebollo_id_ci_service'],
        'PAGOPA_PAYMENT_OPTIONS_URL'       => ['section' => 'pagopa', 'key' => 'payment_options_url'],
        'PAGOPA_PAYMENT_OPTIONS_KEY'       => ['section' => 'pagopa', 'key' => 'payment_options_key'],
        'BIZ_EVENTS_HOST'                  => ['section' => 'pagopa', 'key' => 'biz_events_host'],
        'BIZ_EVENTS_API_KEY'               => ['section' => 'pagopa', 'key' => 'biz_events_api_key'],
        'TASSONOMIE_PAGOPA'                => ['section' => 'pagopa', 'key' => 'tassonomie_url'],

        // ui
        'APP_LOGO_SRC'                     => ['section' => 'ui', 'key' => 'logo_src'],
        'APP_LOGO_TYPE'                    => ['section' => 'ui', 'key' => 'logo_type'],
    ];

    /**
     * Legge un parametro di configurazione per chiave ENV.
     *
     * Priorità: SettingsRepository → ConfigLoader → $default
     */
    public static function get(string $envKey, mixed $default = null): mixed
    {
        // 1. Prova dalla tabella settings
        if (isset(self::ENV_TO_SETTINGS[$envKey])) {
            $map = self::ENV_TO_SETTINGS[$envKey];
            try {
                $val = SettingsRepository::get($map['section'], $map['key']);
                if ($val !== null && $val !== '') {
                    return $val;
                }
            } catch (\Throwable) {
                // DB non disponibile, continua con fallback
            }
        }

        // 2. Prova da config.json (dot notation: SECTION_KEY → section.key)
        // Converti ENV_KEY in dot notation: APP_ENCRYPTION_KEY → app.encryption_key
        $dotKey = strtolower(str_replace('_', '.', $envKey, $count));
        // Solo per chiavi note in config.json (bootstrap keys)
        $configVal = ConfigLoader::get($dotKey);
        if ($configVal !== null && $configVal !== '') {
            return $configVal;
        }

        return $default;
    }

    /**
     * Ritorna la lista completa delle chiavi ENV mappate nel DB.
     * Utile per la migrazione da .env a settings table.
     */
    public static function getMappedEnvKeys(): array
    {
        return array_keys(self::ENV_TO_SETTINGS);
    }

    /**
     * Ritorna la mappatura (section, key) per una ENV_KEY, o null se non mappata.
     */
    public static function getMapping(string $envKey): ?array
    {
        return self::ENV_TO_SETTINGS[$envKey] ?? null;
    }

    /**
     * Recupera la versione applicativa leggendo il file VERSION nella root del progetto.
     * Fallback su GIL_IMAGE_TAG se non presente (es. avvio senza build/volume).
     */
    public static function getVersion(): string
    {
        $info = self::getVersionInfo();

        return (string) ($info['version'] ?? 'development');
    }

    /**
     * Ritorna i metadati normalizzati per versioning e footer.
     *
     * Keys:
     * - version: stringa base (`development`, `dev`, `v1.2.3`, ...)
     * - version_type: `development`, `dev`, `release`, `tag`, `commit`
     * - version_label: label finale da renderizzare (`dev@sha7`, `v1.2.3`, ...)
     * - commit: full SHA se disponibile
     * - ref_url: URL GitHub finale oppure stringa vuota
     */
    public static function getVersionInfo(): array
    {
        $commit = (string) (getenv('GIT_COMMIT_SHA') ?: 'unknown');
        $version = self::readVersionFile() ?? (string) (getenv('GIL_IMAGE_TAG') ?: 'development');

        $info = [
            'version' => trim($version) !== '' ? trim($version) : 'development',
            'version_type' => '',
            'version_label' => '',
            'commit' => $commit,
            'ref_url' => '',
        ];

        $fileInfo = self::readVersionInfoFile();
        if ($fileInfo !== null) {
            $info = array_merge($info, array_intersect_key($fileInfo, $info));
        }

        $info['version'] = trim((string) ($info['version'] ?? 'development')) ?: 'development';
        $info['version_type'] = self::normalizeVersionType((string) ($info['version_type'] ?? ''), $info['version'], $commit);
        $info['version_label'] = trim((string) ($info['version_label'] ?? ''));
        if ($info['version_label'] === '') {
            $info['version_label'] = self::buildVersionLabel($info['version_type'], $info['version'], $commit);
        }

        $info['ref_url'] = trim((string) ($info['ref_url'] ?? ''));
        if ($info['ref_url'] === '') {
            $info['ref_url'] = self::buildRefUrl($info['version_type'], $info['version'], $commit);
        }

        return $info;
    }

    private static function readVersionFile(): ?string
    {
        foreach (self::getVersionCandidates('VERSION') as $filePath) {
            if (is_file($filePath)) {
                return trim((string) file_get_contents($filePath));
            }
        }

        return null;
    }

    private static function readVersionInfoFile(): ?array
    {
        foreach (self::getVersionCandidates('VERSION_INFO.json') as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($filePath), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private static function getVersionCandidates(string $fileName): array
    {
        return [
            dirname(__DIR__, 2) . '/' . $fileName,
            '/var/www/html/' . $fileName,
        ];
    }

    private static function normalizeVersionType(string $versionType, string $version, string $commit): string
    {
        $normalized = strtolower(trim($versionType));
        if (in_array($normalized, ['development', 'dev', 'release', 'tag', 'commit'], true)) {
            return $normalized;
        }

        if ($version === 'development') {
            return 'development';
        }

        if ($version === 'dev') {
            return 'dev';
        }

        if (preg_match('/^v\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1) {
            return 'release';
        }

        if ($commit !== 'unknown') {
            return 'commit';
        }

        return 'development';
    }

    private static function buildVersionLabel(string $versionType, string $version, string $commit): string
    {
        if ($versionType === 'dev') {
            return $commit !== 'unknown' ? 'dev@' . substr($commit, 0, 7) : 'dev';
        }

        if ($versionType === 'commit') {
            return $commit !== 'unknown' ? substr($commit, 0, 7) : $version;
        }

        return $version;
    }

    public static function getRepositoryUrl(): string
    {
        return self::REPOSITORY_URL;
    }

    private static function buildRefUrl(string $versionType, string $version, string $commit): string
    {
        if ($versionType === 'development') {
            return '';
        }

        if (in_array($versionType, ['dev', 'commit'], true) && $commit !== 'unknown') {
            return self::REPOSITORY_URL . '/commit/' . $commit;
        }

        if ($versionType === 'release') {
            return self::REPOSITORY_URL . '/releases/tag/' . $version;
        }

        if ($versionType === 'tag') {
            return self::REPOSITORY_URL . '/tree/' . $version;
        }

        return self::REPOSITORY_URL;
    }
}
