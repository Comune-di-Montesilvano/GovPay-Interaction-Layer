<?php
declare(strict_types=1);

use App\Database\EntrateRepository;
use App\Database\ExternalPaymentTypeRepository;
use App\Logger;
use App\Services\ValidationService;
use GovPay\Pagamenti\Api\PendenzeApi as PagamentiPendenzeApi;
use GovPay\Pagamenti\Configuration as PagamentiConfiguration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\Metadata;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('govpay_frontoffice');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (!$isHttps && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $isHttps = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    $authProxyTypeFrontoffice = strtolower(trim((string)(\App\Config\SettingsRepository::get('frontoffice', 'auth_proxy_type', 'none') ?? 'none')));
    $spidEnabledByProfile = in_array($authProxyTypeFrontoffice, ['spid', 'cie', 'spid_cie'], true);

    // Per callback SPID via POST cross-site (dal proxy al frontoffice) i browser non inviano cookie SameSite=Lax.
    // Quindi, quando SPID è abilitato, serve SameSite=None (che richiede anche Secure).
    $sameSite = ($spidEnabledByProfile && $isHttps) ? 'None' : 'Lax';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
    session_start();
}

if (!function_exists('frontoffice_env_value')) {
    function frontoffice_env_value(string $key, ?string $default = null): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }
        if ($value === null || $value === '') {
            try {
                $cfgValue = \App\Config\Config::get($key, null);
                if ($cfgValue !== null && $cfgValue !== '') {
                    return (string) $cfgValue;
                }
            } catch (\Throwable) {
                // Se la risoluzione DB/config non e' disponibile, usa il default.
            }
        }
        if ($value === null || $value === '') {
            return $default ?? '';
        }
        return (string) $value;
    }
}

$env = static function (string $key, ?string $default = null): string {
    return frontoffice_env_value($key, $default);
};

if (!function_exists('frontoffice_load_pem_value')) {
    function frontoffice_load_pem_value(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (str_contains($trimmed, 'BEGIN ')) {
            return $trimmed;
        }
        if (is_file($trimmed)) {
            $content = @file_get_contents($trimmed);
            return $content !== false ? trim($content) : '';
        }
        return $trimmed;
    }
}

if (!function_exists('frontoffice_inject_spid_contact_extensions')) {
    function frontoffice_inject_spid_contact_extensions(string $metadata, string $ipaCode): string
    {
        if ($ipaCode === '') {
            $ipaCode = 'c_x000';
        }
        if (strpos($metadata, 'xmlns:spid=') === false) {
            $metadata = preg_replace('/<md:EntityDescriptor\b/', '<md:EntityDescriptor xmlns:spid="https://spid.gov.it/saml-extensions"', $metadata, 1);
        }
        if (strpos($metadata, '<spid:IPACode>') === false) {
            $metadata = preg_replace('/(<md:ContactPerson[^>]*contactType="other"[^>]*>)/', '$1' . "\n        <md:Extensions>\n            <spid:IPACode>{$ipaCode}</spid:IPACode>\n            <spid:Public />\n        </md:Extensions>", $metadata, 1);
        }
        return $metadata;
    }
}

if (!function_exists('frontoffice_load_service_options')) {
    function frontoffice_load_service_options(): array
    {
        $options = [];
        $internalOptions = [];
        $externalOptions = [];
        $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
        if ($idDominio !== '') {
            try {
                $repo = new EntrateRepository();
                $rows = $repo->listByDominio($idDominio);
                foreach ($rows as $row) {
                    if ((int)($row['abilitato_backoffice'] ?? 0) !== 1) {
                        continue;
                    }
                    $id = (string)($row['id_entrata'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $label = trim((string)($row['descrizione_effettiva'] ?? $row['descrizione'] ?? $id));
                    if ($label === '') {
                        $label = $id;
                    }
                    $externalUrl = trim((string)($row['external_url'] ?? '')) ?: null;
                    $descrizioneEstesa = trim((string)($row['descrizione_estesa'] ?? ''));
                    $internalOptions[] = [
                        'id' => $id,
                        'label' => $label,
                        'type' => $externalUrl ? 'external' : 'internal',
                        'external_url' => $externalUrl,
                        'descrizione_estesa' => $descrizioneEstesa !== '' ? $descrizioneEstesa : null,
                    ];
                }
                if ($internalOptions !== []) {
                    $internalCount = count(array_filter($internalOptions, static fn ($opt) => ($opt['type'] ?? 'internal') === 'internal'));
                    $externalCount = count($internalOptions) - $internalCount;
                    Logger::getInstance()->info('Tipologie frontoffice caricate dal DB', [
                        'idDominio' => $idDominio,
                        'internal' => $internalCount,
                        'external' => $externalCount,
                    ]);
                }
            } catch (\Throwable $e) {
                Logger::getInstance()->warning('Impossibile caricare le tipologie per il frontoffice', ['error' => $e->getMessage()]);
            }
        }

        // Tipologie di pagamento esterne (catalogo locale) - sempre indipendenti dal dominio
        try {
            $repoExternal = new ExternalPaymentTypeRepository();
            $rows = $repoExternal->listAll();
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = (string)($row['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $label = trim((string)($row['descrizione'] ?? ''));
                if ($label === '') {
                    $label = 'Servizio esterno ' . $id;
                }
                $url = trim((string)($row['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $descrizioneEstesa = trim((string)($row['descrizione_estesa'] ?? ''));
                $externalOptions[] = [
                    'id' => 'EXT:' . $id,
                    'label' => $label,
                    'type' => 'external',
                    'external_url' => $url,
                    'descrizione_estesa' => $descrizioneEstesa !== '' ? $descrizioneEstesa : null,
                ];
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Impossibile caricare le tipologie esterne per il frontoffice', ['error' => $e->getMessage()]);
        }

        $options = array_merge($internalOptions, $externalOptions);

        if ($options === []) {
            Logger::getInstance()->warning('Tipologie frontoffice assenti dal DB: uso fallback statico', ['idDominio' => $idDominio]);
            $options = [
                ['id' => 'SERV_MENSA', 'label' => 'Mensa e servizi scolastici', 'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_NIDI', 'label' => "Nidi d'infanzia / rette asilo", 'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_OCCUPAZIONE_SUOLO', 'label' => 'Occupazione suolo pubblico', 'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_SANZIONI', 'label' => 'Sanzioni e contravvenzioni', 'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_DIRITTI_SEGRETERIA', 'label' => 'Diritti di segreteria e certificati', 'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_ALTRO', 'label' => 'Altro pagamento spontaneo', 'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
            ];
        }

        usort($options, static fn ($a, $b) => strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? '')));
        return $options;
    }
}

if (!function_exists('frontoffice_find_service_option')) {
    function frontoffice_find_service_option(array $options, string $id): ?array
    {
        foreach ($options as $option) {
            if (($option['id'] ?? null) === $id) {
                return $option;
            }
        }
        return null;
    }
}

if (!function_exists('frontoffice_basic_auth')) {
    function frontoffice_basic_auth(): ?array
    {
        $username = \App\Config\SettingsRepository::get('govpay', 'user', '') ?: frontoffice_env_value('GOVPAY_USER', '');
        $password = \App\Config\SettingsRepository::get('govpay', 'password', '') ?: frontoffice_env_value('GOVPAY_PASSWORD', '');
        if ($username !== '' && $password !== '') {
            return [$username, $password];
        }
        return null;
    }
}

if (!function_exists('frontoffice_govpay_client_options')) {
    function frontoffice_govpay_client_options(): array
    {
        $options = [];
        $authMethod = \App\Config\SettingsRepository::get('govpay', 'authentication_method', '') ?: frontoffice_env_value('AUTHENTICATION_GOVPAY', '');
        if (in_array(strtolower($authMethod), ['ssl', 'sslheader'], true)) {
            $cert = \App\Config\SettingsRepository::get('govpay', 'tls_cert_path', '') ?: frontoffice_env_value('GOVPAY_TLS_CERT', '');
            $key = \App\Config\SettingsRepository::get('govpay', 'tls_key_path', '') ?: frontoffice_env_value('GOVPAY_TLS_KEY', '');
            $keyPass = \App\Config\SettingsRepository::get('govpay', 'tls_key_password') ?: null;
            if ($cert === '' || $key === '') {
                throw new \RuntimeException('mTLS abilitato ma certificati GovPay non configurati');
            }
            $options['cert'] = $cert;
            $options['ssl_key'] = ($keyPass !== null && $keyPass !== '')
                ? [$key, $keyPass]
                : $key;
        }
        return $options;
    }
}

if (!function_exists('frontoffice_pagamenti_api_client')) {
    function frontoffice_pagamenti_api_client(): ?PagamentiPendenzeApi
    {
        $pagamentiUrl = frontoffice_env_value('GOVPAY_PAGAMENTI_URL', '');
        if ($pagamentiUrl === '') {
            Logger::getInstance()->warning('GOVPAY_PAGAMENTI_URL non impostata per il frontoffice');
            return null;
        }
        if (!class_exists(PagamentiPendenzeApi::class)) {
            Logger::getInstance()->warning('Client GovPay Pagamenti non disponibile nel frontoffice');
            return null;
        }

        try {
            $config = new PagamentiConfiguration();
            $config->setHost(rtrim($pagamentiUrl, '/'));
            if ($auth = frontoffice_basic_auth()) {
                $config->setUsername($auth[0]);
                $config->setPassword($auth[1]);
            }
            $client = new Client(frontoffice_govpay_client_options());
            return new PagamentiPendenzeApi($client, $config);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Impossibile istanziare il client GovPay Pagamenti', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

if (!function_exists('frontoffice_get_logged_user')) {
    function frontoffice_get_logged_user(): ?array
    {
        $user = $_SESSION['frontoffice_user'] ?? null;
        if (!is_array($user) || $user === []) {
            return null;
        }

        $email = trim((string)($user['email'] ?? $user['mail'] ?? $user['emailAddress'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $user['email'] = $email;
        }

        $fiscalNumber = trim((string)($user['fiscal_number'] ?? $user['fiscalNumber'] ?? $user['codiceFiscale'] ?? ''));
        if ($fiscalNumber !== '') {
            $user['fiscal_number'] = frontoffice_normalize_fiscal_number($fiscalNumber);
        }

        $_SESSION['frontoffice_user'] = $user;

        return $user;
    }
}

if (!function_exists('frontoffice_pick_attribute_value')) {
    function frontoffice_pick_attribute_value(array $attrs, array $keys): string
    {
        $extract = null;
        $extract = static function ($value) use (&$extract): string {
            if (is_array($value)) {
                // Support both list-like values and nested dict/object payloads.
                foreach (['value', 'val', 'text', 'content'] as $candidateKey) {
                    if (array_key_exists($candidateKey, $value)) {
                        $nested = $extract($value[$candidateKey]);
                        if ($nested !== '') {
                            return $nested;
                        }
                    }
                }
                foreach ($value as $item) {
                    $nested = $extract($item);
                    if ($nested !== '') {
                        return $nested;
                    }
                }
                return '';
            }

            if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                return trim((string)$value);
            }

            return '';
        };

        foreach ($keys as $k) {
            if (array_key_exists($k, $attrs)) {
                $picked = $extract($attrs[$k]);
                if ($picked !== '') {
                    return $picked;
                }
            }
        }

        $lowerMap = [];
        foreach ($attrs as $k => $v) {
            if (is_string($k)) {
                $lowerMap[strtolower($k)] = $v;
            }
        }

        foreach ($keys as $k) {
            $lk = strtolower($k);
            if (array_key_exists($lk, $lowerMap)) {
                $picked = $extract($lowerMap[$lk]);
                if ($picked !== '') {
                    return $picked;
                }
            }
        }

        return '';
    }
}

if (!function_exists('frontoffice_pick_email_value')) {
    function frontoffice_pick_email_value(array $attrs): string
    {
        $email = frontoffice_pick_attribute_value($attrs, ['email', 'mail', 'emailAddress', 'e-mail', 'urn:oid:0.9.2342.19200300.100.1.3']);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return $email;
        }

        // Fallback: cerca un valore email-like negli attributi, ma escludi
        // attributi noti che possono contenere '@' senza essere email reali
        // (es. CIE sub: XXXXX@idserver.servizicie.interno.gov.it)
        $excludeKeys = ['sub', 'spidCode', 'spid_code', 'fiscalNumber', 'fiscal_number',
                        'fiscalnumber', 'FiscalNumber', 'edupersontargetedid',
                        'eduPersonTargetedID', 'eduPersonTargetedId',
                        'https://attributes.eid.gov.it/fiscal_number',
                        'https://attributes.spid.gov.it/fiscalNumber',
                        'urn:oid:2.5.4.97', 'urn:oid:1.3.6.1.4.1.4710.2.1.1'];
        // Domini IdP noti che non sono email reali
        $excludeDomains = ['idserver.servizicie.interno.gov.it'];

        $extract = null;
        $extract = static function ($value) use (&$extract): array {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $item) {
                    $out = array_merge($out, $extract($item));
                }
                return $out;
            }

            if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
                $v = trim((string)$value);
                return $v !== '' ? [$v] : [];
            }

            return [];
        };

        foreach ($attrs as $key => $value) {
            if (in_array($key, $excludeKeys, true)) {
                continue;
            }
            foreach ($extract($value) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    // Escludi domini IdP noti (non sono email reali)
                    $domain = strtolower(substr($candidate, strrpos($candidate, '@') + 1));
                    $skip = false;
                    foreach ($excludeDomains as $ed) {
                        if ($domain === $ed || str_ends_with($domain, '.' . $ed)) {
                            $skip = true;
                            break;
                        }
                    }
                    if (!$skip) {
                        return $candidate;
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('frontoffice_detect_auth_provider_name')) {
    function frontoffice_detect_auth_provider_name($auth): string
    {
        if (!$auth || !method_exists($auth, 'getLastResponseXML')) {
            return 'IAM Proxy';
        }

        $xml = (string)$auth->getLastResponseXML();

        // 1. Check AuthenticatingAuthority (provided by proxy if forwarding upstream IdP)
        if (preg_match('/<[^>:]*:AuthenticatingAuthority[^>]*>(.*?)<\/[^>:]*:AuthenticatingAuthority>/i', $xml, $matches)) {
            $authnAuth = strtolower($matches[1]);
            if (str_contains($authnAuth, 'cie') || str_contains($authnAuth, 'servizicie')) {
                return 'CIE';
            }
            if (str_contains($authnAuth, 'eidas')) {
                return 'eIDAS';
            }
            if (str_contains($authnAuth, 'spid')) {
                return 'SPID';
            }
        }

        // 2. Fallback text search in XML for known issuers
        if (stripos($xml, 'demo.spid.gov.it') !== false || stripos($xml, 'validator.spid.gov.it') !== false) {
            return 'SPID (Demo)';
        }
        if (stripos($xml, 'idserver.servizicie.interno.gov.it') !== false) {
            return 'CIE';
        }

        // 3. Fallback check on attributes
        $attrs = $auth->getAttributes() ?? [];
        if (isset($attrs['spidCode']) || isset($attrs['spid_code'])) {
            return 'SPID';
        }
        if (isset($attrs['https://attributes.eid.gov.it/fiscal_number'])) {
            return 'CIE';
        }

        return 'SPID/CIE';
    }
}

if (!function_exists('frontoffice_extract_saml_attributes_from_xml')) {
    function frontoffice_extract_saml_attributes_from_xml(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }

        $doc = new \DOMDocument();
        $loaded = @$doc->loadXML($xml);
        if ($loaded !== true) {
            return [];
        }

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('saml2', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $nodes = $xp->query('//saml2:Attribute');
        if (!$nodes) {
            return [];
        }

        $out = [];
        foreach ($nodes as $attrNode) {
            if (!$attrNode instanceof \DOMElement) {
                continue;
            }
            $name = trim((string)$attrNode->getAttribute('Name'));
            $friendly = trim((string)$attrNode->getAttribute('FriendlyName'));

            $values = [];
            foreach ($attrNode->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'AttributeValue') as $valueNode) {
                $text = trim((string)$valueNode->textContent);
                if ($text !== '') {
                    $values[] = $text;
                }
            }

            if ($values === []) {
                continue;
            }

            foreach ([$name, $friendly] as $k) {
                if ($k === '') {
                    continue;
                }
                if (!isset($out[$k]) || !is_array($out[$k])) {
                    $out[$k] = [];
                }
                $out[$k] = array_values(array_unique(array_merge($out[$k], $values)));
            }
        }

        return $out;
    }
}

if (!function_exists('frontoffice_normalize_fiscal_number')) {
    function frontoffice_normalize_fiscal_number(string $raw): string
    {
        $value = strtoupper(trim($raw));
        $value = preg_replace('/\s+/', '', $value);

        // CIE/SPID spesso inviano il formato qualificato TINIT-<CF>.
        if (strpos($value, 'TINIT-') === 0) {
            $value = substr($value, 6);
        }

        return $value;
    }
}

if (!function_exists('frontoffice_get_logged_user_fiscal_number')) {
    function frontoffice_get_logged_user_fiscal_number(): string
    {
        $user = frontoffice_get_logged_user();
        if ($user === null) {
            return '';
        }
        $raw = (string)($user['fiscal_number'] ?? '');
        return frontoffice_normalize_fiscal_number($raw);
    }
}

if (!function_exists('frontoffice_compose_profiles')) {
    function frontoffice_compose_profiles(): array
    {
        $raw = frontoffice_env_value('COMPOSE_PROFILES', '');
        if ($raw === '') {
            return [];
        }
        $parts = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw))));
        return array_values(array_unique($parts));
    }
}

if (!function_exists('frontoffice_spid_mode')) {
    /**
     * @return 'none'|'external'|'internal'|'auth-proxy'
     */
    function frontoffice_spid_mode(): string
    {
        // Sorgente canonica: configurazione Frontoffice da UI.
        $frontofficeType = strtolower(trim((string)(\App\Config\SettingsRepository::get('frontoffice', 'auth_proxy_type', 'none') ?? 'none')));
        if (in_array($frontofficeType, ['spid', 'cie', 'spid_cie'], true)) {
            return 'auth-proxy';
        }
        return 'none';
    }
}

if (!function_exists('frontoffice_spid_enabled')) {
    function frontoffice_spid_enabled(): bool
    {
        return frontoffice_spid_mode() !== 'none';
    }
}

if (!function_exists('frontoffice_auth_proxy_type')) {
    /**
     * @return 'php-proxy'|'auth-proxy-saml2'
     */
    function frontoffice_auth_proxy_type(): string
    {
        if (frontoffice_spid_mode() === 'auth-proxy') {
            return 'auth-proxy-saml2';
        }
        return 'php-proxy';
    }
}

if (!function_exists('frontoffice_http_get_raw')) {
    function frontoffice_http_get_raw(string $url, bool $insecureSsl = false): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                Logger::getInstance()->warning('CURL init failed', ['url' => $url]);
                return null;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            if ($insecureSsl) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }

            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            
            Logger::getInstance()->info('HTTP GET request', ['url' => $url, 'status' => $status, 'error' => $err, 'insecureSSL' => $insecureSsl]);
            
            if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
                if ($err !== '') {
                    Logger::getInstance()->warning('HTTP GET (raw) fallita', ['url' => $url, 'error' => $err, 'status' => $status]);
                }
                return null;
            }
            return $body;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ];
        if (stripos($url, 'https://') === 0) {
            $opts['ssl' ] = [
                'verify_peer' => !$insecureSsl,
                'verify_peer_name' => !$insecureSsl,
            ];
        }
        $ctx = stream_context_create($opts);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) && $body !== '' ? $body : null;
    }
}

if (!function_exists('frontoffice_satosa_idp_metadata')) {
    /**
     * Estrae dai metadata SATOSA IdP: entityID, SSO Redirect URL, certificato X509.
     */
    function frontoffice_satosa_idp_metadata(string $metadataUrl, bool $insecureSsl = false): ?array
    {
        $metadataUrl = trim($metadataUrl);
        if ($metadataUrl === '') {
            return null;
        }

        $cache = $_SESSION['satosa_idp_metadata_cache'] ?? null;
        if (is_array($cache)
            && isset($cache['ts'], $cache['url'], $cache['data'])
            && is_int($cache['ts'])
            && $cache['url'] === $metadataUrl
            && (time() - $cache['ts']) < 600
            && is_array($cache['data'])
        ) {
            return $cache['data'];
        }

        $xml = frontoffice_http_get_raw($metadataUrl, $insecureSsl);
        if (!is_string($xml) || $xml === '') {
            return null;
        }

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return null;
        }

        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $entityId = '';
        $entityNode = $xp->query('/md:EntityDescriptor')->item(0);
        if ($entityNode instanceof \DOMElement) {
            $entityId = (string)$entityNode->getAttribute('entityID');
        }

        $ssoUrl = '';
        $ssoNodes = $xp->query('//md:IDPSSODescriptor/md:SingleSignOnService[@Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"]');
        if ($ssoNodes && $ssoNodes->length > 0) {
            $fallback = '';
            foreach ($ssoNodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $location = (string)$node->getAttribute('Location');
                if ($fallback === '') {
                    $fallback = $location;
                }
                if ($location !== '' && stripos($location, '/Saml2IDP/') !== false) {
                    $ssoUrl = $location;
                    break;
                }
            }
            if ($ssoUrl === '') {
                $ssoUrl = $fallback;
            }
        }

        $cert = '';
        $certNodes = $xp->query('//md:IDPSSODescriptor/md:KeyDescriptor[@use="signing"]//ds:X509Certificate');
        if ($certNodes && $certNodes->length > 0) {
            $cert = trim((string)$certNodes->item(0)->textContent);
        } else {
            $certNodes = $xp->query('//md:IDPSSODescriptor//ds:X509Certificate');
            if ($certNodes && $certNodes->length > 0) {
                $cert = trim((string)$certNodes->item(0)->textContent);
            }
        }
        $cert = preg_replace('/\s+/', '', $cert);

        if ($entityId === '' || $ssoUrl === '') {
            return null;
        }

        $data = [
            'entityId' => $entityId,
            'ssoUrl' => $ssoUrl,
            'x509cert' => $cert,
        ];
        $_SESSION['satosa_idp_metadata_cache'] = ['ts' => time(), 'url' => $metadataUrl, 'data' => $data];
        return $data;
    }
}

if (!function_exists('frontoffice_satosa_saml_auth')) {
    function frontoffice_satosa_saml_auth(string $frontofficeBaseUrl, string $callbackPath, string $idpMetadataUrl, bool $debug = false): ?SamlAuth
    {
        if ($frontofficeBaseUrl === '') {
            return null;
        }
        $callbackPath = $callbackPath !== '' ? $callbackPath : '/spid/callback';
        if ($callbackPath[0] !== '/') {
            $callbackPath = '/' . ltrim($callbackPath, '/');
        }

        $insecureSsl = frontoffice_spid_proxy_insecure_ssl($idpMetadataUrl);
        $idp = frontoffice_satosa_idp_metadata($idpMetadataUrl, $insecureSsl);
        if (!is_array($idp)) {
            return null;
        }

        // Se l'SSO URL nei metadata punta a host interni (auth-proxy-nginx/localhost), usa la base pubblica.
        $proxyBase = rtrim((string)frontoffice_env_value('IAM_PROXY_PUBLIC_BASE_URL', ''), '/');
        if ($proxyBase === '') {
            $proxyBase = rtrim((string)frontoffice_env_value('SPID_PROXY_PUBLIC_BASE_URL', ''), '/');
        }
        if ($proxyBase !== '' && isset($idp['ssoUrl']) && is_string($idp['ssoUrl'])) {
            $ssoUrl = $idp['ssoUrl'];
            if (preg_match('#^https?://(auth-proxy-nginx|localhost|127\\.0\\.0\\.1)(:[0-9]+)?/#i', $ssoUrl)) {
                $parts = parse_url($ssoUrl);
                $path = $parts['path'] ?? '';
                $query = isset($parts['query']) ? '?' . $parts['query'] : '';
                $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
                $idp['ssoUrl'] = $proxyBase . $path . $query . $fragment;
            }
        }

        $spEntityId = frontoffice_env_value('FRONTOFFICE_SAML_SP_ENTITY_ID', '');
        if ($spEntityId === '') {
            $spEntityId = rtrim($frontofficeBaseUrl, '/') . '/saml/sp';
        }
        $acsUrl = rtrim($frontofficeBaseUrl, '/') . $callbackPath;
        $sloUrl = rtrim($frontofficeBaseUrl, '/') . '/logout';
        $spServiceName = trim(frontoffice_env_value('APP_ENTITY_NAME', 'GovPay'));
        if ($spServiceName === '') {
            $spServiceName = 'GovPay';
        }
        $spServiceSuffix = trim(frontoffice_env_value('APP_ENTITY_SUFFIX', ''));
        $spServiceFull = trim($spServiceName . ($spServiceSuffix !== '' ? ' - ' . $spServiceSuffix : ''));
        if ($spServiceFull === '') {
            $spServiceFull = $spServiceName;
        }
        $orgUrl = trim(frontoffice_env_value('APP_ENTITY_URL', ''));
        if ($orgUrl === '') {
            $orgUrl = rtrim($frontofficeBaseUrl, '/');
        }
        $supportEmail = trim(frontoffice_env_value('APP_SUPPORT_EMAIL', ''));
        if ($supportEmail === '') {
            $domain = preg_replace('/[^a-z0-9]+/', '', strtolower($spServiceName)) ?: 'ente';
            $supportEmail = 'support@' . $domain . '.it';
        }

        $wantAssertionsSigned = trim(frontoffice_env_value('FRONTOFFICE_SAML_WANT_ASSERTIONS_SIGNED', '1')) === '1';
        $wantMessagesSigned = trim(frontoffice_env_value('FRONTOFFICE_SAML_WANT_MESSAGES_SIGNED', '1')) === '1';

        $spCert = frontoffice_load_pem_value((string)frontoffice_env_value('FRONTOFFICE_SAML_SP_X509CERT', ''));
        $spKey = frontoffice_load_pem_value((string)frontoffice_env_value('FRONTOFFICE_SAML_SP_PRIVATEKEY', ''));
        $signMetadata = ($spCert !== '' && $spKey !== '');

        $settings = [
            'strict' => true,
            'debug' => $debug,
            'sp' => [
                'entityId' => $spEntityId,
                'assertionConsumerService' => [
                    'url' => $acsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                    'index' => 0,
                    'isDefault' => true,
                ],
                'singleLogoutService' => [
                    'url' => $sloUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
                // Firma AuthnRequest se i certificati SP sono disponibili.
                'x509cert' => $spCert,
                'privateKey' => $spKey,
                'attributeConsumingService' => [
                    'serviceName' => $spServiceName,
                    'serviceDescription' => $spServiceName,
                    'requestedAttributes' => [
                        ['name' => 'spidCode', 'friendlyName' => 'spidCode', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => true],
                        ['name' => 'name', 'friendlyName' => 'name', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => true],
                        ['name' => 'familyName', 'friendlyName' => 'familyName', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => true],
                        ['name' => 'fiscalNumber', 'friendlyName' => 'fiscalNumber', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => true],
                        ['name' => 'email', 'friendlyName' => 'email', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => true],
                    ],
                ],
            ],
            'idp' => [
                'entityId' => (string)($idp['entityId'] ?? ''),
                'singleSignOnService' => [
                    'url' => (string)($idp['ssoUrl'] ?? ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => (string)($idp['x509cert'] ?? ''),
            ],
            'organization' => [
                'en' => [
                    'name' => $spServiceName,
                    'displayname' => $spServiceFull,
                    'url' => $orgUrl,
                ],
                'it' => [
                    'name' => $spServiceName,
                    'displayname' => $spServiceFull,
                    'url' => $orgUrl,
                ],
            ],
            'contactPerson' => [
                'other' => [
                    'givenName' => $spServiceName,
                    'emailAddress' => $supportEmail,
                ],
            ],
            'security' => [
                'authnRequestsSigned' => ($spCert !== '' && $spKey !== ''),
                'wantAssertionsSigned' => $wantAssertionsSigned,
                'wantMessagesSigned' => $wantMessagesSigned,
                'wantNameId' => false,
                'wantNameIdEncrypted' => false,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'signMetadata' => $signMetadata,
                'signMetadataAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];

        try {
            if (str_starts_with($frontofficeBaseUrl, 'https://')) {
                \OneLogin\Saml2\Utils::setProxyVars(true);
                $_SERVER['HTTPS'] = 'on';
                $_SERVER['SERVER_PORT'] = '443';
            }
            return new SamlAuth($settings);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Impossibile inizializzare SAML Auth', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

if (!function_exists('frontoffice_spid_proxy_insecure_ssl')) {
    function frontoffice_spid_proxy_insecure_ssl(string $proxyBaseUrl): bool
    {
        $host = (string)(parse_url($proxyBaseUrl, PHP_URL_HOST) ?: '');
        return in_array($host, ['localhost', '127.0.0.1', '::1', 'auth-proxy-nginx'], true);
    }
}

if (!function_exists('frontoffice_http_get')) {
    function frontoffice_http_get(string $url, bool $insecureSsl = false): ?array
    {
        // Preferisci cURL se disponibile (gestione timeouts/SSL più robusta).
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            if ($insecureSsl) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }

            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
                if ($err !== '') {
                    Logger::getInstance()->warning('HTTP GET fallita', ['url' => $url, 'error' => $err, 'status' => $status]);
                }
                return null;
            }
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ];
        if (stripos($url, 'https://') === 0) {
            $opts['ssl'] = [
                'verify_peer' => !$insecureSsl,
                'verify_peer_name' => !$insecureSsl,
            ];
        }
        $ctx = stream_context_create($opts);
        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body) || $body === '') {
            return null;
        }
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('frontoffice_spid_decode_proxy_token')) {
    /**
     * Decodifica un token (JWS o JWS+JWE) usando l'endpoint verify del proxy.
     * Nota: in DEV con certificati self-signed (localhost) disabilitiamo la verifica SSL.
     */
    function frontoffice_spid_decode_proxy_token(string $proxyBase, string $token, bool $decrypt, string $secret, string $service = 'spid'): ?array
    {
        $proxyBase = rtrim($proxyBase, '/');
        if ($proxyBase === '' || $token === '') {
            return null;
        }
        $decryptFlag = $decrypt ? 'Y' : 'N';
        $url = $proxyBase . '/proxy.php?action=verify'
            . '&token=' . rawurlencode($token)
            . '&decrypt=' . rawurlencode($decryptFlag)
            . '&service=' . rawurlencode($service);
        if ($decrypt) {
            $url .= '&secret=' . rawurlencode($secret);
        }
        return frontoffice_http_get($url, frontoffice_spid_proxy_insecure_ssl($proxyBase));
    }
}

if (!function_exists('frontoffice_normalize_amount')) {
    function frontoffice_normalize_amount($value): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }
        if ($value === null || $value === '') {
            return 0.0;
        }
        return is_numeric($value) ? round((float)$value, 2) : 0.0;
    }
}

if (!function_exists('frontoffice_generate_pendenza_id')) {
    function frontoffice_generate_pendenza_id(): string
    {
        try {
            $rand = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $rand = md5((string)microtime(true));
        }
        $candidate = 'GIL-' . substr($rand, 0, 16);
        return substr(preg_replace('/[^A-Za-z0-9\-_]/', '-', $candidate), 0, 35);
    }
}

if (!function_exists('frontoffice_build_voci')) {
    function frontoffice_build_voci(string $idDominio, string $idTipo, string $descrizione, float $importo): array
    {
        $iban = $codCont = $tipoBollo = $tipoCont = '';
        try {
            if ($idDominio !== '' && $idTipo !== '') {
                $repo = new EntrateRepository();
                $details = $repo->findDetails($idDominio, $idTipo);
                if ($details) {
                    $iban = (string)($details['iban_accredito'] ?? '');
                    $codCont = (string)($details['codice_contabilita'] ?? '');
                    $tipoBollo = (string)($details['tipo_bollo'] ?? '');
                    $tipoCont = (string)($details['tipo_contabilita'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Impossibile recuperare i dettagli di contabilita', ['error' => $e->getMessage()]);
        }

        $voice = [
            'idVocePendenza' => '1',
            'descrizione' => $descrizione,
            'importo' => $importo,
        ];

        if ($tipoBollo !== '') {
            $voice['tipoBollo'] = $tipoBollo;
        } elseif ($iban !== '' && $tipoCont !== '' && $codCont !== '') {
            $voice['ibanAccredito'] = $iban;
            $voice['tipoContabilita'] = $tipoCont;
            $voice['codiceContabilita'] = $codCont;
        } else {
            $targetCode = $codCont !== '' ? $codCont : $idTipo;
            $voice['codEntrata'] = substr(preg_replace('/[^A-Za-z0-9\-_.]/', '', $targetCode) ?: $idTipo, 0, 35);
        }

        return [$voice];
    }
}

if (!function_exists('frontoffice_prepare_payer')) {
    function frontoffice_prepare_payer(array $raw): array
    {
        $type = strtoupper((string)($raw['tipo'] ?? 'F'));
        if (!in_array($type, ['F', 'G'], true)) {
            $type = 'F';
        }

        $upper = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
        };

        $ident = strtoupper(preg_replace('/\s+/', '', (string)($raw['identificativo'] ?? '')));
        $surname = $upper((string)($raw['anagrafica'] ?? ''));
        $name = $upper((string)($raw['nome'] ?? ''));
        $anagrafica = $type === 'G' ? $surname : trim(($name !== '' ? $name . ' ' : '') . $surname);
        if ($anagrafica === '') {
            $anagrafica = $surname;
        }

        $payload = [
            'tipo' => $type,
            'identificativo' => $ident,
            'anagrafica' => $anagrafica,
        ];

        $email = trim((string)($raw['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $payload['email'] = $email;
        }
        $phone = trim((string)($raw['telefono'] ?? ''));
        if ($phone !== '') {
            $payload['cellulare'] = $phone;
        }

        return $payload;
    }
}

if (!function_exists('frontoffice_extract_numero_avviso')) {
    function frontoffice_extract_numero_avviso(?array $response, ?array $detail = null): ?string
    {
        $candidates = [];
        if ($response) {
            $candidates[] = $response['numeroAvviso'] ?? null;
            $candidates[] = $response['numero_avviso'] ?? null;
            $candidates[] = $response['pendenza']['numeroAvviso'] ?? null;
            $candidates[] = $response['pendenza']['numero_avviso'] ?? null;
            if (!empty($response['avvisi'][0]['numeroAvviso'])) {
                $candidates[] = $response['avvisi'][0]['numeroAvviso'];
            }
        }
        if ($detail) {
            $candidates[] = $detail['numeroAvviso'] ?? null;
        }

        foreach ($candidates as $candidate) {
            $value = trim((string)($candidate ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }
}

if (!function_exists('frontoffice_send_pendenza_to_backoffice')) {
    function frontoffice_send_pendenza_to_backoffice(array $payload): array
    {
        $backofficeUrl = frontoffice_env_value('GOVPAY_BACKOFFICE_URL', '');
        $idA2A = frontoffice_env_value('ID_A2A', '');
        if ($backofficeUrl === '' || $idA2A === '') {
            return ['success' => false, 'errors' => ['Configurazione GovPay incompleta (GOVPAY_BACKOFFICE_URL o ID_A2A mancanti).']];
        }

        $idPendenza = frontoffice_generate_pendenza_id();
        unset($payload['idPendenza']);

        try {
            $client = new Client(frontoffice_govpay_client_options());
            $requestOptions = [
                'headers' => [
                    'Accept' => 'application/json',
                    'Connection' => 'close',
                ],
                'json' => $payload,
            ];
            if ($auth = frontoffice_basic_auth()) {
                $requestOptions['auth'] = $auth;
            }

            $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);
            $resp = $client->request('PUT', $url, $requestOptions);
            $data = json_decode((string)$resp->getBody(), true);
            return ['success' => true, 'idPendenza' => $idPendenza, 'response' => $data];
        } catch (ClientException $e) {
            $body = '';
            if ($e->getResponse()) {
                $body = (string)$e->getResponse()->getBody();
            }
            $message = $body !== '' ? $body : $e->getMessage();
            Logger::getInstance()->error('Errore invio pendenza frontoffice', ['error' => $message]);
            return ['success' => false, 'errors' => [Logger::sanitizeErrorForDisplay($message)]];
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore inatteso invio pendenza frontoffice', ['error' => $e->getMessage()]);
            return ['success' => false, 'errors' => [Logger::sanitizeErrorForDisplay($e->getMessage())]];
        }
    }
}

if (!function_exists('frontoffice_fetch_pagamenti_detail')) {
    function frontoffice_fetch_pagamenti_detail(string $idPendenza): ?array
    {
        $idA2A = frontoffice_env_value('ID_A2A', '');
        if ($idA2A === '' || $idPendenza === '') {
            return null;
        }
        $api = frontoffice_pagamenti_api_client();
        if ($api === null) {
            return null;
        }
        try {
            $result = $api->getPendenza($idA2A, $idPendenza);
            return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Impossibile recuperare il dettaglio della pendenza da GovPay Pagamenti', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

if (!function_exists('frontoffice_pendenza_belongs_to_cf')) {
    function frontoffice_pendenza_belongs_to_cf(array $pendenza, string $codiceFiscale): bool
    {
        $expected = strtoupper(preg_replace('/\s+/', '', trim($codiceFiscale)));
        if ($expected === '') {
            return false;
        }

        $candidates = [];
        foreach (['idDebitore', 'codiceFiscaleDebitore', 'id_debitore'] as $key) {
            if (isset($pendenza[$key]) && is_string($pendenza[$key])) {
                $candidates[] = $pendenza[$key];
            }
        }

        if (isset($pendenza['soggettoPagatore']) && is_array($pendenza['soggettoPagatore'])) {
            foreach (['identificativo', 'identificativoUnivoco', 'codiceFiscale', 'fiscalNumber'] as $key) {
                if (isset($pendenza['soggettoPagatore'][$key]) && is_string($pendenza['soggettoPagatore'][$key])) {
                    $candidates[] = $pendenza['soggettoPagatore'][$key];
                }
            }
        }

        if (isset($pendenza['soggettoVersante']) && is_array($pendenza['soggettoVersante'])) {
            foreach (['identificativo', 'identificativoUnivoco', 'codiceFiscale', 'fiscalNumber'] as $key) {
                if (isset($pendenza['soggettoVersante'][$key]) && is_string($pendenza['soggettoVersante'][$key])) {
                    $candidates[] = $pendenza['soggettoVersante'][$key];
                }
            }
        }

        foreach ($candidates as $value) {
            $normalized = strtoupper(preg_replace('/\s+/', '', trim((string)$value)));
            if ($normalized !== '' && $normalized === $expected) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('frontoffice_fetch_pendenza_by_avviso')) {
    function frontoffice_fetch_pendenza_by_avviso(string $idDominio, string $numeroAvviso): ?array
    {
        $pendenzeHost = frontoffice_govpay_pendenze_base_url();
        if ($pendenzeHost === '' || $idDominio === '' || $numeroAvviso === '') {
            return null;
        }
        if (!class_exists('\GovPay\Pendenze\Api\PendenzeApi') || !class_exists('\GovPay\Pendenze\Configuration')) {
            return null;
        }

        try {
            $config = new \GovPay\Pendenze\Configuration();
            $config->setHost(rtrim($pendenzeHost, '/'));
            $guzzleOptions = frontoffice_govpay_client_options();
            if ($auth = frontoffice_basic_auth()) {
                $guzzleOptions['auth'] = [$auth[0], $auth[1]];
            }
            $httpClient = new Client($guzzleOptions);
            $api = new \GovPay\Pendenze\Api\PendenzeApi($httpClient, $config);

            $result = $api->getPendenzaByAvviso($idDominio, $numeroAvviso);
            return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (ClientException $e) {
            $code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            Logger::getInstance()->info('Pendenza non trovata via Pendenze v2 (byAvviso)', [
                'idDominio' => $idDominio,
                'numeroAvviso' => $numeroAvviso,
                'status' => $code,
            ]);
            return null;
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Errore durante la ricerca avviso via Pendenze v2 (byAvviso)', [
                'idDominio' => $idDominio,
                'numeroAvviso' => $numeroAvviso,
                'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return null;
        }
    }
}

if (!function_exists('frontoffice_normalize_avviso_code')) {
    function frontoffice_normalize_avviso_code(string $value): string
    {
        $normalized = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value));
        return substr($normalized, 0, 35);
    }
}

if (!function_exists('frontoffice_map_pendenza_state')) {
    function frontoffice_map_pendenza_state(string $state): string
    {
        $code = strtoupper(trim($state));
        switch ($code) {
            case 'NON_ESEGUITA':
                return 'Da pagare';
            case 'ESEGUITA':
                return 'Pagata';
            case 'ESEGUITA_PARZIALE':
                return 'Pagata parzialmente';
            case 'ANNULLATA':
                return 'Annullata';
            case 'SCADUTA':
                return 'Scaduta';
            case 'ANOMALA':
                return 'In verifica';
            default:
                return 'Stato sconosciuto';
        }
    }
}

if (!function_exists('frontoffice_is_pendenza_payable')) {
    function frontoffice_is_pendenza_payable(string $state): bool
    {
        $code = strtoupper(trim($state));
        return in_array($code, ['NON_ESEGUITA', 'ESEGUITA_PARZIALE'], true);
    }
}

if (!function_exists('frontoffice_is_pendenza_paid')) {
    function frontoffice_is_pendenza_paid(string $state): bool
    {
        $code = strtoupper(trim($state));
        return $code === 'ESEGUITA';
    }
}

if (!function_exists('frontoffice_extract_ricevuta_identifiers_from_pendenza_detail')) {
    function frontoffice_extract_ricevuta_identifiers_from_pendenza_detail(array $detail): array
    {
        $iuv = trim((string)($detail['iuv'] ?? ''));
        $idRicevuta = trim((string)($detail['idRicevuta'] ?? ''));
        $ccp = '';
        foreach (['ccp', 'codiceContestoPagamento', 'codice_contesto_pagamento'] as $k) {
            if (isset($detail[$k]) && is_scalar($detail[$k])) {
                $ccp = trim((string)$detail[$k]);
                if ($ccp !== '') {
                    break;
                }
            }
        }

        $voci = $detail['voci'] ?? null;
        if (is_array($voci)) {
            foreach ($voci as $voce) {
                if (!is_array($voce)) {
                    continue;
                }
                $riscossione = $voce['riscossione'] ?? null;
                if (!is_array($riscossione)) {
                    continue;
                }
                if ($iuv === '') {
                    $iuv = trim((string)($riscossione['iuv'] ?? ''));
                }
                if ($idRicevuta === '') {
                    $idRicevuta = trim((string)($riscossione['idRicevuta'] ?? ''));
                }
                if ($ccp === '') {
                    foreach (['ccp', 'codiceContestoPagamento', 'codice_contesto_pagamento'] as $k) {
                        if (isset($riscossione[$k]) && is_scalar($riscossione[$k])) {
                            $ccp = trim((string)$riscossione[$k]);
                            if ($ccp !== '') {
                                break;
                            }
                        }
                    }
                }

                if ($iuv !== '' && $idRicevuta !== '' && $ccp !== '') {
                    break;
                }
            }
        }

        return [
            'iuv' => $iuv,
            'idRicevuta' => $idRicevuta,
            'ccp' => $ccp,
        ];
    }
}

if (!function_exists('frontoffice_govpay_pendenze_base_url')) {
    function frontoffice_govpay_pendenze_base_url(): string
    {
        $pendenzeUrl = frontoffice_env_value('GOVPAY_PENDENZE_URL', '');
        return rtrim($pendenzeUrl, '/');
    }
}

if (!function_exists('frontoffice_govpay_pagamenti_base_url')) {
    function frontoffice_govpay_pagamenti_base_url(): string
    {
        $pagamentiUrl = frontoffice_env_value('GOVPAY_PAGAMENTI_URL', '');
        return rtrim($pagamentiUrl, '/');
    }
}

if (!function_exists('frontoffice_fetch_ricevute_for_iuv')) {
    function frontoffice_fetch_ricevute_for_iuv(string $idDominio, string $iuv): ?array
    {
        $baseUrl = frontoffice_govpay_pagamenti_base_url();
        if ($baseUrl === '' || $idDominio === '' || $iuv === '') {
            return null;
        }

        try {
            $client = new Client(frontoffice_govpay_client_options());
            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ];
            if ($auth = frontoffice_basic_auth()) {
                $options['auth'] = [$auth[0], $auth[1]];
            }
            // Allineato al backoffice (diag): richiedi solo ricevute con esito positivo.
            $options['query'] = ['esito' => 'ESEGUITO'];

            $url = $baseUrl . '/ricevute/' . rawurlencode($idDominio) . '/' . rawurlencode($iuv);
            $response = $client->request('GET', $url, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return null;
            }

            $body = (string)$response->getBody();
            if ($body === '') {
                return null;
            }
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Errore durante la ricerca ricevute GovPay', [
                'idDominio' => $idDominio,
                'iuv' => $iuv,
                'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return null;
        }
    }
}

if (!function_exists('frontoffice_stream_rt_pdf')) {
    function frontoffice_stream_rt_pdf(string $idDominio, string $iuv, string $ccp): void
    {
        $baseUrl = frontoffice_govpay_pendenze_base_url();
        if ($idDominio === '' || $iuv === '' || $ccp === '') {
            http_response_code(404);
            echo 'Ricevuta non disponibile.';
            return;
        }
        if ($baseUrl === '') {
            http_response_code(503);
            echo 'Configurazione mancante: GOVPAY_PENDENZE_URL non impostata.';
            return;
        }

        try {
            $client = new Client(frontoffice_govpay_client_options());
            $options = [
                'headers' => [
                    'Accept' => 'application/pdf',
                ],
            ];
            if ($auth = frontoffice_basic_auth()) {
                $options['auth'] = [$auth[0], $auth[1]];
            }

            $url = $baseUrl . '/rpp/' . rawurlencode($idDominio) . '/' . rawurlencode($iuv) . '/' . rawurlencode($ccp) . '/rt';
            $response = $client->request('GET', $url, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                http_response_code(404);
                echo 'Ricevuta non disponibile.';
                return;
            }

            $contentType = strtolower(implode(' ', $response->getHeader('Content-Type')));
            if ($contentType !== '' && strpos($contentType, 'application/pdf') === false) {
                http_response_code(503);
                echo 'La ricevuta non è disponibile in formato PDF.';
                return;
            }

            $filename = 'rt-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $iuv . '_' . $ccp) . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            echo (string)$response->getBody();
            return;
        } catch (ClientException $e) {
            http_response_code(($e->getResponse() ? $e->getResponse()->getStatusCode() : 404) ?: 404);
            echo 'Ricevuta non disponibile.';
            return;
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Errore durante lo scarico PDF RT (Pendenze /rpp)', [
                'idDominio' => $idDominio,
                'iuv' => $iuv,
                'ccp' => $ccp,
                'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            http_response_code(503);
            echo 'Al momento non riusciamo a scaricare la ricevuta. Riprova più tardi.';
        }
    }
}

if (!function_exists('frontoffice_find_paid_rpp_for_pendenza')) {
    /**
     * Ricava (iuv, ccp) interrogando Pendenze v2 (TransazioniApi::findRpp).
     * Serve come fallback quando il dettaglio Pagamenti non contiene i riferimenti della RT.
     */
    function frontoffice_find_paid_rpp_for_pendenza(string $idDominio, string $idPendenza, ?string $idA2A = null): ?array
    {
        $pendenzeHost = frontoffice_govpay_pendenze_base_url();
        if ($pendenzeHost === '' || $idDominio === '' || $idPendenza === '') {
            return null;
        }
        if (!class_exists('\GovPay\Pendenze\Api\TransazioniApi') || !class_exists('\GovPay\Pendenze\Configuration')) {
            return null;
        }

        try {
            $config = new \GovPay\Pendenze\Configuration();
            $config->setHost(rtrim($pendenzeHost, '/'));
            $guzzleOptions = frontoffice_govpay_client_options();
            if ($auth = frontoffice_basic_auth()) {
                $guzzleOptions['auth'] = [$auth[0], $auth[1]];
            }
            $httpClient = new Client($guzzleOptions);
            $api = new \GovPay\Pendenze\Api\TransazioniApi($httpClient, $config);

            $esito = class_exists('\GovPay\Pendenze\Model\EsitoRpp')
                ? \GovPay\Pendenze\Model\EsitoRpp::ESEGUITO
                : 'ESEGUITO';

            $result = $api->findRpp(
                1,
                25,
                null,
                null,
                $idDominio,
                null,
                null,
                ($idA2A !== null && $idA2A !== '') ? $idA2A : null,
                $idPendenza,
                null,
                $esito
            );

            $data = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $rows = $data['risultati'] ?? $data['rpps'] ?? null;
            if (!is_array($rows) || $rows === []) {
                return null;
            }

            $first = $rows[0] ?? null;
            if (!is_array($first)) {
                return null;
            }

            $pickScalar = static function ($value): string {
                return is_scalar($value) ? trim((string)$value) : '';
            };
            $getPath = static function (array $root, array $path) use ($pickScalar): string {
                $current = $root;
                foreach ($path as $segment) {
                    if (!is_array($current) || !array_key_exists($segment, $current)) {
                        return '';
                    }
                    $current = $current[$segment];
                }
                return $pickScalar($current);
            };

            // GovPay Pendenze v2: i campi possono essere top-level oppure annidati in pendenza/rpt/rt.
            $iuv = $pickScalar($first['iuv'] ?? null);
            if ($iuv === '') {
                foreach ([
                    ['pendenza', 'iuvPagamento'],
                    ['pendenza', 'iuvAvviso'],
                    ['rpt', 'datiVersamento', 'identificativoUnivocoVersamento'],
                    ['rt', 'datiPagamento', 'identificativoUnivocoVersamento'],
                ] as $path) {
                    $iuv = $getPath($first, $path);
                    if ($iuv !== '') {
                        break;
                    }
                }
            }

            $ccp = $pickScalar($first['ccp'] ?? null);
            if ($ccp === '') {
                foreach ([
                    ['codiceContestoPagamento'],
                    ['pendenza', 'codiceContestoPagamento'],
                    ['rpt', 'datiVersamento', 'codiceContestoPagamento'],
                    // In alcune RT il campo arriva con iniziale maiuscola.
                    ['rt', 'datiPagamento', 'CodiceContestoPagamento'],
                    ['rt', 'datiPagamento', 'codiceContestoPagamento'],
                    // Fallback: spesso coincide col CCP.
                    ['rt', 'datiPagamento', 'datiSingoloPagamento', 0, 'identificativoUnivocoRiscossione'],
                ] as $path) {
                    $ccp = $getPath($first, $path);
                    if ($ccp !== '') {
                        break;
                    }
                }
            }

            if ($iuv === '' || $ccp === '') {
                return null;
            }

            return ['iuv' => $iuv, 'ccp' => $ccp];
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Impossibile recuperare RPP da Pendenze v2 per scarico RT', [
                'idDominio' => $idDominio,
                'idPendenza' => $idPendenza,
                'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return null;
        }
    }
}

if (!function_exists('frontoffice_stream_ricevuta_pdf')) {
    function frontoffice_stream_ricevuta_pdf(string $idDominio, string $iuv, string $idRicevuta): void
    {
        $baseUrl = frontoffice_govpay_pagamenti_base_url();
        if ($baseUrl === '' || $idDominio === '' || $iuv === '' || $idRicevuta === '') {
            http_response_code(404);
            echo 'Ricevuta non disponibile.';
            return;
        }

        try {
            $client = new Client(frontoffice_govpay_client_options());
            $options = [
                'headers' => [
                    'Accept' => 'application/pdf',
                ],
            ];
            if ($auth = frontoffice_basic_auth()) {
                $options['auth'] = [$auth[0], $auth[1]];
            }

            $url = $baseUrl . '/ricevute/' . rawurlencode($idDominio) . '/' . rawurlencode($iuv) . '/' . rawurlencode($idRicevuta);
            $response = $client->request('GET', $url, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                http_response_code(404);
                echo 'Ricevuta non disponibile.';
                return;
            }

            $contentType = strtolower(implode(' ', $response->getHeader('Content-Type')));
            if ($contentType !== '' && strpos($contentType, 'application/pdf') === false) {
                http_response_code(503);
                echo 'La ricevuta non è disponibile in formato PDF.';
                return;
            }

            $filename = 'ricevuta_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $iuv . '_' . $idRicevuta) . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            echo (string)$response->getBody();
            return;
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Errore durante lo scarico PDF ricevuta GovPay', [
                'idDominio' => $idDominio,
                'iuv' => $iuv,
                'idRicevuta' => $idRicevuta,
                'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            http_response_code(503);
            echo 'Al momento non riusciamo a scaricare la ricevuta. Riprova più tardi.';
        }
    }
}

if (!function_exists('frontoffice_build_avviso_preview')) {
    function frontoffice_build_avviso_preview(array $pendenza, string $idDominio): array
    {
        $numeroAvviso = trim((string)($pendenza['numeroAvviso'] ?? $pendenza['numero_avviso'] ?? ''));
        $iuv = trim((string)($pendenza['iuv'] ?? $pendenza['iuvAvviso'] ?? $pendenza['iuv_avviso'] ?? $pendenza['iuvPagamento'] ?? ''));

        if ($iuv === '' && isset($pendenza['riscossione']) && is_array($pendenza['riscossione'])) {
            $iuv = trim((string)($pendenza['riscossione']['iuv'] ?? ''));
        }

        if ($iuv === '' && isset($pendenza['rpt']) && is_array($pendenza['rpt']) && isset($pendenza['rpt']['datiVersamento']) && is_array($pendenza['rpt']['datiVersamento'])) {
            $iuv = trim((string)($pendenza['rpt']['datiVersamento']['identificativoUnivocoVersamento'] ?? ''));
        }

        if ($iuv === '' && isset($pendenza['rt']) && is_array($pendenza['rt']) && isset($pendenza['rt']['datiPagamento']) && is_array($pendenza['rt']['datiPagamento'])) {
            $iuv = trim((string)($pendenza['rt']['datiPagamento']['identificativoUnivocoVersamento'] ?? ''));
        }

        if ($numeroAvviso === '' && $iuv !== '') {
            $numeroAvviso = $iuv;
        }

        $downloadUrl = ($numeroAvviso !== '' && $idDominio !== '')
            ? '/avvisi/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroAvviso)
            : null;
        $state = strtoupper((string)($pendenza['stato'] ?? ''));
        $importo = $pendenza['importo'] ?? null;

        $idPendenza = (string)($pendenza['idPendenza'] ?? '');
        $checkoutUrl = null;
        if (frontoffice_is_pendenza_payable($state) && $idPendenza !== '') {
            // Checkout dinamico (server-side) per evitare di esporre la subscription key al browser.
            // Usa un endpoint pubblico dedicato al pagamento avviso (senza login), autorizzato via sessione.
            $checkoutUrl = '/pagamento-avviso/checkout?idPendenza=' . rawurlencode($idPendenza);
        } else {
            // Fallback legacy: link statico al portale checkout (solo inserimento dati avviso).
            $checkoutUrl = frontoffice_env_value(
                'FRONTOFFICE_PAGOPA_CHECKOUT_URL',
                'https://checkout.pagopa.it/inserisci-dati-avviso'
            );
        }

        return [
            'numero_avviso' => $numeroAvviso,
            'iuv' => $iuv,
            'importo' => is_numeric($importo) ? (float)$importo : null,
            'causale' => trim((string)($pendenza['causale'] ?? '')),
            'id_pendenza' => $idPendenza,
            'id_a2a' => (string)($pendenza['idA2A'] ?? ''),
            'data_validita' => $pendenza['dataValidita'] ?? null,
            'data_scadenza' => $pendenza['dataScadenza'] ?? null,
            'soggetto_pagatore' => $pendenza['soggettoPagatore'] ?? null,
            'stato' => [
                'code' => $state,
                'label' => frontoffice_map_pendenza_state($state),
            ],
            'is_payable' => frontoffice_is_pendenza_payable($state),
            'is_paid' => frontoffice_is_pendenza_paid($state),
            'download_url' => $downloadUrl,
            'receipt_url' => (frontoffice_is_pendenza_paid($state) && (string)($pendenza['idPendenza'] ?? '') !== '')
                ? '/pendenze/' . rawurlencode((string)$pendenza['idPendenza']) . '/ricevuta'
                : null,
            'voci' => $pendenza['voci'] ?? [],
            'id_dominio' => $idDominio,
            'checkout_url' => $checkoutUrl,
        ];
    }
}

if (!function_exists('frontoffice_amount_to_cents')) {
    function frontoffice_amount_to_cents(float $amount): int
    {
        return (int)max(0, (int)round($amount * 100));
    }
}

// ─── Session Cart Helpers ─────────────────────────────────────────────────────

if (!function_exists('frontoffice_cart_items')) {
    /**
     * Returns the current cart items from session.
     * Each item: ['idPendenza', 'causale', 'importo', 'numeroAvviso', 'data_scadenza', 'added_at']
     * @return array<string, array>
     */
    function frontoffice_cart_items(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $cart = $_SESSION['frontoffice_cart'] ?? [];
        return is_array($cart) ? $cart : [];
    }
}

if (!function_exists('frontoffice_cart_count')) {
    function frontoffice_cart_count(): int
    {
        return count(frontoffice_cart_items());
    }
}

if (!function_exists('frontoffice_cart_add')) {
    /**
     * Adds a pendenza to the session cart (max 5 items).
     * Also adds idPendenza to all relevant session whitelists.
     * @return string|null Error message on failure, null on success.
     */
    function frontoffice_cart_add(string $idPendenza, array $meta): ?string
    {
        if ($idPendenza === '') {
            return 'ID pendenza mancante.';
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return 'Sessione non disponibile.';
        }
        if (!isset($_SESSION['frontoffice_cart']) || !is_array($_SESSION['frontoffice_cart'])) {
            $_SESSION['frontoffice_cart'] = [];
        }
        // Already in cart
        if (isset($_SESSION['frontoffice_cart'][$idPendenza])) {
            return null; // idempotent
        }
        if (count($_SESSION['frontoffice_cart']) >= 5) {
            return 'Puoi aggiungere al massimo 5 avvisi al carrello.';
        }
        $_SESSION['frontoffice_cart'][$idPendenza] = [
            'idPendenza'   => $idPendenza,
            'causale'      => mb_substr(trim((string)($meta['causale'] ?? '')), 0, 140),
            'importo'      => is_numeric($meta['importo'] ?? null) ? (float)$meta['importo'] : null,
            'numeroAvviso' => preg_replace('/\D+/', '', trim((string)($meta['numeroAvviso'] ?? ''))),
            'data_scadenza'=> $meta['dataScadenza'] ?? $meta['data_scadenza'] ?? null,
            'added_at'     => time(),
        ];
        // Sync to whitelist keys so /carrello/checkout can authorize
        foreach (['frontoffice_pendenze_whitelist', 'frontoffice_avviso_pendenze', 'frontoffice_spontaneo_pendenze'] as $key) {
            $list = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
            if (!in_array($idPendenza, $list, true)) {
                $list[] = $idPendenza;
                $_SESSION[$key] = array_slice($list, -100);
            }
        }
        return null;
    }
}

if (!function_exists('frontoffice_cart_remove')) {
    function frontoffice_cart_remove(string $idPendenza): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['frontoffice_cart'][$idPendenza])) {
            unset($_SESSION['frontoffice_cart'][$idPendenza]);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────



if (!function_exists('frontoffice_build_cart_request')) {
    /**
     * Builds a CartRequest for the PagoPA Checkout EC API from an array of resolved pendenza details.
     *
     * @param array[] $pendenzaDetails Array of pendenza detail arrays (already fetched from GovPay).
     * @param string  $idDominio       The organisation fiscal code used as PaymentNotice.fiscalCode.
     * @param string  $returnOkUrl
     * @param string  $returnCancelUrl
     * @param string  $returnErrorUrl
     * @param string  $emailNotice     Optional payer email.
     * @return \PagoPA\CheckoutEc\Model\CartRequest
     */
    function frontoffice_build_cart_request(
        array $pendenzaDetails,
        string $idDominio,
        string $returnOkUrl,
        string $returnCancelUrl,
        string $returnErrorUrl,
        string $emailNotice = ''
    ): \PagoPA\CheckoutEc\Model\CartRequest {
        $companyName = trim(frontoffice_env_value('PAGOPA_CHECKOUT_COMPANY_NAME', frontoffice_env_value('APP_ENTITY_NAME', 'Ente')));
        if ($companyName === '') {
            $companyName = 'Ente';
        }

        $notices = [];
        foreach (array_slice($pendenzaDetails, 0, 5) as $detail) {
            $numeroAvviso = preg_replace('/\D+/', '', trim((string)($detail['numeroAvviso'] ?? '')));
            $importo = $detail['importo'] ?? null;
            $amountCents = frontoffice_amount_to_cents(is_numeric($importo) ? (float)$importo : 0.0);
            $description = trim((string)($detail['causale'] ?? ''));

            if ($numeroAvviso === '' || $amountCents <= 0) {
                continue;
            }

            $notice = new \PagoPA\CheckoutEc\Model\PaymentNotice();
            $notice->setNoticeNumber($numeroAvviso);
            $notice->setFiscalCode($idDominio);
            $notice->setAmount($amountCents);
            $notice->setCompanyName($companyName);
            if ($description !== '') {
                $notice->setDescription(mb_substr($description, 0, 140));
            }
            $notices[] = $notice;
        }

        $returnUrls = new \PagoPA\CheckoutEc\Model\CartRequestReturnUrls();
        $returnUrls->setReturnOkUrl($returnOkUrl);
        $returnUrls->setReturnCancelUrl($returnCancelUrl);
        $returnUrls->setReturnErrorUrl($returnErrorUrl);

        $cart = new \PagoPA\CheckoutEc\Model\CartRequest();
        $cart->setPaymentNotices($notices);
        $cart->setReturnUrls($returnUrls);

        if ($emailNotice !== '' && filter_var($emailNotice, FILTER_VALIDATE_EMAIL) !== false) {
            $cart->setEmailNotice($emailNotice);
        }

        return $cart;
    }
}


if (!function_exists('frontoffice_pagopa_checkout_api_client')) {
    function frontoffice_pagopa_checkout_api_client(): ?\PagoPA\CheckoutEc\Api\DefaultApi
    {
        if (!class_exists(\PagoPA\CheckoutEc\Api\DefaultApi::class)) {
            return null;
        }

        $subscriptionKey = trim(frontoffice_env_value('PAGOPA_CHECKOUT_SUBSCRIPTION_KEY', ''));
        if ($subscriptionKey === '') {
            return null;
        }

        $config = \PagoPA\CheckoutEc\Configuration::getDefaultConfiguration();
        $host = trim(frontoffice_env_value('PAGOPA_CHECKOUT_EC_BASE_URL', ''));
        if ($host !== '') {
            $config->setHost(rtrim($host, '/'));
        }

        // La spec supporta sia header che query key; impostiamo entrambe allo stesso valore.
        $config->setApiKey('Ocp-Apim-Subscription-Key', $subscriptionKey);
        $config->setApiKey('subscription-key', $subscriptionKey);

        $httpClient = new \GuzzleHttp\Client([
            'timeout' => 15,
            'connect_timeout' => 10,
            'allow_redirects' => false,
        ]);

        return new \PagoPA\CheckoutEc\Api\DefaultApi($httpClient, $config);
    }
}

if (!function_exists('frontoffice_lookup_pagopa_avviso')) {
    function frontoffice_lookup_pagopa_avviso(string $numeroAvviso, string $codiceFiscale): array
    {
        $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
        if ($idDominio === '') {
            return [
                'success' => false,
                'errors' => ['Configurazione mancante: ID_DOMINIO non impostato.'],
            ];
        }

        $inputId = strtoupper(preg_replace('/\s+/', '', trim($codiceFiscale)));
        $inputIdDigits = preg_replace('/\D+/', '', $inputId);
        $normalizedInputId = ($inputIdDigits !== '' && strlen($inputIdDigits) === 11) ? $inputIdDigits : $inputId;

        Logger::getInstance()->info('Ricerca avviso PagoPA avviata', [
            'idDominio' => $idDominio,
            'numeroAvviso' => $numeroAvviso,
            'identificativoInput' => $normalizedInputId,
        ]);

        $normalizedAvviso = frontoffice_normalize_avviso_code($numeroAvviso);

        // Canale principale: GovPay Pendenze v2 (byAvviso) è il modo corretto per interrogare un numero avviso.
        $pendenza = frontoffice_fetch_pendenza_by_avviso($idDominio, $normalizedAvviso);

        // Fallback: manteniamo la vecchia ricerca via GovPay Pagamenti (per compatibilità in ambienti che non espongono byAvviso).
        if (!is_array($pendenza) || $pendenza === []) {
            $api = frontoffice_pagamenti_api_client();
            if ($api === null) {
                return [
                    'success' => false,
                    'errors' => ['Al momento non riusciamo a interrogare il sistema dei pagamenti. Riprova più tardi.'],
                ];
            }

            try {
                $result = $api->findPendenze(
                    1,
                    10,
                    null,
                    $idDominio,
                    null,
                    null,
                    $normalizedAvviso,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    false,
                    true,
                    true
                );
                $data = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                Logger::getInstance()->warning('Errore durante la ricerca dell\'avviso PagoPA', [
                    'codiceAvviso' => $numeroAvviso,
                    'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
                ]);
                return [
                    'success' => false,
                    'errors' => ['Al momento non riusciamo a interrogare il sistema dei pagamenti. Riprova più tardi.'],
                ];
            }

            $risultati = $data['risultati'] ?? [];
            $loggerPayload = [
                'numRisultati' => $data['numRisultati'] ?? null,
                'risultatiPerPagina' => $data['risultatiPerPagina'] ?? null,
                'pagina' => $data['pagina'] ?? null,
                'risultatiCount' => is_array($risultati) ? count($risultati) : 0,
                'requestedAvviso' => $normalizedAvviso,
            ];
            Logger::getInstance()->info('Risposta GovPay Pagamenti per ricerca avviso', $loggerPayload);

            $pendenza = null;
            foreach ($risultati as $candidate) {
                $candidateAvviso = frontoffice_normalize_avviso_code((string)($candidate['numeroAvviso'] ?? ''));
                if ($candidateAvviso !== '' && $candidateAvviso === $normalizedAvviso) {
                    $pendenza = $candidate;
                    break;
                }
            }
        }

        if (!is_array($pendenza) || $pendenza === []) {
            Logger::getInstance()->info('Nessun avviso corrispondente trovato dal frontoffice', [
                'requestedAvviso' => $normalizedAvviso,
            ]);
            return [
                'success' => false,
                'errors' => ['Nessun avviso trovato con i dati inseriti.'],
            ];
        }

        if (!frontoffice_pendenza_belongs_to_cf($pendenza, $normalizedInputId)) {
            Logger::getInstance()->warning('Identificativo pagatore/versante non coincide con l\'input', [
                'identificativoInput' => $normalizedInputId,
                'numeroAvviso' => $numeroAvviso,
            ]);
            return [
                'success' => false,
                'errors' => ['Il codice fiscale o la partita IVA indicata non coincide con il soggetto associato all\'avviso.'],
            ];
        }

        $state = strtoupper((string)($pendenza['stato'] ?? ''));
        if (!frontoffice_is_pendenza_payable($state) && $state !== 'ESEGUITA') {
            Logger::getInstance()->info('Avviso trovato ma non pagabile/pagato', [
                'numeroAvviso' => $numeroAvviso,
                'stato' => $state,
            ]);
            return [
                'success' => false,
                'errors' => ['Nessun avviso trovato con i dati inseriti.'],
            ];
        }

        Logger::getInstance()->info('Avviso PagoPA recuperato dal frontoffice', [
            'numeroAvviso' => $numeroAvviso,
            'idPendenza' => $pendenza['idPendenza'] ?? null,
            'stato' => $state,
        ]);

        return [
            'success' => true,
            'preview' => frontoffice_build_avviso_preview($pendenza, $idDominio),
            'pendenza' => $pendenza,
        ];
    }
}

if (!function_exists('frontoffice_process_avviso_form')) {
    function frontoffice_process_avviso_form(array $data): array
    {
        $codiceAvviso = frontoffice_normalize_avviso_code((string)($data['codiceAvviso'] ?? ''));
        $rawIdentificativo = (string)($data['codiceFiscale'] ?? '');
        $identificativo = strtoupper(preg_replace('/\s+/', '', trim($rawIdentificativo)));
        $identificativoDigits = preg_replace('/\D+/', '', $identificativo);

        $lookupIdentificativo = $identificativo;
        if ($identificativoDigits !== '' && strlen($identificativoDigits) === 11) {
            $lookupIdentificativo = $identificativoDigits;
        }
        $formData = [
            'codiceAvviso' => $codiceAvviso,
            'codiceFiscale' => $lookupIdentificativo,
        ];

        $errors = [];
        if ($codiceAvviso === '' || strlen($codiceAvviso) < 13) {
            $errors[] = 'Inserisci un codice avviso valido (almeno 13 caratteri alfanumerici).';
        }

        if ($identificativo === '') {
            $errors[] = 'Il codice fiscale o la partita IVA del pagatore è obbligatorio.';
        } elseif (preg_match('/^[A-Z0-9]{16}$/', $identificativo) === 1) {
            $validation = ValidationService::validateCodiceFiscale($identificativo, '', '');
            if (!$validation['format_ok'] || !$validation['check_ok'] || !$validation['valid']) {
                $errors[] = $validation['message'] ?? 'Codice fiscale non valido.';
            }
        } elseif ($identificativoDigits !== '' && strlen($identificativoDigits) === 11) {
            $validation = ValidationService::validatePartitaIva($identificativoDigits);
            if (!$validation['valid']) {
                $errors[] = $validation['message'] ?? 'Partita IVA non valida.';
            }
        } else {
            $errors[] = 'Inserisci un codice fiscale (16 caratteri) o una partita IVA (11 cifre) valida.';
        }

        if ($errors) {
            return [
                'success' => false,
                'errors' => $errors,
                'form_data' => $formData,
            ];
        }

        $lookup = frontoffice_lookup_pagopa_avviso($codiceAvviso, $lookupIdentificativo);
        $lookup['form_data'] = $formData;

        // Whitelist in sessione: consente il checkout pubblico solo per pendenze ricercate
        // da questo browser tramite il flusso "Paga un avviso".
        if (!empty($lookup['success']) && session_status() === PHP_SESSION_ACTIVE) {
            $idPendenza = (string)($lookup['pendenza']['idPendenza'] ?? '');
            if ($idPendenza !== '') {
                $key = 'frontoffice_avviso_pendenze';
                $list = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
                $list[] = $idPendenza;
                $list = array_values(array_unique(array_filter(array_map('strval', $list), static fn ($v) => trim($v) !== '')));
                if (count($list) > 25) {
                    $list = array_slice($list, -25);
                }
                $_SESSION[$key] = $list;
            }
        }

        return $lookup;
    }
}

if (!function_exists('frontoffice_process_spontaneous_request')) {
    function frontoffice_process_spontaneous_request(array $data, array $serviceOptions): array
    {
        $context = ['form_data' => $data];
        $errors = [];
        $serviceMap = [];
        foreach ($serviceOptions as $option) {
            $serviceMap[$option['id']] = $option;
        }

        $idTipo = trim((string)($data['idTipoPendenza'] ?? ''));
        if ($idTipo === '' || !isset($serviceMap[$idTipo])) {
            $errors[] = 'Seleziona il servizio da pagare.';
        } else {
            $selectedOption = $serviceMap[$idTipo];
            if (($selectedOption['type'] ?? 'internal') !== 'internal') {
                $errors[] = 'La tipologia selezionata non può essere compilata su questo portale.';
            }
        }

        $causale = trim((string)($data['causale'] ?? ''));
        if ($causale === '') {
            $errors[] = 'La causale è obbligatoria.';
        } elseif (!ValidationService::validateCausaleLength($causale)) {
            $errors[] = 'La causale può contenere al massimo 140 caratteri.';
        }

        $importo = frontoffice_normalize_amount($data['importo'] ?? null);
        if ($importo <= 0) {
            $errors[] = 'Inserisci un importo valido (maggiore di zero).';
        }

        $defaultYear = (int)date('Y');
        $annoRaw = $data['annoRiferimento'] ?? $defaultYear;
        $anno = is_scalar($annoRaw) && is_numeric((string)$annoRaw) ? (int)$annoRaw : 0;
        if ($anno < $defaultYear - 5 || $anno > $defaultYear + 1) {
            $errors[] = 'Anno di riferimento non valido.';
        }

        if (empty($data['privacy'])) {
            $errors[] = 'Devi accettare l\'informativa privacy per proseguire.';
        }

        $payerRaw = is_array($data['soggettoPagatore'] ?? null) ? $data['soggettoPagatore'] : [];
        $payerType = strtoupper((string)($payerRaw['tipo'] ?? 'F'));
        if (!in_array($payerType, ['F', 'G'], true)) {
            $payerType = 'F';
        }
        $ident = trim((string)($payerRaw['identificativo'] ?? ''));
        if ($ident === '') {
            $errors[] = $payerType === 'G' ? 'La partita IVA è obbligatoria.' : 'Il codice fiscale è obbligatorio.';
        } else {
            if ($payerType === 'F') {
                $validation = ValidationService::validateCodiceFiscale($ident, $payerRaw['nome'] ?? '', $payerRaw['anagrafica'] ?? '');
                if (!$validation['format_ok'] || !$validation['check_ok'] || !$validation['valid']) {
                    $errors[] = $validation['message'] ?? 'Codice fiscale non valido.';
                }
            } else {
                $validation = ValidationService::validatePartitaIva($ident);
                if (!$validation['valid']) {
                    $errors[] = $validation['message'] ?? 'Partita IVA non valida.';
                }
            }
        }

        $surname = trim((string)($payerRaw['anagrafica'] ?? ''));
        $name = trim((string)($payerRaw['nome'] ?? ''));
        if ($surname === '') {
            $errors[] = $payerType === 'G' ? 'La ragione sociale è obbligatoria.' : 'Il cognome è obbligatorio.';
        }
        if ($payerType === 'F' && $name === '') {
            $errors[] = 'Il nome è obbligatorio per le persone fisiche.';
        }
        $email = trim((string)($payerRaw['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Inserisci un indirizzo email valido.';
        }

        $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
        if ($idDominio === '') {
            $errors[] = 'Configurazione mancante: ID_DOMINIO non impostato.';
        }

        if ($errors) {
            $context['form_errors'] = $errors;
            $context['form_feedback'] = [
                'type' => 'danger',
                'title' => 'Controlla i dati inseriti',
                'message' => 'Alcuni campi non sono corretti. Correggili e riprova.',
            ];
            return $context;
        }

        // Scadenza automatica: oggi + 15 giorni (trasparente per l'utente)
        $dataScadenza = (new \DateTimeImmutable('today'))->modify('+15 days')->format('Y-m-d');

        $payload = [
            'idTipoPendenza' => $idTipo,
            'idDominio' => $idDominio,
            'causale' => $causale,
            'importo' => $importo,
            'annoRiferimento' => $anno,
            'soggettoPagatore' => frontoffice_prepare_payer($payerRaw),
            'voci' => frontoffice_build_voci($idDominio, $idTipo, $causale, $importo),
            'dataValidita' => date('Y-m-d'),
            'dataScadenza' => $dataScadenza,
            'datiAllegati' => ['sorgente' => 'Spontaneo'],
        ];

        $note = trim((string)($data['noteRichiedente'] ?? ''));
        if ($note !== '') {
            $payload['datiAllegati']['noteRichiedente'] = mb_substr($note, 0, 400);
        }

        $sendResult = frontoffice_send_pendenza_to_backoffice($payload);
        if (!$sendResult['success']) {
            $context['form_errors'] = $sendResult['errors'] ?? ['Invio pendenza non riuscito.'];
            $context['form_feedback'] = [
                'type' => 'danger',
                'title' => 'Invio non riuscito',
                'message' => implode(' ', $context['form_errors']),
            ];
            return $context;
        }

        $idPendenza = $sendResult['idPendenza'] ?? '';
        $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
        $numeroAvviso = frontoffice_extract_numero_avviso($sendResult['response'] ?? null, $detail);
        $downloadUrl = ($numeroAvviso && $idDominio !== '')
            ? '/avvisi/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroAvviso)
            : null;

        $context['pendenza_result'] = [
            'idPendenza' => $idPendenza,
            'numeroAvviso' => $numeroAvviso,
            'importo' => $importo,
            'causale' => $causale,
            'download_url' => $downloadUrl,
            // Checkout dinamico (server-side) per evitare di esporre la subscription key al browser.
            // Usiamo un endpoint dedicato allo spontaneo che funziona anche senza login,
            // consentendo il pagamento solo per pendenze generate in questa sessione.
            'checkout_url' => ($idPendenza !== '')
                ? ('/pagamento-spontaneo/checkout?idPendenza=' . rawurlencode($idPendenza))
                : null,
            'data_scadenza' => $detail['dataScadenza'] ?? $dataScadenza,
            'soggetto_pagatore' => $payload['soggettoPagatore'],
        ];

        // Whitelist in sessione: consente il checkout (endpoint pubblico) solo per pendenze
        // generate da questo browser. Evita che chiunque possa avviare checkout con un idPendenza arbitrario.
        if ($idPendenza !== '' && session_status() === PHP_SESSION_ACTIVE) {
            $key = 'frontoffice_spontaneo_pendenze';
            $list = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
            $list[] = $idPendenza;
            $list = array_values(array_unique(array_filter(array_map('strval', $list), static fn ($v) => trim($v) !== '')));
            if (count($list) > 25) {
                $list = array_slice($list, -25);
            }
            $_SESSION[$key] = $list;
        }

        $context['form_feedback'] = [
            'type' => 'success',
            'title' => 'Avviso generato',
            'message' => 'Abbiamo creato il tuo avviso PagoPA. Puoi scaricarlo subito oppure proseguire con il pagamento online.',
        ];
        $context['form_data'] = [];

        return $context;
    }
}

if (!function_exists('frontoffice_stream_avviso_pdf')) {
    function frontoffice_stream_avviso_pdf(string $idDominio, string $numeroAvviso): void
    {
        $expectedDominio = frontoffice_env_value('ID_DOMINIO', '');
        if ($expectedDominio !== '' && $idDominio !== $expectedDominio) {
            http_response_code(404);
            echo 'Avviso non trovato';
            return;
        }

        $backofficeUrl = frontoffice_env_value('GOVPAY_BACKOFFICE_URL', '');
        if ($backofficeUrl === '') {
            http_response_code(500);
            echo 'GOVPAY_BACKOFFICE_URL non impostata';
            return;
        }

        try {
            $options = frontoffice_govpay_client_options();
            $options['headers']['Accept'] = 'application/pdf';
            $options['headers']['Connection'] = 'close';
            if ($auth = frontoffice_basic_auth()) {
                $options['auth'] = $auth;
            }
            $client = new Client($options);
            $url = rtrim($backofficeUrl, '/') . '/avvisi/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroAvviso);
            $resp = $client->request('GET', $url);
            header('Content-Type: ' . ($resp->getHeaderLine('Content-Type') ?: 'application/pdf'));
            header('Content-Disposition: attachment; filename="avviso-' . $idDominio . '-' . $numeroAvviso . '.pdf"');
            header('Cache-Control: no-store');
            echo (string)$resp->getBody();
        } catch (ClientException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 502;
            http_response_code($status);
            echo 'Errore scaricamento avviso: ' . Logger::sanitizeErrorForDisplay($e->getMessage());
        } catch (\Throwable $e) {
            http_response_code(502);
            echo 'Errore scaricamento avviso: ' . Logger::sanitizeErrorForDisplay($e->getMessage());
        }
    }
}

if (!function_exists('frontoffice_stream_documento_pdf')) {
    /**
     * Scarica da GovPay Pendenze v2 il PDF multi-rata per il documento indicato.
     * Endpoint: GET /documenti/{idDominio}/{numeroDocumento}/avvisi
     * Usato dalla route GET /link/documento (link firmato).
     */
    function frontoffice_stream_documento_pdf(string $numeroDocumento): void
    {
        $pendenzeUrl = frontoffice_env_value('GOVPAY_PENDENZE_URL', '');
        $idDominio   = frontoffice_env_value('ID_DOMINIO', '');
        if ($pendenzeUrl === '' || $idDominio === '' || $numeroDocumento === '') {
            http_response_code(400);
            echo 'Parametri mancanti o GOVPAY_PENDENZE_URL/ID_DOMINIO non configurati.';
            return;
        }
        try {
            $options = frontoffice_govpay_client_options();
            $options['headers']['Accept'] = 'application/pdf';
            $options['headers']['Connection'] = 'close';
            if ($auth = frontoffice_basic_auth()) {
                $options['auth'] = $auth;
            }
            $client = new Client($options);
            $url = rtrim($pendenzeUrl, '/') . '/documenti/'
                . rawurlencode($idDominio) . '/' . rawurlencode($numeroDocumento) . '/avvisi';
            $resp = $client->request('GET', $url);
            header('Content-Type: ' . ($resp->getHeaderLine('Content-Type') ?: 'application/pdf'));
            header('Content-Disposition: attachment; filename="avvisi-' . rawurlencode($numeroDocumento) . '.pdf"');
            header('Cache-Control: no-store');
            echo (string)$resp->getBody();
        } catch (ClientException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 502;
            http_response_code($status);
            echo 'Errore scaricamento documento: ' . Logger::sanitizeErrorForDisplay($e->getMessage());
        } catch (\Throwable $e) {
            http_response_code(502);
            echo 'Errore scaricamento documento: ' . Logger::sanitizeErrorForDisplay($e->getMessage());
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Link firmati HMAC (stateless, scadenza 2 anni dalla firma)
// Env: FRONTOFFICE_LINK_SIGNING_KEY (consigliato in produzione)
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('frontoffice_link_signing_key')) {
    function frontoffice_link_signing_key(): string
    {
        $key = frontoffice_env_value('FRONTOFFICE_LINK_SIGNING_KEY', '');
        if ($key === '') {
            // Fallback deterministico basato su segreti già presenti — solo per sviluppo
            $fallback = frontoffice_env_value('APP_SECRET', '')
                . frontoffice_env_value('ID_DOMINIO', '')
                . 'gil-link-signing-fallback';
            $key = hash('sha256', $fallback);
        }
        return $key;
    }
}

if (!function_exists('frontoffice_sign_link')) {
    /**
     * Genera un array di query params da aggiungere all'URL: ['expires' => ..., 'sig' => ...]
     * $params: array associativo con i parametri payload da firmare (es. ['cf' => ..., 'iuv' => ...])
     * $ttlSeconds: durata validità (default 2 anni = 63072000 secondi)
     */
    function frontoffice_sign_link(array $params, int $ttlSeconds = 63072000 /* 60*60*24*365*2 = 2 anni */): array
    {
        $expires = time() + $ttlSeconds;
        $params['expires'] = (string)$expires;
        ksort($params);
        $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $sig = hash_hmac('sha256', $payload, frontoffice_link_signing_key());
        return array_merge($params, ['sig' => $sig]);
    }
}

if (!function_exists('frontoffice_verify_link')) {
    /**
     * Verifica la firma e la scadenza di un URL firmato.
     * $params: i query param ricevuti (inclusi 'expires' e 'sig')
     * Ritorna true se valido, false altrimenti.
     */
    function frontoffice_verify_link(array $params): bool
    {
        $sig = $params['sig'] ?? '';
        if ($sig === '') {
            return false;
        }
        $expires = (int)($params['expires'] ?? 0);
        if ($expires === 0 || time() > $expires) {
            return false;
        }
        $check = $params;
        unset($check['sig']);
        ksort($check);
        $payload = http_build_query($check, '', '&', PHP_QUERY_RFC3986);
        $expected = hash_hmac('sha256', $payload, frontoffice_link_signing_key());
        return hash_equals($expected, $sig);
    }
}

if (!function_exists('frontoffice_generate_pdf_link')) {
    /**
     * Genera URL firmato per il download del PDF avviso via CF+IUV.
     */
    function frontoffice_generate_pdf_link(string $codiceFiscale, string $iuv, string $baseUrl = ''): string
    {
        $params = frontoffice_sign_link(['cf' => $codiceFiscale, 'iuv' => $iuv]);
        $base = rtrim($baseUrl, '/');
        return $base . '/link/avviso?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('frontoffice_generate_ricevuta_link')) {
    /**
     * Genera URL firmato per il download della ricevuta via IUV+IUR.
     */
    function frontoffice_generate_ricevuta_link(string $iuv, string $iur, string $baseUrl = ''): string
    {
        $params = frontoffice_sign_link(['iuv' => $iuv, 'iur' => $iur]);
        $base = rtrim($baseUrl, '/');
        return $base . '/link/ricevuta?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('frontoffice_generate_checkout_link')) {
    /**
     * Genera URL firmato per il checkout immediato via CF+IUV.
     */
    function frontoffice_generate_checkout_link(string $codiceFiscale, string $iuv, string $baseUrl = ''): string
    {
        $params = frontoffice_sign_link(['cf' => $codiceFiscale, 'iuv' => $iuv, 'action' => 'checkout']);
        $base = rtrim($baseUrl, '/');
        return $base . '/link/checkout?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

$entityName = trim($env('APP_ENTITY_NAME', 'Comune di Montesilvano'));
$entitySuffix = trim($env('APP_ENTITY_SUFFIX', 'Provincia di Pescara'));
$entityGovernment = trim($env('APP_ENTITY_GOVERNMENT', 'Regione Abruzzo'));
$entityFull = trim($entityName . ($entitySuffix !== '' ? ' - ' . $entitySuffix : '')) ?: $entityGovernment;

$documentRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
$imgCandidates = [
    $documentRoot . '/img',
    __DIR__ . '/img',
    dirname(__DIR__) . '/img',
    dirname(__DIR__, 2) . '/public/img',
    dirname(__DIR__, 2) . '/img',
];
$imgDir = null;
foreach ($imgCandidates as $candidate) {
    if ($candidate && is_dir($candidate)) {
        $imgDir = $candidate;
        break;
    }
}
if ($imgDir === null) {
    $imgDir = $documentRoot . '/img';
}

$logoSrc = trim($env('APP_LOGO_SRC', '/img/stemma_ente.png'));
$logoType = trim($env('APP_LOGO_TYPE', 'img'));

$appLogo = ['type' => $logoType, 'src' => $logoSrc];
// Fallback se il file non esiste e il tipo è 'img' (logica semplificata: se è specificato un src esterno o relativo usiamolo)
if ($logoType === 'img' && $logoSrc === '/img/stemma_ente.png') {
    $customLogoPath = $imgDir . '/stemma_ente.png';
    if (!file_exists($customLogoPath)) {
        $appLogo = ['type' => 'sprite', 'src' => '/assets/bootstrap-italia/svg/sprites.svg#it-pa'];
    }
}

$faviconCandidates = [
    ['href' => '/img/favicon.ico', 'path' => $imgDir . '/favicon.ico', 'type' => 'image/x-icon'],
    ['href' => '/img/favicon.png', 'path' => $imgDir . '/favicon.png', 'type' => 'image/png'],
];
$appFavicon = ['href' => '/img/favicon_default.png', 'type' => 'image/png'];
foreach ($faviconCandidates as $candidate) {
    if (file_exists($candidate['path'])) {
        $appFavicon = ['href' => $candidate['href'], 'type' => $candidate['type']];
        break;
    }
}

$supportEmail = trim($env('APP_SUPPORT_EMAIL', ''));
if ($supportEmail === '') {
    $supportEmail = 'pagamenti@' . preg_replace('/[^a-z0-9]+/', '', strtolower($entityName ?: 'ente')) . '.it';
}

$serviceCatalog = frontoffice_load_service_options();
$serviceInternalOptions = array_values(array_filter($serviceCatalog, static fn ($opt) => ($opt['type'] ?? 'internal') === 'internal'));
$serviceExternalOptions = array_values(array_filter($serviceCatalog, static fn ($opt) => ($opt['type'] ?? 'internal') === 'external'));

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$normalizedPath = rtrim($requestPath, '/');
if ($normalizedPath === '') {
    $normalizedPath = '/';
}

$frontofficeBaseUrl = rtrim($env('FRONTOFFICE_PUBLIC_BASE_URL', ''), '/');
if ($frontofficeBaseUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '') {
        $frontofficeBaseUrl = $scheme . '://' . $host;
    }
}
$spidCallbackPath = $env('FRONTOFFICE_SPID_CALLBACK_PATH', '/spid/callback');
if ($spidCallbackPath === '' || $spidCallbackPath[0] !== '/') {
    $spidCallbackPath = '/' . ltrim($spidCallbackPath, '/');
}
$spidCallbackUrl = $frontofficeBaseUrl !== '' ? ($frontofficeBaseUrl . $spidCallbackPath) : '';

$routes = [
    '/' => static fn (): array => [
        'template' => 'home.html.twig',
        'context' => [],
    ],
    '/guida' => static function (): array {
        return [
            'template' => 'guida.html.twig',
            'context' => [],
        ];
    },
    '/checkout/ok' => static function (): array {
        // Support both single-pendenza (idPendenza) and multi-notice cart (idCart) flows.
        $idCart = trim((string)($_GET['idCart'] ?? ''));
        $idPendenza = trim((string)($_GET['idPendenza'] ?? $_GET['id_pendenza'] ?? ''));

        // Multi-notice cart path
        if ($idCart !== '') {
            $cartIds = (isset($_SESSION['frontoffice_carrello'][$idCart]) && is_array($_SESSION['frontoffice_carrello'][$idCart]))
                ? $_SESSION['frontoffice_carrello'][$idCart]
                : [];
            $numeroAvvisi = max(count($cartIds), 1);

            return [
                'template' => 'checkout/ok.html.twig',
                'context' => [
                    'numero_avvisi' => $numeroAvvisi,
                    'detail_path' => '/pendenze',
                    'login_path' => '/login?return_to=%2Fpendenze',
                    'is_logged_in' => frontoffice_get_logged_user() !== null,
                ],
            ];
        }

        // Single-pendenza path (original behavior)
        $detailPath = $idPendenza !== '' ? ('/pendenze/' . rawurlencode($idPendenza)) : '/pendenze';

        $numeroAvviso = null;
        if ($idPendenza !== '') {
            $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
            if (is_array($detail)) {
                $tmp = trim((string)($detail['numeroAvviso'] ?? ''));
                $tmp = preg_replace('/\D+/', '', $tmp);
                if (is_string($tmp) && $tmp !== '') {
                    $numeroAvviso = $tmp;
                }
            }
        }

        return [
            'template' => 'checkout/ok.html.twig',
            'context' => [
                'numero_avviso' => $numeroAvviso,
                'detail_path' => $detailPath,
                'login_path' => '/login?return_to=' . rawurlencode($detailPath),
                'is_logged_in' => frontoffice_get_logged_user() !== null,
            ],
        ];
    },

    '/checkout/cancel' => static function (): array {
        $idPendenza = trim((string)($_GET['idPendenza'] ?? $_GET['id_pendenza'] ?? ''));
        $detailPath = $idPendenza !== '' ? ('/pendenze/' . rawurlencode($idPendenza)) : '/pendenze';

        $numeroAvviso = null;
        if ($idPendenza !== '') {
            $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
            if (is_array($detail)) {
                $tmp = trim((string)($detail['numeroAvviso'] ?? ''));
                $tmp = preg_replace('/\D+/', '', $tmp);
                if (is_string($tmp) && $tmp !== '') {
                    $numeroAvviso = $tmp;
                }
            }
        }

        return [
            'template' => 'checkout/cancel.html.twig',
            'context' => [
                'numero_avviso' => $numeroAvviso,
                'detail_path' => $detailPath,
                'login_path' => '/login?return_to=' . rawurlencode($detailPath),
                'is_logged_in' => frontoffice_get_logged_user() !== null,
            ],
        ];
    },
    '/checkout/error' => static function (): array {
        $idPendenza = trim((string)($_GET['idPendenza'] ?? $_GET['id_pendenza'] ?? ''));
        $detailPath = $idPendenza !== '' ? ('/pendenze/' . rawurlencode($idPendenza)) : '/pendenze';

        $numeroAvviso = null;
        if ($idPendenza !== '') {
            $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
            if (is_array($detail)) {
                $tmp = trim((string)($detail['numeroAvviso'] ?? ''));
                $tmp = preg_replace('/\D+/', '', $tmp);
                if (is_string($tmp) && $tmp !== '') {
                    $numeroAvviso = $tmp;
                }
            }
        }

        return [
            'template' => 'checkout/error.html.twig',
            'context' => [
                'numero_avviso' => $numeroAvviso,
                'detail_path' => $detailPath,
                'login_path' => '/login?return_to=' . rawurlencode($detailPath),
                'is_logged_in' => frontoffice_get_logged_user() !== null,
            ],
        ];
    },
    '/login' => static function () use ($env, $spidCallbackUrl): array {
        if (!frontoffice_spid_enabled()) {
            http_response_code(404);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/login',
                ],
            ];
        }

        if (frontoffice_auth_proxy_type() === 'auth-proxy-saml2') {
            $frontofficeBaseUrl = rtrim($env('FRONTOFFICE_PUBLIC_BASE_URL', ''), '/');
            $envValue = $env('FRONTOFFICE_PUBLIC_BASE_URL', '');
            $httpHost = (string)($_SERVER['HTTP_HOST'] ?? '');
            
            if ($frontofficeBaseUrl === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                if ($host !== '') {
                    $frontofficeBaseUrl = $scheme . '://' . $host;
                }
            }
            
            // Debug: Add to session for inspection
            $_SESSION['debug_spid_url_config'] = [
                'FRONTOFFICE_PUBLIC_BASE_URL_env' => $envValue,
                'HTTP_HOST' => $httpHost,
                'frontofficeBaseUrl_final' => $frontofficeBaseUrl,
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            error_log("DEBUG SPID LOGIN: FRONTOFFICE_PUBLIC_BASE_URL_env=$envValue, HTTP_HOST=$httpHost, final=$frontofficeBaseUrl");

            $spidCallbackPath = $env('FRONTOFFICE_SPID_CALLBACK_PATH', '/spid/callback');
            if ($spidCallbackPath === '' || $spidCallbackPath[0] !== '/') {
                $spidCallbackPath = '/' . ltrim($spidCallbackPath, '/');
            }

            $proxyBase = rtrim($env('IAM_PROXY_PUBLIC_BASE_URL', ''), '/');
            if ($proxyBase === '') {
                $proxyBase = rtrim($env('SPID_PROXY_PUBLIC_BASE_URL', ''), '/');
            }
            // Preferisci l'URL interno (per il container frontoffice -> auth-proxy-nginx:443)
            // Fallback all'URL esterno (per il browser -> auth-proxy-nginx:9445)
            $metadataUrl = $env('IAM_PROXY_SAML2_IDP_METADATA_URL_INTERNAL', '');
            if ($metadataUrl === '') {
                $metadataUrl = $env('IAM_PROXY_SAML2_IDP_METADATA_URL', '');
            }
            if ($metadataUrl === '' && $proxyBase !== '') {
                $metadataUrl = $proxyBase . '/Saml2IDP/metadata';
            }
            // Con SSL=on nginx richiede HTTPS anche sulla porta 80 interna
            if (getenv('SSL') === 'on' && str_starts_with($metadataUrl, 'http://')) {
                $metadataUrl = 'https://' . substr($metadataUrl, 7);
            }

            if ($frontofficeBaseUrl === '' || $metadataUrl === '') {
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Login IAM Proxy non configurato: imposta IAM_PROXY_PUBLIC_BASE_URL (o SPID_PROXY_PUBLIC_BASE_URL) e verifica IAM_PROXY_SAML2_IDP_METADATA_URL.',
                    ],
                ];
            }

            $returnTo = (string)($_GET['return_to'] ?? '/');
            if ($returnTo === '' || $returnTo[0] !== '/') {
                $returnTo = '/';
            }
            $_SESSION['spid_return_to'] = $returnTo;

            $debug = trim((string)$env('IAM_PROXY_DEBUG', '0')) === '1' || strtolower(trim((string)$env('IAM_PROXY_DEBUG', 'false'))) === 'true';
            $auth = frontoffice_satosa_saml_auth($frontofficeBaseUrl, $spidCallbackPath, $metadataUrl, $debug);
            if (!$auth) {
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Login IAM Proxy non disponibile: impossibile inizializzare SAML. Verifica metadata e dipendenze PHP-SAML.',
                    ],
                ];
            }

            // RelayState: usa disco page del proxy IAM
            $proxyBase = rtrim($env('IAM_PROXY_PUBLIC_BASE_URL', ''), '/');
            $discoUrl = $proxyBase ? ($proxyBase . '/static/disco.html') : '/static/disco.html';

            // Optional: force IdP selection via idp_hint (useful for demo SPID)
            $idpHint = trim((string)$env('FRONTOFFICE_SPID_IDP_HINT', ''));
            if ($idpHint !== '') {
                $auth->login($discoUrl, ['idp_hint' => $idpHint]);
            } else {
                $auth->login($discoUrl);
            }
            exit;
        }

        $proxyBase = rtrim($env('SPID_PROXY_PUBLIC_BASE_URL', ''), '/');
        $clientId = $env('SPID_PROXY_CLIENT_ID', '');

        $signResponse = trim((string)$env('SPID_PROXY_SIGN_RESPONSE', '1')) === '1';
        $encryptResponse = trim((string)$env('SPID_PROXY_ENCRYPT_RESPONSE', '0')) === '1';
        $clientSecret = trim((string)$env('SPID_PROXY_CLIENT_SECRET', ''));

        if ($encryptResponse && !$signResponse) {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Login SPID non configurato: SPID_PROXY_ENCRYPT_RESPONSE=1 richiede anche SPID_PROXY_SIGN_RESPONSE=1.',
                ],
            ];
        }
        if ($encryptResponse && $clientSecret === '') {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Login SPID non configurato: SPID_PROXY_ENCRYPT_RESPONSE=1 richiede SPID_PROXY_CLIENT_SECRET (la stessa chiave va configurata anche lato proxy).',
                ],
            ];
        }

        $redirectUri = $env('FRONTOFFICE_SPID_REDIRECT_URI', '');
        $allowedRedirectsRaw = $env('SPID_PROXY_REDIRECT_URIS', '');
        $allowedRedirects = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $allowedRedirectsRaw))));

        if ($redirectUri === '') {
            // Default: usa SEMPRE la callback del frontoffice.
            // Se SPID_PROXY_REDIRECT_URIS è valorizzato, deve includere questo URL (match esatto), altrimenti
            // il proxy rifiuterà il redirect o finirai su un endpoint "demo" tipo /proxy-sample.php.
            $redirectUri = $spidCallbackUrl;
            if ($redirectUri !== '' && $allowedRedirects !== [] && !in_array($redirectUri, $allowedRedirects, true)) {
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Login SPID non configurato: SPID_PROXY_REDIRECT_URIS deve includere la callback del frontoffice (' . $redirectUri . ').',
                    ],
                ];
            }
        }

        // Se l'utente ha impostato un redirect esplicito, verifica comunque che sia autorizzato dal proxy.
        if ($redirectUri !== '' && $allowedRedirects !== [] && !in_array($redirectUri, $allowedRedirects, true)) {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Login SPID non configurato: FRONTOFFICE_SPID_REDIRECT_URI non è presente in SPID_PROXY_REDIRECT_URIS (' . $redirectUri . ').',
                ],
            ];
        }

        if ($proxyBase === '' || $clientId === '' || $redirectUri === '') {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Login SPID non configurato: verifica SPID_PROXY_PUBLIC_BASE_URL, SPID_PROXY_CLIENT_ID e configura un redirect URI valido (FRONTOFFICE_SPID_REDIRECT_URI oppure SPID_PROXY_REDIRECT_URIS).',
                ],
            ];
        }

        try {
            $state = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $state = md5((string)microtime(true));
        }

        $returnTo = (string)($_GET['return_to'] ?? '/');
        if ($returnTo === '' || $returnTo[0] !== '/') {
            $returnTo = '/';
        }
        $_SESSION['spid_state'] = $state;
        $_SESSION['spid_return_to'] = $returnTo;

        $target = $proxyBase . '/proxy-home.php'
            . '?client_id=' . rawurlencode($clientId)
            . '&redirect_uri=' . rawurlencode($redirectUri)
            . '&state=' . rawurlencode($state);

        header('Location: ' . $target, true, 302);
        exit;
    },
    '/spid/callback' => static function () use ($method, $env): array {
        // DEBUG: Write entry point
        file_put_contents('/tmp/spid_callback.log', date('Y-m-d H:i:s') . " - ENTRY - frontoffice_spid_enabled=" . (frontoffice_spid_enabled() ? 'true' : 'false') . ", auth_proxy_type=" . frontoffice_auth_proxy_type() . "\n", FILE_APPEND);
        
        if (!frontoffice_spid_enabled()) {
            http_response_code(404);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/spid/callback',
                ],
            ];
        }

        if ($method !== 'POST') {
            http_response_code(405);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/spid/callback',
                ],
            ];
        }

        if (frontoffice_auth_proxy_type() === 'auth-proxy-saml2') {
            file_put_contents('/tmp/spid_callback.log', date('Y-m-d H:i:s') . " - ENTERING auth-proxy-saml2 block\n", FILE_APPEND);
            // Get debug info from session (set in /login handler)
            $debugFromSession = $_SESSION['debug_spid_url_config'] ?? [];
            $debugFromSessionStr = $debugFromSession ? json_encode($debugFromSession) : 'no debug data';
            
            // Write to a temporary debug file
            $debugFile = '/tmp/spid_callback_debug_' . date('Ymdhis') . '.txt';
            file_put_contents($debugFile, "=== SPID CALLBACK DEBUG ===\nTimestamp: " . date('Y-m-d H:i:s') . "\nSession debug: " . $debugFromSessionStr . "\n");
            
            $frontofficeBaseUrl = rtrim($env('FRONTOFFICE_PUBLIC_BASE_URL', ''), '/');
            if ($frontofficeBaseUrl === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                if ($host !== '') {
                    $frontofficeBaseUrl = $scheme . '://' . $host;
                }
            }

            $spidCallbackPath = $env('FRONTOFFICE_SPID_CALLBACK_PATH', '/spid/callback');
            if ($spidCallbackPath === '' || $spidCallbackPath[0] !== '/') {
                $spidCallbackPath = '/' . ltrim($spidCallbackPath, '/');
            }

            $proxyBase = rtrim($env('IAM_PROXY_PUBLIC_BASE_URL', ''), '/');
            if ($proxyBase === '') {
                $proxyBase = rtrim($env('SPID_PROXY_PUBLIC_BASE_URL', ''), '/');
            }
            // Preferisci l'URL interno (per il container frontoffice -> auth-proxy-nginx:443)
            // Fallback all'URL esterno (per il browser -> auth-proxy-nginx:9445)
            $metadataUrl = $env('IAM_PROXY_SAML2_IDP_METADATA_URL_INTERNAL', '');
            if ($metadataUrl === '') {
                $metadataUrl = $env('IAM_PROXY_SAML2_IDP_METADATA_URL', '');
            }
            if ($metadataUrl === '' && $proxyBase !== '') {
                $metadataUrl = $proxyBase . '/Saml2IDP/metadata';
            }

            $debug = trim((string)$env('IAM_PROXY_DEBUG', '0')) === '1' || strtolower(trim((string)$env('IAM_PROXY_DEBUG', 'false'))) === 'true';
            $auth = frontoffice_satosa_saml_auth($frontofficeBaseUrl, $spidCallbackPath, $metadataUrl, $debug);
            if (!$auth) {
                error_log("DEBUG SPID CALLBACK: auth object is null, session_debug=$debugFromSessionStr, frontofficeBaseUrl=$frontofficeBaseUrl, metadataUrl=$metadataUrl");
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Callback IAM Proxy non valida: SAML non inizializzabile (metadata o configurazione mancante).',
                        'debug_session_info' => $debugFromSessionStr,
                    ],
                ];
            }

            error_log("DEBUG SPID CALLBACK: auth object created, frontofficeBaseUrl=$frontofficeBaseUrl, spidCallbackPath=$spidCallbackPath, metadataUrl=$metadataUrl, session_debug=$debugFromSessionStr");

            try {
                $auth->processResponse();
            } catch (SamlError $e) {
                error_log("DEBUG SPID CALLBACK: SamlError in processResponse: " . $e->getMessage());
                Logger::getInstance()->warning('SAML error', ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                error_log("DEBUG SPID CALLBACK: Exception in processResponse: " . $e->getMessage());
                Logger::getInstance()->warning('Errore processResponse SAML', ['error' => $e->getMessage()]);
            }

            $errors = $auth->getErrors();
            error_log("DEBUG SPID CALLBACK: errors=" . implode(', ', $errors) . ", isAuthenticated=" . ($auth->isAuthenticated() ? 'true' : 'false'));
            if (!empty($errors) || !$auth->isAuthenticated()) {
                $reason = $auth->getLastErrorReason();
                error_log("DEBUG SPID CALLBACK: getLastErrorReason=$reason");
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Login IAM Proxy fallito: ' . ($reason ?: 'risposta SAML non valida.') ,
                        'debug_spid_errors' => implode(', ', $errors),
                        'debug_spid_reason' => $reason,
                        'debug_session_info' => $debugFromSessionStr,
                    ],
                ];
            }

            $attrs = $auth->getAttributes();
            $rawXmlAttrs = frontoffice_extract_saml_attributes_from_xml((string)$auth->getLastResponseXML());

            $postedXmlAttrs = [];
            $postedSamlResponse = (string)($_POST['SAMLResponse'] ?? '');
            if ($postedSamlResponse !== '') {
                $decodedPostedXml = base64_decode($postedSamlResponse, true);
                if (is_string($decodedPostedXml) && $decodedPostedXml !== '') {
                    $postedXmlAttrs = frontoffice_extract_saml_attributes_from_xml($decodedPostedXml);
                }
            }

            if ($postedXmlAttrs !== []) {
                $attrs = array_merge($postedXmlAttrs, $attrs);
            }
            if ($rawXmlAttrs !== []) {
                $attrs = array_merge($rawXmlAttrs, $attrs);
            }
            error_log('DEBUG SPID ATTR KEYS (SAML): ' . json_encode([
                'attrs_keys' => array_keys($attrs),
                'xml_attrs_keys' => array_keys($rawXmlAttrs),
                'posted_xml_attrs_keys' => array_keys($postedXmlAttrs),
                'email_candidates' => [
                    'email' => $attrs['email'] ?? null,
                    'mail' => $attrs['mail'] ?? null,
                    'emailAddress' => $attrs['emailAddress'] ?? null,
                    'urn_mail' => $attrs['urn:oid:0.9.2342.19200300.100.1.3'] ?? null,
                ],
            ], JSON_UNESCAPED_UNICODE));

            $user = [
                'first_name' => frontoffice_pick_attribute_value($attrs, ['name', 'givenName', 'given_name', 'first_name', 'nome', 'urn:oid:2.5.4.42']),
                'last_name' => frontoffice_pick_attribute_value($attrs, ['familyName', 'family_name', 'sn', 'surname', 'last_name', 'cognome', 'urn:oid:2.5.4.4']),
                'email' => frontoffice_pick_email_value($attrs),
                'fiscal_number' => frontoffice_normalize_fiscal_number(frontoffice_pick_attribute_value($attrs, ['fiscalNumber', 'fiscal_number', 'fiscalCode', 'fiscal_code', 'codiceFiscale', 'https://attributes.eid.gov.it/fiscal_number', 'https://attributes.spid.gov.it/fiscalNumber', 'urn:oid:2.5.4.97', 'urn:oid:1.3.6.1.4.1.4710.2.1.1'])),
                'spid_code' => frontoffice_pick_attribute_value($attrs, ['spidCode', 'spid_code']),
                'provider_id' => 'IAM_PROXY_ITALIA',
                'provider_name' => frontoffice_detect_auth_provider_name($auth),
                'response_id' => '',
            ];

            $_SESSION['frontoffice_user'] = $user;
            error_log('DEBUG SPID CALLBACK USER (SAML): ' . json_encode([
                'email' => (string)($user['email'] ?? ''),
                'mail' => (string)($user['mail'] ?? ''),
                'fiscal_number' => (string)($user['fiscal_number'] ?? ''),
                'keys' => array_keys($user),
            ], JSON_UNESCAPED_UNICODE));

            $returnTo = (string)($_POST['RelayState'] ?? ($_SESSION['spid_return_to'] ?? '/'));
            unset($_SESSION['spid_return_to']);
            if ($returnTo === '' || $returnTo[0] !== '/') {
                $returnTo = '/';
            }

            header('Location: ' . $returnTo, true, 302);
            exit;
        }

        $state = (string)($_POST['state'] ?? '');
        $expectedState = (string)($_SESSION['spid_state'] ?? '');
        if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            http_response_code(400);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Callback SPID non valida (state mismatch). Riprovare il login.',
                ],
            ];
        }

        $providerId = (string)($_POST['providerId'] ?? '');
        $providerName = (string)($_POST['providerName'] ?? '');
        $responseId = (string)($_POST['responseId'] ?? '');

        $token = isset($_POST['data']) && is_string($_POST['data']) ? trim($_POST['data']) : '';
        $attrs = null;
        if ($token !== '') {
            $proxyBase = rtrim((string)$env('SPID_PROXY_PUBLIC_BASE_URL', ''), '/');
            $encryptResponse = trim((string)$env('SPID_PROXY_ENCRYPT_RESPONSE', '0')) === '1';
            $clientSecret = trim((string)$env('SPID_PROXY_CLIENT_SECRET', ''));
            if ($encryptResponse && $clientSecret === '') {
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Callback SPID non valida: response cifrata ma SPID_PROXY_CLIENT_SECRET non è configurato.',
                    ],
                ];
            }

            $service = (stripos($providerId, 'CIE') === 0) ? 'cie' : 'spid';
            $decoded = frontoffice_spid_decode_proxy_token($proxyBase, $token, $encryptResponse, $clientSecret, $service);
            if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Callback SPID non valida: impossibile decodificare la response del proxy. Verifica SPID_PROXY_SIGN_RESPONSE/SPID_PROXY_ENCRYPT_RESPONSE e la chiave SPID_PROXY_CLIENT_SECRET.',
                    ],
                ];
            }
            $attrs = $decoded['data'];
        }

        $attrsForUser = (array)($attrs ?? []);
        $user = [
            'first_name' => (string)(frontoffice_pick_attribute_value($attrsForUser, ['name', 'givenName', 'given_name', 'first_name', 'nome']) ?: ($_POST['name'] ?? $_POST['givenName'] ?? '')),
            'last_name' => (string)(frontoffice_pick_attribute_value($attrsForUser, ['familyName', 'family_name', 'sn', 'surname', 'last_name', 'cognome']) ?: ($_POST['familyName'] ?? $_POST['surname'] ?? '')),
            'email' => (string)(frontoffice_pick_email_value($attrsForUser) ?: ($_POST['email'] ?? $_POST['mail'] ?? $_POST['emailAddress'] ?? '')),
            'fiscal_number' => frontoffice_normalize_fiscal_number((string)(frontoffice_pick_attribute_value($attrsForUser, ['fiscalNumber', 'fiscal_number', 'fiscalnumber', 'FiscalNumber', 'fiscalCode', 'fiscal_code', 'codiceFiscale', 'https://attributes.eid.gov.it/fiscal_number', 'https://attributes.spid.gov.it/fiscalNumber']) ?: ($_POST['fiscalNumber'] ?? $_POST['fiscal_number'] ?? $_POST['fiscalnumber'] ?? ''))),
            'spid_code' => (string)(frontoffice_pick_attribute_value($attrsForUser, ['spidCode', 'spid_code']) ?: ($_POST['spidCode'] ?? '')),
            'provider_id' => $providerId,
            'provider_name' => $providerName,
            'response_id' => $responseId,
        ];

        if ($token !== '') {
            $user['token'] = $token;
        }

        $_SESSION['frontoffice_user'] = $user;
        error_log('DEBUG SPID CALLBACK USER (TOKEN): ' . json_encode([
            'email' => (string)($user['email'] ?? ''),
            'mail' => (string)($user['mail'] ?? ''),
            'fiscal_number' => (string)($user['fiscal_number'] ?? ''),
            'keys' => array_keys($user),
        ], JSON_UNESCAPED_UNICODE));
        unset($_SESSION['spid_state']);
        $returnTo = (string)($_SESSION['spid_return_to'] ?? '/');
        unset($_SESSION['spid_return_to']);
        if ($returnTo === '' || $returnTo[0] !== '/') {
            $returnTo = '/';
        }

        header('Location: ' . $returnTo, true, 302);
        exit;
    },
    '/saml/sp' => static function () use ($env): array {
        if (!frontoffice_spid_enabled()) {
            http_response_code(404);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/saml/sp',
                ],
            ];
        }

        $freezeMetadata = trim(frontoffice_env_value('FRONTOFFICE_SAML_SP_METADATA_FREEZE', '0')) === '1';
        $metadataFile = trim(frontoffice_env_value('FRONTOFFICE_SAML_SP_METADATA_FILE', ''));
        $metadataCandidates = [];
        if ($metadataFile !== '') {
            $metadataCandidates[] = $metadataFile;
        }
        $metadataCandidates[] = '/var/www/html/metadata-sp/frontoffice_sp.xml';
        $metadataCandidates[] = '/metadata/sp/frontoffice_sp.xml';
        $frozenMetadataPath = null;
        foreach ($metadataCandidates as $candidate) {
            if ($candidate !== '' && file_exists($candidate)) {
                $frozenMetadataPath = $candidate;
                break;
            }
        }
        if ($frozenMetadataPath !== null) {
            $xml = @file_get_contents($frozenMetadataPath);
            if (is_string($xml) && $xml !== '') {
                header('Content-Type: application/xml; charset=utf-8', true, 200);
                return [
                    'raw_output' => $xml,
                ];
            }
        } elseif ($freezeMetadata) {
            http_response_code(500);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Metadata SP freeze attivo ma file non trovato. Configura FRONTOFFICE_SAML_SP_METADATA_FILE o monta metadata-sp nel container.',
                ],
            ];
        }

        $frontofficeBaseUrl = rtrim($env('FRONTOFFICE_PUBLIC_BASE_URL', ''), '/');
        if ($frontofficeBaseUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
            if ($host !== '') {
                $frontofficeBaseUrl = $scheme . '://' . $host;
            }
        }

        $spidCallbackPath = $env('FRONTOFFICE_SPID_CALLBACK_PATH', '/spid/callback');
        if ($spidCallbackPath === '' || $spidCallbackPath[0] !== '/') {
            $spidCallbackPath = '/' . ltrim($spidCallbackPath, '/');
        }

        $proxyBase = rtrim($env('IAM_PROXY_PUBLIC_BASE_URL', ''), '/');
        if ($proxyBase === '') {
            $proxyBase = rtrim($env('SPID_PROXY_PUBLIC_BASE_URL', ''), '/');
        }
        $metadataUrl = $env('IAM_PROXY_SAML2_IDP_METADATA_URL_INTERNAL', '');
        if ($metadataUrl === '') {
            $metadataUrl = $env('IAM_PROXY_SAML2_IDP_METADATA_URL', '');
        }
        if ($metadataUrl === '' && $proxyBase !== '') {
            $metadataUrl = $proxyBase . '/Saml2IDP/metadata';
        }

        $debug = trim((string)$env('IAM_PROXY_DEBUG', '0')) === '1' || strtolower(trim((string)$env('IAM_PROXY_DEBUG', 'false'))) === 'true';
        $auth = frontoffice_satosa_saml_auth($frontofficeBaseUrl, $spidCallbackPath, $metadataUrl, $debug);
        if (!$auth) {
            http_response_code(500);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'SAML SP metadata non disponibile',
                ],
            ];
        }

        // Genera i metadati SP usando la libreria OneLogin/php-saml
        // Usa la classe Metadata per costruire il metadata XML dal file di configurazione SAML
        try {
            $settings = $auth->getSettings();
            $securityData = $settings->getSecurityData();
            $spMetadata = Metadata::builder(
                $settings->getSPData(),
                (bool)($securityData['authnRequestsSigned'] ?? false),
                (bool)($securityData['wantAssertionsSigned'] ?? false),
                null,
                null,
                $settings->getContacts(),
                $settings->getOrganization()
            );
            $spMetadata = preg_replace('/<md:AssertionConsumerService([^>]*?)index="\d+"([^>]*?)\/>/', '<md:AssertionConsumerService$1index="0"$2 isDefault="true" />', $spMetadata);
            $spMetadata = preg_replace('/<md:AttributeConsumingService index="\d+"/', '<md:AttributeConsumingService index="0"', $spMetadata);
            $ipaCode = trim(frontoffice_env_value('APP_ENTITY_IPA_CODE', ''));
            if ($ipaCode === '') {
                $ipaCode = trim(frontoffice_env_value('SATOSA_CONTACT_PERSON_IPA_CODE', ''));
            }
            $spMetadata = frontoffice_inject_spid_contact_extensions($spMetadata, $ipaCode);
            if (!empty($securityData['signMetadata'])) {
                $spKey = $settings->getSPkey();
                $spCert = $settings->getSPcert();
                if ($spKey !== '' && $spCert !== '') {
                    if (@openssl_pkey_get_private($spKey) === false) {
                        throw new \RuntimeException('SP private key non valida');
                    }
                    if (@openssl_x509_parse($spCert) === false) {
                        throw new \RuntimeException('Certificato SP non valido');
                    }
                    $spMetadata = Metadata::addX509KeyDescriptors($spMetadata, $spCert, false);
                    $spMetadata = Metadata::signMetadata(
                        $spMetadata,
                        $spKey,
                        $spCert,
                        $securityData['signMetadataAlgorithm'] ?? null,
                        $securityData['digestAlgorithm'] ?? null
                    );
                }
            }
            
            if ($spMetadata === '' || $spMetadata === null) {
                http_response_code(500);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Impossibile generare SAML SP metadata',
                    ],
                ];
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Error generating SP metadata', ['error' => $e->getMessage()]);
            http_response_code(500);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Errore nella generazione del metadata SP: ' . $e->getMessage(),
                ],
            ];
        }

        // Ritorna il metadata come XML
        header('Content-Type: application/xml; charset=utf-8', true, 200);
        return [
            'raw_output' => $spMetadata,
        ];
    },
    '/profile' => static function (): array {
        if (!frontoffice_spid_enabled()) {
            http_response_code(404);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/profile',
                ],
            ];
        }

        $user = frontoffice_get_logged_user();
        error_log('DEBUG PROFILE USER: ' . json_encode([
            'email' => (string)($user['email'] ?? ''),
            'mail' => (string)($user['mail'] ?? ''),
            'fiscal_number' => (string)($user['fiscal_number'] ?? ''),
            'keys' => is_array($user) ? array_keys($user) : [],
        ], JSON_UNESCAPED_UNICODE));
        if (!is_array($user) || $user === []) {
            header('Location: /login?return_to=%2Fprofile', true, 302);
            exit;
        }
        return [
            'template' => 'profile.html.twig',
            'context' => [
                'profile' => $user,
            ],
        ];
    },
    '/logout' => static function () use ($env, $frontofficeBaseUrl): array {
        if (!frontoffice_spid_enabled()) {
            http_response_code(404);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/logout',
                ],
            ];
        }

        unset($_SESSION['frontoffice_user']);

        // Prova logout remoto solo per il proxy PHP; per SATOSA facciamo logout locale (SLO opzionale e dipende dalla config).
        $proxyBase = rtrim($env('SPID_PROXY_PUBLIC_BASE_URL', ''), '/');
        $clientId = trim($env('SPID_PROXY_CLIENT_ID', ''));

        $returnTo = trim((string)($_GET['return_to'] ?? '/'));
        if ($returnTo === '' || $returnTo[0] !== '/') {
            $returnTo = '/';
        }

        $frontofficeBase = rtrim((string)$frontofficeBaseUrl, '/');
        $redirectUri = $frontofficeBase !== '' ? ($frontofficeBase . $returnTo) : '';

        // Chiudi la sessione PHP locale (così il logout è immediato anche se il proxy impiega qualche redirect).
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
        }

        if (frontoffice_auth_proxy_type() === 'php-proxy' && $proxyBase !== '' && $clientId !== '' && $redirectUri !== '') {
            try {
                $state = bin2hex(random_bytes(16));
            } catch (\Throwable $e) {
                $state = md5((string)microtime(true));
            }
            $target = $proxyBase . '/proxy.php'
                . '?action=logout'
                . '&client_id=' . rawurlencode($clientId)
                . '&redirect_uri=' . rawurlencode($redirectUri)
                . '&state=' . rawurlencode($state);

            header('Location: ' . $target, true, 302);
            exit;
        }

        header('Location: ' . $returnTo, true, 302);
        exit;
    },
    '/pagamento-spontaneo' => static function () use ($method, $serviceCatalog, $serviceInternalOptions, $serviceExternalOptions, $env): array {
        $defaultYear = (int) date('Y');
        $payPortalUrl = $env('FRONTOFFICE_PAGOPA_CHECKOUT_URL', 'https://checkout.pagopa.it/');
        $selectedId = $method === 'POST'
            ? trim((string)($_POST['idTipoPendenza'] ?? ''))
            : trim((string)($_GET['tipologia'] ?? ''));

        $selectedService = $selectedId !== ''
            ? frontoffice_find_service_option($serviceCatalog, $selectedId)
            : null;
        $selectionError = null;

        if ($method !== 'POST' && $selectedService && ($selectedService['type'] ?? 'internal') !== 'internal') {
            $target = trim((string)($selectedService['external_url'] ?? ''));
            if ($target !== '') {
                header('Location: ' . $target, true, 302);
                exit;
            }
            Logger::getInstance()->warning('Tipologia esterna priva di URL', ['id' => $selectedService['id']]);
            $selectionError = 'La tipologia selezionata non è disponibile al momento.';
            $selectedService = null;
        }

        $showForm = $method === 'POST' || ($selectedService && ($selectedService['type'] ?? 'internal') === 'internal');

        if (!$showForm) {
            return [
                'template' => 'pagamenti/spontaneo-list.html.twig',
                'context' => [
                    // Lista unica ordinata alfabeticamente (frontoffice_load_service_options() ritorna gia' A->Z)
                    // Manteniamo la caratterizzazione grafica tramite service.type (internal/external)
                    'internal_services' => $serviceCatalog,
                    'external_services' => [],
                    'selection_error' => $selectionError ?? ($selectedId !== '' ? 'La tipologia selezionata non è disponibile.' : null),
                ],
            ];
        }

        $baseContext = [
            'service_options' => $serviceInternalOptions,
            'default_year' => $defaultYear,
            'pay_portal_url' => $payPortalUrl,
            'selected_service' => $selectedService,
        ];

        if ($method !== 'POST' && $selectedService) {
            $baseContext['form_data'] = array_merge(['idTipoPendenza' => $selectedService['id']], $baseContext['form_data'] ?? []);
        }

        if ($method === 'POST') {
            $result = frontoffice_process_spontaneous_request($_POST, $serviceInternalOptions);
            $baseContext = array_merge($baseContext, $result);
            $resolvedId = (string)($baseContext['form_data']['idTipoPendenza'] ?? $selectedId);
            if ($resolvedId !== '') {
                $baseContext['selected_service'] = frontoffice_find_service_option($serviceCatalog, $resolvedId);
            }
            if (!empty($baseContext['selected_service'])) {
                $baseContext['form_data']['idTipoPendenza'] = $baseContext['selected_service']['id'];
            }
        } else {
            $baseContext['form_data'] = $baseContext['form_data'] ?? ['idTipoPendenza' => $selectedService['id'] ?? ''];

            // Precompila i dati del soggetto pagatore quando l'utente è loggato (SPID/CIE).
            // Non sovrascrive quanto già presente in form_data.
            $loggedUser = frontoffice_get_logged_user();
            if (is_array($loggedUser) && $loggedUser !== []) {
                $fiscalNumber = frontoffice_get_logged_user_fiscal_number();
                $fiscalCompact = strtoupper(preg_replace('/\s+/', '', trim($fiscalNumber)));
                $fiscalDigits = preg_replace('/\D+/', '', $fiscalCompact);

                $isLegalEntity = ($fiscalDigits !== '' && strlen($fiscalDigits) === 11);
                $defaultTipo = $isLegalEntity ? 'G' : 'F';
                $defaultIdent = $isLegalEntity ? $fiscalDigits : $fiscalCompact;

                $defaultNome = trim((string)($loggedUser['first_name'] ?? ''));
                $defaultCognome = trim((string)($loggedUser['last_name'] ?? ''));
                $defaultEmail = trim((string)($loggedUser['email'] ?? ''));

                $defaultAnagrafica = $defaultTipo === 'F'
                    ? $defaultCognome
                    : trim(($defaultCognome !== '' ? $defaultCognome : $defaultNome));
                if ($defaultTipo === 'G' && $defaultAnagrafica === '') {
                    $defaultAnagrafica = $defaultEmail;
                }

                $existing = (isset($baseContext['form_data']['soggettoPagatore']) && is_array($baseContext['form_data']['soggettoPagatore']))
                    ? $baseContext['form_data']['soggettoPagatore']
                    : [];

                $prefill = [
                    'tipo' => $defaultTipo,
                    'identificativo' => $defaultIdent,
                    'anagrafica' => $defaultAnagrafica,
                    'nome' => $defaultTipo === 'F' ? $defaultNome : '',
                    'email' => $defaultEmail,
                ];

                foreach ($prefill as $k => $v) {
                    if (!isset($existing[$k]) || trim((string)$existing[$k]) === '') {
                        if ($v !== '') {
                            $existing[$k] = $v;
                        }
                    }
                }

                $baseContext['form_data']['soggettoPagatore'] = $existing;
            }
        }

        return [
            'template' => 'pagamenti/spontaneo.html.twig',
            'context' => $baseContext,
        ];
    },
    '/pagamento-avviso' => static function () use ($method): array {
        if ($method === 'POST') {
            $result = frontoffice_process_avviso_form($_POST);
            if (!empty($result['success'])) {
                $preview = is_array($result['preview'] ?? null) ? $result['preview'] : [];
                $isPaid = !empty($preview['is_paid']);
                $loggedUser = frontoffice_get_logged_user();

                // Avviso già pagato: se l'utente non è autenticato non mostriamo dettagli.
                // Manteniamo la pagina del form (come "avviso non trovato") e cambiamo solo il messaggio con un warning.
                if ($isPaid && $loggedUser === null) {
                    return [
                        'template' => 'pagamenti/avviso.html.twig',
                        'context' => [
                            'form_submitted' => true,
                            'form_data' => $result['form_data'] ?? $_POST,
                            'form_errors' => [],
                            'form_feedback' => [
                                'type' => 'warning',
                                'title' => 'Avviso già pagato',
                                'message' => 'L\'avviso inserito risulta già pagato. Per scaricare la ricevuta è necessario effettuare l\'accesso.',
                            ],
                        ],
                    ];
                }

                return [
                    'template' => 'pagamenti/avviso-preview.html.twig',
                    'context' => [
                        'avviso_preview' => $preview,
                        'pendenza' => $result['pendenza'] ?? [],
                        'back_url' => '/pagamento-avviso',
                        'search_params' => $result['form_data'] ?? [],
                    ],
                ];
            }

            $errors = $result['errors'] ?? ['Non è stato possibile verificare l\'avviso.'];
            $message = implode(' ', array_unique($errors));

            return [
                'template' => 'pagamenti/avviso.html.twig',
                'context' => [
                    'form_submitted' => true,
                    'form_data' => $result['form_data'] ?? $_POST,
                    'form_errors' => $errors,
                    'form_feedback' => [
                        'type' => 'danger',
                        'title' => 'Verifica non riuscita',
                        'message' => $message,
                    ],
                ],
            ];
        }

        return [
            'template' => 'pagamenti/avviso.html.twig',
            'context' => [
                'form_submitted' => false,
                'form_data' => [],
                'form_errors' => [],
                'form_feedback' => null,
            ],
        ];
    },
    '/pendenze' => static function () use ($method, $env): array {
        if (!frontoffice_spid_enabled()) {
            http_response_code(404);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/pendenze',
                ],
            ];
        }

        if ($method !== 'GET') {
            http_response_code(405);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/pendenze',
                ],
            ];
        }

        $user = frontoffice_get_logged_user();
        if ($user === null) {
            header('Location: /login?return_to=%2Fpendenze', true, 302);
            exit;
        }

        $codiceFiscale = frontoffice_get_logged_user_fiscal_number();
        if ($codiceFiscale === '') {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Profilo SPID incompleto: codice fiscale non presente. Riprovare il login.',
                ],
            ];
        }

        $page = (int)($_GET['page'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        $perPage = (int)($_GET['per_page'] ?? 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
        $idA2A = frontoffice_env_value('ID_A2A', '');
        $statoRaw = strtoupper(trim((string)($_GET['stato'] ?? '')));
        $allowedStates = [
            \GovPay\Pagamenti\Model\StatoPendenza::ESEGUITA,
            \GovPay\Pagamenti\Model\StatoPendenza::NON_ESEGUITA,
            \GovPay\Pagamenti\Model\StatoPendenza::ESEGUITA_PARZIALE,
            \GovPay\Pagamenti\Model\StatoPendenza::ANNULLATA,
            \GovPay\Pagamenti\Model\StatoPendenza::SCADUTA,
            \GovPay\Pagamenti\Model\StatoPendenza::ANOMALA,
        ];
        $stato = in_array($statoRaw, $allowedStates, true) ? $statoRaw : null;

        $api = frontoffice_pagamenti_api_client();
        if ($api === null) {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Al momento non riusciamo a interrogare il sistema dei pagamenti. Riprova più tardi.',
                ],
            ];
        }

        try {
            $result = $api->findPendenze(
                $page,
                $perPage,
                null,
                $idDominio !== '' ? $idDominio : null,
                null,
                null,
                null,
                $idA2A !== '' ? $idA2A : null,
                null,
                $codiceFiscale,
                $stato,
                null,
                null,
                null,
                false,
                true,
                true
            );
            $data = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Errore durante la ricerca pendenze per utente frontoffice', [
                'codiceFiscale' => $codiceFiscale,
                'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Al momento non riusciamo a interrogare il sistema dei pagamenti. Riprova più tardi.',
                ],
            ];
        }

        $risultati = $data['risultati'] ?? [];
        if (!is_array($risultati)) {
            $risultati = [];
        }

        $rows = [];
        foreach ($risultati as $pendenza) {
            if (!is_array($pendenza)) {
                continue;
            }
            $state = strtoupper((string)($pendenza['stato'] ?? ''));
            $numeroAvviso = trim((string)($pendenza['numeroAvviso'] ?? ''));
            $rows[] = [
                'id_pendenza' => (string)($pendenza['idPendenza'] ?? ''),
                'id_a2a' => (string)($pendenza['idA2A'] ?? ''),
                'numero_avviso' => $numeroAvviso,
                'causale' => trim((string)($pendenza['causale'] ?? '')),
                'importo' => isset($pendenza['importo']) && is_numeric($pendenza['importo']) ? (float)$pendenza['importo'] : null,
                'data_scadenza' => $pendenza['dataScadenza'] ?? null,
                'data_validita' => $pendenza['dataValidita'] ?? null,
                'stato' => [
                    'code' => $state,
                    'label' => frontoffice_map_pendenza_state($state),
                ],
                'is_payable' => frontoffice_is_pendenza_payable($state),
                'is_paid' => frontoffice_is_pendenza_paid($state),
                'download_url' => ($numeroAvviso !== '' && $idDominio !== '')
                    ? '/avvisi/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroAvviso)
                    : null,
                'receipt_url' => (frontoffice_is_pendenza_paid($state) && (string)($pendenza['idPendenza'] ?? '') !== '')
                    ? '/pendenze/' . rawurlencode((string)$pendenza['idPendenza']) . '/ricevuta'
                    : null,
                'detail_url' => (string)($pendenza['idPendenza'] ?? '') !== ''
                    ? '/pendenze/' . rawurlencode((string)$pendenza['idPendenza'])
                    : null,
            ];
        }

        // Populate session whitelist so /carrello/checkout can authorize these pendenze.
        if (session_status() === PHP_SESSION_ACTIVE) {
            $fetchedIds = array_values(array_filter(array_map(static fn ($r) => $r['id_pendenza'] ?? '', $rows), static fn ($v) => $v !== ''));
            $key = 'frontoffice_pendenze_whitelist';
            $existing = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
            $merged = array_values(array_unique(array_merge($existing, $fetchedIds)));
            if (count($merged) > 100) {
                $merged = array_slice($merged, -100);
            }
            $_SESSION[$key] = $merged;
        }

        return [
            'template' => 'pendenze/index.html.twig',
            'context' => [
                'profile' => $user,
                'codice_fiscale' => $codiceFiscale,
                'pendenze' => $rows,
                'filters' => [
                    'stato' => $statoRaw,
                ],
                'pagination' => [
                    'pagina' => (int)($data['pagina'] ?? $page),
                    'risultati_per_pagina' => (int)($data['risultatiPerPagina'] ?? $perPage),
                    'num_risultati' => (int)($data['numRisultati'] ?? 0),
                    'total_pages' => (int) max(1, (int) ceil(((int)($data['numRisultati'] ?? 0)) / max(1, (int)($data['risultatiPerPagina'] ?? $perPage)))),
                ],
            ],
        ];
    },

    '/accesso-negato' => static function (): array {
        http_response_code(403);
        $errorMessage = trim((string)($_GET['error'] ?? ''));
        return [
            'template' => 'errors/accesso-negato.html.twig',
            'context' => [
                'error_message' => $errorMessage !== '' ? $errorMessage : null,
            ],
        ];
    },
];


$routeDefinition = null;

if ($method === 'GET' && preg_match('#^/avvisi/([^/]+)/([^/]+)$#', $normalizedPath, $match)) {
    frontoffice_stream_avviso_pdf(rawurldecode($match[1]), rawurldecode($match[2]));
    return;
}

// ─────────────────────────────────────────────────────────────────────────────
// Route: GET /link/avviso — Download PDF avviso tramite link firmato (CF+IUV)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $normalizedPath === '/link/avviso') {
    $qp = $_GET;
    if (!frontoffice_verify_link($qp)) {
        http_response_code(403);
        echo 'Link non valido o scaduto.';
        Logger::getInstance()->warning('Link avviso non valido o scaduto', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'expires' => $qp['expires'] ?? '',
        ]);
        return;
    }
    $cf = trim((string)($qp['cf'] ?? ''));
    $iuv = trim((string)($qp['iuv'] ?? ''));
    if ($cf === '' || $iuv === '') {
        http_response_code(400);
        echo 'Parametri mancanti.';
        return;
    }
    $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '') {
        http_response_code(503);
        echo 'Configurazione mancante: ID_DOMINIO non impostato.';
        return;
    }
    // Recupera la pendenza per verificare ownership CF → IUV
    $pendenza = frontoffice_fetch_pendenza_by_avviso($idDominio, $iuv);
    if ($pendenza === null) {
        http_response_code(404);
        echo 'Avviso non trovato.';
        return;
    }
    if (!frontoffice_pendenza_belongs_to_cf($pendenza, $cf)) {
        http_response_code(403);
        echo 'Accesso non autorizzato.';
        Logger::getInstance()->warning('Mismatch CF nel link avviso', [
            'iuv' => substr($iuv, -4),
        ]);
        return;
    }
    $numeroAvviso = (string)(
        $pendenza['numeroAvviso']
        ?? $pendenza['numero_avviso']
        ?? $iuv
    );
    frontoffice_stream_avviso_pdf($idDominio, $numeroAvviso);
    return;
}

// ─────────────────────────────────────────────────────────────────────────────
// Route: GET /link/documento — Download PDF multi-rata tramite link firmato
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $normalizedPath === '/link/documento') {
    $qp = $_GET;
    if (!frontoffice_verify_link($qp)) {
        http_response_code(403);
        echo 'Link non valido o scaduto.';
        Logger::getInstance()->warning('Link documento non valido o scaduto', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'expires' => $qp['expires'] ?? '',
        ]);
        return;
    }
    $numeroDocumento = trim((string)($qp['doc'] ?? ''));
    if ($numeroDocumento === '') {
        http_response_code(400);
        echo 'Parametri mancanti.';
        return;
    }
    frontoffice_stream_documento_pdf($numeroDocumento);
    return;
}

// ─────────────────────────────────────────────────────────────────────────────
// Route: GET /link/ricevuta — Download ricevuta tramite link firmato (IUV+IUR)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $normalizedPath === '/link/ricevuta') {
    $qp = $_GET;
    if (!frontoffice_verify_link($qp)) {
        http_response_code(403);
        echo 'Link non valido o scaduto.';
        Logger::getInstance()->warning('Link ricevuta non valido o scaduto', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'expires' => $qp['expires'] ?? '',
        ]);
        return;
    }
    $iuv = trim((string)($qp['iuv'] ?? ''));
    $iur = trim((string)($qp['iur'] ?? ''));
    if ($iuv === '' || $iur === '') {
        http_response_code(400);
        echo 'Parametri mancanti.';
        return;
    }
    $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '') {
        http_response_code(503);
        echo 'Configurazione mancante: ID_DOMINIO non impostato.';
        return;
    }
    frontoffice_stream_ricevuta_pdf($idDominio, $iuv, $iur);
    return;
}

// ─────────────────────────────────────────────────────────────────────────────
// Route: GET /link/checkout — Checkout immediato tramite link firmato (CF+IUV)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $normalizedPath === '/link/checkout') {
    $qp = $_GET;
    if (!frontoffice_verify_link($qp)) {
        http_response_code(403);
        echo 'Link non valido o scaduto.';
        Logger::getInstance()->warning('Link checkout non valido o scaduto', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'expires' => $qp['expires'] ?? '',
        ]);
        return;
    }
    $cf = trim((string)($qp['cf'] ?? ''));
    $iuv = trim((string)($qp['iuv'] ?? ''));
    if ($cf === '' || $iuv === '') {
        http_response_code(400);
        echo 'Parametri mancanti.';
        return;
    }
    $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '') {
        http_response_code(503);
        echo 'Configurazione mancante: ID_DOMINIO non impostato.';
        return;
    }
    // Recupera la pendenza e verifica ownership CF → IUV
    $pendenza = frontoffice_fetch_pendenza_by_avviso($idDominio, $iuv);
    if ($pendenza === null) {
        http_response_code(404);
        echo 'Avviso non trovato.';
        return;
    }
    if (!frontoffice_pendenza_belongs_to_cf($pendenza, $cf)) {
        http_response_code(403);
        echo 'Accesso non autorizzato.';
        Logger::getInstance()->warning('Mismatch CF nel link checkout', [
            'iuv' => substr($iuv, -4),
        ]);
        return;
    }
    $stato = strtoupper(trim((string)($pendenza['stato'] ?? $pendenza['statoPendenza'] ?? '')));
    if (!in_array($stato, ['NON_ESEGUITA', 'ESEGUITA_PARZIALE'], true)) {
        http_response_code(400);
        echo 'Pendenza non pagabile (stato: ' . htmlspecialchars($stato, ENT_QUOTES, 'UTF-8') . ').';
        return;
    }
    $idPendenza = (string)($pendenza['idPendenza'] ?? '');
    if ($idPendenza === '') {
        http_response_code(503);
        echo 'Impossibile determinare l\'identificativo della pendenza.';
        return;
    }
    // Aggiungi alla whitelist di sessione usata dalla route /pagamento-avviso/checkout.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    foreach (['frontoffice_avviso_pendenze', 'frontoffice_pendenze_whitelist', 'authorized_checkout_pendenze'] as $key) {
        $list = (isset($_SESSION[$key]) && is_array($_SESSION[$key])) ? $_SESSION[$key] : [];
        $list[] = $idPendenza;
        $list = array_values(array_unique(array_filter(array_map('strval', $list), static fn($v) => trim($v) !== '')));
        if (count($list) > 50) {
            $list = array_slice($list, -50);
        }
        $_SESSION[$key] = $list;
    }
    // Redirect al checkout pagoPA
    $checkoutUrl = '/pagamento-avviso/checkout?idPendenza=' . rawurlencode($idPendenza);
    header('Location: ' . $checkoutUrl, true, 302);
    return;
}

if ($method === 'GET' && $normalizedPath === '/pagamento-spontaneo/checkout') {
    $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '') {
        http_response_code(503);
        echo 'Configurazione mancante: ID_DOMINIO non impostato.';
        return;
    }

    $idPendenza = trim((string)($_GET['idPendenza'] ?? ''));
    if ($idPendenza === '') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    if ($frontofficeBaseUrl === '') {
        http_response_code(503);
        echo 'Configurazione mancante: FRONTOFFICE_PUBLIC_BASE_URL non impostato.';
        return;
    }

    $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
    if (!$detail) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    // Autorizzazione:
    // - Se utente loggato: consenti solo se la pendenza appartiene al suo CF.
    // - Se non loggato: consenti solo se la pendenza è stata generata in questa sessione (whitelist).
    $loggedUser = frontoffice_get_logged_user();
    if (is_array($loggedUser) && $loggedUser !== []) {
        if (!frontoffice_pendenza_belongs_to_cf($detail, frontoffice_get_logged_user_fiscal_number())) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
    } else {
        $key = 'frontoffice_spontaneo_pendenze';
        $list = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
        $allowed = in_array($idPendenza, array_map('strval', $list), true);
        if (!$allowed) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
    }

    $state = strtoupper((string)($detail['stato'] ?? ''));
    if (!frontoffice_is_pendenza_payable($state)) {
        http_response_code(404);
        echo 'Pagamento non disponibile per questa pendenza.';
        return;
    }

    $numeroAvviso = trim((string)($detail['numeroAvviso'] ?? ''));
    $numeroAvviso = preg_replace('/\D+/', '', $numeroAvviso);
    if (!is_string($numeroAvviso) || $numeroAvviso === '') {
        http_response_code(503);
        echo 'Numero avviso non disponibile.';
        return;
    }

    $importo = $detail['importo'] ?? null;
    $amountEur = is_numeric($importo) ? (float)$importo : 0.0;
    $amountCents = frontoffice_amount_to_cents($amountEur);
    if ($amountCents <= 0) {
        http_response_code(503);
        echo 'Importo non valido.';
        return;
    }

    $hasCheckoutClient = class_exists(\PagoPA\CheckoutEc\Api\DefaultApi::class);
    $subscriptionKeyConfigured = trim(frontoffice_env_value('PAGOPA_CHECKOUT_SUBSCRIPTION_KEY', '')) !== '';
    if (!$hasCheckoutClient || !$subscriptionKeyConfigured) {
        http_response_code(503);
        echo 'Checkout pagoPA non configurato.';
        Logger::getInstance()->warning('Checkout pagoPA non configurato (spontaneo)', [
            'has_client' => $hasCheckoutClient,
            'has_key' => $subscriptionKeyConfigured,
        ]);
        return;
    }

    $api = frontoffice_pagopa_checkout_api_client();
    if ($api === null) {
        http_response_code(503);
        echo 'Checkout pagoPA non configurato.';
        Logger::getInstance()->warning('Checkout pagoPA non configurato: helper client null (spontaneo)');
        return;
    }

    $companyName = trim(frontoffice_env_value('PAGOPA_CHECKOUT_COMPANY_NAME', frontoffice_env_value('APP_ENTITY_NAME', 'Ente')));
    if ($companyName === '') {
        $companyName = 'Ente';
    }
    $description = trim((string)($detail['causale'] ?? ''));

    $okUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_OK_URL', ''));
    $cancelUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_CANCEL_URL', ''));
    $errorUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_ERROR_URL', ''));
    if ($okUrl === '') {
        $okUrl = $frontofficeBaseUrl . '/checkout/ok?idPendenza=' . rawurlencode($idPendenza);
    }
    if ($cancelUrl === '') {
        $cancelUrl = $frontofficeBaseUrl . '/checkout/cancel?idPendenza=' . rawurlencode($idPendenza);
    }
    if ($errorUrl === '') {
        $errorUrl = $frontofficeBaseUrl . '/checkout/error?idPendenza=' . rawurlencode($idPendenza);
    }

    // Email notice: preferisci quella del soggetto pagatore; fallback a profilo loggato.
    $emailNotice = '';
    if (isset($detail['soggettoPagatore']) && is_array($detail['soggettoPagatore'])) {
        $emailNotice = trim((string)($detail['soggettoPagatore']['email'] ?? ''));
    }
    if ($emailNotice === '' && is_array($loggedUser)) {
        $emailNotice = trim((string)($loggedUser['email'] ?? ''));
    }

    try {
        $notice = new \PagoPA\CheckoutEc\Model\PaymentNotice();
        $notice->setNoticeNumber($numeroAvviso);
        $notice->setFiscalCode($idDominio);
        $notice->setAmount($amountCents);
        $notice->setCompanyName($companyName);
        if ($description !== '') {
            $notice->setDescription($description);
        }

        $returnUrls = new \PagoPA\CheckoutEc\Model\CartRequestReturnUrls();
        $returnUrls->setReturnOkUrl($okUrl);
        $returnUrls->setReturnCancelUrl($cancelUrl);
        $returnUrls->setReturnErrorUrl($errorUrl);

        $cart = new \PagoPA\CheckoutEc\Model\CartRequest();
        if ($emailNotice !== '' && filter_var($emailNotice, FILTER_VALIDATE_EMAIL) !== false) {
            $cart->setEmailNotice($emailNotice);
        }
        $cart->setPaymentNotices([$notice]);
        $cart->setReturnUrls($returnUrls);

        [, $statusCode, $headers] = $api->postCartsWithHttpInfo($cart);

        $location = '';
        if (is_array($headers)) {
            foreach ($headers as $name => $values) {
                if (strtolower((string)$name) !== 'location') {
                    continue;
                }
                if (is_array($values) && isset($values[0]) && is_string($values[0])) {
                    $location = trim($values[0]);
                }
                break;
            }
        }

        if ($location === '' || $statusCode < 300 || $statusCode >= 400) {
            Logger::getInstance()->warning('Checkout pagoPA: risposta inattesa da POST /carts (spontaneo)', [
                'idPendenza' => $idPendenza,
                'status' => $statusCode,
                'has_location' => $location !== '',
            ]);
            http_response_code(503);
            echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
            return;
        }

        header('Location: ' . $location, true, 302);
        exit;
    } catch (\Throwable $e) {
        Logger::getInstance()->warning('Errore durante la creazione del carrello pagoPA Checkout (spontaneo)', [
            'idPendenza' => $idPendenza,
            'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
        ]);
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }
}

if ($method === 'GET' && $normalizedPath === '/pagamento-avviso/checkout') {
    $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '') {
        http_response_code(503);
        echo 'Configurazione mancante: ID_DOMINIO non impostato.';
        return;
    }

    $idPendenza = trim((string)($_GET['idPendenza'] ?? $_GET['id_pendenza'] ?? ''));
    if ($idPendenza === '') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    if ($frontofficeBaseUrl === '') {
        http_response_code(503);
        echo 'Configurazione mancante: FRONTOFFICE_PUBLIC_BASE_URL non impostato.';
        return;
    }

    $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
    if (!$detail) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    // Autorizzazione:
    // - Se utente loggato: consenti solo se la pendenza appartiene al suo CF.
    // - Se non loggato: consenti solo se la pendenza è stata ricercata in questa sessione (whitelist).
    $loggedUser = frontoffice_get_logged_user();
    if (is_array($loggedUser) && $loggedUser !== []) {
        if (!frontoffice_pendenza_belongs_to_cf($detail, frontoffice_get_logged_user_fiscal_number())) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
    } else {
        $key = 'frontoffice_avviso_pendenze';
        $list = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
        $allowed = in_array($idPendenza, array_map('strval', $list), true);
        if (!$allowed) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
    }

    $state = strtoupper((string)($detail['stato'] ?? ''));
    if (!frontoffice_is_pendenza_payable($state)) {
        http_response_code(404);
        echo 'Pagamento non disponibile per questa pendenza.';
        return;
    }

    $numeroAvviso = trim((string)($detail['numeroAvviso'] ?? ''));
    $numeroAvviso = preg_replace('/\D+/', '', $numeroAvviso);
    if (!is_string($numeroAvviso) || $numeroAvviso === '') {
        http_response_code(503);
        echo 'Numero avviso non disponibile.';
        return;
    }

    $importo = $detail['importo'] ?? null;
    $amountEur = is_numeric($importo) ? (float)$importo : 0.0;
    $amountCents = frontoffice_amount_to_cents($amountEur);
    if ($amountCents <= 0) {
        http_response_code(503);
        echo 'Importo non valido.';
        return;
    }

    $hasCheckoutClient = class_exists(\PagoPA\CheckoutEc\Api\DefaultApi::class);
    $subscriptionKeyConfigured = trim(frontoffice_env_value('PAGOPA_CHECKOUT_SUBSCRIPTION_KEY', '')) !== '';
    if (!$hasCheckoutClient || !$subscriptionKeyConfigured) {
        http_response_code(503);
        echo 'Checkout pagoPA non configurato.';
        Logger::getInstance()->warning('Checkout pagoPA non configurato (avviso)', [
            'has_client' => $hasCheckoutClient,
            'has_key' => $subscriptionKeyConfigured,
        ]);
        return;
    }

    $api = frontoffice_pagopa_checkout_api_client();
    if ($api === null) {
        http_response_code(503);
        echo 'Checkout pagoPA non configurato.';
        Logger::getInstance()->warning('Checkout pagoPA non configurato: helper client null (avviso)');
        return;
    }

    $companyName = trim(frontoffice_env_value('PAGOPA_CHECKOUT_COMPANY_NAME', frontoffice_env_value('APP_ENTITY_NAME', 'Ente')));
    if ($companyName === '') {
        $companyName = 'Ente';
    }
    $description = trim((string)($detail['causale'] ?? ''));

    $okUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_OK_URL', ''));
    $cancelUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_CANCEL_URL', ''));
    $errorUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_ERROR_URL', ''));
    if ($okUrl === '') {
        $okUrl = $frontofficeBaseUrl . '/checkout/ok?idPendenza=' . rawurlencode($idPendenza);
    }
    if ($cancelUrl === '') {
        $cancelUrl = $frontofficeBaseUrl . '/checkout/cancel?idPendenza=' . rawurlencode($idPendenza);
    }
    if ($errorUrl === '') {
        $errorUrl = $frontofficeBaseUrl . '/checkout/error?idPendenza=' . rawurlencode($idPendenza);
    }

    // Email notice: preferisci quella del soggetto pagatore; fallback a profilo loggato.
    $emailNotice = '';
    if (isset($detail['soggettoPagatore']) && is_array($detail['soggettoPagatore'])) {
        $emailNotice = trim((string)($detail['soggettoPagatore']['email'] ?? ''));
    }
    if ($emailNotice === '' && is_array($loggedUser)) {
        $emailNotice = trim((string)($loggedUser['email'] ?? ''));
    }

    try {
        $notice = new \PagoPA\CheckoutEc\Model\PaymentNotice();
        $notice->setNoticeNumber($numeroAvviso);
        $notice->setFiscalCode($idDominio);
        $notice->setAmount($amountCents);
        $notice->setCompanyName($companyName);
        if ($description !== '') {
            $notice->setDescription($description);
        }

        $returnUrls = new \PagoPA\CheckoutEc\Model\CartRequestReturnUrls();
        $returnUrls->setReturnOkUrl($okUrl);
        $returnUrls->setReturnCancelUrl($cancelUrl);
        $returnUrls->setReturnErrorUrl($errorUrl);

        $cart = new \PagoPA\CheckoutEc\Model\CartRequest();
        if ($emailNotice !== '' && filter_var($emailNotice, FILTER_VALIDATE_EMAIL) !== false) {
            $cart->setEmailNotice($emailNotice);
        }
        $cart->setPaymentNotices([$notice]);
        $cart->setReturnUrls($returnUrls);

        [, $statusCode, $headers] = $api->postCartsWithHttpInfo($cart);

        $location = '';
        if (is_array($headers)) {
            foreach ($headers as $name => $values) {
                if (strtolower((string)$name) !== 'location') {
                    continue;
                }
                if (is_array($values) && isset($values[0]) && is_string($values[0])) {
                    $location = trim($values[0]);
                }
                break;
            }
        }

        if ($location === '' || $statusCode < 300 || $statusCode >= 400) {
            Logger::getInstance()->warning('Checkout pagoPA: risposta inattesa da POST /carts (avviso)', [
                'idPendenza' => $idPendenza,
                'status' => $statusCode,
                'has_location' => $location !== '',
            ]);
            http_response_code(503);
            echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
            return;
        }

        header('Location: ' . $location, true, 302);
        exit;
    } catch (\Throwable $e) {
        Logger::getInstance()->warning('Errore durante la creazione del carrello pagoPA Checkout (avviso)', [
            'idPendenza' => $idPendenza,
            'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
        ]);
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }
}

if ($method === 'GET' && preg_match('#^/pendenze/([^/]+)/checkout$#', $normalizedPath, $match)) {
    if (!frontoffice_spid_enabled()) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $user = frontoffice_get_logged_user();
    if ($user === null) {
        header('Location: /login?return_to=' . rawurlencode($requestPath), true, 302);
        exit;
    }

    $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '') {
        http_response_code(503);
        echo 'Configurazione mancante: ID_DOMINIO non impostato.';
        return;
    }

    $idPendenza = trim(rawurldecode($match[1]));
    if ($idPendenza === '') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    if ($frontofficeBaseUrl === '') {
        http_response_code(503);
        echo 'Configurazione mancante: FRONTOFFICE_PUBLIC_BASE_URL non impostato.';
        return;
    }

    $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
    if (!$detail) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    if (!frontoffice_pendenza_belongs_to_cf($detail, frontoffice_get_logged_user_fiscal_number())) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $state = strtoupper((string)($detail['stato'] ?? ''));
    if (!frontoffice_is_pendenza_payable($state)) {
        http_response_code(404);
        echo 'Pagamento non disponibile per questa pendenza.';
        return;
    }

    $numeroAvviso = trim((string)($detail['numeroAvviso'] ?? ''));
    $numeroAvviso = preg_replace('/\D+/', '', $numeroAvviso);
    if (!is_string($numeroAvviso) || $numeroAvviso === '') {
        http_response_code(503);
        echo 'Numero avviso non disponibile.';
        return;
    }

    $importo = $detail['importo'] ?? null;
    $amountEur = is_numeric($importo) ? (float)$importo : 0.0;
    $amountCents = frontoffice_amount_to_cents($amountEur);
    if ($amountCents <= 0) {
        http_response_code(503);
        echo 'Importo non valido.';
        return;
    }

    $hasCheckoutClient = class_exists(\PagoPA\CheckoutEc\Api\DefaultApi::class);
    $subscriptionKeyConfigured = trim(frontoffice_env_value('PAGOPA_CHECKOUT_SUBSCRIPTION_KEY', '')) !== '';

    if (!$hasCheckoutClient || !$subscriptionKeyConfigured) {
        http_response_code(503);
        if (!$hasCheckoutClient) {
            echo 'Checkout pagoPA non configurato (client non installato).';
            Logger::getInstance()->warning('Checkout pagoPA non configurato: client non installato');
        } else {
            echo 'Checkout pagoPA non configurato (subscription key mancante).';
            Logger::getInstance()->warning('Checkout pagoPA non configurato: subscription key mancante');
        }
        return;
    }

    $api = frontoffice_pagopa_checkout_api_client();
    if ($api === null) {
        http_response_code(503);
        echo 'Checkout pagoPA non configurato.';
        Logger::getInstance()->warning('Checkout pagoPA non configurato: helper client null');
        return;
    }

    $companyName = trim(frontoffice_env_value('PAGOPA_CHECKOUT_COMPANY_NAME', frontoffice_env_value('APP_ENTITY_NAME', 'Ente')));
    if ($companyName === '') {
        $companyName = 'Ente';
    }
    $description = trim((string)($detail['causale'] ?? ''));

    $okUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_OK_URL', ''));
    $cancelUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_CANCEL_URL', ''));
    $errorUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_ERROR_URL', ''));
    if ($okUrl === '') {
        $okUrl = $frontofficeBaseUrl . '/checkout/ok?idPendenza=' . rawurlencode($idPendenza);
    }
    if ($cancelUrl === '') {
        $cancelUrl = $frontofficeBaseUrl . '/checkout/cancel?idPendenza=' . rawurlencode($idPendenza);
    }
    if ($errorUrl === '') {
        $errorUrl = $frontofficeBaseUrl . '/checkout/error?idPendenza=' . rawurlencode($idPendenza);
    }

    try {
        $notice = new \PagoPA\CheckoutEc\Model\PaymentNotice();
        $notice->setNoticeNumber($numeroAvviso);
        $notice->setFiscalCode($idDominio);
        $notice->setAmount($amountCents);
        $notice->setCompanyName($companyName);
        if ($description !== '') {
            $notice->setDescription($description);
        }

        $returnUrls = new \PagoPA\CheckoutEc\Model\CartRequestReturnUrls();
        $returnUrls->setReturnOkUrl($okUrl);
        $returnUrls->setReturnCancelUrl($cancelUrl);
        $returnUrls->setReturnErrorUrl($errorUrl);

        $cart = new \PagoPA\CheckoutEc\Model\CartRequest();

        $emailNotice = trim((string)($user['email'] ?? ''));
        if ($emailNotice !== '' && filter_var($emailNotice, FILTER_VALIDATE_EMAIL) !== false) {
            $cart->setEmailNotice($emailNotice);
        }

        $cart->setPaymentNotices([$notice]);
        $cart->setReturnUrls($returnUrls);

        [, $statusCode, $headers] = $api->postCartsWithHttpInfo($cart);

        $location = '';
        if (is_array($headers)) {
            foreach ($headers as $name => $values) {
                if (strtolower((string)$name) !== 'location') {
                    continue;
                }
                if (is_array($values) && isset($values[0]) && is_string($values[0])) {
                    $location = trim($values[0]);
                }
                break;
            }
        }

        if ($location === '' || $statusCode < 300 || $statusCode >= 400) {
            Logger::getInstance()->warning('Checkout pagoPA: risposta inattesa da POST /carts', [
                'idPendenza' => $idPendenza,
                'status' => $statusCode,
                'has_location' => $location !== '',
            ]);
            http_response_code(503);
            echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
            return;
        }

        header('Location: ' . $location, true, 302);
        exit;
    } catch (\Throwable $e) {
        Logger::getInstance()->warning('Errore durante la creazione del carrello pagoPA Checkout', [
            'idPendenza' => $idPendenza,
            'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
        ]);
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }
}

// ─── POST /carrello/checkout ─────────────────────────────────────────────────
// Multi-pendenza cart checkout via PagoPA Checkout EC API (POST /carts).
// Disponibile anche per utenti non loggati (guest); l'autorizzazione si basa
// sulla whitelist di sessione. Se l'utente è loggato si verifica anche il CF.
if ($method === 'POST' && $normalizedPath === '/carrello/checkout') {
    $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '') {
        http_response_code(503);
        echo 'Configurazione mancante: ID_DOMINIO non impostato.';
        return;
    }

    $loggedUser = frontoffice_get_logged_user();
    $loggedCf   = $loggedUser !== null ? frontoffice_get_logged_user_fiscal_number() : '';

    // Parse selected idPendenza[] from POST body
    $rawIds = $_POST['pendenze'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }
    $rawIds = array_values(array_unique(array_filter(array_map('strval', $rawIds), static fn ($v) => trim($v) !== '')));

    if (count($rawIds) === 0) {
        http_response_code(400);
        echo 'Seleziona almeno una pendenza da pagare.';
        return;
    }
    if (count($rawIds) > 5) {
        http_response_code(400);
        echo 'Puoi pagare al massimo 5 pendenze contemporaneamente.';
        return;
    }

    // Whitelist di sessione: aggrega tutte le sorgenti note (pendenze login, avviso guest, spontaneo guest).
    $whitelistKeys = ['frontoffice_pendenze_whitelist', 'frontoffice_avviso_pendenze', 'frontoffice_spontaneo_pendenze'];
    $whitelist = [];
    foreach ($whitelistKeys as $k) {
        if (isset($_SESSION[$k]) && is_array($_SESSION[$k])) {
            $whitelist = array_merge($whitelist, $_SESSION[$k]);
        }
    }
    $whitelist = array_values(array_unique(array_map('strval', $whitelist)));

    foreach ($rawIds as $pid) {
        if (!in_array($pid, $whitelist, true)) {
            Logger::getInstance()->warning('Cart checkout: idPendenza non in whitelist', ['idPendenza' => $pid]);
            http_response_code(403);
            echo 'Le pendenze selezionate non sono autorizzate per il pagamento. Torna alla lista e riprova.';
            return;
        }
    }

    // Resolve each pendenza detail
    $pendenzaDetails = [];
    foreach ($rawIds as $pid) {
        $detail = frontoffice_fetch_pagamenti_detail($pid);
        if (!$detail) {
            http_response_code(404);
            echo 'Una delle pendenze selezionate non è stata trovata. Aggiorna la pagina e riprova.';
            return;
        }

        // Se l'utente è loggato verifica che la pendenza appartenga al suo CF.
        if ($loggedCf !== '' && !frontoffice_pendenza_belongs_to_cf($detail, $loggedCf)) {
            http_response_code(403);
            echo 'Una delle pendenze selezionate non appartiene al tuo account.';
            return;
        }

        // Must be payable
        $state = strtoupper((string)($detail['stato'] ?? ''));
        if (!frontoffice_is_pendenza_payable($state)) {
            http_response_code(422);
            echo 'Una delle pendenze selezionate non è più pagabile. Aggiorna la pagina e riprova.';
            return;
        }

        // Must have a valid numero avviso and positive amount
        $numeroAvviso = preg_replace('/\D+/', '', trim((string)($detail['numeroAvviso'] ?? '')));
        $importo = $detail['importo'] ?? null;
        $amountCents = frontoffice_amount_to_cents(is_numeric($importo) ? (float)$importo : 0.0);
        if ($numeroAvviso === '' || $amountCents <= 0) {
            http_response_code(422);
            echo 'Una delle pendenze non ha un numero avviso o importo valido.';
            return;
        }

        $pendenzaDetails[] = $detail;
    }

    // Check checkout client availability
    if (!class_exists(\PagoPA\CheckoutEc\Api\DefaultApi::class) ||
        trim(frontoffice_env_value('PAGOPA_CHECKOUT_SUBSCRIPTION_KEY', '')) === '') {
        http_response_code(503);
        echo 'Checkout pagoPA non configurato.';
        Logger::getInstance()->warning('Carrello: checkout pagoPA non configurato');
        return;
    }

    $api = frontoffice_pagopa_checkout_api_client();
    if ($api === null) {
        http_response_code(503);
        echo 'Checkout pagoPA non disponibile.';
        return;
    }

    // Generate a cart ID and store mapping in session for the OK page
    try {
        $idCart = bin2hex(random_bytes(8));
    } catch (\Throwable $e) {
        $idCart = md5(implode('-', $rawIds) . microtime(true));
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['frontoffice_carrello'][$idCart] = $rawIds;
    }

    // Build return URLs (pass idCart so /checkout/ok can show a multi-notice summary)
    $okUrl     = $frontofficeBaseUrl . '/checkout/ok?idCart=' . rawurlencode($idCart);
    $cancelUrl = $frontofficeBaseUrl . '/checkout/cancel?idCart=' . rawurlencode($idCart);
    $errorUrl  = $frontofficeBaseUrl . '/checkout/error?idCart=' . rawurlencode($idCart);

    // Get email from logged-in user for notice (empty string if guest)
    $emailNotice = $loggedUser !== null ? trim((string)($loggedUser['email'] ?? '')) : '';


    try {
        $cart = frontoffice_build_cart_request(
            $pendenzaDetails,
            $idDominio,
            $okUrl,
            $cancelUrl,
            $errorUrl,
            $emailNotice
        );

        [, $statusCode, $headers] = $api->postCartsWithHttpInfo($cart);

        $location = '';
        if (is_array($headers)) {
            foreach ($headers as $name => $values) {
                if (strtolower((string)$name) !== 'location') {
                    continue;
                }
                $location = is_array($values) && isset($values[0]) ? trim($values[0]) : '';
                break;
            }
        }

        if ($location === '' || $statusCode < 300 || $statusCode >= 400) {
            Logger::getInstance()->warning('Carrello: risposta inattesa da POST /carts', [
                'idCart'   => $idCart,
                'status'   => $statusCode,
                'location' => $location,
            ]);
            http_response_code(503);
            echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
            return;
        }

        Logger::getInstance()->info('Carrello pagoPA avviato', [
            'idCart'   => $idCart,
            'count'    => count($pendenzaDetails),
            'pendenze' => $rawIds,
        ]);

        header('Location: ' . $location, true, 302);
        exit;
    } catch (\Throwable $e) {
        Logger::getInstance()->warning('Errore durante la creazione del carrello pagoPA', [
            'idCart' => $idCart,
            'error'  => Logger::sanitizeErrorForDisplay($e->getMessage()),
        ]);
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }
}
// ─────────────────────────────────────────────────────────────────────────────


if ($method === 'GET' && preg_match('#^/pendenze/([^/]+)/ricevuta$#', $normalizedPath, $match)) {
    if (!frontoffice_spid_enabled()) {
        http_response_code(404);
        echo 'Not found';
        return;
    }


    $user = frontoffice_get_logged_user();
    if ($user === null) {
        header('Location: /login?return_to=' . rawurlencode($requestPath), true, 302);
        exit;
    }

    $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '') {
        http_response_code(503);
        echo 'Configurazione mancante: ID_DOMINIO non impostato.';
        return;
    }

    $idPendenza = trim(rawurldecode($match[1]));
    if ($idPendenza === '') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
    if (!$detail) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    if (!frontoffice_pendenza_belongs_to_cf($detail, frontoffice_get_logged_user_fiscal_number())) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $state = strtoupper((string)($detail['stato'] ?? ''));
    if (!frontoffice_is_pendenza_paid($state)) {
        http_response_code(404);
        echo 'Ricevuta non disponibile.';
        return;
    }

    $ids = frontoffice_extract_ricevuta_identifiers_from_pendenza_detail($detail);
    $iuv = trim((string)($ids['iuv'] ?? ''));
    $idRicevuta = trim((string)($ids['idRicevuta'] ?? ''));
    $ccp = trim((string)($ids['ccp'] ?? ''));

    // Fallback forte: se mancano IUV/CCP nel dettaglio, ricaviamoli da Pendenze v2 (RPP eseguite).
    $idA2AFromDetail = trim((string)($detail['idA2A'] ?? $detail['id_a2a'] ?? ''));
    if (($iuv === '' || $ccp === '')) {
        $rpp = frontoffice_find_paid_rpp_for_pendenza($idDominio, $idPendenza, ($idA2AFromDetail !== '' ? $idA2AFromDetail : null));
        if (is_array($rpp)) {
            if ($iuv === '') {
                $iuv = trim((string)($rpp['iuv'] ?? ''));
            }
            if ($ccp === '') {
                $ccp = trim((string)($rpp['ccp'] ?? ''));
            }
        }
    }

    if ($iuv === '') {
        http_response_code(404);
        echo 'Ricevuta non disponibile.';
        return;
    }

    // Allineato al backoffice: la RT si scarica da Pendenze v2 /rpp/{idDominio}/{iuv}/{ccp}/rt.
    if ($ccp !== '') {
        frontoffice_stream_rt_pdf($idDominio, $iuv, $ccp);
        return;
    }

    // Fallback: cerchiamo il CCP interrogando Pagamenti /ricevute/{idDominio}/{iuv}.
    $ricevute = frontoffice_fetch_ricevute_for_iuv($idDominio, $iuv);
    $risultati = is_array($ricevute['risultati'] ?? null) ? $ricevute['risultati'] : [];
    if ($risultati === []) {
        http_response_code(404);
        echo 'Ricevuta non disponibile.';
        return;
    }

    $first = $risultati[0] ?? null;
    $iuvFromApi = is_array($first) ? trim((string)($first['iuv'] ?? $iuv)) : $iuv;
    $ccpFromApi = '';
    if (is_array($first)) {
        foreach (['ccp', 'codiceContestoPagamento', 'codice_contesto_pagamento'] as $k) {
            if (isset($first[$k]) && is_scalar($first[$k])) {
                $ccpFromApi = trim((string)$first[$k]);
                if ($ccpFromApi !== '') {
                    break;
                }
            }
        }
    }
    if ($ccpFromApi === '' || $iuvFromApi === '') {
        // Ultimo fallback: se abbiamo idRicevuta (anche se non abbiamo CCP), proviamo lo scarico PDF legacy.
        if ($idRicevuta !== '') {
            frontoffice_stream_ricevuta_pdf($idDominio, $iuv, $idRicevuta);
            return;
        }
        http_response_code(404);
        echo 'Ricevuta non disponibile.';
        return;
    }

    frontoffice_stream_rt_pdf($idDominio, $iuvFromApi, $ccpFromApi);
    return;
}

if ($method === 'GET' && preg_match('#^/pendenze/([^/]+)$#', $normalizedPath, $match)) {
    if (!frontoffice_spid_enabled()) {
        http_response_code(404);
        $routeDefinition = [
            'template' => 'errors/404.html.twig',
            'context' => [
                'requested_path' => $requestPath,
            ],
        ];
    } else {
        $user = frontoffice_get_logged_user();
        if ($user === null) {
            header('Location: /login?return_to=%2Fpendenze', true, 302);
            exit;
        }

        $idPendenza = rawurldecode($match[1]);
        $idPendenza = trim($idPendenza);
        if ($idPendenza === '') {
            http_response_code(404);
            $routeDefinition = [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => $requestPath,
                ],
            ];
        } else {
            $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
            if (!$detail) {
                http_response_code(404);
                $routeDefinition = [
                    'template' => 'errors/404.html.twig',
                    'context' => [
                        'requested_path' => $requestPath,
                    ],
                ];
            } elseif (!frontoffice_pendenza_belongs_to_cf($detail, frontoffice_get_logged_user_fiscal_number())) {
                http_response_code(404);
                $routeDefinition = [
                    'template' => 'errors/404.html.twig',
                    'context' => [
                        'requested_path' => $requestPath,
                    ],
                ];
            } else {
                $idDominio = \App\Config\SettingsRepository::get('entity', 'id_dominio', '') ?: frontoffice_env_value('ID_DOMINIO', '');
                $routeDefinition = [
                    'template' => 'pendenze/detail.html.twig',
                    'context' => [
                        'profile' => $user,
                        'codice_fiscale' => frontoffice_get_logged_user_fiscal_number(),
                        'pendenza' => $detail,
                        'pendenza_preview' => frontoffice_build_avviso_preview($detail, $idDominio),
                        'back_url' => '/pendenze',
                    ],
                ];
            }
        }
    }
}

// ─── POST /carrello/aggiungi-multiplo ────────────────────────────────────────
// Aggiunge più pendenze al carrello (form submit da pendenze list) e reindirizza
// al carrello. Non chiama PagoPA checkout direttamente.
if ($method === 'POST' && $normalizedPath === '/carrello/aggiungi-multiplo') {
    $rawIds = $_POST['pendenze'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }
    $rawIds = array_values(array_unique(array_filter(array_map('strval', $rawIds), static fn ($v) => trim($v) !== '')));

    if (count($rawIds) === 0) {
        header('Location: /pendenze');
        return;
    }

    $whitelistKeys = ['frontoffice_pendenze_whitelist', 'frontoffice_avviso_pendenze', 'frontoffice_spontaneo_pendenze'];
    $whitelist = [];
    foreach ($whitelistKeys as $k) {
        if (isset($_SESSION[$k]) && is_array($_SESSION[$k])) {
            $whitelist = array_merge($whitelist, $_SESSION[$k]);
        }
    }
    $whitelist = array_values(array_unique(array_map('strval', $whitelist)));

    $loggedUser = frontoffice_get_logged_user();
    $loggedCf = $loggedUser !== null ? frontoffice_get_logged_user_fiscal_number() : '';

    foreach (array_slice($rawIds, 0, 5) as $pid) {
        if (!in_array($pid, $whitelist, true)) {
            continue;
        }
        $detail = frontoffice_fetch_pagamenti_detail($pid);
        if (!$detail) {
            continue;
        }
        if ($loggedCf !== '' && !frontoffice_pendenza_belongs_to_cf($detail, $loggedCf)) {
            continue;
        }
        $state = strtoupper((string)($detail['stato'] ?? ''));
        if (!frontoffice_is_pendenza_payable($state)) {
            continue;
        }
        frontoffice_cart_add($pid, $detail);
    }

    header('Location: /carrello');
    return;
}

// ─── POST /carrello/aggiungi (AJAX) ──────────────────────────────────────────
if ($method === 'POST' && $normalizedPath === '/carrello/aggiungi') {
    header('Content-Type: application/json; charset=utf-8');
    $idPendenza = trim((string)($_POST['idPendenza'] ?? ''));
    if ($idPendenza === '') {
        http_response_code(400);
        echo json_encode(['error' => 'idPendenza mancante.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Whitelist check: idPendenza must have been served to this session
    $whitelistKeys = ['frontoffice_pendenze_whitelist', 'frontoffice_avviso_pendenze', 'frontoffice_spontaneo_pendenze'];
    $whitelist = [];
    foreach ($whitelistKeys as $k) {
        if (isset($_SESSION[$k]) && is_array($_SESSION[$k])) {
            $whitelist = array_merge($whitelist, $_SESSION[$k]);
        }
    }
    if (!in_array($idPendenza, array_map('strval', $whitelist), true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Pendenza non autorizzata.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    // Resolve detail to populate cart metadata
    $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
    if (!$detail) {
        http_response_code(404);
        echo json_encode(['error' => 'Pendenza non trovata.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $state = strtoupper((string)($detail['stato'] ?? ''));
    if (!frontoffice_is_pendenza_payable($state)) {
        http_response_code(422);
        echo json_encode(['error' => 'La pendenza non è pagabile.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $err = frontoffice_cart_add($idPendenza, $detail);
    if ($err !== null) {
        http_response_code(422);
        echo json_encode(['error' => $err], JSON_UNESCAPED_UNICODE);
        return;
    }

    $items = frontoffice_cart_items();
    $totalCents = 0;
    foreach ($items as $item) {
        $totalCents += frontoffice_amount_to_cents((float)($item['importo'] ?? 0.0));
    }
    echo json_encode([
        'count'       => count($items),
        'total_cents' => $totalCents,
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// ─── POST /carrello/rimuovi (AJAX) ───────────────────────────────────────────
if ($method === 'POST' && $normalizedPath === '/carrello/rimuovi') {
    header('Content-Type: application/json; charset=utf-8');
    $idPendenza = trim((string)($_POST['idPendenza'] ?? ''));
    if ($idPendenza !== '') {
        frontoffice_cart_remove($idPendenza);
    }
    $items = frontoffice_cart_items();
    $totalCents = 0;
    foreach ($items as $item) {
        $totalCents += frontoffice_amount_to_cents((float)($item['importo'] ?? 0.0));
    }
    echo json_encode([
        'count'       => count($items),
        'total_cents' => $totalCents,
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// ─── GET /carrello ────────────────────────────────────────────────────────────
if ($method === 'GET' && $normalizedPath === '/carrello') {
    $items = frontoffice_cart_items();
    // Build display rows enriching with up-to-date amounts
    $rows = [];
    $totalCents = 0;
    foreach ($items as $pid => $item) {
        $cents = frontoffice_amount_to_cents((float)($item['importo'] ?? 0.0));
        $totalCents += $cents;
        $rows[] = [
            'id_pendenza'   => $pid,
            'causale'       => $item['causale'] ?? '',
            'importo'       => $item['importo'],
            'importo_cents' => $cents,
            'numero_avviso' => $item['numeroAvviso'] ?? '',
            'data_scadenza' => $item['data_scadenza'] ?? null,
        ];
    }
    $routeDefinition = [
        'template' => 'carrello/index.html.twig',
        'context'  => [
            'cart_items'   => $rows,
            'total_cents'  => $totalCents,
            'total_euro'   => $totalCents / 100,
            'can_checkout' => count($rows) > 0 && class_exists(\PagoPA\CheckoutEc\Api\DefaultApi::class) &&
                              trim(frontoffice_env_value('PAGOPA_CHECKOUT_SUBSCRIPTION_KEY', '')) !== '',
        ],
    ];
}
// ─────────────────────────────────────────────────────────────────────────────

if ($routeDefinition === null) {
    $routeDefinition = $routes[$normalizedPath] ?? null;
}

if ($routeDefinition === null) {
    http_response_code(404);
    $route = [
        'template' => 'errors/404.html.twig',
        'context' => [
            'requested_path' => $requestPath,
        ],
    ];
} else {
    $route = is_callable($routeDefinition) ? $routeDefinition() : $routeDefinition;
}

$templateBase = dirname(__DIR__);
$templateCandidates = [
    $templateBase . '/frontoffice/templates',
    $templateBase . '/templates',
    dirname($templateBase) . '/templates',
    __DIR__ . '/../templates',
];
$templatePaths = [];
foreach ($templateCandidates as $candidate) {
    if ($candidate && is_dir($candidate) && !in_array($candidate, $templatePaths, true)) {
        $templatePaths[] = $candidate;
    }
}
if ($templatePaths === []) {
    $templatePaths[] = __DIR__ . '/../templates';
}

$loader = new FilesystemLoader($templatePaths);
$twig = new Environment($loader, [
    'cache' => false,
    'autoescape' => 'html',
]);

// ─── i18n (Multilingual Support) ──────────────────────────────────────────────
$supportedLocales = ['it', 'en', 'es', 'fr', 'de'];
$currentLocale = 'it';

if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLocales, true)) {
    $_SESSION['locale'] = $_GET['lang'];
}

if (isset($_SESSION['locale']) && in_array($_SESSION['locale'], $supportedLocales, true)) {
    $currentLocale = $_SESSION['locale'];
} else {
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $browserLangs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach ($browserLangs as $lang) {
            $langCode = strtolower(substr(trim($lang), 0, 2));
            if (in_array($langCode, $supportedLocales, true)) {
                $currentLocale = $langCode;
                $_SESSION['locale'] = $currentLocale;
                break;
            }
        }
    }
}

$localesDir = dirname(__DIR__) . '/locales';
if (!is_dir($localesDir)) {
    $localesDir = dirname(__DIR__) . '/frontoffice/locales';
}
if (!is_dir($localesDir)) {
    $localesDir = __DIR__ . '/../locales';
}

$translations = [];
$localeFile = $localesDir . '/' . $currentLocale . '.json';
if (file_exists($localeFile)) {
    $jsonContent = file_get_contents($localeFile);
    if ($jsonContent !== false) {
        $parsed = json_decode($jsonContent, true);
        if (is_array($parsed)) {
            $translations = $parsed;
        }
    }
}

// Custom Twig Extension for translation
class I18nExtension extends \Twig\Extension\AbstractExtension
{
    private array $translations;
    private string $currentLocale;

    public function __construct(array $translations, string $currentLocale)
    {
        $this->translations = $translations;
        $this->currentLocale = $currentLocale;
    }

    public function getFilters(): array
    {
        return [
            new \Twig\TwigFilter('trans', [$this, 'trans']),
        ];
    }

    public function trans(string $key): string
    {
        return $this->translations[$key] ?? $key;
    }
}

$twig->addExtension(new I18nExtension($translations, $currentLocale));
// ─────────────────────────────────────────────────────────────────────────────


$baseContext = [
    'app_entity' => [
        'name' => $entityName,
        'suffix' => $entitySuffix,
        'government' => $entityGovernment,
        'full' => $entityFull,
    ],
    'app_logo' => $appLogo,
    'app_favicon' => $appFavicon,
    'current_user' => frontoffice_get_logged_user(),
    'spid_enabled' => frontoffice_spid_enabled(),
    'spid_mode' => frontoffice_spid_mode(),
    'support_email' => $supportEmail,
    'support_phone' => $env('APP_SUPPORT_PHONE', '800.000.000'),
    'support_hours' => $env('APP_SUPPORT_HOURS', 'Lun-Ven 9:00-18:00'),
    'support_location' => $env('APP_SUPPORT_LOCATION', 'Via Roma 1, 00100 Montesilvano (PE)'),
    'cart_count' => frontoffice_cart_count(),
    'cart_items_ids' => array_keys(frontoffice_cart_items()),
    'current_locale' => $currentLocale,
    'app_version' => (function () {
        $candidates = [
            dirname(__DIR__, 2) . '/VERSION',
            dirname(__DIR__, 3) . '/VERSION',
            '/var/www/html/VERSION',
        ];
        foreach ($candidates as $f) {
            if (file_exists($f)) {
                return trim((string) file_get_contents($f));
            }
        }
        return 'dev';
    })(),
];

$context = array_merge(
    $baseContext,
    ['current_path' => $normalizedPath],
    $route['context'] ?? []
);

// Se il route ha raw_output, stampalo direttamente senza template
if (!empty($route['raw_output'])) {
    echo $route['raw_output'];
} else {
    echo $twig->render($route['template'], $context);
}
