<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use App\Config\SettingsRepository;
use App\Config\ConfigLoader;
use App\Database\Connection;
use App\Logger;
use App\Controllers\ConfigurazioneController;

/**
 * Gestisce il pannello Impostazioni (/impostazioni) con le sezioni:
 *   - GovPay API
 *   - API Esterne (pagoPA, BizEvents)
 *   - Backoffice (mail, dati ente, supporto)
 *   - Frontoffice (URL, auth proxy OIDC esterno, logo/favicon)
 *
 * Accesso: superadmin e admin (solo lettura per admin, scrittura per superadmin).
 */
class ImpostazioniController
{
    // Path dei volumi montati sul container backoffice
    private const INTERNAL_SPID_METADATA_PATH = '/var/www/html/metadata-sp/frontoffice_sp.xml';
    private const PUBLIC_SPID_METADATA_PATH   = '/var/www/html/metadata-agid/satosa_spid_public_metadata.xml';
    private const CIE_METADATA_PATH           = '/var/www/html/metadata-cieoidc/entity-configuration.json';
    private const SPID_CERTS_DIR              = '/var/www/html/spid-certs';
    private const CIEOIDC_KEYS_DIR            = '/var/www/html/cieoidc-keys';
    private const CIEOIDC_META_DIR            = '/var/www/html/metadata-cieoidc';
    private const SPID_PUBLIC_META_DIR        = '/var/www/html/metadata-agid';

    public function __construct(private readonly Twig $twig)
    {
    }

    // ──────────────────────────────────────────────────────────────────────
    // FRONTOFFICE CONFIG API (sidecar)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * GET /api/frontoffice/config
     *
     * Restituisce le variabili di configurazione necessarie al frontoffice
     * (che non ha accesso diretto al DB). Il frontoffice le inietta in $_ENV
     * al bootstrap e le legge via frontoffice_env_value().
     *
     * Endpoint pubblico (auth: Bearer MASTER_TOKEN).
     */
    public function getFrontofficeConfig(Request $request, Response $response): Response
    {
        $cfg = \App\Config\Config::class;

        // Legge tutte le sezioni rilevanti dal DB una volta sola
        $fo      = SettingsRepository::getSection('frontoffice');
        $entity  = SettingsRepository::getSection('entity');
        $govpay  = SettingsRepository::getSection('govpay');
        $pagopa  = SettingsRepository::getSection('pagopa');
        $bo      = SettingsRepository::getSection('backoffice');
        $ui      = SettingsRepository::getSection('ui');

        // Helper: legge un valore decifrato dalla sezione se necessario
        $plain = static function (mixed $v): string {
            if (is_array($v) && isset($v['value'])) {
                return (string)$v['value'];
            }
            return (string)($v ?? '');
        };

        $authProxyType = strtolower(trim($plain($fo['auth_proxy_type'] ?? 'none')));

        $config = [
            // Auth
            'FRONTOFFICE_AUTH_PROXY_TYPE'       => $authProxyType,
            'EXTERNAL_OIDC_ISSUER'              => $plain($fo['external_oidc_issuer'] ?? ''),
            'EXTERNAL_OIDC_CLIENT_ID'           => $plain($fo['external_oidc_client_id'] ?? ''),
            'EXTERNAL_OIDC_CLIENT_SECRET'       => $plain($fo['external_oidc_client_secret'] ?? ''),
            'EXTERNAL_OIDC_LOGOUT_URL'          => $plain($fo['external_oidc_logout_url'] ?? ''),

            // Entity
            'ID_DOMINIO'                        => $plain($entity['id_dominio'] ?? ''),
            'APP_ENTITY_NAME'                   => $plain($entity['name'] ?? ''),
            'APP_ENTITY_SUFFIX'                 => $plain($entity['suffix'] ?? ''),

            // GovPay
            'GOVPAY_BACKOFFICE_URL'             => $plain($govpay['backoffice_url'] ?? ''),
            'GOVPAY_CHECKOUT_URL'               => $plain($govpay['checkout_url'] ?? ''),
            'GOVPAY_CHECKOUT_ENABLED'           => ($plain($govpay['checkout_enabled'] ?? '0') === '1') ? '1' : '0',
            'AUTHENTICATION_GOVPAY'             => $plain($govpay['authentication_method'] ?? 'none'),

            // pagoPA
            'PAGOPA_CHECKOUT_EC_BASE_URL'       => $plain($pagopa['checkout_ec_base_url'] ?? ''),
            'PAGOPA_CHECKOUT_SUBSCRIPTION_KEY'  => $plain($pagopa['checkout_subscription_key'] ?? ''),
            'PAGOPA_CHECKOUT_COMPANY_NAME'      => $plain($pagopa['checkout_company_name'] ?? ''),
            'PAGOPA_CHECKOUT_RETURN_OK_URL'     => $plain($pagopa['checkout_return_ok_url'] ?? ''),
            'PAGOPA_CHECKOUT_RETURN_CANCEL_URL' => $plain($pagopa['checkout_return_cancel_url'] ?? ''),
            'PAGOPA_CHECKOUT_RETURN_ERROR_URL'  => $plain($pagopa['checkout_return_error_url'] ?? ''),
            'PAGOPA_CHECKOUT_CONFIGURED'        => ($plain($pagopa['checkout_configured'] ?? '0') === '1') ? '1' : '0',
            'PAGOPA_EBOLLO_ENABLED'             => ($plain($pagopa['ebollo_enabled'] ?? '0') === '1') ? '1' : '0',
            'PAGOPA_EBOLLO_MODE'                => $plain($pagopa['ebollo_mode'] ?? 'legacy'),
            'BIZ_EVENTS_HOST'                   => $plain($pagopa['biz_events_host'] ?? ''),
            'BIZ_EVENTS_API_KEY'                => $plain($pagopa['biz_events_api_key'] ?? ''),

            // Frontoffice
            'FRONTOFFICE_PUBLIC_BASE_URL'       => $plain($fo['public_base_url'] ?? ''),
            'FEATURED_SERVICES'                 => $plain($fo['featured_services'] ?? '[]'),
            'SUPPORT_LOCATION'                  => $plain($bo['support_location'] ?? ''),
            'BOLLO_TIPO_PENDENZA'               => $plain($ui['bollo_tipo_pendenza'] ?? 'BOLLOT'),
            'AUX_DIGIT'                         => $plain($entity['aux_digit'] ?? ''),
            'TRUSTED_PROXIES'                   => $plain($bo['trusted_proxies'] ?? ''),
        ];

        // Rimuovi valori vuoti per non sovrascrivere variabili già presenti in $_ENV nel container
        $config = array_filter($config, static fn($v) => $v !== '');

        $body = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response->getBody()->write((string)$body);
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────────
    // INDEX
    // ──────────────────────────────────────────────────────────────────────

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();

        $tab = $request->getQueryParams()['tab'] ?? 'generale';

        $data = [
            'active_tab'   => $tab,
            'is_superadmin' => $this->isSuperadmin(),
            'govpay'       => SettingsRepository::getSection('govpay'),
            'pagopa'       => SettingsRepository::getSection('pagopa'),
            'backoffice'   => SettingsRepository::getSection('backoffice'),
            'frontoffice'  => SettingsRepository::getSection('frontoffice'),
            'entity'       => SettingsRepository::getSection('entity'),

            'ui'           => SettingsRepository::getSection('ui'),
            'app'          => SettingsRepository::getSection('app'),
            'csrf_token'   => $this->generateCsrf(),
        ];

        // Tab che appartengono a /configurazione: carica i dati necessari
        $confTabs = ['dominio','tassonomie','tipologie','tipologie_esterne','gestionali',
                     'templates','servizi_io','utenti','operatori','applicazioni','confapi','info','logs',
                     'gruppi-utenti'];
        if (in_array($tab, $confTabs, true)) {
            $confCtrl = new ConfigurazioneController($this->twig);
            $data = array_merge($data, $confCtrl->getTabData($tab, $request));
        }

        // Tab Cron: lista job e storico esecuzioni
        if ($tab === 'cron' && $this->isSuperadmin()) {
            $data['jobs'] = CronController::getJobs();
            $daemonStatus = [];
            foreach ($data['jobs'] as $key => $job) {
                if ($job['daemon'] ?? false) {
                    $daemonStatus[$key] = CronController::isDaemonRunning($key);
                }
            }
            $data['daemon_status'] = $daemonStatus;
            $data['ragioneria_scan_da'] = SettingsRepository::get(
                'backoffice',
                'ragioneria_scan_da',
                date('Y-01-01', strtotime('-1 year'))
            );
        }

        // Tab Info GIL: stato container e info sistema
        if ($tab === 'info-gil') {
            $gilInfo = [
                'version' => \App\Config\Config::getVersion(),
                'php'     => phpversion(),
                'os'      => php_uname('s') . ' ' . php_uname('r'),
            ];
            $data['gil_info'] = $gilInfo;
        }

        // Tab Frontoffice: service catalog for featured services
        if ($tab === 'frontoffice') {
            $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
            $foCatalog = [];
            if ($idDominio !== '') {
                try {
                    $repo = new \App\Database\EntrateRepository();
                    $rows = $repo->listByDominio($idDominio);
                    foreach ($rows as $row) {
                        if ((int)($row['abilitato_backoffice'] ?? 0) !== 1) { continue; }
                        $id = (string)($row['id_entrata'] ?? '');
                        if ($id === '') { continue; }
                        $label = trim((string)($row['descrizione_effettiva'] ?? $row['descrizione'] ?? $id));
                        $foCatalog[] = ['id' => $id, 'label' => $label !== '' ? $label : $id];
                    }
                } catch (\Throwable $e) {}
            }
            $data['service_catalog']   = $foCatalog;
            $featuredRaw               = SettingsRepository::get('frontoffice', 'featured_services', '[]');
            $data['frontoffice_featured'] = json_decode($featuredRaw ?: '[]', true) ?: [];
        }

        // Tab IBAN: carica lista conti di accredito da GovPay
        if ($tab === 'iban') {
            $data['iban_list']  = [];
            $data['iban_json']  = null;
            $data['iban_error'] = null;
            $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
            $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
            if ($idDominio === '') {
                $data['iban_error'] = 'ID Dominio non configurato.';
            } elseif ($backofficeUrl === '') {
                $data['iban_error'] = 'GovPay Backoffice URL non configurato.';
            } elseif (!class_exists('GovPay\Backoffice\Api\EntiCreditoriApi')) {
                $data['iban_error'] = 'Client GovPay Backoffice non disponibile.';
            } else {
                try {
                    $cfg = new \GovPay\Backoffice\Configuration();
                    $cfg->setHost(rtrim($backofficeUrl, '/'));
                    $this->applyGovpayCredentials($cfg);
                    $api = new \GovPay\Backoffice\Api\EntiCreditoriApi($this->buildGovpayHttpClient(), $cfg);
                    $result = $api->findContiAccredito($idDominio, 1, 100);
                    $raw = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($result);
                    $arr = is_array($raw) ? $raw : (json_decode(json_encode($raw, JSON_UNESCAPED_SLASHES), true) ?: []);
                    $data['iban_list'] = is_array($arr['risultati'] ?? null) ? $arr['risultati'] : [];

                    // Conteggio tipologie associate (primarie e secondarie)
                    $primaryCounts = [];
                    $secondaryCounts = [];
                    try {
                        $entrateRepo = new \App\Database\EntrateRepository();
                        $tipologie = $entrateRepo->listByDominio($idDominio);
                        foreach ($tipologie as $t) {
                            $primaryIban = isset($t['iban_accredito']) ? strtoupper(trim((string)$t['iban_accredito'])) : '';
                            if ($primaryIban !== '' && $primaryIban !== '-') {
                                $primaryCounts[$primaryIban] = ($primaryCounts[$primaryIban] ?? 0) + 1;
                            }
                            $secondaryIban = isset($t['iban_appoggio']) ? strtoupper(trim((string)$t['iban_appoggio'])) : '';
                            if ($secondaryIban !== '' && $secondaryIban !== '-') {
                                $secondaryCounts[$secondaryIban] = ($secondaryCounts[$secondaryIban] ?? 0) + 1;
                            }
                        }
                    } catch (\Throwable $_) {}

                    foreach ($data['iban_list'] as &$row) {
                        $iban = isset($row['iban']) ? strtoupper(trim((string)$row['iban'])) : '';
                        $row['num_primarie'] = $primaryCounts[$iban] ?? 0;
                        $row['num_secondarie'] = $secondaryCounts[$iban] ?? 0;
                    }
                    unset($row);

                    $data['iban_json'] = json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } catch (\GovPay\Backoffice\ApiException $e) {
                    $data['iban_error'] = 'GovPay HTTP ' . $e->getCode() . ': ' . $this->govpayErrorDetail($e->getResponseBody());
                } catch (\Throwable $e) {
                    $data['iban_error'] = 'Errore: ' . $e->getMessage();
                }
            }
        }

        // Tab Rendicontazione: impostazioni motore + regole riconoscimento gestionali esterni
        if ($tab === 'rendicontazione') {
            $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
            $repo = new \App\Database\RendicontazioneRepository();
            $data['iuv_prefix_gil']          = SettingsRepository::get('rendicontazione', 'iuv_prefix_gil', 'GIL');
            $data['scan_interval_minuti']    = SettingsRepository::get('rendicontazione', 'scan_interval_minuti', '15');
            $data['scansioni_quiete_soglia'] = SettingsRepository::get('rendicontazione', 'scansioni_quiete_soglia', '3');
            $data['max_giorni_retry']        = SettingsRepository::get('rendicontazione', 'max_giorni_retry', '14');
            $data['geri_max_tentativi']      = SettingsRepository::get('rendicontazione', 'geri_max_tentativi', '3');
            $data['notifica_admin_auto']     = SettingsRepository::get('rendicontazione', 'notifica_admin_auto', 'false');
            $data['admin_emails']            = SettingsRepository::get('rendicontazione', 'admin_emails', '');
            $data['bridge_url']              = SettingsRepository::get('rendicontazione', 'bridge_url', '');
            $data['regole_esterne']          = $repo->getRegoleEsterne($idDominio);

            // Il tab posta verso RendicontazioneController, che valida il CSRF contro
            // $_SESSION['rendicontazione_csrf'] (non 'impostazioni_csrf'): il token esposto
            // al template deve provenire dalla stessa sessione scoped, altrimenti la
            // validazione fallisce sempre.
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            if (empty($_SESSION['rendicontazione_csrf'])) {
                $_SESSION['rendicontazione_csrf'] = bin2hex(random_bytes(32));
            }
            $data['csrf_token'] = (string)$_SESSION['rendicontazione_csrf'];
        }

        $data['ssl_on'] = (strtolower((string)(getenv('SSL') ?: 'off')) === 'on');

        return $this->twig->render($response, 'impostazioni/index.html.twig', $data);
    }

    // ──────────────────────────────────────────────────────────────────────
    // SAVE ACTIONS
    // ──────────────────────────────────────────────────────────────────────

    public function saveGenerale(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $by = $this->currentUser();
        SettingsRepository::setSection('entity', [
            'ipa_code'         => $body['entity_ipa_code'] ?? '',
            'name'             => $body['entity_name'] ?? '',
            'suffix'           => $body['entity_suffix'] ?? '',
            'government'       => $body['entity_government'] ?? '',
            'url'              => $body['entity_url'] ?? '',
            'id_dominio'       => $body['id_dominio'] ?? '',
            'id_a2a'           => $body['id_a2a'] ?? '',
            'support_email'    => $body['support_email'] ?? '',
            'support_phone'    => $body['support_phone'] ?? '',
            'support_hours'    => $body['support_hours'] ?? '',
            'support_location' => $body['support_location'] ?? '',
        ], $by);

        return $this->jsonOk('Dati ente salvati.');
    }

    public function saveGovpay(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $by = $this->currentUser();
        SettingsRepository::setSection('govpay', [
            'pendenze_url'          => $body['pendenze_url'] ?? '',
            'pagamenti_url'         => $body['pagamenti_url'] ?? '',
            'ragioneria_url'        => $body['ragioneria_url'] ?? '',
            'backoffice_url'        => $body['backoffice_url'] ?? '',
            'pendenze_patch_url'    => $body['pendenze_patch_url'] ?? '',
            'checkout_url'          => $body['checkout_url'] ?? '',
            'authentication_method' => $body['authentication_method'] ?? 'basic',
            'user'                  => ['value' => $body['user'] ?? '', 'encrypted' => true],
            'password'              => ['value' => $body['password'] ?? '', 'encrypted' => true],
        ], $by);

        return $this->jsonOk('Impostazioni GovPay salvate.');
    }

    public function saveApiEsterne(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $by = $this->currentUser();

        // Merge parziale: legge i valori esistenti e aggiorna solo le chiavi presenti
        // nella richiesta — così ogni sub-tab può salvare solo i propri campi senza
        // azzerare quelli degli altri tab API.
        $existing = SettingsRepository::getSection('pagopa');

        $plainKeys = [
            'checkout_ec_base_url', 'checkout_company_name',
            'checkout_return_ok_url', 'checkout_return_cancel_url', 'checkout_return_error_url',
            'ebollo_base_url', 'ebollo_mode', 'ebollo_id_ci_service',
            'payment_options_url', 'biz_events_host', 'tassonomie_url',
        ];
        $encryptedKeys = ['checkout_subscription_key', 'ebollo_subscription_key', 'ebollo_subscription_key_secondary', 'payment_options_key', 'biz_events_api_key'];

        $merged = $existing;
        foreach ($plainKeys as $key) {
            if (array_key_exists($key, $body)) {
                $merged[$key] = $body[$key];
            }
        }
        foreach ($encryptedKeys as $key) {
            if (array_key_exists($key, $body) && $body[$key] !== '') {
                $merged[$key] = ['value' => $body[$key], 'encrypted' => true];
            }
        }

        SettingsRepository::setSection('pagopa', $merged, $by);
        return $this->jsonOk('Impostazioni API Esterne salvate.');
    }

    public function saveBackoffice(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $by = $this->currentUser();
        $publicBaseUrl = $this->normalizePublicBaseUrl((string)($body['public_base_url'] ?? ''));
        SettingsRepository::setSection('backoffice', [
            'public_base_url'      => $publicBaseUrl,
            'apache_server_name'   => $body['apache_server_name'] ?? '',
            'mailer_dsn'           => ['value' => $body['mailer_dsn'] ?? 'null://null', 'encrypted' => true],
            'mailer_from_address'  => $body['mailer_from_address'] ?? '',
            'mailer_from_name'     => $body['mailer_from_name'] ?? '',
        ], $by);
        // Compatibilità: se il vecchio campo è ancora presente nel form, aggiorna il flag app.debug.
        if (array_key_exists('app_debug', $body)) {
            SettingsRepository::set('app', 'debug', $body['app_debug'] === 'true' ? 'true' : 'false', false, $by);
        }

        return $this->jsonOk('Impostazioni Backoffice salvate.');
    }

    public function saveTefa(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }
        $by = $this->currentUser();
        SettingsRepository::set(
            'backoffice',
            'tefa_enabled',
            isset($body['tefa_enabled']) && $body['tefa_enabled'] === 'true' ? 'true' : 'false',
            false,
            $by
        );
        return $this->jsonOk('Impostazioni TEFA salvate.');
    }

    public function saveFrontoffice(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $by = $this->currentUser();
        $publicBaseUrl = $this->normalizePublicBaseUrl((string)($body['public_base_url'] ?? ''));
        $featuredIds = array_values(array_slice(
            array_filter(array_map('strval', (array)($body['featured_services'] ?? []))),
            0, 8
        ));
        
        $frontofficeData = [
            'public_base_url'      => $publicBaseUrl,
            'auth_proxy_type'      => $body['auth_proxy_type'] ?? 'none',
            'featured_services'    => json_encode($featuredIds),
            'external_oidc_issuer' => $body['external_oidc_issuer'] ?? '',
            'external_oidc_client_id' => $body['external_oidc_client_id'] ?? '',
            'external_oidc_logout_url' => $body['external_oidc_logout_url'] ?? '',
        ];
        
        if (isset($body['external_oidc_client_secret']) && $body['external_oidc_client_secret'] !== '') {
            $frontofficeData['external_oidc_client_secret'] = [
                'value' => $body['external_oidc_client_secret'],
                'encrypted' => true
            ];
        }
        
        SettingsRepository::setSection('frontoffice', $frontofficeData, $by);

        return $this->jsonOk('Impostazioni Frontoffice salvate.');
    }

    public function saveDebug(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $by = $this->currentUser();
        SettingsRepository::set('app', 'debug', ($body['app_debug'] ?? 'false') === 'true' ? 'true' : 'false', false, $by);


        return $this->jsonOk('Flag debug salvati con successo.');
    }

    /**
     * Rotazione chiave cifratura applicativa (APP_ENCRYPTION_KEY) con re-cifratura
     * di tutti i record settings.encrypted=1.
     *
     * POST /impostazioni/sicurezza/rotate-encryption-key
     */
    public function rotateEncryptionKey(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();

        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $oldKey = (string)($body['old_key'] ?? '');
        $newKey = (string)($body['new_key'] ?? '');
        $confirmNewKey = (string)($body['confirm_new_key'] ?? '');
        $dryRun = in_array(
            strtolower(trim((string)($body['dry_run'] ?? 'false'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        $forceRuntimeMismatch = in_array(
            strtolower(trim((string)($body['force_runtime_mismatch'] ?? 'false'))),
            ['1', 'true', 'yes', 'on'],
            true
        );

        if ($oldKey === '' || $newKey === '' || $confirmNewKey === '') {
            return $this->jsonError('Compila old key, new key e conferma.');
        }

        if (!hash_equals($newKey, $confirmNewKey)) {
            return $this->jsonError('La conferma della nuova chiave non coincide.');
        }

        if (hash_equals($oldKey, $newKey)) {
            return $this->jsonError('La nuova chiave deve essere diversa da quella attuale.');
        }

        if (strlen($newKey) !== 32) {
            return $this->jsonError('La nuova chiave deve essere esattamente di 32 caratteri.');
        }

        $runtimeKey = $this->getRuntimeEncryptionKey();
        if ($runtimeKey !== '' && !hash_equals($runtimeKey, $newKey) && !$forceRuntimeMismatch) {
            return $this->jsonError(
                'La chiave runtime corrente non corrisponde alla nuova chiave. ' .
                'Aggiorna prima APP_ENCRYPTION_KEY su tutte le istanze oppure abilita la conferma esplicita.',
                409
            );
        }

        try {
            $pdo = Connection::getPDO();
            $rows = $pdo->query(
                "SELECT id, section, key_name, value
                 FROM settings
                 WHERE encrypted = 1
                   AND value IS NOT NULL
                   AND value <> ''
                 ORDER BY id ASC"
            )?->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            if ($rows === []) {
                return $this->jsonOk('Nessun valore cifrato da migrare.', [
                    'rotated' => 0,
                    'legacy_plaintext_normalized' => 0,
                    'forced_runtime_mismatch' => $forceRuntimeMismatch,
                ]);
            }

            $updatedBy = $this->currentUser();
            $updatedCount = 0;
            $normalizedPlaintextCount = 0;

            if (!$dryRun) {
                $pdo->beginTransaction();
            }

            $updateStmt = null;
            if (!$dryRun) {
                $updateStmt = $pdo->prepare(
                    'UPDATE settings SET value = :value, updated_by = :updated_by WHERE id = :id'
                );
            }

            foreach ($rows as $row) {
                $value = (string)($row['value'] ?? '');
                $decrypt = $this->decryptValueForKeyRotation($value, $oldKey);

                if (!($decrypt['ok'] ?? false)) {
                    if (!$dryRun && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $settingPath = (string)($row['section'] ?? '') . '.' . (string)($row['key_name'] ?? '');
                    return $this->jsonError(
                        "Rotazione interrotta: impossibile decifrare {$settingPath} con la old key (chiave errata o dato corrotto).",
                        409
                    );
                }

                if (($decrypt['source'] ?? '') === 'plaintext') {
                    $normalizedPlaintextCount++;
                }

                if ($dryRun) {
                    $updatedCount++;
                    continue;
                }

                $reEncrypted = $this->encryptValueForKeyRotation((string)$decrypt['plaintext'], $newKey);
                if ($reEncrypted === null) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return $this->jsonError('Rotazione interrotta: errore durante la cifratura con la nuova chiave.', 500);
                }

                $updateStmt->execute([
                    ':value' => $reEncrypted,
                    ':updated_by' => $updatedBy,
                    ':id' => (int)$row['id'],
                ]);
                $updatedCount++;
            }

            if ($dryRun) {
                return $this->jsonOk(
                    'Dry-run completato: nessuna modifica salvata nel DB.',
                    [
                        'dry_run' => true,
                        'rotated' => $updatedCount,
                        'legacy_plaintext_normalized' => $normalizedPlaintextCount,
                        'forced_runtime_mismatch' => $forceRuntimeMismatch,
                    ]
                );
            }

            $pdo->commit();
            SettingsRepository::flush();

            return $this->jsonOk(
                'Rotazione chiave completata. Verifica che APP_ENCRYPTION_KEY sia allineata su tutte le istanze e riavvia i servizi applicativi.',
                [
                    'dry_run' => false,
                    'rotated' => $updatedCount,
                    'legacy_plaintext_normalized' => $normalizedPlaintextCount,
                    'forced_runtime_mismatch' => $forceRuntimeMismatch,
                ]
            );
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::getInstance()->error('Rotazione APP_ENCRYPTION_KEY fallita', ['error' => $e->getMessage()]);
            return $this->jsonError('Rotazione chiave fallita: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Elenco chiavi attualmente marcate encrypted=1 nella tabella settings.
     *
     * GET /impostazioni/sicurezza/encrypted-keys
     */
    public function getEncryptedSettingsKeys(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();

        try {
            $pdo = Connection::getPDO();
            $stmt = $pdo->query(
                "SELECT section, key_name, value
                 FROM settings
                 WHERE encrypted = 1
                 ORDER BY section ASC, key_name ASC"
            );

            $rawRows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            $runtimeKey = $this->getRuntimeEncryptionKey();

            $rows = [];
            foreach ($rawRows as $row) {
                $value = (string)($row['value'] ?? '');
                if ($value === '') {
                    $status = 'empty';
                } elseif ($runtimeKey === '') {
                    $status = 'no_key';
                } else {
                    $result = $this->decryptValueForKeyRotation($value, $runtimeKey);
                    if (!$result['ok']) {
                        $status = 'error';
                    } elseif (($result['source'] ?? '') === 'plaintext') {
                        $status = 'plaintext';
                    } else {
                        $status = 'ok';
                    }
                }
                $rows[] = [
                    'section'  => $row['section'],
                    'key_name' => $row['key_name'],
                    'status'   => $status,
                ];
            }

            return $this->jsonResponse([
                'success' => true,
                'count'   => count($rows),
                'items'   => $rows,
            ]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Lettura chiavi encrypted=1 fallita', ['error' => $e->getMessage()]);
            return $this->jsonError('Impossibile leggere le chiavi cifrate: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Verifica la password dell'utente corrente (per sblocco UI sezioni sensibili).
     *
     * POST /impostazioni/sicurezza/verify-password
     */
    public function verifyPassword(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token CSRF non valido.', 403);
        }

        $password = (string)($body['password'] ?? '');
        if ($password === '') {
            return $this->jsonError('Password obbligatoria.', 400);
        }

        try {
            $email = $_SESSION['user']['email'] ?? '';
            if ($email === '') {
                return $this->jsonError('Sessione non valida.', 401);
            }

            $pdo = Connection::getPDO();
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE email = :email AND is_disabled = 0 LIMIT 1');
            $stmt->execute([':email' => $email]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($password, (string)$hash)) {
                return $this->jsonError('Password errata.', 401);
            }

            return $this->jsonOk('Password verificata.');
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Verifica password fallita', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore interno.', 500);
        }
    }




    /**
     * Normalizza un URL base rimuovendo lo slash finale per evitare doppi slash
     * nella composizione degli endpoint derivati.
     */
    private function normalizePublicBaseUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }

        $parsed = parse_url($trimmed);
        if ($parsed !== false && isset($parsed['scheme'], $parsed['host'])) {
            return rtrim($trimmed, '/');
        }

        return $trimmed;
    }

    // ──────────────────────────────────────────────────────────────────────
    // TEST ACTIONS
    // ──────────────────────────────────────────────────────────────────────

    public function testGovpayConnection(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $url = SettingsRepository::get('govpay', 'backoffice_url', '');
        if (empty($url)) {
            return $this->jsonError('GovPay Backoffice URL non configurato.');
        }
        try {
            $cfg = new \GovPay\Backoffice\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            (new \GovPay\Backoffice\Api\InfoApi($this->buildGovpayHttpClient(), $cfg))->getInfo();
            return $this->jsonOk('GovPay Backoffice: connessione OK.');
        } catch (\GovPay\Backoffice\ApiException $e) {
            return $this->jsonError('GovPay Backoffice: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()));
        } catch (\Throwable $e) {
            return $this->jsonError('GovPay Backoffice: ' . $e->getMessage());
        }
    }

    public function testGovpayPendenze(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $url = SettingsRepository::get('govpay', 'pendenze_url', '');
        if (empty($url)) {
            return $this->jsonError('GovPay Pendenze URL non configurato.');
        }
        try {
            $cfg = new \GovPay\Pendenze\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            (new \GovPay\Pendenze\Api\ProfiloApi($this->buildGovpayHttpClient(), $cfg))->getProfilo();
            return $this->jsonOk('GovPay Pendenze: connessione OK.');
        } catch (\GovPay\Pendenze\ApiException $e) {
            return $this->jsonError('GovPay Pendenze: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()));
        } catch (\Throwable $e) {
            return $this->jsonError('GovPay Pendenze: ' . $e->getMessage());
        }
    }

    public function testGovpayPagamenti(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $url = SettingsRepository::get('govpay', 'pagamenti_url', '');
        if (empty($url)) {
            return $this->jsonError('GovPay Pagamenti URL non configurato.');
        }
        try {
            $cfg = new \GovPay\Pagamenti\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            (new \GovPay\Pagamenti\Api\UtentiApi($this->buildGovpayHttpClient(), $cfg))->getProfilo();
            return $this->jsonOk('GovPay Pagamenti: connessione OK.');
        } catch (\GovPay\Pagamenti\ApiException $e) {
            return $this->jsonError('GovPay Pagamenti: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()));
        } catch (\Throwable $e) {
            return $this->jsonError('GovPay Pagamenti: ' . $e->getMessage());
        }
    }

    public function testGovpayRagioneria(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $url = SettingsRepository::get('govpay', 'ragioneria_url', '');
        if (empty($url)) {
            return $this->jsonError('GovPay Ragioneria URL non configurato.');
        }
        try {
            $cfg = new \GovPay\Ragioneria\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            (new \GovPay\Ragioneria\Api\UtentiApi($this->buildGovpayHttpClient(), $cfg))->getProfilo();
            return $this->jsonOk('GovPay Ragioneria: connessione OK.');
        } catch (\GovPay\Ragioneria\ApiException $e) {
            return $this->jsonError('GovPay Ragioneria: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()));
        } catch (\Throwable $e) {
            return $this->jsonError('GovPay Ragioneria: ' . $e->getMessage());
        }
    }

    public function testGovpayPendenzePatch(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $url = SettingsRepository::get('govpay', 'pendenze_patch_url', '');
        if (empty($url)) {
            return $this->jsonError('GovPay Pendenze Patch URL non configurato.');
        }
        try {
            $cfg = new \GovPay\Pendenze\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            (new \GovPay\Pendenze\Api\ProfiloApi($this->buildGovpayHttpClient(), $cfg))->getProfilo();
            return $this->jsonOk('GovPay Pendenze Patch: connessione OK.');
        } catch (\GovPay\Pendenze\ApiException $e) {
            return $this->jsonError('GovPay Pendenze Patch: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()));
        } catch (\Throwable $e) {
            return $this->jsonError('GovPay Pendenze Patch: ' . $e->getMessage());
        }
    }

    public function testCheckout(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $url = SettingsRepository::get('pagopa', 'checkout_ec_base_url', '');
        $key = SettingsRepository::get('pagopa', 'checkout_subscription_key', '');
        if (empty($url)) {
            return $this->jsonError('URL pagoPA Checkout non configurato.');
        }
        // L'API Checkout EC non ha endpoint GET: POST /carts con body vuoto è
        // l'unico modo per verificare raggiungibilità + validità della chiave.
        // Risposta attesa: 400/422 (dati mancanti) = API su, chiave valida.
        $ch = curl_init(rtrim($url, '/') . '/carts');
        if ($ch === false) {
            return $this->jsonError('URL pagoPA Checkout non valido.');
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($key)) {
            $headers[] = "Ocp-Apim-Subscription-Key: {$key}";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        if ($result === false || $curlErr) {
            return $this->jsonError("pagoPA Checkout: connessione fallita — {$curlErr}");
        }
        if ($httpCode === 0) {
            return $this->jsonError('pagoPA Checkout: nessuna risposta (timeout o host non raggiungibile).');
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return $this->jsonError("pagoPA Checkout: HTTP {$httpCode} — chiave API non valida o mancante.");
        }
        if ($httpCode === 400 || $httpCode === 422) {
            return $this->jsonOk("pagoPA Checkout: API raggiungibile — HTTP {$httpCode} (validità chiave non verificabile su questo endpoint).");
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return $this->jsonOk("pagoPA Checkout: HTTP {$httpCode} — OK.");
        }
        return $this->jsonOk("pagoPA Checkout: HTTP {$httpCode} — server raggiungibile.");
    }

    public function testEBollo(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();

        $url = SettingsRepository::get('pagopa', 'ebollo_base_url', '');
        $candidateKeys = array_values(array_filter(array_unique([
            (string)SettingsRepository::get('pagopa', 'ebollo_subscription_key', ''),
            (string)SettingsRepository::get('pagopa', 'ebollo_subscription_key_secondary', ''),
            (string)SettingsRepository::get('pagopa', 'checkout_subscription_key', ''),
        ]), static fn (string $k): bool => trim($k) !== ''));

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        // SANP 3.12.0 @e.bollo 2.0 (Pagamento dovuto): idCIService da valorizzare con 00005.
        $idCiService = SettingsRepository::get('pagopa', 'ebollo_id_ci_service', '00005');
        if (empty($idCiService)) {
            $idCiService = '00005';
        }

        if (empty($url)) {
            return $this->jsonError('URL @e.bollo non configurato.');
        }
        if (count($candidateKeys) === 0) {
            return $this->jsonError('Subscription Key @e.bollo non configurata (né fallback Checkout).');
        }
        if (empty($idDominio)) {
            return $this->jsonError('ID Dominio non configurato.');
        }

        $endpointBase = rtrim((string)$url, '/') . '/organizations/' . rawurlencode((string)$idDominio) . '/mbd';
        $ch = curl_init($endpointBase);
        if ($ch === false) {
            return $this->jsonError('URL @e.bollo non valido.');
        }

        try {
            $requestId = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $requestId = sha1((string)microtime(true));
        }

        $payload = [
            'paymentNotices' => [[
                'firstName' => 'TEST',
                'lastName' => 'UTENTE',
                'fiscalCode' => 'RSSMRA80A01H501U',
                'email' => 'test@example.com',
                'amount' => 1600,
                'province' => 'RM',
            ]],
            'idCIService' => (string)$idCiService,
            'returnUrls' => [
                'successUrl' => 'https://example.com/ok',
                'cancelUrl' => 'https://example.com/cancel',
                'errorUrl' => 'https://example.com/error',
            ],
        ];

        $result = false;
        $httpCode = 0;
        $curlErr = '';
        $usedKeyIndex = 0;
        foreach ($candidateKeys as $idx => $key) {
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpointBase,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Ocp-Apim-Subscription-Key: ' . $key,
                    'X-Request-Id: ' . $requestId,
                ],
            ]);

            $result = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            $usedKeyIndex = $idx + 1;
            if ($result === false || $curlErr) {
                break;
            }
            if ($httpCode !== 401 && $httpCode !== 403) {
                break;
            }
        }
        if ($result === false || $curlErr) {
            return $this->jsonError("@e.bollo: connessione fallita — {$curlErr}");
        }
        if ($httpCode === 0) {
            return $this->jsonError('@e.bollo: nessuna risposta (timeout o host non raggiungibile).');
        }

        $body = json_decode((string)$result, true);
        $detail = is_array($body) ? trim((string)($body['detail'] ?? '')) : '';

        if ($httpCode === 401 || $httpCode === 403) {
            return $this->jsonError("@e.bollo: HTTP {$httpCode} — chiave API non valida o mancante.");
        }
        if ($httpCode === 400 || $httpCode === 422) {
            $suffix = $detail !== '' ? " — {$detail}" : '';
            return $this->jsonOk("@e.bollo: API raggiungibile — HTTP {$httpCode}{$suffix} (chiave #{$usedKeyIndex})");
        }
        if ($httpCode === 404) {
            $suffix = $detail !== '' ? " — {$detail}" : '';
            return $this->jsonOk("@e.bollo: endpoint raggiungibile ma organizzazione/servizio non abilitata (HTTP 404){$suffix} (chiave #{$usedKeyIndex})");
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return $this->jsonOk("@e.bollo: HTTP {$httpCode} — OK (chiave #{$usedKeyIndex}).");
        }

        return $this->jsonOk("@e.bollo: HTTP {$httpCode} — server raggiungibile (chiave #{$usedKeyIndex}).");
    }

    public function testPaymentOptions(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $url = SettingsRepository::get('pagopa', 'payment_options_url', '');
        $key = SettingsRepository::get('pagopa', 'payment_options_key', '');
        return $this->pingUrlWithKey($url, 'Payment Options API', $key);
    }

    public function testBizEvents(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $host = SettingsRepository::get('pagopa', 'biz_events_host', '');
        $key  = SettingsRepository::get('pagopa', 'biz_events_api_key', '');
        if (empty($host)) {
            return $this->jsonError('URL BizEvents non configurato.');
        }
        return $this->pingUrlWithKey(rtrim($host, '/') . '/info', 'BizEvents', $key);
    }

    public function testTassonomie(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $url = SettingsRepository::get('pagopa', 'tassonomie_url', '');
        if (empty($url)) {
            return $this->jsonError('URL Tassonomie non configurato.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return $this->jsonError('URL Tassonomie non valido.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        if ($raw === false || $curlErr) {
            return $this->jsonError("Tassonomie: connessione fallita — {$curlErr}");
        }
        if ($httpCode !== 200) {
            return $this->jsonError("Tassonomie: HTTP {$httpCode}.");
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return $this->jsonError('Tassonomie: risposta non valida (JSON non array).');
        }
        $count = count($data);
        return $this->jsonOk("Tassonomie: {$count} voci caricate — OK.");
    }

    public function testEmail(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();

        $recipient = $_SESSION['user']['email'] ?? '';
        if (empty($recipient)) {
            return $this->jsonError('Email utente non trovata.');
        }

        try {
            $mailerService = \App\Services\MailerService::forSuite('backoffice');
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
            $mailerService->sendTestEmail($recipient, $appName);
            return $this->jsonOk("Email di test inviata a {$recipient}.");
            $email = (new \Symfony\Component\Mime\Email())
                ->to(new \Symfony\Component\Mime\Address($recipient))
                ->subject("[{$appName}] Email di test")
                ->html("<p>Questo è un messaggio di test inviato dal backoffice di <strong>"
                    . htmlspecialchars($appName, ENT_QUOTES) . "</strong>.</p>")
                ->text("Questo è un messaggio di test inviato dal backoffice di {$appName}.");
            $mailerService->send($email);
            return $this->jsonOk("Email di test inviata a {$recipient}.");
        } catch (\Throwable $e) {
            return $this->jsonError('Invio fallito: ' . $e->getMessage());
        }
    }



    // ──────────────────────────────────────────────────────────────────────
    // UPLOAD LOGO / FAVICON
    // ──────────────────────────────────────────────────────────────────────

    public function uploadLogo(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->handleImageUpload($request, 'logo_file', '/var/www/html/public/img/stemma_ente.png', 'ui', 'logo_src', '/img/stemma_ente.png');
    }

    public function uploadFavicon(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->handleImageUpload($request, 'favicon_file', '/var/www/html/public/img/favicon.png', 'ui', 'favicon_src', '/img/favicon.png');
    }

    // ──────────────────────────────────────────────────────────────────────
    // UPLOAD CERTIFICATI GOVPAY (mTLS)
    // ──────────────────────────────────────────────────────────────────────

    public function uploadGovpayCert(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->handleCertUpload($request, 'govpay_cert', '/var/www/certificate/govpay-cert.pem', 'govpay', 'tls_cert_path');
    }

    public function uploadGovpayKey(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->handleCertUpload($request, 'govpay_key', '/var/www/certificate/govpay-key.pem', 'govpay', 'tls_key_path');
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────

    private function buildGovpayHttpClient(): \GuzzleHttp\Client
    {
        return \App\Services\GovPayClientFactory::makeBackofficeClient();
    }

    private function applyGovpayCredentials(object $config): void
    {
        \App\Services\GovPayClientFactory::applyCredentials($config);
    }

    private function govpayErrorDetail(mixed $body): string
    {
        if (empty($body)) {
            return 'errore sconosciuto';
        }
        $decoded = json_decode((string)$body, true);
        return $decoded['descrizione'] ?? $decoded['detail'] ?? $decoded['message'] ?? substr((string)$body, 0, 120);
    }

    private function pingUrlWithKey(string $url, string $label, string $apiKey): Response
    {
        if (empty($url)) {
            return $this->jsonError("URL {$label} non configurato.");
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return $this->jsonError("URL {$label} non valido.");
        }
        $headers = ['Accept: application/json'];
        if (!empty($apiKey)) {
            $headers[] = "Ocp-Apim-Subscription-Key: {$apiKey}";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        if ($result === false || $curlErr) {
            return $this->jsonError("Connessione {$label} fallita: {$curlErr}");
        }
        if ($httpCode === 0) {
            return $this->jsonError("Connessione {$label}: nessuna risposta (timeout o host non raggiungibile).");
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return $this->jsonError("Connessione {$label}: HTTP {$httpCode} — chiave API non valida o mancante.");
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return $this->jsonOk("Connessione {$label}: HTTP {$httpCode} — OK.");
        }
        return $this->jsonOk("Connessione {$label}: HTTP {$httpCode} — server raggiungibile.");
    }

    private function pingUrl(string $url, string $label): Response
    {
        if (empty($url)) {
            return $this->jsonError("URL {$label} non configurato.");
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return $this->jsonError("URL {$label} non valido.");
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $result   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        if ($result === false || $curlErr) {
            return $this->jsonError("Connessione {$label} fallita: {$curlErr}");
        }
        if ($httpCode === 0) {
            return $this->jsonError("Connessione {$label}: nessuna risposta (timeout o host non raggiungibile).");
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return $this->jsonOk("Connessione {$label}: HTTP {$httpCode} — OK.");
        }
        if ($httpCode >= 300 && $httpCode < 400) {
            return $this->jsonOk("Connessione {$label}: HTTP {$httpCode} — server raggiungibile (redirect).");
        }
        if ($httpCode >= 400 && $httpCode < 500) {
            return $this->jsonOk("Connessione {$label}: HTTP {$httpCode} — server raggiungibile (risponde con {$httpCode}).");
        }
        return $this->jsonOk("Connessione {$label}: HTTP {$httpCode} — server raggiungibile.");
    }

    /**
     * Restituisce la APP_ENCRYPTION_KEY attiva dopo verifica password.
     *
     * POST /impostazioni/sicurezza/show-encryption-key
     */
    public function showEncryptionKey(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token CSRF non valido.', 403);
        }

        $password = (string)($body['password'] ?? '');
        if ($password === '') {
            return $this->jsonError('Password obbligatoria.', 400);
        }

        try {
            $email = $_SESSION['user']['email'] ?? '';
            if ($email === '') {
                return $this->jsonError('Sessione non valida.', 401);
            }

            $pdo = Connection::getPDO();
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE email = :email AND is_disabled = 0 LIMIT 1');
            $stmt->execute([':email' => $email]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($password, (string)$hash)) {
                return $this->jsonError('Password errata.', 401);
            }

            $key = $this->getRuntimeEncryptionKey();
            if ($key === '') {
                return $this->jsonError('APP_ENCRYPTION_KEY non configurata nel runtime.', 404);
            }

            Logger::getInstance()->info('APP_ENCRYPTION_KEY visualizzata', ['user' => $email]);

            return $this->jsonResponse(['success' => true, 'key' => $key]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('showEncryptionKey fallito', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore interno.', 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // IBAN — Conti di Accredito (GovPay EntiCreditoriApi)
    // ──────────────────────────────────────────────────────────────────────

    public function ibanList(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();

        if (!class_exists('GovPay\Backoffice\Api\EntiCreditoriApi')) {
            return $this->jsonError('Client GovPay Backoffice non disponibile.', 500);
        }

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            return $this->jsonError('ID Dominio non configurato. Vai al tab <a href="/impostazioni?tab=generale">Dati di base</a> e imposta l\'ID Dominio.', 400);
        }

        $url = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($url === '') {
            return $this->jsonError('GovPay Backoffice URL non configurato.', 400);
        }

        try {
            $cfg = new \GovPay\Backoffice\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            $api = new \GovPay\Backoffice\Api\EntiCreditoriApi($this->buildGovpayHttpClient(), $cfg);
            $result = $api->findContiAccredito($idDominio, 1, 100);
            $raw = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($result);
            $arr = is_array($raw) ? $raw : (json_decode(json_encode($raw, JSON_UNESCAPED_SLASHES), true) ?: []);
            $list = is_array($arr['risultati'] ?? null) ? $arr['risultati'] : [];
            return $this->jsonResponse([
                'success' => true,
                'data' => $list,
                '_debug' => [
                    'id_dominio' => $idDominio,
                    'backoffice_url' => rtrim($url, '/'),
                    'raw_keys' => array_keys($arr),
                    'num_risultati' => $arr['numRisultati'] ?? $arr['num_risultati'] ?? null,
                    'list_count' => count($list),
                ],
            ]);
        } catch (\GovPay\Backoffice\ApiException $e) {
            return $this->jsonError('GovPay: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()), 502);
        } catch (\Throwable $e) {
            return $this->jsonError('Errore GovPay: ' . $e->getMessage(), 500);
        }
    }

    public function ibanSave(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        if (!class_exists('GovPay\Backoffice\Api\EntiCreditoriApi')) {
            return $this->jsonError('Client GovPay Backoffice non disponibile.', 500);
        }

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            return $this->jsonError('ID Dominio non configurato.', 400);
        }

        $iban = strtoupper(trim((string)($body['iban'] ?? '')));
        if ($iban === '' || !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{1,30}$/', $iban)) {
            return $this->jsonError('IBAN non valido. Formato atteso: 2 lettere + 2 cifre + fino a 30 alfanumerici.', 422);
        }

        $url = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($url === '') {
            return $this->jsonError('GovPay Backoffice URL non configurato.', 400);
        }

        try {
            $post = new \GovPay\Backoffice\Model\ContiAccreditoPost();
            $post->setBic(!empty($body['bic']) ? $body['bic'] : null);
            $post->setIntestatario(!empty($body['intestatario']) ? $body['intestatario'] : null);
            $post->setDescrizione(!empty($body['descrizione']) ? $body['descrizione'] : null);
            $post->setPostale(isset($body['postale']) && $body['postale'] === 'true');
            $post->setMybank(isset($body['mybank']) && $body['mybank'] === 'true');
            $post->setAbilitato(!isset($body['abilitato']) || $body['abilitato'] !== 'false');
            if (!empty($body['aut_stampa_poste_italiane'])) {
                $post->setAutStampaPosteItaliane($body['aut_stampa_poste_italiane']);
            }

            $cfg = new \GovPay\Backoffice\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            $api = new \GovPay\Backoffice\Api\EntiCreditoriApi($this->buildGovpayHttpClient(), $cfg);
            $api->addContiAccreditoWithHttpInfo($idDominio, $iban, $post);
            return $this->jsonOk('Conto di accredito ' . $iban . ' salvato.');
        } catch (\GovPay\Backoffice\ApiException $e) {
            return $this->jsonError('GovPay: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()), 502);
        } catch (\Throwable $e) {
            return $this->jsonError('Errore GovPay: ' . $e->getMessage(), 500);
        }
    }

    public function ibanToggle(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        if (!class_exists('GovPay\Backoffice\Api\EntiCreditoriApi')) {
            return $this->jsonError('Client GovPay Backoffice non disponibile.', 500);
        }

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            return $this->jsonError('ID Dominio non configurato.', 400);
        }

        $iban = strtoupper(trim((string)($body['iban'] ?? '')));
        if ($iban === '') {
            return $this->jsonError('IBAN mancante.', 422);
        }

        $url = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($url === '') {
            return $this->jsonError('GovPay Backoffice URL non configurato.', 400);
        }

        try {
            $cfg = new \GovPay\Backoffice\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            $httpClient = $this->buildGovpayHttpClient();
            $api = new \GovPay\Backoffice\Api\EntiCreditoriApi($httpClient, $cfg);

            $current = $api->getContiAccredito($idDominio, $iban);
            $currentData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($current);
            $wasAbilitato = is_array($currentData) ? (bool)($currentData['abilitato'] ?? true) : true;
            $newAbilitato = !$wasAbilitato;

            $post = new \GovPay\Backoffice\Model\ContiAccreditoPost();
            if (is_array($currentData)) {
                if (!empty($currentData['bic'])) $post->setBic($currentData['bic']);
                if (!empty($currentData['intestatario'])) $post->setIntestatario($currentData['intestatario']);
                if (!empty($currentData['descrizione'])) $post->setDescrizione($currentData['descrizione']);
                $post->setPostale((bool)($currentData['postale'] ?? false));
                $post->setMybank((bool)($currentData['mybank'] ?? false));
                if (!empty($currentData['aut_stampa_poste_italiane'])) $post->setAutStampaPosteItaliane($currentData['aut_stampa_poste_italiane']);
            }
            $post->setAbilitato($newAbilitato);

            $api->addContiAccreditoWithHttpInfo($idDominio, $iban, $post);
            return $this->jsonResponse(['success' => true, 'abilitato' => $newAbilitato, 'message' => 'IBAN ' . ($newAbilitato ? 'abilitato' : 'disabilitato') . '.']);
        } catch (\GovPay\Backoffice\ApiException $e) {
            return $this->jsonError('GovPay: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()), 502);
        } catch (\Throwable $e) {
            return $this->jsonError('Errore GovPay: ' . $e->getMessage(), 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // RUOLI GovPay (RuoliApi)
    // ──────────────────────────────────────────────────────────────────────

    public function ruoliSave(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        if (!class_exists('GovPay\Backoffice\Api\RuoliApi')) {
            return $this->jsonError('Client GovPay Backoffice non disponibile.', 500);
        }

        $url = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($url === '') {
            return $this->jsonError('GovPay Backoffice URL non configurato.', 400);
        }

        $idRuolo = trim((string)($body['id_ruolo'] ?? ''));
        if ($idRuolo === '' || !preg_match('/^[a-zA-Z0-9\-_]{1,255}$/', $idRuolo)) {
            return $this->jsonError('ID ruolo non valido. Ammessi: lettere, cifre, trattino, underscore (1–255 char).', 422);
        }

        $aclRaw = $body['acl'] ?? null;
        if (is_string($aclRaw)) {
            $aclRaw = json_decode($aclRaw, true);
        }
        if (!is_array($aclRaw) || count($aclRaw) === 0) {
            return $this->jsonError('Almeno una voce ACL è richiesta.', 422);
        }

        $serviziValidi = [
            'Anagrafica PagoPA', 'Anagrafica Creditore', 'Anagrafica Applicazioni',
            'Anagrafica Ruoli', 'Pagamenti', 'Pendenze',
            'Rendicontazioni e Incassi', 'Giornale degli Eventi',
            'Configurazione e manutenzione',
        ];

        $aclList = [];
        foreach ($aclRaw as $entry) {
            $servizio = (string)($entry['servizio'] ?? '');
            if (!in_array($servizio, $serviziValidi, true)) {
                return $this->jsonError('Servizio non valido: ' . $servizio, 422);
            }
            $autorizzazioni = array_values(array_unique(array_intersect(
                (array)($entry['autorizzazioni'] ?? []),
                ['R', 'W']
            )));
            if (empty($autorizzazioni)) {
                continue;
            }
            $aclEntry = new \GovPay\Backoffice\Model\AclPost();
            $aclEntry->setServizio($servizio);
            $aclEntry->setAutorizzazioni($autorizzazioni);
            $aclList[] = $aclEntry;
        }

        if (empty($aclList)) {
            return $this->jsonError('Almeno una voce ACL con autorizzazioni R e/o W è richiesta.', 422);
        }

        try {
            $cfg = new \GovPay\Backoffice\Configuration();
            $cfg->setHost(rtrim($url, '/'));
            $this->applyGovpayCredentials($cfg);
            $api = new \GovPay\Backoffice\Api\RuoliApi($this->buildGovpayHttpClient(), $cfg);

            $ruoloPost = new \GovPay\Backoffice\Model\RuoloPost();
            $ruoloPost->setAcl($aclList);

            $api->addRuoloWithHttpInfo($idRuolo, $ruoloPost);
            return $this->jsonOk('Ruolo "' . $idRuolo . '" salvato.');
        } catch (\GovPay\Backoffice\ApiException $e) {
            return $this->jsonError('GovPay: HTTP ' . $e->getCode() . ' — ' . $this->govpayErrorDetail($e->getResponseBody()), 502);
        } catch (\Throwable $e) {
            return $this->jsonError('Errore GovPay: ' . $e->getMessage(), 500);
        }
    }

    private function triggerAuthProxyReload(): bool
    {
        return $this->callAuthProxyControlEndpoint('reload');
    }

    private function triggerAuthProxyRestart(): bool
    {
        return $this->callAuthProxyControlEndpoint('restart');
    }
    private function getRuntimeEncryptionKey(): string
    {
        $fromConfig = (string)(ConfigLoader::get('app.encryption_key') ?? '');
        if ($fromConfig !== '') {
            return $fromConfig;
        }

        $fromEnv = (string)($_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY') ?: '');
        return $fromEnv;
    }

    /**
     * @return array{ok:bool,plaintext?:string,source?:string}
     */
    private function decryptValueForKeyRotation(string $value, string $oldKey): array
    {
        $decoded = base64_decode($value, true);
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');

        // Valore legacy in chiaro: normalizza cifrandolo con la nuova chiave.
        if ($decoded === false || strlen($decoded) <= $ivLength) {
            return [
                'ok' => true,
                'plaintext' => $value,
                'source' => 'plaintext',
            ];
        }

        $iv = substr($decoded, 0, $ivLength);
        $ciphertext = substr($decoded, $ivLength);
        $cleartext = openssl_decrypt($ciphertext, 'aes-256-cbc', $oldKey, OPENSSL_RAW_DATA, $iv);

        if ($cleartext === false) {
            return ['ok' => false];
        }

        return [
            'ok' => true,
            'plaintext' => $cleartext,
            'source' => 'encrypted',
        ];
    }

    private function encryptValueForKeyRotation(string $plaintext, string $newKey): ?string
    {
        $ivLength = openssl_cipher_iv_length('aes-256-cbc');
        try {
            $iv = random_bytes($ivLength);
        } catch (\Throwable $e) {
            return null;
        }

        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $newKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            return null;
        }

        return base64_encode($iv . $ciphertext);
    }

    private function generateCsrf(): string
    {
        if (empty($_SESSION['impostazioni_csrf'])) {
            $_SESSION['impostazioni_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['impostazioni_csrf'];
    }

    private function validateCsrf(array $body): bool
    {
        $expected = (string) ($_SESSION['impostazioni_csrf'] ?? '');
        $provided = (string) ($body['csrf_token'] ?? '');
        $valid = $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
        if ($valid) {
            unset($_SESSION['impostazioni_csrf']); // Invalida dopo uso
        }
        return $valid;
    }

    private function parseBody(Request $request): array
    {
        $ct = $request->getHeaderLine('Content-Type');
        if (str_contains($ct, 'application/json')) {
            $decoded = json_decode((string) $request->getBody(), true);
            return is_array($decoded) ? $decoded : [];
        }
        return (array) ($request->getParsedBody() ?? []);
    }

    private function isSuperadmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'superadmin';
    }

    private function currentUser(): string
    {
        return $_SESSION['user']['email'] ?? 'system';
    }

    private function requireAdminOrAbove(): void
    {
        $role = $_SESSION['user']['role'] ?? '';
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato: permessi insufficienti'];
            header('Location: /');
            exit;
        }
    }

    private function requireSuperadmin(): void
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso riservato al superadmin.'];
            header('Location: /impostazioni');
            exit;
        }
    }

    private function jsonOk(string $message, array $extra = []): Response
    {
        return $this->jsonResponse(array_merge(['success' => true, 'message' => $message], $extra));
    }

    private function jsonError(string $message, int $status = 400): Response
    {
        $resp = $this->jsonResponse([
            'success' => false,
            'message' => $message,
            'error_status' => $status,
        ], 200);

        return $resp->withHeader('X-App-Error-Status', (string)$status);
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_SESSION['impostazioni_csrf'])) {
                $_SESSION['impostazioni_csrf'] = bin2hex(random_bytes(32));
            }
            $data['csrf_token'] = $_SESSION['impostazioni_csrf'];
        }
        $resp = new SlimResponse($status);
        $resp->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $resp->withHeader('Content-Type', 'application/json');
    }

    /**
     * Salva un file caricato via upload in un path del filesystem del container.
     *
     * @param string[] $allowedMimes MIME type accettati (lax: se vuoto accetta tutto)
     */
    private function saveUploadedFile(Request $request, string $fieldName, string $destPath, array $allowedMimes = []): Response
    {
        $files = $request->getUploadedFiles();
        $file  = $files[$fieldName] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError('Nessun file ricevuto o errore upload.');
        }
        if ($allowedMimes && !in_array($file->getClientMediaType(), $allowedMimes, true)) {
            return $this->jsonError('Tipo file non supportato.');
        }
        try {
            @mkdir(dirname($destPath), 0755, true);
            $file->moveTo($destPath);
            return $this->jsonOk('File caricato correttamente.');
        } catch (\Throwable $e) {
            return $this->jsonError('Salvataggio fallito: ' . $e->getMessage());
        }
    }

    private function handleCertUpload(
        Request $request,
        string $fieldName,
        string $destPath,
        string $settingSection,
        string $settingKey
    ): Response {
        $files = $request->getUploadedFiles();
        $file = $files[$fieldName] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError('Errore caricamento file.');
        }

        try {
            @mkdir(dirname($destPath), 0755, true);
            $file->moveTo($destPath);
            SettingsRepository::set($settingSection, $settingKey, $destPath, false, $this->currentUser());
            return $this->jsonOk('File caricato correttamente.');
        } catch (\Throwable $e) {
            return $this->jsonError('Salvataggio fallito: ' . $e->getMessage());
        }
    }

    private function handleImageUpload(
        Request $request,
        string $fieldName,
        string $destPath,
        string $settingSection,
        string $settingKey,
        string $settingValue
    ): Response {
        $files = $request->getUploadedFiles();
        $file = $files[$fieldName] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError('Errore caricamento file.');
        }

        $mime = $file->getClientMediaType();
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/svg+xml', 'image/x-icon'], true)) {
            return $this->jsonError('Formato non supportato (png, jpg, svg, ico).');
        }

        try {
            $file->moveTo($destPath);
            SettingsRepository::set($settingSection, $settingKey, $settingValue, false, $this->currentUser());
            return $this->jsonOk('Immagine caricata correttamente.');
        } catch (\Throwable $e) {
            return $this->jsonError('Salvataggio fallito: ' . $e->getMessage());
        }
    }
}
