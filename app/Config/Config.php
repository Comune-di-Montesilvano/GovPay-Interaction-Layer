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
        'PAGOPA_PAYMENT_OPTIONS_URL'       => ['section' => 'pagopa', 'key' => 'payment_options_url'],
        'PAGOPA_PAYMENT_OPTIONS_KEY'       => ['section' => 'pagopa', 'key' => 'payment_options_key'],
        'BIZ_EVENTS_HOST'                  => ['section' => 'pagopa', 'key' => 'biz_events_host'],
        'BIZ_EVENTS_API_KEY'               => ['section' => 'pagopa', 'key' => 'biz_events_api_key'],
        'TASSONOMIE_PAGOPA'                => ['section' => 'pagopa', 'key' => 'tassonomie_url'],

        // iam_proxy
        'IAM_PROXY_PUBLIC_BASE_URL'                    => ['section' => 'iam_proxy', 'key' => 'public_base_url'],
        'IAM_PROXY_SAML2_IDP_METADATA_URL'             => ['section' => 'iam_proxy', 'key' => 'saml2_idp_metadata_url'],
        'IAM_PROXY_SAML2_IDP_METADATA_URL_INTERNAL'   => ['section' => 'iam_proxy', 'key' => 'saml2_idp_metadata_url_internal'],
        'IAM_PROXY_HOSTNAME'                           => ['section' => 'iam_proxy', 'key' => 'hostname'],
        'IAM_PROXY_DEBUG'                              => ['section' => 'iam_proxy', 'key' => 'debug'],
        'ENABLE_SPID'                                  => ['section' => 'iam_proxy', 'key' => 'enable_spid'],
        'ENABLE_CIE_OIDC'                              => ['section' => 'iam_proxy', 'key' => 'enable_cie_oidc'],
        'ENABLE_IT_WALLET'                             => ['section' => 'iam_proxy', 'key' => 'enable_it_wallet'],
        'ENABLE_OIDCOP'                                => ['section' => 'iam_proxy', 'key' => 'enable_oidcop'],
        'ENABLE_IDEM'                                  => ['section' => 'iam_proxy', 'key' => 'enable_idem'],
        'ENABLE_EIDAS'                                 => ['section' => 'iam_proxy', 'key' => 'enable_eidas'],
        'SPID_CERT_COMMON_NAME'                        => ['section' => 'iam_proxy', 'key' => 'spid_cert_common_name'],
        'SPID_CERT_ORG_ID'                             => ['section' => 'iam_proxy', 'key' => 'spid_cert_org_id'],
        'SPID_CERT_ORG_NAME'                           => ['section' => 'iam_proxy', 'key' => 'spid_cert_org_name'],
        'SPID_CERT_ENTITY_ID'                          => ['section' => 'iam_proxy', 'key' => 'spid_cert_entity_id'],
        'SPID_CERT_LOCALITY_NAME'                      => ['section' => 'iam_proxy', 'key' => 'spid_cert_locality_name'],
        'SPID_CERT_KEY_SIZE'                           => ['section' => 'iam_proxy', 'key' => 'spid_cert_key_size'],
        'SPID_CERT_DAYS'                               => ['section' => 'iam_proxy', 'key' => 'spid_cert_days'],
        'SATOSA_ORGANIZATION_DISPLAY_NAME_IT'          => ['section' => 'iam_proxy', 'key' => 'satosa_org_display_name_it'],
        'SATOSA_ORGANIZATION_NAME_IT'                  => ['section' => 'iam_proxy', 'key' => 'satosa_org_name_it'],
        'SATOSA_CONTACT_PERSON_EMAIL_ADDRESS'          => ['section' => 'iam_proxy', 'key' => 'satosa_contact_email'],
        'SATOSA_CONTACT_PERSON_TELEPHONE_NUMBER'       => ['section' => 'iam_proxy', 'key' => 'satosa_contact_phone'],
        'SATOSA_CONTACT_PERSON_FISCALCODE'             => ['section' => 'iam_proxy', 'key' => 'satosa_contact_fiscalcode'],
        'SATOSA_CONTACT_PERSON_IPA_CODE'               => ['section' => 'iam_proxy', 'key' => 'satosa_contact_ipa_code'],
        'SATOSA_ORGANIZATION_IDENTIFIER'               => ['section' => 'iam_proxy', 'key' => 'satosa_org_identifier'],
        'CIE_OIDC_CLIENT_NAME'                         => ['section' => 'iam_proxy', 'key' => 'cie_oidc_client_name'],

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
        $candidates = [
            dirname(__DIR__, 2) . '/VERSION',
            '/var/www/html/VERSION',
        ];

        foreach ($candidates as $f) {
            if (file_exists($f)) {
                return trim((string) file_get_contents($f));
            }
        }

        return (string)(getenv('GIL_IMAGE_TAG') ?: 'dev');
    }
}
