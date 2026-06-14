<?php
declare(strict_types=1);

use App\Logger;
use App\Services\ValidationService;
use GuzzleHttp\Client;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\Metadata;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__) . '/vendor/autoload.php';

// ─── Bootstrap config dal backoffice sidecar ─────────────────────────────────
// Carica all'avvio le variabili di configurazione dall'endpoint /api/frontoffice/config
// e le inietta in $_ENV. frontoffice_env_value() le troverà senza toccare il DB.
// Il frontoffice è dipendente dall'avvio del backoffice (depends_on: service_healthy).
(static function (): void {
    $backofficeUrl = rtrim((string)($_ENV['BACKOFFICE_INTERNAL_URL'] ?? getenv('BACKOFFICE_INTERNAL_URL') ?: 'http://backoffice'), '/');
    $masterToken   = (string)($_ENV['MASTER_TOKEN'] ?? getenv('MASTER_TOKEN') ?: '');
    if ($masterToken === '') {
        return;
    }
    $cacheDir = '/var/cache/frontoffice';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0700, true);
    }
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        $cacheDir = sys_get_temp_dir();
    }
    $cacheFile = $cacheDir . '/frontoffice_config_cache.json';
    $cacheTtl  = 300; // 5 minuti

    // Usa cache su file per non chiamare il backoffice ad ogni request
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            foreach ($cached as $k => $v) {
                if (!isset($_ENV[$k]) && getenv($k) === false) {
                    $_ENV[$k] = $v;
                }
            }
            return;
        }
    }

    // Fetch dal backoffice
    $ch = curl_init($backofficeUrl . '/api/frontoffice/config');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $masterToken, 'Accept: application/json'],
    ]);
    $raw     = curl_exec($ch);
    $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    unset($ch);

    if ($raw !== false && $status === 200 && $curlErr === '') {
        $data = json_decode((string)$raw, true);
        if (is_array($data)) {
            if (file_put_contents($cacheFile, json_encode($data)) !== false) {
                chmod($cacheFile, 0600);
            }
            foreach ($data as $k => $v) {
                if (!isset($_ENV[$k]) && getenv($k) === false) {
                    $_ENV[$k] = $v;
                }
            }
        }
    }
})();
// ─────────────────────────────────────────────────────────────────────────────

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('govpay_frontoffice');

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (!$isHttps && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $isHttps = strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    $authProxyTypeFrontoffice = strtolower(trim((string)($_ENV['FRONTOFFICE_AUTH_PROXY_TYPE'] ?? getenv('FRONTOFFICE_AUTH_PROXY_TYPE') ?: 'none')));
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

// ─── i18n Language selection & CSRF initialization (run early while session is writeable) ───
(static function (): void {
    $supportedLocales = ['it', 'en', 'es', 'fr', 'de'];
    if (isset($_GET['lang']) && in_array($_GET['lang'], $supportedLocales, true)) {
        $_SESSION['locale'] = $_GET['lang'];
    }
    if (!isset($_SESSION['locale']) || !in_array($_SESSION['locale'], $supportedLocales, true)) {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browserLangs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
            foreach ($browserLangs as $lang) {
                $langCode = strtolower(substr(trim($lang), 0, 2));
                if (in_array($langCode, $supportedLocales, true)) {
                    $_SESSION['locale'] = $langCode;
                    break;
                }
            }
        }
    }

    // Inizializza token CSRF finché la sessione è scrivibile
    if (empty($_SESSION['frontoffice_csrf_token'])) {
        $_SESSION['frontoffice_csrf_token'] = bin2hex(random_bytes(32));
    }
})();

if (!function_exists('frontoffice_env_value')) {
    function frontoffice_env_value(string $key, ?string $default = null): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
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

if (!function_exists('frontoffice_csrf_token')) {
    function frontoffice_csrf_token(): string
    {
        if (empty($_SESSION['frontoffice_csrf_token']) && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['frontoffice_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['frontoffice_csrf_token'] ?? '';
    }
}

if (!function_exists('frontoffice_csrf_validate')) {
    function frontoffice_csrf_validate(?string $token): bool
    {
        $stored = $_SESSION['frontoffice_csrf_token'] ?? null;
        if ($stored === null || $token === null || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }
}

if (!function_exists('frontoffice_backoffice_api')) {
    /**
     * Chiama un endpoint API del backoffice GIL (sidecar pattern).
     * Autenticazione: Bearer MASTER_TOKEN.
     * Propaga X-Real-IP dell'utente finale per rate limiting lato backoffice.
     *
     * @param string $method  GET | POST | PUT | DELETE
     * @param string $path    Es. '/api/frontoffice/tipologie'
     * @param array  $data    Dati query (GET) o body JSON (POST/PUT)
     * @return array{success:bool,data:mixed,message:string,error_status:int}
     */
    function frontoffice_backoffice_api(string $method, string $path, array $data = []): array
    {
        $baseUrl     = rtrim(frontoffice_env_value('BACKOFFICE_INTERNAL_URL', 'http://backoffice'), '/');
        $masterToken = frontoffice_env_value('MASTER_TOKEN', '');

        if ($masterToken === '') {
            Logger::getInstance()->warning('frontoffice_backoffice_api: MASTER_TOKEN non configurato');
            return ['success' => false, 'data' => null, 'message' => 'MASTER_TOKEN non configurato', 'error_status' => 503];
        }

        $method = strtoupper($method);
        $url    = $baseUrl . $path;

        $headers = [
            'Authorization' => 'Bearer ' . $masterToken,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        // Propaga IP reale per rate limiting server-side
        $clientIp = frontoffice_client_ip();
        if ($clientIp !== '') {
            $headers['X-Real-IP'] = $clientIp;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        curl_setopt($ch, CURLOPT_URL, $url);

        $rawBody  = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);

        if ($rawBody === false || $curlErr !== '') {
            Logger::getInstance()->warning('frontoffice_backoffice_api: errore cURL', [
                'url'   => $url,
                'error' => $curlErr,
            ]);
            return ['success' => false, 'data' => null, 'message' => 'Errore di rete verso il backoffice', 'error_status' => 503];
        }

        $decoded = json_decode((string)$rawBody, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'data' => null, 'message' => 'Risposta non valida dal backoffice', 'error_status' => 503];
        }

        $errorStatus = (int)($decoded['error_status'] ?? ($httpCode >= 400 ? $httpCode : 0));
        return [
            'success'      => (bool)($decoded['success'] ?? false),
            'data'         => $decoded['data'] ?? ($decoded['pendenza'] ?? ($decoded['tipologie'] ?? ($decoded['templates'] ?? null))),
            'message'      => (string)($decoded['message'] ?? ''),
            'error_status' => $errorStatus,
            '_raw'         => $decoded,
        ];
    }
}

if (!function_exists('frontoffice_backoffice_api_stream')) {
    /**
     * Chiama un endpoint binario del backoffice (es. ricevuta PDF) e streamma la risposta.
     * Setta header HTTP direttamente e fa echo del body. Non ritorna array.
     */
    function frontoffice_backoffice_api_stream(string $path): void
    {
        $baseUrl     = rtrim(frontoffice_env_value('BACKOFFICE_INTERNAL_URL', 'http://backoffice'), '/');
        $masterToken = frontoffice_env_value('MASTER_TOKEN', '');

        if ($masterToken === '') {
            http_response_code(503);
            echo 'MASTER_TOKEN non configurato';
            return;
        }

        $ch = curl_init($baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $masterToken,
            'Accept: application/pdf',
        ]);
        curl_setopt($ch, CURLOPT_HEADER, true);

        $rawResponse = curl_exec($ch);
        $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlErr     = curl_error($ch);
        unset($ch);

        if ($rawResponse === false || $curlErr !== '') {
            http_response_code(503);
            echo 'Errore di rete verso il backoffice';
            return;
        }

        $responseBody = substr((string)$rawResponse, $headerSize);

        if ($httpCode < 200 || $httpCode >= 300) {
            http_response_code($httpCode >= 400 ? $httpCode : 503);
            echo 'Ricevuta non disponibile';
            return;
        }

        $rawHeaders = substr((string)$rawResponse, 0, $headerSize);
        foreach (explode("\r\n", $rawHeaders) as $line) {
            $lower = strtolower($line);
            if (str_starts_with($lower, 'content-type:') || str_starts_with($lower, 'content-disposition:')) {
                header($line);
            }
        }
        header('X-Content-Type-Options: nosniff');
        echo $responseBody;
    }
}

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

if (!function_exists('frontoffice_slugify')) {
    function frontoffice_slugify(string $text): string
    {
        $s = mb_strtolower(trim($text));
        if (function_exists('iconv')) {
            $s = (string)(@iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s);
        }
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }
}

if (!function_exists('frontoffice_load_service_options')) {
    function frontoffice_load_service_options(): array
    {
        $result = frontoffice_backoffice_api('GET', '/api/frontoffice/tipologie');
        $raw    = $result['_raw'] ?? [];

        $internalOptions = [];
        $externalOptions = [];

        foreach ((array)($raw['tipologie'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
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
            $externalUrl      = trim((string)($row['external_url'] ?? '')) ?: null;
            $descrizioneEstesa = trim((string)($row['descrizione_estesa'] ?? ''));
            $internalOptions[] = [
                'id'                => $id,
                'label'             => $label,
                'slug'              => frontoffice_slugify($label) ?: strtolower($id),
                'type'              => $externalUrl ? 'external' : 'internal',
                'external_url'      => $externalUrl,
                'descrizione_estesa'=> $descrizioneEstesa !== '' ? $descrizioneEstesa : null,
            ];
        }

        foreach ((array)($raw['tipologie_esterne'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id  = (string)($row['id'] ?? '');
            $url = trim((string)($row['url'] ?? ''));
            if ($id === '' || $url === '') {
                continue;
            }
            $label            = trim((string)($row['descrizione'] ?? '')) ?: 'Servizio esterno ' . $id;
            $descrizioneEstesa = trim((string)($row['descrizione_estesa'] ?? ''));
            $externalOptions[] = [
                'id'                => 'EXT:' . $id,
                'label'             => $label,
                'type'              => 'external',
                'external_url'      => $url,
                'descrizione_estesa'=> $descrizioneEstesa !== '' ? $descrizioneEstesa : null,
            ];
        }

        $options = array_merge($internalOptions, $externalOptions);

        if ($options === []) {
            Logger::getInstance()->warning('Tipologie frontoffice assenti: uso fallback statico');
            $options = [
                ['id' => 'SERV_MENSA',              'label' => 'Mensa e servizi scolastici',          'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_NIDI',               'label' => "Nidi d'infanzia / rette asilo",        'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_OCCUPAZIONE_SUOLO',  'label' => 'Occupazione suolo pubblico',           'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_SANZIONI',            'label' => 'Sanzioni e contravvenzioni',           'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_DIRITTI_SEGRETERIA', 'label' => 'Diritti di segreteria e certificati',  'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
                ['id' => 'SERV_ALTRO',              'label' => 'Altro pagamento spontaneo',            'type' => 'internal', 'external_url' => null, 'descrizione_estesa' => null],
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
            return 'Auth Proxy';
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
        $frontofficeType = strtolower(trim((string)($_ENV['FRONTOFFICE_AUTH_PROXY_TYPE'] ?? getenv('FRONTOFFICE_AUTH_PROXY_TYPE') ?: 'none')));
        if (in_array($frontofficeType, ['spid', 'cie', 'spid_cie'], true)) {
            return 'auth-proxy';
        }
        if ($frontofficeType === 'external') {
            return 'external';
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
     * @return 'php-proxy'|'auth-proxy-saml2'|'external-oidc'
     */
    function frontoffice_auth_proxy_type(): string
    {
        $mode = frontoffice_spid_mode();
        if ($mode === 'auth-proxy') {
            return 'auth-proxy-saml2';
        }
        if ($mode === 'external') {
            return 'external-oidc';
        }
        return 'php-proxy';
    }
}

if (!function_exists('frontoffice_http_get_raw')) {
    function frontoffice_http_get_raw(string $url, bool $insecureSsl = false): ?string
    {
        $hostHeader = '';
        $urlHost = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        $sslOn = strtolower((string)frontoffice_env_value('SSL', 'off')) === 'on';
        $desiredScheme = $sslOn ? 'https' : 'http';
        if (in_array(strtolower($urlHost), ['auth-proxy-nginx', 'localhost', '127.0.0.1'], true)) {
            $proxyBase = rtrim(frontoffice_env_value('IAM_PROXY_PUBLIC_BASE_URL', ''), '/');
            $hostHeader = (string)(parse_url($proxyBase, PHP_URL_HOST) ?: '');

            if (str_starts_with($url, 'http://') && $desiredScheme === 'https') {
                $url = 'https://' . substr($url, 7);
            } elseif (str_starts_with($url, 'https://') && $desiredScheme === 'http') {
                $url = 'http://' . substr($url, 8);
            }
        }

        $attempt = ['url' => $url, 'host' => $hostHeader];

        if (function_exists('curl_init')) {
            $ch = curl_init($attempt['url']);
            if ($ch === false) {
                Logger::getInstance()->warning('CURL init failed', ['url' => $attempt['url']]);
                return null;
            }
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            if ($attempt['host'] !== '') {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $attempt['host']]);
            }
            if ($insecureSsl) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }

            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);

            Logger::getInstance()->info('HTTP GET request', [
                'url' => $attempt['url'],
                'status' => $status,
                'error' => $err,
                'insecureSSL' => $insecureSsl,
                'hostHeader' => $attempt['host'],
                'mode' => $desiredScheme,
            ]);

            if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
                return $body;
            }

            if ($err !== '') {
                Logger::getInstance()->warning('HTTP GET (raw) fallita', [
                    'url' => $attempt['url'],
                    'error' => $err,
                    'status' => $status,
                    'hostHeader' => $attempt['host'],
                    'mode' => $desiredScheme,
                ]);
            }
            return null;
        }

        $opts = [
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ];
        $attemptOpts = $opts;
        if ($attempt['host'] !== '') {
            $attemptOpts['http']['header'] = "Host: {$attempt['host']}\r\n";
        }
        if ($insecureSsl) {
            $attemptOpts['ssl'] = [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ];
        }
        $ctx = stream_context_create($attemptOpts);
        $body = @file_get_contents($attempt['url'], false, $ctx);
        if (is_string($body) && $body !== '') {
            return $body;
        }
        return null;
    }
}

if (!function_exists('frontoffice_satosa_idp_metadata')) {
    /**
     * Estrae dai metadata SATOSA IdP: entityID, SSO Redirect URL, certificato X509.
     */
    function frontoffice_satosa_idp_metadata(string $metadataUrl, bool $insecureSsl = false, bool $forceRefresh = false): ?array
    {
        $metadataUrl = trim($metadataUrl);
        if ($metadataUrl === '') {
            return null;
        }

        // Prevent SSRF: only allow http/https to known proxy hosts
        $scheme = strtolower((string)(parse_url($metadataUrl, PHP_URL_SCHEME) ?: ''));
        $host   = strtolower((string)(parse_url($metadataUrl, PHP_URL_HOST) ?: ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $allowedHosts = ['auth-proxy-nginx', 'localhost', '127.0.0.1', '::1'];
        foreach (['IAM_PROXY_PUBLIC_BASE_URL', 'SPID_PROXY_PUBLIC_BASE_URL'] as $envKey) {
            $base = frontoffice_env_value($envKey, '');
            if ($base !== '') {
                $h = strtolower((string)(parse_url($base, PHP_URL_HOST) ?: ''));
                if ($h !== '') {
                    $allowedHosts[] = $h;
                }
            }
        }
        if (!in_array($host, $allowedHosts, true)) {
            Logger::getInstance()->warning('IdP metadata URL host non consentito', ['host' => $host]);
            return null;
        }

        $cache = $_SESSION['satosa_idp_metadata_cache'] ?? null;
        if (!$forceRefresh
            && is_array($cache)
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
    function frontoffice_satosa_saml_auth(string $frontofficeBaseUrl, string $callbackPath, string $idpMetadataUrl, bool $debug = false, bool $forceMetadataRefresh = false): ?SamlAuth
    {
        if ($frontofficeBaseUrl === '') {
            return null;
        }
        $callbackPath = $callbackPath !== '' ? $callbackPath : '/spid/callback';
        if ($callbackPath[0] !== '/') {
            $callbackPath = '/' . ltrim($callbackPath, '/');
        }

        $insecureSsl = frontoffice_spid_proxy_insecure_ssl($idpMetadataUrl);
        $idp = frontoffice_satosa_idp_metadata($idpMetadataUrl, $insecureSsl, $forceMetadataRefresh);
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
                    $rawTipoBollo = (string)($details['tipo_bollo'] ?? '');
                    $tipoBollo = in_array($rawTipoBollo, ['01'], true) ? $rawTipoBollo : '';
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
            $voice['codEntrata'] = substr(preg_replace('/[^A-Za-z0-9\-_.]/', '', $idTipo), 0, 35);
        }

        return [$voice];
    }
}

if (!function_exists('frontoffice_build_bollo_voci')) {
    /**
     * Costruisce le voci per una pendenza Marca da Bollo Telematica.
     * Ogni voce corrisponde a un documento: hashDocumento è SHA-256 raw→base64
     * del contenuto del file (se allegato) oppure di titolo+CF+entropy (se solo testo).
     *
     * @param array  $documenti  Array di ['titolo'=>string, 'file_content'=>string|null]
     * @param string $cf         Codice fiscale/PIVA uppercase senza spazi
     * @param string $provincia  Sigla provincia 2 lettere uppercase
     * @param string $tipoBollo  Valore tipoBollo GovPay (es. "01")
     */
    function frontoffice_build_bollo_voci(array $documenti, string $cf, string $provincia, string $tipoBollo): array
    {
        $voci = [];
        foreach ($documenti as $i => $doc) {
            $titolo = trim((string)($doc['titolo'] ?? ''));
            if ($titolo === '') {
                continue;
            }
            $hashSource = !empty($doc['file_content'])
                ? $doc['file_content']
                : ($titolo . '|' . $cf . '|' . $i . '|' . bin2hex(random_bytes(8)));
            $voci[] = [
                'idVocePendenza'     => (string)($i + 1),
                'descrizione'        => $titolo,
                'importo'            => 16.00,
                'tipoBollo'          => '01',
                'hashDocumento'      => base64_encode(hash('sha256', $hashSource, true)),
                'provinciaResidenza' => strtoupper($provincia),
            ];
        }
        return $voci;
    }
}

if (!function_exists('frontoffice_process_bollo_request')) {
    function frontoffice_process_bollo_request(array $data, array $files): array
    {
        $context = ['form_data' => $data];
        $errors = [];

        // Anno
        $defaultYear = (int) date('Y');
        $annoRaw = $data['annoRiferimento'] ?? $defaultYear;
        $anno = is_scalar($annoRaw) && is_numeric((string) $annoRaw) ? (int) $annoRaw : 0;
        if ($anno < $defaultYear - 5 || $anno > $defaultYear + 1) {
            $errors[] = 'Anno di riferimento non valido.';
        }

        // Provincia
        $provincia = strtoupper(preg_replace('/[^A-Za-z]/', '', trim((string)($data['provinciaResidenza'] ?? ''))));
        if ($provincia === '') {
            $errors[] = 'La provincia di residenza è obbligatoria.';
        } elseif (strlen($provincia) !== 2) {
            $errors[] = 'La provincia di residenza deve essere di 2 lettere (es. PE, RM).';
        }

        // Soggetto pagatore
        $payerRaw = is_array($data['soggettoPagatore'] ?? null) ? $data['soggettoPagatore'] : [];
        $payerType = strtoupper((string)($payerRaw['tipo'] ?? 'F'));
        if (!in_array($payerType, ['F', 'G'], true)) {
            $payerType = 'F';
        }
        $ident = strtoupper(preg_replace('/\s+/', '', trim((string)($payerRaw['identificativo'] ?? ''))));
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
        $name    = trim((string)($payerRaw['nome'] ?? ''));
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

        // Documenti + file opzionali
        $documentiRaw = is_array($data['documenti'] ?? null) ? array_values($data['documenti']) : [];
        $filesRaw     = is_array($files['file_bollo'] ?? null) ? $files['file_bollo'] : [];
        $documenti = [];
        foreach ($documentiRaw as $i => $titoloRaw) {
            $titolo = trim((string) $titoloRaw);
            if ($titolo === '') {
                continue;
            }
            $fileContent = null;
            $tmpName = $filesRaw['tmp_name'][$i] ?? null;
            $fileErr = (int)($filesRaw['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($fileErr === UPLOAD_ERR_OK && is_string($tmpName) && $tmpName !== '' && is_uploaded_file($tmpName)) {
                $fc = @file_get_contents($tmpName);
                if ($fc !== false && $fc !== '') {
                    $fileContent = $fc;
                }
            }
            $documenti[] = ['titolo' => $titolo, 'file_content' => $fileContent];
        }
        if (count($documenti) === 0) {
            $errors[] = 'Inserisci almeno un documento da bollare.';
        } elseif (count($documenti) > 5) {
            $errors[] = 'Puoi bollare al massimo 5 documenti per volta.';
        }

        // Privacy
        if (empty($data['privacy'])) {
            $errors[] = "Devi accettare l'informativa privacy per proseguire.";
        }

        // ID Dominio
        $idDominio = frontoffice_env_value('ID_DOMINIO', '');
        if ($idDominio === '') {
            $errors[] = 'Configurazione mancante: ID_DOMINIO non impostato.';
        }

        // Lookup tipo bollo tramite backoffice API (evita accesso DB diretto)
        $idTipo    = frontoffice_env_value('BOLLO_TIPO_PENDENZA', 'BOLLOT');
        if ($idTipo === '') {
            $idTipo = 'BOLLOT';
        }
        $tipoBollo = '01';
        if ($idDominio !== '') {
            $tipologieResult = frontoffice_backoffice_api('GET', '/api/frontoffice/tipologie');
            foreach ((array)($tipologieResult['_raw']['tipologie'] ?? []) as $row) {
                if (is_array($row) && (string)($row['id_entrata'] ?? '') === $idTipo) {
                    $rawTipoBollo = (string)($row['tipo_bollo'] ?? '');
                    if (in_array($rawTipoBollo, ['01'], true)) {
                        $tipoBollo = $rawTipoBollo;
                    }
                    break;
                }
            }
        }

        if ($errors) {
            $context['form_errors'] = $errors;
            $context['form_feedback'] = [
                'type'    => 'danger',
                'title'   => 'Controlla i dati inseriti',
                'message' => 'Alcuni campi non sono corretti. Correggili e riprova.',
            ];
            return $context;
        }

        $voci          = frontoffice_build_bollo_voci($documenti, $ident, $provincia, $tipoBollo);
        $importoTotale = count($voci) * 16.00;
        $dataScadenza  = (new \DateTimeImmutable('today'))->modify('+15 days')->format('Y-m-d');
        $nDoc          = count($documenti);
        $causale       = $nDoc === 1
            ? mb_substr('MBT - ' . $documenti[0]['titolo'], 0, 140)
            : 'Marca da Bollo Telematica (' . $nDoc . ' documenti)';

        $payload = [
            'idTipoPendenza'   => $idTipo,
            'idDominio'        => $idDominio,
            'causale'          => $causale,
            'importo'          => $importoTotale,
            'tassonomiaAvviso' => 'Imposte e tasse',
            'annoRiferimento'  => $anno,
            'soggettoPagatore' => frontoffice_prepare_payer($payerRaw),
            'voci'             => $voci,
            'dataValidita'     => $dataScadenza,
            'dataScadenza'     => $dataScadenza,
            'datiAllegati'     => frontoffice_build_dati_allegati(),
        ];

        $sendResult = frontoffice_send_pendenza_to_backoffice($payload);
        if (!$sendResult['success']) {
            $context['form_feedback'] = [
                'type'    => 'danger',
                'title'   => 'Invio non riuscito',
                'message' => implode(' ', $sendResult['errors'] ?? ['Invio pendenza non riuscito.']),
            ];
            return $context;
        }

        $idPendenza   = $sendResult['idPendenza'] ?? '';
        $detail       = frontoffice_fetch_pagamenti_detail($idPendenza);
        $numeroAvviso = frontoffice_extract_numero_avviso($sendResult['response'] ?? null, $detail);
        // MBT: GovPay non genera avvisi PDF per marca da bollo (422 Avviso non disponibile).
        // Costruiamo l'URL del bollettino HTML stampabile come sostituto.
        $_descParams = '';
        foreach ($documenti as $_d) {
            $_t = mb_substr(trim((string)($_d['titolo'] ?? '')), 0, 200);
            if ($_t !== '') {
                $_descParams .= '&desc[]=' . rawurlencode($_t);
            }
        }
        $downloadUrl = '/avviso-bollo?'
            . 'iuv='       . rawurlencode(preg_replace('/\D/', '', (string)($numeroAvviso ?? '')))
            . '&ente='     . rawurlencode($idDominio)
            . '&importo='  . (int)round($importoTotale * 100)
            . '&causale='  . rawurlencode($causale)
            . '&cf='       . rawurlencode($ident)
            . '&scadenza=' . rawurlencode($detail['dataScadenza'] ?? $dataScadenza)
            . $_descParams;

        // Whitelist per checkout senza login
        if ($idPendenza !== '' && session_status() === PHP_SESSION_ACTIVE) {
            foreach (['frontoffice_spontaneo_pendenze', 'frontoffice_avviso_pendenze'] as $key) {
                $list = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
                $list[] = $idPendenza;
                $list = array_values(array_unique(array_filter(array_map('strval', $list), static fn ($v) => trim($v) !== '')));
                if (count($list) > 25) {
                    $list = array_slice($list, -25);
                }
                $_SESSION[$key] = $list;
            }
        }

        // Auto-aggiunta al carrello disabilitata per la marca da bollo
        $cartError = null;

        $checkoutToken = $idPendenza !== '' ? frontoffice_generate_checkout_token($idPendenza) : '';
        $context['pendenza_result'] = [
            'idPendenza'        => $idPendenza,
            'numeroAvviso'      => $numeroAvviso,
            'importo'           => $importoTotale,
            'causale'           => $causale,
            'documenti'         => array_column($documenti, 'titolo'),
            'download_url'      => $downloadUrl,
            'checkout_url'      => $idPendenza !== ''
                ? ('/pagamento-spontaneo/checkout?idPendenza=' . rawurlencode($idPendenza) . ($checkoutToken !== '' ? '&t=' . rawurlencode($checkoutToken) : ''))
                : null,
            'cart_url'          => null,
            'cart_error'        => null,
            'data_scadenza'     => $detail['dataScadenza'] ?? $dataScadenza,
            'soggetto_pagatore' => $payload['soggettoPagatore'],
        ];

        $context['form_feedback'] = [
            'type'    => 'success',
            'title'   => 'Avviso generato',
            'message' => 'La marca da bollo è stata generata e aggiunta al carrello.',
        ];
        $context['form_data'] = [];

        return $context;
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
        if ($phone !== '' && trim(preg_replace('/^\+39\s*/', '', $phone)) !== '') {
            $payload['cellulare'] = frontoffice_normalize_cellulare($phone);
        }

        return $payload;
    }
}

if (!function_exists('frontoffice_normalize_cellulare')) {
    function frontoffice_normalize_cellulare(string $phone): string
    {
        // GovPay pattern: \+[0-9]{2,2}\s[0-9]{3,3}\-[0-9]{7,7}
        $clean = preg_replace('/[\s\-\.]/', '', $phone);
        if (preg_match('/^\+(\d{2})(\d{3})(\d{7})$/', $clean, $m)) {
            return "+{$m[1]} {$m[2]}-{$m[3]}";
        }
        return $phone;
    }
}

if (!function_exists('frontoffice_add_notification_to_pendenza')) {
    function frontoffice_add_notification_to_pendenza(string $idPendenza, array $notificationData): bool
    {
        if ($idPendenza === '') {
            return false;
        }
        try {
            $result = frontoffice_backoffice_api(
                'POST',
                '/api/frontoffice/pendenze/' . rawurlencode($idPendenza) . '/notifiche',
                $notificationData
            );
            return (bool)($result['_raw']['updated'] ?? false);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('frontoffice_add_notification_to_pendenza failed', [
                'err' => $e->getMessage(),
            ]);
            return false;
        }
    }
}

if (!function_exists('frontoffice_build_dati_allegati')) {
    function frontoffice_build_dati_allegati(): array
    {
        $datiAllegati = ['sorgente' => 'Spontaneo'];
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['frontoffice_user']) && is_array($_SESSION['frontoffice_user'])) {
            $foUser = $_SESSION['frontoffice_user'];
            $datiAllegati['utente_autenticato'] = true;
            $datiAllegati['utente_nome'] = $foUser['first_name'] ?? '';
            $datiAllegati['utente_cognome'] = $foUser['last_name'] ?? '';
            $datiAllegati['utente_cf'] = $foUser['fiscal_number'] ?? '';
            $datiAllegati['utente_email'] = $foUser['email'] ?? '';
            $datiAllegati['utente_provider'] = $foUser['provider_name'] ?? ($foUser['provider_id'] ?? '');
        }
        return $datiAllegati;
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
    /**
     * Delega la creazione pendenza al backoffice sidecar via API.
     * Il backoffice risolve voci (se non presenti), iuv_prefix e chiama GovPay.
     */
    function frontoffice_send_pendenza_to_backoffice(array $payload): array
    {
        unset($payload['idPendenza']);
        $result = frontoffice_backoffice_api('POST', '/api/frontoffice/pendenze', $payload);
        if (!$result['success']) {
            $msg = $result['message'] ?: 'Invio pendenza non riuscito.';
            Logger::getInstance()->error('Errore invio pendenza frontoffice via backoffice API', ['error' => $msg]);
            return ['success' => false, 'errors' => [$msg]];
        }
        return [
            'success'    => true,
            'idPendenza' => (string)($result['_raw']['idPendenza'] ?? ''),
            'response'   => $result['_raw']['response'] ?? null,
        ];
    }
}

if (!function_exists('frontoffice_fetch_pagamenti_detail')) {
    function frontoffice_fetch_pagamenti_detail(string $idPendenza): ?array
    {
        if ($idPendenza === '') {
            return null;
        }
        $result = frontoffice_backoffice_api('GET', '/api/frontoffice/pendenze/' . rawurlencode($idPendenza));
        if (!$result['success']) {
            Logger::getInstance()->warning('Impossibile recuperare il dettaglio della pendenza via backoffice API', ['idPendenza' => $idPendenza]);
            return null;
        }
        $pendenza = $result['_raw']['pendenza'] ?? null;
        return is_array($pendenza) ? $pendenza : null;
    }
}

if (!function_exists('frontoffice_fetch_pagamenti_detail_raw')) {
    // Mantenuta per compatibilità — delega a frontoffice_fetch_pagamenti_detail
    function frontoffice_fetch_pagamenti_detail_raw(string $idA2A, string $idPendenza): ?array
    {
        return frontoffice_fetch_pagamenti_detail($idPendenza);
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
        if ($idDominio === '' || $numeroAvviso === '') {
            return null;
        }
        $result = frontoffice_backoffice_api(
            'GET',
            '/api/frontoffice/pendenze/avviso/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroAvviso)
        );
        if (!$result['success']) {
            Logger::getInstance()->info('Pendenza non trovata via backoffice API (byAvviso)', [
                'idDominio'    => $idDominio,
                'numeroAvviso' => $numeroAvviso,
                'error_status' => $result['error_status'],
            ]);
            return null;
        }
        $pendenza = $result['_raw']['pendenza'] ?? null;
        return is_array($pendenza) ? $pendenza : null;
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
        // GovPay Backoffice v1 usa 'iuvPagamento' o 'numeroAvviso', non 'iuv'
        $iuv = trim((string)($detail['iuv'] ?? ''));
        if ($iuv === '') {
            $iuv = trim((string)($detail['iuvPagamento'] ?? ''));
        }
        if ($iuv === '') {
            $iuv = trim((string)($detail['numeroAvviso'] ?? ''));
        }

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

        // GovPay Backoffice v1 usa 'riscossioni' (array), non 'riscossione' (singolare)
        $voci = $detail['voci'] ?? null;
        if (is_array($voci)) {
            foreach ($voci as $voce) {
                if (!is_array($voce)) {
                    continue;
                }
                $riscossioni = $voce['riscossioni'] ?? null;
                if (!is_array($riscossioni)) {
                    continue;
                }
                foreach ($riscossioni as $riscossione) {
                    if (!is_array($riscossione)) {
                        continue;
                    }
                    if ($iuv === '') {
                        $iuv = trim((string)($riscossione['iuv'] ?? ''));
                    }
                    if ($idRicevuta === '') {
                        $idRicevuta = trim((string)($riscossione['idRicevuta'] ?? ''));
                    }
                }
            }
        }

        // CCP vive in detail['rpp'][*]['rpt']['datiVersamento']['codiceContestoPagamento']
        if ($ccp === '') {
            $rppList = $detail['rpp'] ?? null;
            if (is_array($rppList)) {
                foreach ($rppList as $rppEntry) {
                    if (!is_array($rppEntry)) {
                        continue;
                    }
                    $candidate = trim((string)(
                        $rppEntry['rpt']['datiVersamento']['codiceContestoPagamento']
                        ?? $rppEntry['rpt']['creditorReferenceId']
                        ?? ''
                    ));
                    if ($candidate !== '') {
                        $ccp = $candidate;
                        break;
                    }
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

if (!function_exists('frontoffice_fetch_ricevute_for_iuv')) {
    // Con Backoffice v1 il ccp viene estratto dalla pendenza detail (riscossioni).
    // Questo fallback non è più necessario — mantenuto come stub per compatibilità.
    function frontoffice_fetch_ricevute_for_iuv(string $idDominio, string $iuv): ?array
    {
        return null;
    }
}

if (!function_exists('frontoffice_stream_rt_pdf')) {
    function frontoffice_stream_rt_pdf(string $idDominio, string $iuv, string $ccp): void
    {
        if ($idDominio === '' || $iuv === '' || $ccp === '') {
            http_response_code(404);
            echo 'Ricevuta non disponibile.';
            return;
        }
        $path = '/api/frontoffice/ricevuta/'
            . rawurlencode($idDominio) . '/'
            . rawurlencode($iuv) . '/'
            . rawurlencode($ccp);
        frontoffice_backoffice_api_stream($path);
    }
}

if (!function_exists('frontoffice_find_paid_rpp_for_pendenza')) {
    /**
     * Ricava (iuv, ccp) dal dettaglio pendenza via backoffice API (Backoffice v1).
     * Con Backoffice v1 il ccp è nelle riscossioni della pendenza — nessuna chiamata extra.
     */
    function frontoffice_find_paid_rpp_for_pendenza(string $idDominio, string $idPendenza, ?string $idA2A = null): ?array
    {
        if ($idPendenza === '') {
            return null;
        }
        $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
        if (!is_array($detail)) {
            return null;
        }
        $ids = frontoffice_extract_ricevuta_identifiers_from_pendenza_detail($detail);
        $iuv = trim((string)($ids['iuv'] ?? ''));
        $ccp = trim((string)($ids['ccp'] ?? ''));
        return ($iuv !== '' && $ccp !== '') ? ['iuv' => $iuv, 'ccp' => $ccp] : null;
    }
}

if (!function_exists('frontoffice_stream_ricevuta_pdf')) {
    // Con Backoffice v1 il terzo parametro è il ccp (codice contesto pagamento).
    // Alias a frontoffice_stream_rt_pdf() — stessa chiamata all'endpoint sidecar.
    function frontoffice_stream_ricevuta_pdf(string $idDominio, string $iuv, string $ccp): void
    {
        frontoffice_stream_rt_pdf($idDominio, $iuv, $ccp);
    }
}

if (!function_exists('frontoffice_stream_ricevuta_by_pendenza')) {
    /**
     * Scarica la RT della pendenza delegando al backoffice la risoluzione di IUV+CCP.
     * Usa GET /api/frontoffice/pendenze/{idPendenza}/ricevuta — backoffice chiama buildReceiptPathLookup.
     */
    function frontoffice_stream_ricevuta_by_pendenza(string $idPendenza): void
    {
        if ($idPendenza === '') {
            http_response_code(404);
            echo 'Ricevuta non disponibile.';
            return;
        }
        $path = '/api/frontoffice/pendenze/' . rawurlencode($idPendenza) . '/ricevuta';
        frontoffice_backoffice_api_stream($path);
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

        $state  = strtoupper((string)($pendenza['stato'] ?? ''));
        $importo = $pendenza['importo'] ?? null;

        // Marca da bollo: GovPay non genera PDF avviso (422). Usa template HTML /avviso-bollo.
        $isBolloPreview = frontoffice_is_bollo_detail($pendenza);
        if ($isBolloPreview && $numeroAvviso !== '' && $idDominio !== '') {
            $cf = frontoffice_extract_pendenza_debtor_cf($pendenza);
            $importoCentsPreview = is_numeric($importo) ? (int)round((float)$importo * 100) : 0;
            $causalePreview = mb_substr(trim((string)($pendenza['causale'] ?? '')), 0, 140);
            $scadenzaPreview = trim((string)($pendenza['dataScadenza'] ?? ''));
            $iuvClean = preg_replace('/\D/', '', $numeroAvviso);
            $downloadUrl = '/avviso-bollo?' . http_build_query(array_filter([
                'iuv'      => $iuvClean !== '' ? $iuvClean : null,
                'ente'     => $idDominio,
                'importo'  => $importoCentsPreview > 0 ? $importoCentsPreview : null,
                'causale'  => $causalePreview !== '' ? $causalePreview : null,
                'cf'       => $cf !== '' ? $cf : null,
                'scadenza' => $scadenzaPreview !== '' ? $scadenzaPreview : null,
            ]), '', '&', PHP_QUERY_RFC3986);
        } else {
            // Usa link firmato per evitare enumerazione IUV su /avvisi/ pubblico
            if (!$isBolloPreview) {
                $cf = frontoffice_extract_pendenza_debtor_cf($pendenza);
            }
            $downloadUrl = ($numeroAvviso !== '' && $idDominio !== '' && $cf !== '')
                ? frontoffice_generate_pdf_link($cf, $numeroAvviso)
                : null;
        }

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

        $isPaid = frontoffice_is_pendenza_paid($state);

        $publicReceiptUrl = null;
        if ($isPaid && $idPendenza !== '' && $idDominio !== '') {
            $cfDebitore = frontoffice_extract_pendenza_debtor_cf($pendenza);
            if ($cfDebitore !== '') {
                $publicReceiptUrl = frontoffice_build_public_receipt_url($idDominio, $idPendenza, $cfDebitore, 300);
            }
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
            'is_paid' => $isPaid,
            'is_bollo' => $isBolloPreview,
            'tipologia' => isset($pendenza['tipoPendenza']) && is_array($pendenza['tipoPendenza']) ? [
                'id' => (string)($pendenza['tipoPendenza']['idTipoPendenza'] ?? $pendenza['tipoPendenza']['idTipo'] ?? $pendenza['tipoPendenza']['id'] ?? ''),
                'descrizione' => trim((string)($pendenza['tipoPendenza']['descrizione'] ?? '')),
            ] : null,
            'download_url' => $downloadUrl,
            'receipt_url' => ($isPaid && $idPendenza !== '')
                ? '/pendenze/' . rawurlencode($idPendenza) . '/ricevuta'
                : null,
            'receipt_download_url' => $publicReceiptUrl,
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

// ─── Rate limit (delegato al backoffice via API sidecar) ─────────────────────

if (!function_exists('frontoffice_client_ip')) {
    function frontoffice_client_ip(): string
    {
        $trustedProxiesSetting = frontoffice_env_value('TRUSTED_PROXIES', '');
        $trustedProxies = array_filter(array_map('trim', explode(',', $trustedProxiesSetting)));
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
            $forwarded = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if ($forwarded !== '') {
                $first = trim((string)(explode(',', $forwarded)[0] ?? ''));
                if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                    return substr($first, 0, 45);
                }
            }
        }
        return substr($remoteAddr, 0, 45);
    }
}

if (!function_exists('frontoffice_rate_limit_check')) {
    /**
     * Sliding-window rate limit delegato al backoffice sidecar.
     * Ritorna true se entro soglia, false se la supera. Fail-open su errori di rete.
     */
    function frontoffice_rate_limit_check(string $key, int $limit, int $windowSec = 60): bool
    {
        if ($key === '' || $limit <= 0) {
            return true;
        }
        $result = frontoffice_backoffice_api('POST', '/api/frontoffice/rate-limit/check', [
            'key'        => $key,
            'limit'      => $limit,
            'window_sec' => $windowSec,
        ]);
        if (!$result['success'] && $result['error_status'] === 401) {
            Logger::getInstance()->warning('Rate limit: autenticazione backoffice fallita, fail-open');
            return true;
        }
        return (bool)($result['_raw']['allowed'] ?? true);
    }
}

if (!function_exists('frontoffice_rate_limit_response')) {
    function frontoffice_rate_limit_response(): array
    {
        header('Retry-After: 60');
        return [
            'template' => 'pagamenti/rate-limited.html.twig',
            'context' => [],
        ];
    }
}

// ─── HMAC firmato per download ricevuta pubblico ─────────────────────────────

if (!function_exists('frontoffice_public_receipt_secret')) {
    function frontoffice_public_receipt_secret(): string
    {
        $base = frontoffice_env_value('APP_ENCRYPTION_KEY', '');
        if ($base === '') {
            return '';
        }
        return hash('sha256', $base . '|public-receipt');
    }
}

if (!function_exists('frontoffice_normalize_cf_key')) {
    function frontoffice_normalize_cf_key(string $cf): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($cf)) ?? '');
    }
}

if (!function_exists('frontoffice_checkout_token_secret')) {
    function frontoffice_checkout_token_secret(): string
    {
        $base = frontoffice_env_value('FRONTOFFICE_LINK_SIGNING_KEY', '');
        if ($base === '') {
            return '';
        }
        return hash('sha256', $base . '|checkout-link');
    }
}

if (!function_exists('frontoffice_generate_checkout_token')) {
    function frontoffice_generate_checkout_token(string $idPendenza): string
    {
        $secret = frontoffice_checkout_token_secret();
        if ($secret === '') {
            return '';
        }
        return hash_hmac('sha256', $idPendenza, $secret);
    }
}

if (!function_exists('frontoffice_verify_checkout_token')) {
    function frontoffice_verify_checkout_token(string $idPendenza, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $expected = frontoffice_generate_checkout_token($idPendenza);
        return $expected !== '' && hash_equals($expected, $token);
    }
}

if (!function_exists('frontoffice_checkout_return_is_authorized')) {
    /**
     * Verifica che il visitante della pagina di return checkout abbia titolo a vedere i dettagli
     * della pendenza. Tre livelli:
     *   1. Token HMAC firmato (link costruito dal frontoffice, sopravvive al reload).
     *   2. Sessione — idPendenza in whitelist corrente (spontaneo/avviso di questa sessione).
     *   3. Utente loggato con CF corrispondente al debitore (richiede fetch GovPay).
     *
     * Se nessuna condizione è vera: false → mostra pagina generica senza dati pendenza.
     */
    function frontoffice_checkout_return_is_authorized(string $idPendenza, string $token): bool
    {
        if ($idPendenza === '') {
            return false;
        }

        // 1. Token HMAC — fast path, no session needed
        if ($token !== '' && frontoffice_verify_checkout_token($idPendenza, $token)) {
            return true;
        }

        // 2. Session whitelist
        if (session_status() === PHP_SESSION_ACTIVE) {
            foreach (['frontoffice_spontaneo_pendenze', 'frontoffice_avviso_pendenze'] as $key) {
                $list = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
                if (in_array($idPendenza, array_map('strval', $list), true)) {
                    return true;
                }
            }
        }

        // 3. Utente loggato con CF corrispondente
        $loggedUser = frontoffice_get_logged_user();
        if (is_array($loggedUser) && $loggedUser !== []) {
            $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
            if (is_array($detail) && frontoffice_pendenza_belongs_to_cf($detail, frontoffice_get_logged_user_fiscal_number())) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('frontoffice_sign_receipt_token')) {
    function frontoffice_sign_receipt_token(string $idDominio, string $idPendenza, string $cf, int $exp): string
    {
        $secret = frontoffice_public_receipt_secret();
        if ($secret === '') {
            return '';
        }
        $payload = $idDominio . '|' . $idPendenza . '|' . frontoffice_normalize_cf_key($cf) . '|' . $exp;
        return hash_hmac('sha256', $payload, $secret);
    }
}

if (!function_exists('frontoffice_verify_receipt_token')) {
    function frontoffice_verify_receipt_token(string $idDominio, string $idPendenza, string $cf, int $exp, string $token): bool
    {
        if ($idDominio === '' || $idPendenza === '' || $cf === '' || $exp <= 0 || $token === '') {
            return false;
        }
        if ($exp < time()) {
            return false;
        }
        $expected = frontoffice_sign_receipt_token($idDominio, $idPendenza, $cf, $exp);
        if ($expected === '' || strlen($expected) !== strlen($token)) {
            return false;
        }
        return hash_equals($expected, $token);
    }
}

if (!function_exists('frontoffice_build_public_receipt_url')) {
    function frontoffice_build_public_receipt_url(string $idDominio, string $idPendenza, string $cf, int $ttl = 300): ?string
    {
        if ($idDominio === '' || $idPendenza === '' || $cf === '') {
            return null;
        }
        $exp = time() + max(60, $ttl);
        $token = frontoffice_sign_receipt_token($idDominio, $idPendenza, $cf, $exp);
        if ($token === '') {
            return null;
        }
        return '/ricevuta/pubblica?id=' . rawurlencode($idPendenza)
            . '&exp=' . $exp
            . '&t=' . $token;
    }
}

if (!function_exists('frontoffice_extract_pendenza_debtor_cf')) {
    function frontoffice_extract_pendenza_debtor_cf(array $pendenza): string
    {
        $candidates = [];
        foreach (['idDebitore', 'codiceFiscaleDebitore', 'id_debitore'] as $k) {
            if (isset($pendenza[$k]) && is_string($pendenza[$k])) {
                $candidates[] = $pendenza[$k];
            }
        }
        if (isset($pendenza['soggettoPagatore']) && is_array($pendenza['soggettoPagatore'])) {
            foreach (['identificativo', 'identificativoUnivoco', 'codiceFiscale', 'fiscalNumber'] as $k) {
                if (isset($pendenza['soggettoPagatore'][$k]) && is_string($pendenza['soggettoPagatore'][$k])) {
                    $candidates[] = $pendenza['soggettoPagatore'][$k];
                }
            }
        }
        if (isset($pendenza['soggettoVersante']) && is_array($pendenza['soggettoVersante'])) {
            foreach (['identificativo', 'identificativoUnivoco', 'codiceFiscale', 'fiscalNumber'] as $k) {
                if (isset($pendenza['soggettoVersante'][$k]) && is_string($pendenza['soggettoVersante'][$k])) {
                    $candidates[] = $pendenza['soggettoVersante'][$k];
                }
            }
        }
        foreach ($candidates as $value) {
            $norm = frontoffice_normalize_cf_key((string)$value);
            if ($norm !== '') {
                return $norm;
            }
        }
        return '';
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
        $rawEmail = trim((string)($meta['email'] ?? $meta['soggettoPagatore']['email'] ?? ''));
        $_SESSION['frontoffice_cart'][$idPendenza] = [
            'idPendenza'   => $idPendenza,
            'causale'      => mb_substr(trim((string)($meta['causale'] ?? '')), 0, 140),
            'importo'      => is_numeric($meta['importo'] ?? null) ? (float)$meta['importo'] : null,
            'numeroAvviso' => preg_replace('/\D+/', '', trim((string)($meta['numeroAvviso'] ?? ''))),
            'data_scadenza'=> $meta['dataScadenza'] ?? $meta['data_scadenza'] ?? null,
            'email'        => filter_var($rawEmail, FILTER_VALIDATE_EMAIL) !== false ? $rawEmail : '',
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

if (!function_exists('frontoffice_is_bollo_detail')) {
    function frontoffice_is_bollo_detail(array $detail): bool
    {
        $tipo = strtoupper(trim((string)($detail['tipoPendenza']['idTipoPendenza'] ?? '')));
        if ($tipo === 'BOLLOT') {
            return true;
        }
        $voci = $detail['voci'] ?? null;
        if (!is_array($voci)) {
            return false;
        }
        foreach ($voci as $voce) {
            if (!is_array($voce)) {
                continue;
            }
            $tipoBollo = trim((string)($voce['tipoBollo'] ?? $voce['tipo_bollo'] ?? ''));
            if ($tipoBollo !== '') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('frontoffice_is_ebollo_v2_enabled')) {
    function frontoffice_is_ebollo_v2_enabled(): bool
    {
        $mode = strtolower(trim(frontoffice_env_value('PAGOPA_EBOLLO_MODE', 'legacy')));
        return $mode === 'v2' || $mode === '2.0' || $mode === 'v2.0';
    }
}

if (!function_exists('frontoffice_start_ebollo_checkout')) {
    /**
     * Avvia il checkout @e.bollo per una singola pendenza BOLLOT.
     * Ritorna ['success'=>bool,'location'=>string,'status'=>int,'message'=>string].
     */
    function frontoffice_start_ebollo_checkout(
        array $detail,
        string $idPendenza,
        string $idDominio,
        string $okUrl,
        string $cancelUrl,
        string $errorUrl,
        string $fallbackEmail,
        string $flow
    ): array {
        if (frontoffice_env_value('PAGOPA_EBOLLO_ENABLED', '0') !== '1') {
            Logger::getInstance()->warning('Configurazione @e.bollo incompleta', ['flow' => $flow]);
            return [
                'success' => false,
                'status'  => 503,
                'message' => 'Configurazione @e.bollo non completa. Contatta l\'amministratore.',
            ];
        }

        $voci = is_array($detail['voci'] ?? null) ? $detail['voci'] : [];
        $firstVoce = [];
        foreach ($voci as $voce) {
            if (!is_array($voce)) {
                continue;
            }
            $tipoBollo = trim((string)($voce['tipoBollo'] ?? $voce['tipo_bollo'] ?? ''));
            if ($tipoBollo !== '') {
                $firstVoce = $voce;
                break;
            }
        }

        $province = strtoupper(trim((string)($firstVoce['provinciaResidenza'] ?? '')));
        $province = preg_replace('/[^A-Z]/', '', $province);
        if (!is_string($province) || strlen($province) !== 2) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Dati marca da bollo incompleti: provincia di residenza non valida.',
            ];
        }

        $documentHash = trim((string)($firstVoce['hashDocumento'] ?? ''));
        if ($documentHash !== '' && strlen($documentHash) !== 44) {
            $documentHash = '';
        }

        $payer = is_array($detail['soggettoPagatore'] ?? null) ? $detail['soggettoPagatore'] : [];
        $fiscalCode = strtoupper(preg_replace('/\s+/', '', trim((string)($payer['identificativo'] ?? $payer['codiceFiscale'] ?? $payer['fiscalNumber'] ?? ''))));
        if ($fiscalCode === '') {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Dati marca da bollo incompleti: codice fiscale/partita IVA mancante.',
            ];
        }

        $email = trim((string)($payer['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = trim($fallbackEmail);
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Dati marca da bollo incompleti: email del pagatore non valida.',
            ];
        }

        $anagrafica = trim((string)($payer['anagrafica'] ?? ''));
        $nomeRaw = trim((string)($payer['nome'] ?? ''));
        $parts = preg_split('/\s+/', trim($anagrafica));
        if (!is_array($parts)) {
            $parts = [];
        }
        $firstName = $nomeRaw !== '' ? $nomeRaw : trim((string)($parts[0] ?? ''));
        $lastName = '';
        if (count($parts) > 1) {
            $lastName = trim((string)implode(' ', array_slice($parts, 1)));
        } elseif ($anagrafica !== '') {
            $lastName = $anagrafica;
        }
        if ($firstName === '') {
            $firstName = 'SOGGETTO';
        }
        if ($lastName === '') {
            $lastName = 'PAGATORE';
        }

        $amountCents = frontoffice_amount_to_cents(is_numeric($detail['importo'] ?? null) ? (float)$detail['importo'] : 0.0);
        if ($amountCents <= 0) {
            return [
                'success' => false,
                'status' => 422,
                'message' => 'Importo marca da bollo non valido.',
            ];
        }

        $paymentNotice = [
            'firstName' => mb_substr($firstName, 0, 140),
            'lastName' => mb_substr($lastName, 0, 140),
            'fiscalCode' => $fiscalCode,
            'email' => $email,
            'amount' => $amountCents,
            'province' => $province,
        ];
        if ($documentHash !== '') {
            $paymentNotice['documentHash'] = $documentHash;
        }

        // Delega al backoffice sidecar — le credenziali @e.bollo restano nel backoffice
        $apiResult = frontoffice_backoffice_api('POST', '/api/frontoffice/bollo/ebollo-checkout', [
            'idDominio'      => $idDominio,
            'paymentNotices' => [$paymentNotice],
            'returnUrls'     => [
                'successUrl' => $okUrl,
                'cancelUrl'  => $cancelUrl,
                'errorUrl'   => $errorUrl,
            ],
        ]);

        if (!$apiResult['success']) {
            Logger::getInstance()->warning('frontoffice_start_ebollo_checkout: errore backoffice sidecar', [
                'flow' => $flow, 'idPendenza' => $idPendenza, 'message' => $apiResult['message'],
            ]);
            return [
                'success' => false,
                'status'  => $apiResult['error_status'] ?: 503,
                'message' => $apiResult['message'] ?: 'Al momento non riusciamo ad avviare il pagamento @e.bollo. Riprova più tardi.',
            ];
        }

        $location = trim((string)($apiResult['_raw']['location'] ?? ''));
        if ($location === '') {
            return ['success' => false, 'status' => 503, 'message' => '@e.bollo checkout: redirect URL mancante.'];
        }

        Logger::getInstance()->info('Checkout @e.bollo avviato via backoffice sidecar', [
            'flow' => $flow, 'idPendenza' => $idPendenza,
        ]);
        return ['success' => true, 'location' => $location];
    }
}

if (!function_exists('frontoffice_is_govpay_checkout_enabled')) {
    function frontoffice_is_govpay_checkout_enabled(): bool
    {
        return frontoffice_env_value('GOVPAY_CHECKOUT_ENABLED', '0') === '1';
    }
}

if (!function_exists('frontoffice_start_govpay_checkout')) {
    /**
     * Avvia il checkout GovPay (API pagamento v2) per una singola pendenza bollo.
     * Delega al backoffice sidecar — le credenziali GovPay restano nel backoffice.
     */
    function frontoffice_start_govpay_checkout(
        array $detail,
        string $idPendenza,
        string $returnUrl,
        string $flow = 'spontaneo'
    ): array {
        $result = frontoffice_backoffice_api('POST', '/api/frontoffice/bollo/govpay-checkout', [
            'idPendenza' => $idPendenza,
            'returnUrl'  => $returnUrl,
        ]);

        if (!$result['success']) {
            Logger::getInstance()->warning('frontoffice_start_govpay_checkout: errore backoffice sidecar', [
                'flow' => $flow, 'idPendenza' => $idPendenza, 'message' => $result['message'],
            ]);
            return ['success' => false, 'status' => $result['error_status'] ?: 503, 'message' => $result['message'] ?: 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.'];
        }

        $location = trim((string)($result['_raw']['location'] ?? ''));
        if ($location === '') {
            return ['success' => false, 'status' => 503, 'message' => 'GovPay checkout: redirect URL mancante.'];
        }

        Logger::getInstance()->info('Checkout GovPay avviato via backoffice sidecar', [
            'flow' => $flow, 'idPendenza' => $idPendenza,
        ]);
        return ['success' => true, 'location' => $location];
    }
}


if (!function_exists('frontoffice_resolve_bollo_checkout_url')) {
    /**
     * Risolve il checkout bollo con priorità: @e.bollo v2 > GovPay > null (passa a pagoPA).
     * Ritorna ['location'=>string] su successo, ['error_code'=>int,'error_msg'=>string] su errore,
     * oppure ['skip'=>true] se nessun checkout bollo è configurato.
     */
    function frontoffice_resolve_bollo_checkout_url(
        array  $detail,
        string $idPendenza,
        string $idDominio,
        string $okUrl,
        string $cancelUrl,
        string $errorUrl,
        string $emailNotice,
        string $frontofficeBaseUrl,
        string $flow
    ): array {
        if (!frontoffice_is_bollo_detail($detail)) {
            return ['skip' => true];
        }

        if (frontoffice_is_ebollo_v2_enabled()) {
            $r = frontoffice_start_ebollo_checkout($detail, $idPendenza, $idDominio, $okUrl, $cancelUrl, $errorUrl, $emailNotice, $flow);
            if (!($r['success'] ?? false)) {
                return ['error_code' => (int)($r['status'] ?? 503), 'error_msg' => (string)($r['message'] ?? 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.')];
            }
            return ['location' => (string)$r['location']];
        }

        // Tenta GovPay checkout senza dipendenza da cache env.
        // Il backoffice risponde 404 se checkout_url non è configurato (skip a pagoPA),
        // 503 se GovPay è temporaneamente irraggiungibile (errore visibile all'utente).
        $returnUrl = $frontofficeBaseUrl . '/checkout/govpay-return?idPendenza=' . rawurlencode($idPendenza);
        $r = frontoffice_start_govpay_checkout($detail, $idPendenza, $returnUrl, $flow);
        if ($r['success'] ?? false) {
            return ['location' => (string)$r['location']];
        }
        if ((int)($r['status'] ?? 503) === 404) {
            // Checkout URL non configurato → fall-through a pagoPA standard
            return ['skip' => true];
        }
        return ['error_code' => (int)($r['status'] ?? 503), 'error_msg' => (string)($r['message'] ?? 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.')];
    }
}

if (!function_exists('frontoffice_lookup_pagopa_avviso')) {
    function frontoffice_lookup_pagopa_avviso(string $numeroAvviso, string $codiceFiscale): array
    {
        $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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

        // Numero avviso pagoPA corretto = auxDigit (1) + IUV (17) = 18 cifre.
        // Se l'utente inserisce 17 cifre numeriche ha omesso l'auxDigit:
        // recuperalo dal DB e prepend prima della ricerca.
        if (strlen($normalizedAvviso) === 17 && ctype_digit($normalizedAvviso)) {
            $auxDigit = frontoffice_env_value('AUX_DIGIT', '');
            if ($auxDigit !== '' && ctype_digit($auxDigit)) {
                Logger::getInstance()->info('Numero avviso espanso con aux_digit', [
                    'original'  => $normalizedAvviso,
                    'expanded'  => $auxDigit . $normalizedAvviso,
                    'auxDigit'  => $auxDigit,
                ]);
                $normalizedAvviso = $auxDigit . $normalizedAvviso;
            }
        }

        // Canale principale: GovPay Pendenze v2 (byAvviso) è il modo corretto per interrogare un numero avviso.
        $pendenza = frontoffice_fetch_pendenza_by_avviso($idDominio, $normalizedAvviso);

        // Fallback: ricerca via backoffice API (findPendenze per numeroAvviso)
        if (!is_array($pendenza) || $pendenza === []) {
            $fallbackResult = frontoffice_backoffice_api('GET', '/api/frontoffice/pendenze', [
                'cf'        => '',
                'page'      => 1,
                'per_page'  => 10,
                'numero_avviso' => $normalizedAvviso,
            ]);
            $risultati = $fallbackResult['_raw']['data']['risultati'] ?? [];
            Logger::getInstance()->info('Fallback findPendenze per ricerca avviso via backoffice', [
                'requestedAvviso' => $normalizedAvviso,
                'count'           => is_array($risultati) ? count($risultati) : 0,
            ]);
            foreach ((array)$risultati as $candidate) {
                $candidateAvviso = frontoffice_normalize_avviso_code((string)($candidate['numeroAvviso'] ?? ''));
                if ($candidateAvviso !== '' && $candidateAvviso === $normalizedAvviso) {
                    $pendenza = $candidate;
                    break;
                }
            }
            if (!is_array($pendenza) || $pendenza === []) {
                return [
                    'success' => false,
                    'errors'  => ['Al momento non riusciamo a interrogare il sistema dei pagamenti. Riprova più tardi.'],
                ];
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

        $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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
            'dataValidita' => $dataScadenza,
            'dataScadenza' => $dataScadenza,
            'datiAllegati' => frontoffice_build_dati_allegati(),
        ];

        $sendResult = frontoffice_send_pendenza_to_backoffice($payload);
        if (!$sendResult['success']) {
            $context['form_feedback'] = [
                'type' => 'danger',
                'title' => 'Invio non riuscito',
                'message' => implode(' ', $sendResult['errors'] ?? ['Invio pendenza non riuscito.']),
            ];
            return $context;
        }

        $idPendenza = $sendResult['idPendenza'] ?? '';
        $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
        $numeroAvviso = frontoffice_extract_numero_avviso($sendResult['response'] ?? null, $detail);
        $cfSpontaneo = strtoupper(trim((string)($payerRaw['identificativo'] ?? '')));
        $downloadUrl = ($numeroAvviso && $idDominio !== '' && $cfSpontaneo !== '')
            ? frontoffice_generate_pdf_link($cfSpontaneo, $numeroAvviso)
            : null;

        $context['pendenza_result'] = [
            'idPendenza' => $idPendenza,
            'idTipoPendenza' => $idTipo,
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
        if ($idDominio === '' || $numeroAvviso === '') {
            http_response_code(404);
            echo 'Avviso non trovato';
            return;
        }
        frontoffice_backoffice_api_stream(
            '/api/frontoffice/avviso/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroAvviso)
        );
    }
}

if (!function_exists('frontoffice_stream_documento_pdf')) {
    function frontoffice_stream_documento_pdf(string $numeroDocumento): void
    {
        if ($numeroDocumento === '') {
            http_response_code(400);
            echo 'Parametro numeroDocumento mancante.';
            return;
        }
        frontoffice_backoffice_api_stream(
            '/api/frontoffice/documento/' . rawurlencode($numeroDocumento) . '/avvisi'
        );
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
            throw new \RuntimeException('FRONTOFFICE_LINK_SIGNING_KEY non configurata — impossibile generare o verificare link firmati');
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
    function frontoffice_sign_link(array $params, int $ttlSeconds = 31536000 /* 60*60*24*365 = 1 anno */): array
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
        $params = frontoffice_sign_link(['type' => 'avviso', 'cf' => $codiceFiscale, 'iuv' => $iuv]);
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
        $params = frontoffice_sign_link(['type' => 'ricevuta', 'iuv' => $iuv, 'iur' => $iur], 7776000 /* 90 giorni */);
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
        $params = frontoffice_sign_link(['type' => 'checkout', 'cf' => $codiceFiscale, 'iuv' => $iuv, 'action' => 'checkout'], 2592000 /* 30 giorni */);
        $base = rtrim($baseUrl, '/');
        return $base . '/link/checkout?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}

$entityName = trim($env('APP_ENTITY_NAME', 'Ente'));
$entitySuffix = trim($env('APP_ENTITY_SUFFIX', 'Servizi ai cittadini'));
$entityGovernment = trim($env('APP_ENTITY_GOVERNMENT', ''));
$entityFull = trim($entityName . ($entitySuffix !== '' ? ' - ' . $entitySuffix : '')) ?: $entityGovernment;
$entityWebsite = trim($env('APP_ENTITY_WEBSITE', ''));

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
$appFavicon = (($appLogo['type'] ?? '') === 'img' && !empty($appLogo['src']))
    ? ['href' => $appLogo['src'], 'type' => 'image/png']
    : ['href' => '/img/favicon_default.png', 'type' => 'image/png'];
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

// Validate CSRF token for all POST requests, excluding the SAML assertion callback path.
if ($method === 'POST' && $normalizedPath !== $spidCallbackPath) {
    $csrfToken = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!frontoffice_csrf_validate($csrfToken)) {
        Logger::getInstance()->warning('CSRF validation failed for frontoffice request', [
            'path' => $normalizedPath,
            'ip' => frontoffice_client_ip(),
        ]);
        http_response_code(403);
        echo 'Forbidden (CSRF token missing or invalid)';
        return;
    }
}

$routes = [
    '/' => static function () use ($serviceCatalog): array {
        $featuredIds = json_decode(frontoffice_env_value('FEATURED_SERVICES', '[]') ?: '[]', true) ?: [];
        $featuredServices = [];
        if (!empty($featuredIds)) {
            $idMap = [];
            foreach ($serviceCatalog as $svc) {
                $idMap[$svc['id']] = $svc;
            }
            foreach ($featuredIds as $id) {
                if (isset($idMap[$id])) {
                    $featuredServices[] = $idMap[$id];
                }
            }
        }
        return [
            'template' => 'home.html.twig',
            'context'  => ['featured_services' => $featuredServices],
        ];
    },
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

            $idDominio = frontoffice_env_value('ID_DOMINIO', '');
            $receiptItems = [];
            foreach (array_slice($cartIds, 0, 5) as $pid) {
                $detail = frontoffice_fetch_pagamenti_detail((string)$pid);
                if (!is_array($detail)) {
                    continue;
                }
                $nav = preg_replace('/\D+/', '', trim((string)($detail['numeroAvviso'] ?? '')));
                if ($nav === '' || $idDominio === '') {
                    continue;
                }
                $cf = frontoffice_extract_pendenza_debtor_cf($detail);
                if ($cf === '') {
                    continue;
                }
                $url = frontoffice_build_public_receipt_url($idDominio, (string)$pid, $cf, 3600);
                if ($url === null) {
                    continue;
                }
                $receiptItems[] = ['numero_avviso' => $nav, 'receipt_url' => $url];
            }

            return [
                'template' => 'checkout/ok.html.twig',
                'context' => [
                    'numero_avvisi'  => $numeroAvvisi,
                    'receipt_items'  => $receiptItems,
                    'detail_path'    => '/pendenze',
                    'login_path'     => '/login?return_to=%2Fpendenze',
                    'is_logged_in'   => frontoffice_get_logged_user() !== null,
                ],
            ];
        }

        // Single-pendenza path (original behavior)
        $detailPath = $idPendenza !== '' ? ('/pendenze/' . rawurlencode($idPendenza)) : '/pendenze';

        // Rate limit + autorizzazione: evita enumerazione IUV da IP arbitrari
        if (!frontoffice_rate_limit_check('ip:' . frontoffice_client_ip() . ':checkout-return', 30, 60)) {
            http_response_code(429);
            return ['template' => 'checkout/ok.html.twig', 'context' => [
                'detail_path' => $detailPath, 'login_path' => '/login', 'is_logged_in' => false,
            ]];
        }

        $tokenParam = trim((string)($_GET['t'] ?? ''));
        $isAuthorized = $idPendenza !== '' && frontoffice_checkout_return_is_authorized($idPendenza, $tokenParam);

        $numeroAvviso = null;
        $receiptUrl   = null;
        if ($isAuthorized) {
            $detail = frontoffice_fetch_pagamenti_detail($idPendenza);
            if (is_array($detail)) {
                $tmp = trim((string)($detail['numeroAvviso'] ?? ''));
                $tmp = preg_replace('/\D+/', '', $tmp);
                if (is_string($tmp) && $tmp !== '') {
                    $numeroAvviso = $tmp;
                }
                $idDominio = frontoffice_env_value('ID_DOMINIO', '');
                $cf = frontoffice_extract_pendenza_debtor_cf($detail);
                if ($cf !== '' && $idDominio !== '') {
                    $receiptUrl = frontoffice_build_public_receipt_url($idDominio, $idPendenza, $cf, 3600);
                }
            }
        }

        return [
            'template' => 'checkout/ok.html.twig',
            'context' => [
                'numero_avviso' => $numeroAvviso,
                'receipt_url'   => $receiptUrl,
                'detail_path'   => $detailPath,
                'login_path'    => '/login?return_to=' . rawurlencode($detailPath),
                'is_logged_in'  => frontoffice_get_logged_user() !== null,
            ],
        ];
    },

    '/checkout/cancel' => static function (): array {
        $idPendenza = trim((string)($_GET['idPendenza'] ?? $_GET['id_pendenza'] ?? ''));
        $idCart     = trim((string)($_GET['idCart'] ?? ''));
        $detailPath = $idPendenza !== '' ? ('/pendenze/' . rawurlencode($idPendenza)) : '/pendenze';

        // Rate limit + autorizzazione
        if ($idPendenza !== '' && !frontoffice_rate_limit_check('ip:' . frontoffice_client_ip() . ':checkout-return', 30, 60)) {
            http_response_code(429);
            return ['template' => 'checkout/cancel.html.twig', 'context' => [
                'detail_path' => $detailPath, 'login_path' => '/login', 'is_logged_in' => false,
            ]];
        }

        $tokenParam = trim((string)($_GET['t'] ?? ''));
        $isAuthorized = $idCart !== '' || ($idPendenza !== '' && frontoffice_checkout_return_is_authorized($idPendenza, $tokenParam));

        // Collect IDs to process (single pendenza o cart)
        $pendenzaIds = [];
        if ($isAuthorized) {
            if ($idPendenza !== '') {
                $pendenzaIds = [$idPendenza];
            } elseif ($idCart !== '' && session_status() === PHP_SESSION_ACTIVE) {
                $pendenzaIds = (array)($_SESSION['frontoffice_carrello'][$idCart] ?? []);
            }
        }

        $numeroAvviso = null;
        $pdfItems     = [];
        foreach (array_slice($pendenzaIds, 0, 5) as $pid) {
            $detail = frontoffice_fetch_pagamenti_detail((string)$pid);
            if (!is_array($detail)) {
                continue;
            }
            $nav = preg_replace('/\D+/', '', trim((string)($detail['numeroAvviso'] ?? '')));
            if ($nav === '') {
                continue;
            }
            if ($numeroAvviso === null) {
                $numeroAvviso = $nav;
            }
            $cf = trim((string)($detail['soggettoPagatore']['identificativo'] ?? ''));
            if ($cf === '') {
                continue;
            }
            if (frontoffice_is_bollo_detail($detail)) {
                $idDomCan     = frontoffice_env_value('ID_DOMINIO', '');
                $importoCents = (int)round((float)($detail['importo'] ?? 0) * 100);
                $causale      = mb_substr(trim((string)($detail['causale'] ?? '')), 0, 140);
                $scadenza     = trim((string)($detail['dataScadenza'] ?? ''));
                $bolloParams  = http_build_query(array_filter([
                    'iuv'      => $nav,
                    'ente'     => $idDomCan,
                    'importo'  => $importoCents > 0 ? $importoCents : null,
                    'causale'  => $causale !== '' ? $causale : null,
                    'cf'       => $cf,
                    'scadenza' => $scadenza !== '' ? $scadenza : null,
                ]), '', '&', PHP_QUERY_RFC3986);
                $pdfItems[] = ['numero_avviso' => $nav, 'pdf_url' => '/avviso-bollo?' . $bolloParams, 'is_bollo' => true];
            } else {
                $pdfItems[] = ['numero_avviso' => $nav, 'pdf_url' => frontoffice_generate_pdf_link($cf, $nav), 'is_bollo' => false];
            }
        }

        $retryUrl = $idCart !== ''
            ? '/carrello'
            : ($idPendenza !== '' ? '/pagamento-spontaneo/checkout?idPendenza=' . rawurlencode($idPendenza) . ($tokenParam !== '' ? '&t=' . rawurlencode($tokenParam) : '') : null);

        return [
            'template' => 'checkout/cancel.html.twig',
            'context' => [
                'numero_avviso' => $numeroAvviso,
                'pdf_items'     => $pdfItems,
                'detail_path'   => $detailPath,
                'login_path'    => '/login?return_to=' . rawurlencode($detailPath),
                'is_logged_in'  => frontoffice_get_logged_user() !== null,
                'retry_url'     => $retryUrl,
            ],
        ];
    },
    '/checkout/error' => static function (): array {
        $idPendenza = trim((string)($_GET['idPendenza'] ?? $_GET['id_pendenza'] ?? ''));
        $idCart     = trim((string)($_GET['idCart'] ?? ''));
        $detailPath = $idPendenza !== '' ? ('/pendenze/' . rawurlencode($idPendenza)) : '/pendenze';

        // Rate limit + autorizzazione
        if ($idPendenza !== '' && !frontoffice_rate_limit_check('ip:' . frontoffice_client_ip() . ':checkout-return', 30, 60)) {
            http_response_code(429);
            return ['template' => 'checkout/error.html.twig', 'context' => [
                'detail_path' => $detailPath, 'login_path' => '/login', 'is_logged_in' => false,
            ]];
        }

        $tokenParam = trim((string)($_GET['t'] ?? ''));
        $isAuthorized = $idCart !== '' || ($idPendenza !== '' && frontoffice_checkout_return_is_authorized($idPendenza, $tokenParam));

        // Collect IDs to process (single pendenza o cart)
        $pendenzaIds = [];
        if ($isAuthorized) {
            if ($idPendenza !== '') {
                $pendenzaIds = [$idPendenza];
            } elseif ($idCart !== '' && session_status() === PHP_SESSION_ACTIVE) {
                $pendenzaIds = (array)($_SESSION['frontoffice_carrello'][$idCart] ?? []);
            }
        }

        $numeroAvviso = null;
        $pdfItems     = [];
        foreach (array_slice($pendenzaIds, 0, 5) as $pid) {
            $detail = frontoffice_fetch_pagamenti_detail((string)$pid);
            if (!is_array($detail)) {
                continue;
            }
            $nav = preg_replace('/\D+/', '', trim((string)($detail['numeroAvviso'] ?? '')));
            if ($nav === '') {
                continue;
            }
            if ($numeroAvviso === null) {
                $numeroAvviso = $nav;
            }
            $cf = trim((string)($detail['soggettoPagatore']['identificativo'] ?? ''));
            if ($cf === '') {
                continue;
            }
            if (frontoffice_is_bollo_detail($detail)) {
                $idDomErr     = frontoffice_env_value('ID_DOMINIO', '');
                $importoCents = (int)round((float)($detail['importo'] ?? 0) * 100);
                $causale      = mb_substr(trim((string)($detail['causale'] ?? '')), 0, 140);
                $scadenza     = trim((string)($detail['dataScadenza'] ?? ''));
                $bolloParams  = http_build_query(array_filter([
                    'iuv'      => $nav,
                    'ente'     => $idDomErr,
                    'importo'  => $importoCents > 0 ? $importoCents : null,
                    'causale'  => $causale !== '' ? $causale : null,
                    'cf'       => $cf,
                    'scadenza' => $scadenza !== '' ? $scadenza : null,
                ]), '', '&', PHP_QUERY_RFC3986);
                $pdfItems[] = ['numero_avviso' => $nav, 'pdf_url' => '/avviso-bollo?' . $bolloParams, 'is_bollo' => true];
            } else {
                $pdfItems[] = ['numero_avviso' => $nav, 'pdf_url' => frontoffice_generate_pdf_link($cf, $nav), 'is_bollo' => false];
            }
        }

        $retryUrl = $idCart !== ''
            ? '/carrello'
            : ($idPendenza !== '' ? '/pagamento-spontaneo/checkout?idPendenza=' . rawurlencode($idPendenza) . ($tokenParam !== '' ? '&t=' . rawurlencode($tokenParam) : '') : null);

        return [
            'template' => 'checkout/error.html.twig',
            'context' => [
                'numero_avviso' => $numeroAvviso,
                'pdf_items'     => $pdfItems,
                'detail_path'   => $detailPath,
                'login_path'    => '/login?return_to=' . rawurlencode($detailPath),
                'is_logged_in'  => frontoffice_get_logged_user() !== null,
                'retry_url'     => $retryUrl,
            ],
        ];
    },
    '/checkout/govpay-return' => static function (): array {
        $idPendenza = trim((string)($_GET['idPendenza'] ?? ''));
        $detailPath = $idPendenza !== '' ? ('/pendenze/' . rawurlencode($idPendenza)) : '/pendenze';
        return [
            'template' => 'checkout/govpay-return.html.twig',
            'context' => [
                'detail_path'  => $detailPath,
                'login_path'   => '/login?return_to=' . rawurlencode($detailPath),
                'is_logged_in' => frontoffice_get_logged_user() !== null,
            ],
        ];
    },
    '/login' => static function () use ($env, $spidCallbackUrl, $frontofficeBaseUrl): array {
        if (!frontoffice_spid_enabled()) {
            http_response_code(404);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/login',
                ],
            ];
        }

        if (frontoffice_auth_proxy_type() === 'external-oidc') {
            $issuer = rtrim($env('EXTERNAL_OIDC_ISSUER', ''), '/');
            $clientId = trim($env('EXTERNAL_OIDC_CLIENT_ID', ''));
            
            if ($issuer === '' || $clientId === '') {
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Login OIDC esterno non configurato. Imposta EXTERNAL_OIDC_ISSUER e EXTERNAL_OIDC_CLIENT_ID.',
                    ],
                ];
            }

            try {
                $state = bin2hex(random_bytes(16));
                $codeVerifier = bin2hex(random_bytes(32));
            } catch (\Throwable $e) {
                $state = md5((string)microtime(true) . uniqid('', true));
                $codeVerifier = md5(uniqid('', true) . microtime(true));
            }

            $_SESSION['oidc_state'] = $state;
            $_SESSION['oidc_code_verifier'] = $codeVerifier;
            
            $challengeHash = hash('sha256', $codeVerifier, true);
            $codeChallenge = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($challengeHash));

            $returnTo = (string)($_GET['return_to'] ?? '/');
            if ($returnTo === '' || $returnTo[0] !== '/') {
                $returnTo = '/';
            }
            $_SESSION['spid_return_to'] = $returnTo;

            $redirectUri = rtrim($frontofficeBaseUrl, '/') . '/oidc/callback';
            
            $authUrl = $issuer . '/OIDC/authorization?' . http_build_query([
                'client_id'             => $clientId,
                'response_type'         => 'code',
                'scope'                 => 'openid profile email',
                'redirect_uri'          => $redirectUri,
                'state'                 => $state,
                'code_challenge'        => $codeChallenge,
                'code_challenge_method' => 'S256',
            ]);

            header('Location: ' . $authUrl, true, 302);
            exit;
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
            
            $spidCallbackPath = $env('FRONTOFFICE_SPID_CALLBACK_PATH', '/spid/callback');
            if ($spidCallbackPath === '' || $spidCallbackPath[0] !== '/') {
                $spidCallbackPath = '/' . ltrim($spidCallbackPath, '/');
            }

            $proxyBase = rtrim($env('IAM_PROXY_PUBLIC_BASE_URL', ''), '/');
            // Preferisci l'URL interno (per il container frontoffice -> auth-proxy-nginx:443)
            // Fallback all'URL esterno (per il browser -> auth-proxy-nginx:9445)
            $metadataUrl = $env('AUTH_PROXY_SAML2_IDP_METADATA_URL_INTERNAL', '');
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
                        'message' => 'Login Auth Proxy non configurato: imposta IAM_PROXY_PUBLIC_BASE_URL (o SPID_PROXY_PUBLIC_BASE_URL) e verifica IAM_PROXY_SAML2_IDP_METADATA_URL.',
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
                        'message' => 'Login Auth Proxy non disponibile: impossibile inizializzare SAML. Verifica metadata e dipendenze PHP-SAML.',
                    ],
                ];
            }

            // RelayState: usa disco page del proxy Auth
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
            // Preferisci l'URL interno (per il container frontoffice -> auth-proxy-nginx:443)
            // Fallback all'URL esterno (per il browser -> auth-proxy-nginx:9445)
            $metadataUrl = $env('AUTH_PROXY_SAML2_IDP_METADATA_URL_INTERNAL', '');
            if ($metadataUrl === '') {
                $metadataUrl = $env('IAM_PROXY_SAML2_IDP_METADATA_URL', '');
            }
            if ($metadataUrl === '' && $proxyBase !== '') {
                $metadataUrl = $proxyBase . '/Saml2IDP/metadata';
            }

            $debug = trim((string)$env('IAM_PROXY_DEBUG', '0')) === '1' || strtolower(trim((string)$env('IAM_PROXY_DEBUG', 'false'))) === 'true';
            $auth = frontoffice_satosa_saml_auth($frontofficeBaseUrl, $spidCallbackPath, $metadataUrl, $debug);
            if (!$auth) {
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Callback Auth Proxy non valida: SAML non inizializzabile (metadata o configurazione mancante).',
                        'debug_session_info' => $debugFromSessionStr,
                    ],
                ];
            }

            try {
                $auth->processResponse();
            } catch (SamlError $e) {
                Logger::getInstance()->warning('SAML error', ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                Logger::getInstance()->warning('Errore processResponse SAML', ['error' => $e->getMessage()]);
            }

            $errors = $auth->getErrors();
            $reason = $auth->getLastErrorReason();
            $shouldRetryWithFreshMetadata = !$auth->isAuthenticated()
                && !empty($errors)
                && in_array('invalid_response', $errors, true)
                && is_string($reason)
                && stripos($reason, 'Signature validation failed') !== false;

            if ($shouldRetryWithFreshMetadata) {
                $auth = frontoffice_satosa_saml_auth($frontofficeBaseUrl, $spidCallbackPath, $metadataUrl, $debug, true);
                if ($auth instanceof SamlAuth) {
                    try {
                        $auth->processResponse();
                    } catch (SamlError $e) {
                        Logger::getInstance()->warning('SAML error retry', ['error' => $e->getMessage()]);
                    } catch (\Throwable $e) {
                        Logger::getInstance()->warning('Errore processResponse SAML retry', ['error' => $e->getMessage()]);
                    }
                    $errors = $auth->getErrors();
                    $reason = $auth->getLastErrorReason();
                }
            }

            if (!empty($errors) || !$auth->isAuthenticated()) {
                http_response_code(503);
                return [
                    'template' => 'errors/503.html.twig',
                    'context' => [
                        'message' => 'Login Auth Proxy fallito: ' . ($reason ?: 'risposta SAML non valida.') ,
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

            $returnTo = (string)($_POST['RelayState'] ?? ($_SESSION['spid_return_to'] ?? '/'));
            unset($_SESSION['spid_return_to']);
            if ($returnTo === '' || $returnTo[0] !== '/') {
                $returnTo = '/';
            }

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
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
        unset($_SESSION['spid_state']);
        $returnTo = (string)($_SESSION['spid_return_to'] ?? '/');
        unset($_SESSION['spid_return_to']);
        if ($returnTo === '' || $returnTo[0] !== '/') {
            $returnTo = '/';
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        header('Location: ' . $returnTo, true, 302);
        exit;
    },
    '/oidc/callback' => static function () use ($env, $frontofficeBaseUrl): array {
        if (!frontoffice_spid_enabled() || frontoffice_auth_proxy_type() !== 'external-oidc') {
            http_response_code(404);
            return [
                'template' => 'errors/404.html.twig',
                'context' => [
                    'requested_path' => '/oidc/callback',
                ],
            ];
        }

        $state = (string)($_GET['state'] ?? '');
        $expectedState = (string)($_SESSION['oidc_state'] ?? '');
        if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            http_response_code(400);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'State OIDC non valido o sessione scaduta. Riprova ad accedere.',
                ],
            ];
        }
        unset($_SESSION['oidc_state']);

        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            http_response_code(400);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Codice di autorizzazione OIDC non fornito dal proxy.',
                ],
            ];
        }

        $issuer = rtrim($env('EXTERNAL_OIDC_ISSUER', ''), '/');
        $clientId = trim($env('EXTERNAL_OIDC_CLIENT_ID', ''));
        $clientSecret = trim($env('EXTERNAL_OIDC_CLIENT_SECRET', ''));
        $redirectUri = rtrim($frontofficeBaseUrl, '/') . '/oidc/callback';
        $codeVerifier = (string)($_SESSION['oidc_code_verifier'] ?? '');
        unset($_SESSION['oidc_code_verifier']);

        $tokenUrl = $issuer . '/OIDC/token';

        $postData = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];

        $urlHost = (string)(parse_url($tokenUrl, PHP_URL_HOST) ?: '');
        $insecureSsl = in_array(strtolower($urlHost), ['localhost', '127.0.0.1', '::1', 'auth-proxy-nginx', 'pa-sso-proxy', 'sso-proxy'], true);

        $res = '';
        $status = 0;
        $err = '';
        try {
            $client = new \GuzzleHttp\Client([
                'timeout'     => 10,
                'verify'      => !$insecureSsl,
                'http_errors' => false,
            ]);

            $response = $client->request('POST', $tokenUrl, [
                'auth'        => [$clientId, $clientSecret], // Client authentication basic
                'form_params' => $postData,
                'headers'     => [
                    'Accept' => 'application/json',
                ],
            ]);

            $status = $response->getStatusCode();
            $res = (string)$response->getBody();
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }

        if ($res === '' || $status < 200 || $status >= 300) {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Scambio del token OIDC fallito. HTTP status: ' . $status . ($err ? ' - Errore: ' . $err : '') . "\nRisposta: " . $res,
                ],
            ];
        }

        $tokenResponse = json_decode($res, true);
        Logger::getInstance()->info('OIDC token exchange response keys: ' . implode(', ', array_keys($tokenResponse ?: [])));
        $idToken = $tokenResponse['id_token'] ?? '';
        if ($idToken === '') {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Scambio del token OIDC fallito: la risposta non contiene un id_token valido.',
                ],
            ];
        }

        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'ID Token OIDC non valido (manca la sezione payload).',
                ],
            ];
        }

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
        Logger::getInstance()->info('OIDC ID Token Decoded Payload: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!is_array($payload)) {
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Impossibile decodificare il payload dell\'ID Token OIDC.',
                ],
            ];
        }

        // Recupero claims/attributi utente da UserInfo endpoint (standard OIDC per CIE e SPID se non inclusi nell'ID Token)
        $userinfo = [];
        $accessToken = $tokenResponse['access_token'] ?? '';
        if ($accessToken !== '') {
            $userinfoUrl = $issuer . '/OIDC/userinfo';
            try {
                $client = new \GuzzleHttp\Client([
                    'timeout'     => 10,
                    'verify'      => !$insecureSsl,
                    'http_errors' => false,
                ]);

                $response = $client->request('GET', $userinfoUrl, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Accept'        => 'application/json',
                    ],
                ]);

                $userinfoStatus = $response->getStatusCode();
                $userinfoRes = (string)$response->getBody();

                Logger::getInstance()->info('OIDC UserInfo HTTP status: ' . $userinfoStatus);
                if ($userinfoRes !== '' && $userinfoStatus >= 200 && $userinfoStatus < 300) {
                    Logger::getInstance()->info('OIDC UserInfo response: ' . $userinfoRes);
                    $userinfo = json_decode($userinfoRes, true) ?: [];
                } else {
                    Logger::getInstance()->warning('OIDC UserInfo failed', ['status' => $userinfoStatus, 'response' => $userinfoRes]);
                }
            } catch (\Throwable $e) {
                Logger::getInstance()->error('OIDC UserInfo request failed', [
                    'url'   => $userinfoUrl,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $claims = array_merge(is_array($payload) ? $payload : [], is_array($userinfo) ? $userinfo : []);

        $flatVal = static function ($val): string {
            if (is_array($val)) {
                $val = reset($val);
            }
            return is_scalar($val) ? trim((string)$val) : '';
        };

        $pickAttr = static function (array $attrs, array $keys) use ($flatVal): string {
            foreach ($keys as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '') {
                    $res = $flatVal($attrs[$k]);
                    if ($res !== '') {
                        return $res;
                    }
                }
            }
            return '';
        };

        $firstName = $pickAttr($claims, ['given_name', 'first_name', 'name', 'givenName']);
        $lastName = $pickAttr($claims, ['family_name', 'last_name', 'sn', 'surname', 'familyName']);
        $email = $pickAttr($claims, ['email', 'mail', 'emailAddress']);
        $fiscalNumber = $pickAttr($claims, [
            'fiscal_number',
            'https://attributes.eid.gov.it/fiscal_number',
            'https://attributes.spid.gov.it/fiscalNumber',
            'fiscalNumber',
            'fiscalCode',
            'codiceFiscale'
        ]);

        $spidCode = $flatVal($claims['spid_code'] ?? $claims['spidCode'] ?? '');
        $providerName = $flatVal($claims['provider_name'] ?? $claims['amr'][0] ?? $claims['amr'] ?? 'OIDC');
        $responseId = $flatVal($claims['jti'] ?? '');

        $user = [
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'email'         => $email,
            'fiscal_number' => frontoffice_normalize_fiscal_number($fiscalNumber),
            'spid_code'     => $spidCode,
            'provider_id'   => 'EXTERNAL_OIDC_PROXY',
            'provider_name' => $providerName,
            'response_id'   => $responseId,
        ];

        $_SESSION['frontoffice_user'] = $user;

        $returnTo = (string)($_SESSION['spid_return_to'] ?? '/');
        unset($_SESSION['spid_return_to']);
        if ($returnTo === '' || $returnTo[0] !== '/') {
            $returnTo = '/';
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
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
        $metadataUrl = $env('AUTH_PROXY_SAML2_IDP_METADATA_URL_INTERNAL', '');
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

        if (frontoffice_auth_proxy_type() === 'external-oidc') {
            $logoutUrl = trim($env('EXTERNAL_OIDC_LOGOUT_URL', ''));
            $clientId = trim($env('EXTERNAL_OIDC_CLIENT_ID', ''));
            if ($logoutUrl !== '') {
                $separator = str_contains($logoutUrl, '?') ? '&' : '?';
                $target = $logoutUrl
                    . $separator . 'client_id=' . rawurlencode($clientId)
                    . '&post_logout_redirect_uri=' . rawurlencode($redirectUri)
                    . '&redirect_uri=' . rawurlencode($redirectUri);
                header('Location: ' . $target, true, 302);
                exit;
            }
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

        // Risolvi slug → ID (se il parametro è uno slug e non un ID diretto)
        if ($selectedId !== '') {
            $isDirectId = frontoffice_find_service_option($serviceCatalog, $selectedId) !== null;
            if (!$isDirectId) {
                foreach ($serviceCatalog as $svc) {
                    if (($svc['slug'] ?? '') === $selectedId) {
                        $selectedId = $svc['id'];
                        break;
                    }
                }
            }
        }

        // Redirect BOLLOT al form dedicato
        if ($method === 'GET' && $selectedId !== '') {
            $bolloId = frontoffice_env_value('BOLLO_TIPO_PENDENZA', 'BOLLOT');
            if ($selectedId === $bolloId) {
                header('Location: /pagamento-bollo', true, 302);
                exit;
            }
        }

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
    '/pagamento-bollo' => static function () use ($method): array {
        $defaultYear = (int) date('Y');

        if ($method === 'POST') {
            $result = frontoffice_process_bollo_request($_POST, $_FILES);
            return [
                'template' => 'pagamenti/bollo.html.twig',
                'context'  => array_merge(['default_year' => $defaultYear], $result),
            ];
        }

        $baseContext = ['default_year' => $defaultYear, 'form_data' => []];

        // Precompila con dati SPID/CIE se loggato
        $loggedUser = frontoffice_get_logged_user();
        if (is_array($loggedUser) && $loggedUser !== []) {
            $fiscalNumber  = frontoffice_get_logged_user_fiscal_number();
            $fiscalCompact = strtoupper(preg_replace('/\s+/', '', trim((string) $fiscalNumber)));
            $fiscalDigits  = preg_replace('/\D+/', '', $fiscalCompact);
            $isLegalEntity = ($fiscalDigits !== '' && strlen($fiscalDigits) === 11);
            $defaultTipo   = $isLegalEntity ? 'G' : 'F';
            $defaultIdent  = $isLegalEntity ? $fiscalDigits : $fiscalCompact;
            $defaultNome   = trim((string)($loggedUser['first_name'] ?? ''));
            $defaultCognome = trim((string)($loggedUser['last_name'] ?? ''));
            $defaultEmail  = trim((string)($loggedUser['email'] ?? ''));
            $defaultAnagrafica = $defaultTipo === 'F'
                ? $defaultCognome
                : trim($defaultCognome ?: $defaultNome);
            if ($defaultTipo === 'G' && $defaultAnagrafica === '') {
                $defaultAnagrafica = $defaultEmail;
            }
            $baseContext['form_data']['soggettoPagatore'] = [
                'tipo'           => $defaultTipo,
                'identificativo' => $defaultIdent,
                'anagrafica'     => $defaultAnagrafica,
                'nome'           => $defaultTipo === 'F' ? $defaultNome : '',
                'email'          => $defaultEmail,
            ];
        }

        return [
            'template' => 'pagamenti/bollo.html.twig',
            'context'  => $baseContext,
        ];
    },
    '/pagamento-avviso' => static function () use ($method, $env): array {
        $payPortalUrl = $env('FRONTOFFICE_PAGOPA_CHECKOUT_URL', 'https://checkout.pagopa.it/');
        if ($method === 'POST') {
            // Rate limit per IP sul form pubblico.
            $clientIp = frontoffice_client_ip();
            if (!frontoffice_rate_limit_check('ip:' . $clientIp . ':pagamento-avviso', 10, 60)) {
                Logger::getInstance()->info('Rate limit superato su /pagamento-avviso', ['ip' => $clientIp]);
                return frontoffice_rate_limit_response();
            }

            // Rate limit per coppia CF+numeroAvviso (anti enumerazione).
            $cfRaw = strtoupper(preg_replace('/\s+/', '', (string)($_POST['codiceFiscale'] ?? '')) ?? '');
            $avvisoRaw = frontoffice_normalize_avviso_code((string)($_POST['codiceAvviso'] ?? ''));
            if ($cfRaw !== '' && $avvisoRaw !== '') {
                $pairKey = 'pair:' . hash('sha256', $cfRaw . '|' . $avvisoRaw);
                if (!frontoffice_rate_limit_check($pairKey, 5, 60)) {
                    Logger::getInstance()->info('Rate limit superato su coppia CF+avviso', ['ip' => $clientIp]);
                    return frontoffice_rate_limit_response();
                }
            }

            $result = frontoffice_process_avviso_form($_POST);
            if (!empty($result['success'])) {
                $preview = is_array($result['preview'] ?? null) ? $result['preview'] : [];
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
                    'pay_portal_url' => $payPortalUrl,
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
                'pay_portal_url' => $payPortalUrl,
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

        $idDominio = frontoffice_env_value('ID_DOMINIO', '');
        $statoRaw  = strtoupper(trim((string)($_GET['stato'] ?? '')));
        $allowedStates = ['ESEGUITA', 'NON_ESEGUITA', 'ESEGUITA_PARZIALE', 'ANNULLATA', 'SCADUTA', 'ANOMALA'];
        $stato = in_array($statoRaw, $allowedStates, true) ? $statoRaw : null;
        $idTipoPendenza = trim((string)($_GET['tipologia'] ?? ''));

        // Fetch internal typologies for the filter select dropdown
        $tipologieApiResult = frontoffice_backoffice_api('GET', '/api/frontoffice/tipologie');
        $tipologie = [];
        if ($tipologieApiResult['success']) {
            $tipologie = $tipologieApiResult['data'] ?? $tipologieApiResult['_raw']['tipologie'] ?? [];
        }

        $apiResult = frontoffice_backoffice_api('GET', '/api/frontoffice/pendenze', array_filter([
            'cf'             => $codiceFiscale,
            'page'           => $page,
            'per_page'       => $perPage,
            'stato'          => $stato ?? '',
            'idTipoPendenza' => $idTipoPendenza,
        ]));

        if (!$apiResult['success']) {
            Logger::getInstance()->warning('Errore ricerca pendenze via backoffice API', ['cf' => $codiceFiscale]);
            http_response_code(503);
            return [
                'template' => 'errors/503.html.twig',
                'context' => [
                    'message' => 'Al momento non riusciamo a interrogare il sistema dei pagamenti. Riprova più tardi.',
                ],
            ];
        }

        $data = $apiResult['_raw']['data'] ?? [];

        $risultati = $data['risultati'] ?? [];
        if (!is_array($risultati)) {
            $risultati = [];
        }

        $bollotTipo = frontoffice_env_value('BOLLO_TIPO_PENDENZA', 'BOLLOT');
        $rows = [];
        foreach ($risultati as $pendenza) {
            if (!is_array($pendenza)) {
                continue;
            }
            $state = strtoupper((string)($pendenza['stato'] ?? ''));
            $numeroAvviso = trim((string)($pendenza['numeroAvviso'] ?? ''));
            $rowIsBollo = strcasecmp((string)($pendenza['tipoPendenza']['idTipoPendenza'] ?? ''), $bollotTipo) === 0;
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
                    ? ($rowIsBollo
                        ? ('/avviso-bollo?' . http_build_query(array_filter([
                            'iuv'      => preg_replace('/\D/', '', $numeroAvviso),
                            'ente'     => $idDominio,
                            'importo'  => isset($pendenza['importo']) && is_numeric($pendenza['importo']) && (float)$pendenza['importo'] > 0 ? (int)round((float)$pendenza['importo'] * 100) : null,
                            'causale'  => ($pendenza['causale'] ?? '') !== '' ? mb_substr(trim((string)$pendenza['causale']), 0, 140) : null,
                            'cf'       => $codiceFiscale !== '' ? $codiceFiscale : null,
                            'scadenza' => ($pendenza['dataScadenza'] ?? '') !== '' ? $pendenza['dataScadenza'] : null,
                        ]), '', '&', PHP_QUERY_RFC3986))
                        : frontoffice_generate_pdf_link($codiceFiscale, $numeroAvviso))
                    : null,
                'receipt_url' => (frontoffice_is_pendenza_paid($state) && (string)($pendenza['idPendenza'] ?? '') !== '')
                    ? '/pendenze/' . rawurlencode((string)$pendenza['idPendenza']) . '/ricevuta'
                    : null,
                'detail_url' => (string)($pendenza['idPendenza'] ?? '') !== ''
                    ? '/pendenze/' . rawurlencode((string)$pendenza['idPendenza'])
                    : null,
                'is_bollo' => $rowIsBollo,
                'tipologia' => isset($pendenza['tipoPendenza']) && is_array($pendenza['tipoPendenza']) ? [
                    'id' => (string)($pendenza['tipoPendenza']['idTipoPendenza'] ?? $pendenza['tipoPendenza']['idTipo'] ?? $pendenza['tipoPendenza']['id'] ?? ''),
                    'descrizione' => trim((string)($pendenza['tipoPendenza']['descrizione'] ?? '')),
                ] : null,
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
                'tipologie' => $tipologie,
                'filters' => [
                    'stato' => $statoRaw,
                    'tipologia' => $idTipoPendenza,
                ],
                'pagination' => (static function () use ($data, $page, $perPage, $rows): array {
                    $meta       = is_array($data['metadatiPaginazione'] ?? null) ? $data['metadatiPaginazione'] : [];
                    $numPag     = (int)($data['numPagine'] ?? $meta['numPagine'] ?? 0);
                    $numRis     = (int)($data['numRisultati'] ?? $meta['numRisultati'] ?? $meta['num_risultati'] ?? 0);
                    $rpp        = (int)($data['risultatiPerPagina'] ?? $meta['risultatiPerPagina'] ?? $perPage);
                    $pag        = (int)($data['pagina'] ?? $meta['pagina'] ?? $page);
                    if ($rpp < 1) { $rpp = $perPage; }
                    if ($numPag > 0) {
                        $totalPages = $numPag;
                    } elseif ($numRis > 0) {
                        $totalPages = (int) max(1, (int) ceil($numRis / $rpp));
                    } else {
                        $totalPages = count($rows) >= $rpp ? $pag + 1 : $pag;
                    }
                    return [
                        'pagina'               => $pag,
                        'risultati_per_pagina' => $rpp,
                        'num_risultati'        => $numRis,
                        'total_pages'          => $totalPages,
                    ];
                })(),
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

    '/avviso-bollo' => static function (): array {
        $iuv      = preg_replace('/\D/', '', trim((string)($_GET['iuv'] ?? '')));
        $ente     = preg_replace('/[^A-Za-z0-9]/', '', trim((string)($_GET['ente'] ?? '')));
        $importo  = max(0, (int)($_GET['importo'] ?? 0));
        $causale  = mb_substr(trim((string)($_GET['causale'] ?? '')), 0, 140);
        $cfDeb    = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim((string)($_GET['cf'] ?? ''))));
        $scadenza = trim((string)($_GET['scadenza'] ?? ''));
        if ($scadenza !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $scadenza)) {
            try { $scadenza = (new \DateTime($scadenza))->format('d/m/Y'); } catch (\Throwable $e) {}
        }
        $desc     = array_values(array_filter(array_map(
            static fn($d) => mb_substr(trim((string)$d), 0, 200),
            (array)($_GET['desc'] ?? [])
        ), static fn($d) => $d !== ''));
        if ($iuv === '' || $ente === '') {
            http_response_code(400);
            return [
                'template' => 'errors/404.html.twig',
                'context'  => ['requested_path' => '/avviso-bollo'],
            ];
        }
        $qrString   = $importo > 0 ? ('PAGOPA|002|' . $iuv . '|' . $ente . '|' . $importo) : '';
        $importoEur = $importo > 0 ? number_format($importo / 100, 2, ',', '.') : '—';
        return [
            'template' => 'pagamenti/avviso-bollo.html.twig',
            'context'  => compact('iuv', 'ente', 'importo', 'importoEur', 'causale', 'cfDeb', 'scadenza', 'qrString', 'desc'),
        ];
    },
];


$routeDefinition = null;

if ($method === 'GET' && preg_match('#^/avvisi/([^/]+)/([^/]+)$#', $normalizedPath, $match)) {
    // Rate limit per IP — defense-in-depth contro enumerazione IUV
    if (!frontoffice_rate_limit_check('ip:' . frontoffice_client_ip() . ':avvisi', 20, 60)) {
        http_response_code(429);
        header('Retry-After: 60');
        echo 'Troppi tentativi. Riprova tra un minuto.';
        return;
    }
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
    $linkType = (string)($qp['type'] ?? '');
    if ($linkType !== '' && $linkType !== 'avviso') {
        http_response_code(403);
        echo 'Link non valido o scaduto.';
        return;
    }
    $cf = trim((string)($qp['cf'] ?? ''));
    $iuv = trim((string)($qp['iuv'] ?? ''));
    if ($cf === '' || $iuv === '') {
        http_response_code(400);
        echo 'Parametri mancanti.';
        return;
    }
    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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
    // Marca da bollo: GovPay non genera PDF avviso (ritorna 422).
    // Redirect al template HTML stampabile /avviso-bollo con dati dalla pendenza.
    if (frontoffice_is_bollo_detail($pendenza)) {
        $importoCents = (int)round((float)($pendenza['importo'] ?? 0) * 100);
        $causale      = mb_substr(trim((string)($pendenza['causale'] ?? '')), 0, 140);
        $scadenza     = trim((string)($pendenza['dataScadenza'] ?? ''));
        $params       = http_build_query(array_filter([
            'iuv'      => $iuv,
            'ente'     => $idDominio,
            'importo'  => $importoCents > 0 ? $importoCents : null,
            'causale'  => $causale !== '' ? $causale : null,
            'cf'       => $cf,
            'scadenza' => $scadenza !== '' ? $scadenza : null,
        ]), '', '&', PHP_QUERY_RFC3986);
        header('Location: /avviso-bollo?' . $params, true, 302);
        exit;
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
    $linkType = (string)($qp['type'] ?? '');
    if ($linkType !== '' && $linkType !== 'documento') {
        http_response_code(403);
        echo 'Link non valido o scaduto.';
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
    $linkType = (string)($qp['type'] ?? '');
    if ($linkType !== '' && $linkType !== 'ricevuta') {
        http_response_code(403);
        echo 'Link non valido o scaduto.';
        return;
    }
    $iuv = trim((string)($qp['iuv'] ?? ''));
    $iur = trim((string)($qp['iur'] ?? ''));
    if ($iuv === '' || $iur === '') {
        http_response_code(400);
        echo 'Parametri mancanti.';
        return;
    }
    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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
    $linkType = (string)($qp['type'] ?? '');
    if ($linkType !== '' && $linkType !== 'checkout') {
        http_response_code(403);
        echo 'Link non valido o scaduto.';
        return;
    }
    $cf = trim((string)($qp['cf'] ?? ''));
    $iuv = trim((string)($qp['iuv'] ?? ''));
    if ($cf === '' || $iuv === '') {
        http_response_code(400);
        echo 'Parametri mancanti.';
        return;
    }
    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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
    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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

    // Fallback: se Pagamenti API non disponibile usa dati dal carrello di sessione
    if (!$detail) {
        $cartItems = frontoffice_cart_items();
        if (isset($cartItems[$idPendenza]) && is_array($cartItems[$idPendenza])) {
            $ci = $cartItems[$idPendenza];
            $detail = [
                'idPendenza'      => $idPendenza,
                'stato'           => 'NON_ESEGUITA',
                'numeroAvviso'    => $ci['numeroAvviso'] ?? '',
                'importo'         => $ci['importo'] ?? 0,
                'causale'         => $ci['causale'] ?? '',
                'dataScadenza'    => $ci['data_scadenza'] ?? null,
                'soggettoPagatore'=> ($ci['email'] ?? '') !== '' ? ['email' => $ci['email']] : null,
            ];
        }
    }

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
            $cartItems = frontoffice_cart_items();
            // Per pendenze create in sessione senza CF loggato, permetti se in carrello
            if (!isset($cartItems[$idPendenza])) {
                http_response_code(404);
                echo 'Not found';
                return;
            }
        }
    } else {
        $key = 'frontoffice_spontaneo_pendenze';
        $list = isset($_SESSION[$key]) && is_array($_SESSION[$key]) ? $_SESSION[$key] : [];
        $allowed = in_array($idPendenza, array_map('strval', $list), true);
        // Fallback: accetta token HMAC firmato dal server (usato nei link email)
        if (!$allowed) {
            $t = trim((string)($_GET['t'] ?? ''));
            $allowed = frontoffice_verify_checkout_token($idPendenza, $t);
        }
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

    $okUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_OK_URL', ''));
    $cancelUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_CANCEL_URL', ''));
    $errorUrl  = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_ERROR_URL', ''));
    $_checkoutTok = frontoffice_generate_checkout_token($idPendenza);
    $_tokSuffix = $_checkoutTok !== '' ? ('&t=' . rawurlencode($_checkoutTok)) : '';
    if ($okUrl === '') {
        $okUrl = $frontofficeBaseUrl . '/checkout/ok?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix;
    }
    if ($cancelUrl === '') {
        $cancelUrl = $frontofficeBaseUrl . '/checkout/cancel?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix;
    }
    if ($errorUrl === '') {
        $errorUrl = $frontofficeBaseUrl . '/checkout/error?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix;
    }

    $emailNotice = '';
    if (isset($detail['soggettoPagatore']) && is_array($detail['soggettoPagatore'])) {
        $emailNotice = trim((string)($detail['soggettoPagatore']['email'] ?? ''));
    }
    if ($emailNotice === '' && is_array($loggedUser)) {
        $emailNotice = trim((string)($loggedUser['email'] ?? ''));
    }

    $bolloCheckout = frontoffice_resolve_bollo_checkout_url($detail, $idPendenza, $idDominio, $okUrl, $cancelUrl, $errorUrl, $emailNotice, $frontofficeBaseUrl, 'spontaneo');
    if (!($bolloCheckout['skip'] ?? false)) {
        if (isset($bolloCheckout['error_code'])) {
            http_response_code($bolloCheckout['error_code']);
            echo $bolloCheckout['error_msg'];
            return;
        }
        header('Location: ' . $bolloCheckout['location'], true, 302);
        exit;
    }

    $cartResult = frontoffice_backoffice_api('POST', '/api/frontoffice/carrello/checkout', [
        'notices'    => [['numeroAvviso' => $numeroAvviso, 'importo' => $amountEur, 'causale' => trim((string)($detail['causale'] ?? ''))]],
        'idDominio'  => $idDominio,
        'returnUrls' => ['ok' => $okUrl, 'cancel' => $cancelUrl, 'error' => $errorUrl],
        'emailNotice'=> $emailNotice,
    ]);

    if (!$cartResult['success']) {
        Logger::getInstance()->warning('Checkout spontaneo: errore backoffice sidecar', ['idPendenza' => $idPendenza, 'message' => $cartResult['message']]);
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }

    $location = trim((string)($cartResult['_raw']['location'] ?? ''));
    if ($location === '') {
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }

    header('Location: ' . $location, true, 302);
    exit;
}

if ($method === 'GET' && $normalizedPath === '/pagamento-avviso/checkout') {
    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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
        $cartItems = frontoffice_cart_items();
        if (isset($cartItems[$idPendenza]) && is_array($cartItems[$idPendenza])) {
            $ci = $cartItems[$idPendenza];
            $detail = [
                'idPendenza'      => $idPendenza,
                'stato'           => 'NON_ESEGUITA',
                'numeroAvviso'    => $ci['numeroAvviso'] ?? '',
                'importo'         => $ci['importo'] ?? 0,
                'causale'         => $ci['causale'] ?? '',
                'dataScadenza'    => $ci['data_scadenza'] ?? null,
                'soggettoPagatore'=> ($ci['email'] ?? '') !== '' ? ['email' => $ci['email']] : null,
            ];
        }
    }
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
            $cartItems = frontoffice_cart_items();
            if (!isset($cartItems[$idPendenza])) {
                http_response_code(404);
                echo 'Not found';
                return;
            }
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

    $okUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_OK_URL', ''));
    $cancelUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_CANCEL_URL', ''));
    $errorUrl  = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_ERROR_URL', ''));
    $_checkoutTok2 = frontoffice_generate_checkout_token($idPendenza);
    $_tokSuffix2 = $_checkoutTok2 !== '' ? ('&t=' . rawurlencode($_checkoutTok2)) : '';
    if ($okUrl === '') {
        $okUrl = $frontofficeBaseUrl . '/checkout/ok?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix2;
    }
    if ($cancelUrl === '') {
        $cancelUrl = $frontofficeBaseUrl . '/checkout/cancel?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix2;
    }
    if ($errorUrl === '') {
        $errorUrl = $frontofficeBaseUrl . '/checkout/error?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix2;
    }

    $emailNotice = '';
    if (isset($detail['soggettoPagatore']) && is_array($detail['soggettoPagatore'])) {
        $emailNotice = trim((string)($detail['soggettoPagatore']['email'] ?? ''));
    }
    if ($emailNotice === '' && is_array($loggedUser)) {
        $emailNotice = trim((string)($loggedUser['email'] ?? ''));
    }

    $bolloCheckout = frontoffice_resolve_bollo_checkout_url($detail, $idPendenza, $idDominio, $okUrl, $cancelUrl, $errorUrl, $emailNotice, $frontofficeBaseUrl, 'avviso');
    if (!($bolloCheckout['skip'] ?? false)) {
        if (isset($bolloCheckout['error_code'])) {
            http_response_code($bolloCheckout['error_code']);
            echo $bolloCheckout['error_msg'];
            return;
        }
        header('Location: ' . $bolloCheckout['location'], true, 302);
        exit;
    }

    $cartResult = frontoffice_backoffice_api('POST', '/api/frontoffice/carrello/checkout', [
        'notices'    => [['numeroAvviso' => $numeroAvviso, 'importo' => $amountEur, 'causale' => trim((string)($detail['causale'] ?? ''))]],
        'idDominio'  => $idDominio,
        'returnUrls' => ['ok' => $okUrl, 'cancel' => $cancelUrl, 'error' => $errorUrl],
        'emailNotice'=> $emailNotice,
    ]);

    if (!$cartResult['success']) {
        Logger::getInstance()->warning('Checkout avviso: errore backoffice sidecar', ['idPendenza' => $idPendenza, 'message' => $cartResult['message']]);
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }

    $location = trim((string)($cartResult['_raw']['location'] ?? ''));
    if ($location === '') {
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }

    header('Location: ' . $location, true, 302);
    exit;
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

    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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

    if (frontoffice_env_value('PAGOPA_CHECKOUT_CONFIGURED', '0') !== '1') {
        http_response_code(503);
        echo 'Checkout pagoPA non configurato.';
        Logger::getInstance()->warning('Checkout pagoPA non configurato');
        return;
    }

    $okUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_OK_URL', ''));
    $cancelUrl = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_CANCEL_URL', ''));
    $errorUrl  = trim(frontoffice_env_value('PAGOPA_CHECKOUT_RETURN_ERROR_URL', ''));
    $_checkoutTok3 = frontoffice_generate_checkout_token($idPendenza);
    $_tokSuffix3 = $_checkoutTok3 !== '' ? ('&t=' . rawurlencode($_checkoutTok3)) : '';
    if ($okUrl === '') {
        $okUrl = $frontofficeBaseUrl . '/checkout/ok?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix3;
    }
    if ($cancelUrl === '') {
        $cancelUrl = $frontofficeBaseUrl . '/checkout/cancel?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix3;
    }
    if ($errorUrl === '') {
        $errorUrl = $frontofficeBaseUrl . '/checkout/error?idPendenza=' . rawurlencode($idPendenza) . $_tokSuffix3;
    }

    $emailNotice  = trim((string)($user['email'] ?? ''));
    $bolloCheckout = frontoffice_resolve_bollo_checkout_url($detail, $idPendenza, $idDominio, $okUrl, $cancelUrl, $errorUrl, $emailNotice, $frontofficeBaseUrl, 'profilo');
    if (!($bolloCheckout['skip'] ?? false)) {
        if (isset($bolloCheckout['error_code'])) {
            http_response_code($bolloCheckout['error_code']);
            echo $bolloCheckout['error_msg'];
            return;
        }
        header('Location: ' . $bolloCheckout['location'], true, 302);
        exit;
    }

    $cartResult = frontoffice_backoffice_api('POST', '/api/frontoffice/carrello/checkout', [
        'notices'    => [['numeroAvviso' => $numeroAvviso, 'importo' => $amountEur, 'causale' => trim((string)($detail['causale'] ?? ''))]],
        'idDominio'  => $idDominio,
        'returnUrls' => ['ok' => $okUrl, 'cancel' => $cancelUrl, 'error' => $errorUrl],
        'emailNotice'=> $emailNotice,
    ]);

    if (!$cartResult['success']) {
        Logger::getInstance()->warning('Checkout pendenza/profilo: errore backoffice sidecar', ['idPendenza' => $idPendenza, 'message' => $cartResult['message']]);
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }

    $location = trim((string)($cartResult['_raw']['location'] ?? ''));
    if ($location === '') {
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }

    header('Location: ' . $location, true, 302);
    exit;
}

// ─── POST /carrello/checkout ─────────────────────────────────────────────────
// Multi-pendenza cart checkout via PagoPA Checkout EC API (POST /carts).
// Disponibile anche per utenti non loggati (guest); l'autorizzazione si basa
// sulla whitelist di sessione. Se l'utente è loggato si verifica anche il CF.
if ($method === 'POST' && $normalizedPath === '/carrello/checkout') {
    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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
            $cartItems = frontoffice_cart_items();
            if (isset($cartItems[$pid]) && is_array($cartItems[$pid])) {
                $ci = $cartItems[$pid];
                $detail = [
                    'idPendenza'      => $pid,
                    'stato'           => 'NON_ESEGUITA',
                    'numeroAvviso'    => $ci['numeroAvviso'] ?? '',
                    'importo'         => $ci['importo'] ?? 0,
                    'causale'         => $ci['causale'] ?? '',
                    'dataScadenza'    => $ci['data_scadenza'] ?? null,
                    'soggettoPagatore'=> ($ci['email'] ?? '') !== '' ? ['email' => $ci['email']] : null,
                ];
            }
        }
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

    // Get email for notice: logged user first, then payer email from first resolved pendenza.
    $emailNotice = $loggedUser !== null ? trim((string)($loggedUser['email'] ?? '')) : '';
    if ($emailNotice === '') {
        foreach ($pendenzaDetails as $pd) {
            $candidate = trim((string)($pd['soggettoPagatore']['email'] ?? ''));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $emailNotice = $candidate;
                break;
            }
        }
    }

    $bolloDetails = array_values(array_filter($pendenzaDetails, static fn (array $d): bool => frontoffice_is_bollo_detail($d)));
    if (count($bolloDetails) > 0) {
        if (count($pendenzaDetails) !== 1) {
            http_response_code(422);
            echo 'Le pendenze Marca da Bollo devono essere pagate singolarmente.';
            return;
        }

        $bolloPendenzaId = (string)($bolloDetails[0]['idPendenza'] ?? $rawIds[0] ?? '');
        $_bolloCTok = frontoffice_generate_checkout_token($bolloPendenzaId);
        $_bolloTS   = $_bolloCTok !== '' ? ('&t=' . rawurlencode($_bolloCTok)) : '';
        $okUrl     = $frontofficeBaseUrl . '/checkout/ok?idPendenza=' . rawurlencode($bolloPendenzaId) . $_bolloTS;
        $cancelUrl = $frontofficeBaseUrl . '/checkout/cancel?idPendenza=' . rawurlencode($bolloPendenzaId) . $_bolloTS;
        $errorUrl  = $frontofficeBaseUrl . '/checkout/error?idPendenza=' . rawurlencode($bolloPendenzaId) . $_bolloTS;
        $bolloCheckout = frontoffice_resolve_bollo_checkout_url($bolloDetails[0], $bolloPendenzaId, $idDominio, $okUrl, $cancelUrl, $errorUrl, $emailNotice, $frontofficeBaseUrl, 'carrello');
        if (!($bolloCheckout['skip'] ?? false)) {
            if (isset($bolloCheckout['error_code'])) {
                http_response_code($bolloCheckout['error_code']);
                echo $bolloCheckout['error_msg'];
                return;
            }
            header('Location: ' . $bolloCheckout['location'], true, 302);
            exit;
        }
    }

    // Costruisce i notice da inviare al backoffice sidecar
    $notices = [];
    foreach ($pendenzaDetails as $pd) {
        $notices[] = [
            'numeroAvviso' => preg_replace('/\D+/', '', trim((string)($pd['numeroAvviso'] ?? ''))),
            'importo'      => $pd['importo'] ?? 0,
            'causale'      => trim((string)($pd['causale'] ?? '')),
        ];
    }

    $cartResult = frontoffice_backoffice_api('POST', '/api/frontoffice/carrello/checkout', [
        'notices'     => $notices,
        'idDominio'   => $idDominio,
        'returnUrls'  => ['ok' => $okUrl, 'cancel' => $cancelUrl, 'error' => $errorUrl],
        'emailNotice' => $emailNotice,
    ]);

    if (!$cartResult['success']) {
        Logger::getInstance()->warning('Carrello: risposta errore dal backoffice API', [
            'idCart'  => $idCart,
            'message' => $cartResult['message'],
        ]);
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }

    $location = trim((string)($cartResult['_raw']['location'] ?? ''));
    if ($location === '') {
        http_response_code(503);
        echo 'Al momento non riusciamo ad avviare il pagamento. Riprova più tardi.';
        return;
    }

    Logger::getInstance()->info('Carrello pagoPA avviato via backoffice sidecar', [
        'idCart'   => $idCart,
        'count'    => count($pendenzaDetails),
        'pendenze' => $rawIds,
    ]);

    header('Location: ' . $location, true, 302);
    exit;
}
// ─────────────────────────────────────────────────────────────────────────────


if ($method === 'GET' && $normalizedPath === '/ricevuta/pubblica') {
    // Rate limit per IP sull'endpoint pubblico ricevuta.
    $clientIp = frontoffice_client_ip();
    if (!frontoffice_rate_limit_check('ip:' . $clientIp . ':ricevuta', 10, 60)) {
        Logger::getInstance()->info('Rate limit superato su /ricevuta/pubblica', ['ip' => $clientIp]);
        header('Retry-After: 60');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Troppi tentativi</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:42rem;margin:4rem auto;padding:0 1rem;color:#1a1a1a}</style>'
            . '</head><body><h1>Troppi tentativi</h1>'
            . '<p>Per la tua sicurezza abbiamo temporaneamente limitato le richieste da questa connessione. '
            . 'Riprova tra qualche minuto.</p></body></html>';
        return;
    }

    $idPendenzaParam = trim((string)($_GET['id'] ?? ''));
    $expParam = (int)($_GET['exp'] ?? 0);
    $tokenParam = trim((string)($_GET['t'] ?? ''));

    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
    if ($idDominio === '' || $idPendenzaParam === '' || $expParam <= 0 || $tokenParam === '') {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    if ($expParam < time()) {
        http_response_code(410);
        echo 'Link scaduto.';
        return;
    }

    $detail = frontoffice_fetch_pagamenti_detail($idPendenzaParam);
    if (!$detail) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $cfDebitore = frontoffice_extract_pendenza_debtor_cf($detail);
    if ($cfDebitore === '' || !frontoffice_verify_receipt_token($idDominio, $idPendenzaParam, $cfDebitore, $expParam, $tokenParam)) {
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

    frontoffice_stream_ricevuta_by_pendenza($idPendenzaParam);
    return;
}

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

    $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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

    frontoffice_stream_ricevuta_by_pendenza($idPendenza);
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
                $idDominio = frontoffice_env_value('ID_DOMINIO', '');
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
        if (frontoffice_is_bollo_detail($detail)) {
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

    // Fast-path: reject before any API call if cart already full
    if (count(frontoffice_cart_items()) >= 5) {
        echo json_encode(['error' => 'Puoi aggiungere al massimo 5 avvisi al carrello.'], JSON_UNESCAPED_UNICODE);
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

    if (frontoffice_is_bollo_detail($detail)) {
        http_response_code(422);
        echo json_encode(['error' => 'Il carrello non è disponibile per la Marca da Bollo.'], JSON_UNESCAPED_UNICODE);
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
            'can_checkout' => count($rows) > 0 && frontoffice_env_value('PAGOPA_CHECKOUT_CONFIGURED', '0') === '1',
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
    // Rilasciamo il lock di sessione per le richieste GET (sola lettura) lente,
    // escludendo i flussi critici di login/auth.
    $bypassPaths = ['/login', '/logout', '/spid/callback', '/saml/sp'];
    if ($method === 'GET' && !in_array($normalizedPath, $bypassPaths, true)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
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
$currentLocale = $_SESSION['locale'] ?? 'it';
if (!in_array($currentLocale, $supportedLocales, true)) {
    $currentLocale = 'it';
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


$versionInfo = \App\Config\Config::getVersionInfo();

$bollotAttivo  = false;
$bollotIdTipo  = frontoffice_env_value('BOLLO_TIPO_PENDENZA', 'BOLLOT') ?: 'BOLLOT';
$tipologieResp = frontoffice_backoffice_api('GET', '/api/frontoffice/tipologie');
foreach ((array)($tipologieResp['_raw']['tipologie'] ?? []) as $_row) {
    if (is_array($_row) && (string)($_row['id_entrata'] ?? '') === $bollotIdTipo && (int)($_row['abilitato_backoffice'] ?? 0) === 1) {
        $bollotAttivo = true;
        break;
    }
}

$baseContext = [
    'csrf_token' => frontoffice_csrf_token(),
    'bollot_attivo' => $bollotAttivo,
    'app_entity' => [
        'name' => $entityName,
        'suffix' => $entitySuffix,
        'government' => $entityGovernment,
        'full' => $entityFull,
        'website' => $entityWebsite,
    ],
    'app_logo' => $appLogo,
    'app_favicon' => $appFavicon,
    'current_user' => frontoffice_get_logged_user(),
    'spid_enabled' => frontoffice_spid_enabled(),
    'spid_mode' => frontoffice_spid_mode(),
    'support_email' => $supportEmail,
    'support_phone' => $env('APP_SUPPORT_PHONE', '800.000.000'),
    'support_hours' => $env('APP_SUPPORT_HOURS', 'Lun-Ven 9:00-18:00'),
    'support_location' => $env('APP_SUPPORT_LOCATION', 'Via Roma 1, 00100 Roma (RM)'),
    'cart_count' => frontoffice_cart_count(),
    'cart_items_ids' => array_keys(frontoffice_cart_items()),
    'bollo_cart_enabled' => true,
    'current_locale' => $currentLocale,
    'app_version' => $versionInfo['version'],
    'app_commit' => $versionInfo['commit'],
    'app_version_type' => $versionInfo['version_type'],
    'app_version_label' => $versionInfo['version_label'],
    'app_ref_url' => $versionInfo['ref_url'],
    'app_repo_url' => \App\Config\Config::getRepositoryUrl(),
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
    // Courtesy pages must be HTTP 200 to prevent upstream reverse proxies
    // from replacing our branded error templates.
    if (!empty($route['template']) && is_string($route['template']) && str_starts_with($route['template'], 'errors/')) {
        http_response_code(200);
    }
    echo $twig->render($route['template'], $context);
}
