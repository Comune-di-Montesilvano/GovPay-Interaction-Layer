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
use App\Logger;
use App\Controllers\ConfigurazioneController;

/**
 * Gestisce il pannello Impostazioni (/impostazioni) con 5 sezioni:
 *   - GovPay API
 *   - API Esterne (pagoPA, BizEvents)
 *   - Backoffice (mail, dati ente, supporto)
 *   - Frontoffice (URL, auth proxy, logo/favicon)
 *   - Login Proxy (SATOSA/SPID/CIE)
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
            'iam_proxy'    => SettingsRepository::getSection('iam_proxy'),
            'ui'           => SettingsRepository::getSection('ui'),
            'app'          => SettingsRepository::getSection('app'),
            'csrf_token'   => $this->generateCsrf(),
        ];

        // Tab che appartengono a /configurazione: carica i dati necessari
        $confTabs = ['dominio','tassonomie','tipologie','tipologie_esterne','gestionali',
                     'templates','servizi_io','utenti','operatori','applicazioni','confapi','info','logs'];
        if (in_array($tab, $confTabs, true)) {
            $confCtrl = new ConfigurazioneController($this->twig);
            $data = array_merge($data, $confCtrl->getTabData($tab, $request));
        }

        // Tab Info GIL: stato container e info sistema
        if ($tab === 'info-gil') {
            $gilInfo = [
                'version' => getenv('GIL_IMAGE_TAG') ?: 'dev',
                'php'     => phpversion(),
                'os'      => php_uname('s') . ' ' . php_uname('r'),
            ];
            $data['gil_info'] = $gilInfo;
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
            'payment_options_url', 'biz_events_host', 'tassonomie_url',
        ];
        $encryptedKeys = ['checkout_subscription_key', 'payment_options_key', 'biz_events_api_key'];

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
        SettingsRepository::set('app', 'debug', $body['app_debug'] === 'true' ? 'true' : 'false', false, $by);

        return $this->jsonOk('Impostazioni Backoffice salvate.');
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
        SettingsRepository::setSection('frontoffice', [
            'public_base_url'   => $publicBaseUrl,
            'auth_proxy_type'   => $body['auth_proxy_type'] ?? 'none',
        ], $by);

        return $this->jsonOk('Impostazioni Frontoffice salvate.');
    }

    public function saveLoginProxy(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        if (!$this->validateCsrf($body)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $by = $this->currentUser();
        $publicBaseUrl = $this->normalizePublicBaseUrl((string)($body['public_base_url'] ?? ''));

        // Campi ordinari (non cifrati)
        $plain = [
            'public_base_url'                  => $publicBaseUrl,
            'saml2_idp_metadata_url'           => $body['saml2_idp_metadata_url'] ?? '',
            'saml2_idp_metadata_url_internal'  => $body['saml2_idp_metadata_url_internal'] ?? '',
            'http_port'                        => $body['http_port'] ?? '',
            'debug'                            => $body['debug'] ?? 'false',
            'enable_spid'                      => $body['enable_spid'] ?? 'false',
            'enable_cie_oidc'                  => $body['enable_cie_oidc'] ?? 'false',
            'enable_it_wallet'                 => $body['enable_it_wallet'] ?? 'false',
            'enable_oidcop'                    => $body['enable_oidcop'] ?? 'false',
            'enable_idem'                      => $body['enable_idem'] ?? 'false',
            'enable_eidas'                     => $body['enable_eidas'] ?? 'false',
            'satosa_base'                      => $body['satosa_base'] ?? '',
            'satosa_disco_srv'                 => $body['satosa_disco_srv'] ?? '',
            'satosa_cancel_redirect_url'       => $body['satosa_cancel_redirect_url'] ?? '',
            'satosa_unknow_error_redirect_page' => $body['satosa_unknow_error_redirect_page'] ?? '',
            'satosa_get_spid_idp_metadata'     => $body['satosa_get_spid_idp_metadata'] ?? 'true',
            'satosa_get_cie_idp_metadata'      => $body['satosa_get_cie_idp_metadata'] ?? 'true',
            'satosa_get_ficep_idp_metadata'    => $body['satosa_get_ficep_idp_metadata'] ?? 'false',
            'satosa_use_demo_spid_idp'         => $body['satosa_use_demo_spid_idp'] ?? 'false',
            'satosa_use_spid_validator'        => $body['satosa_use_spid_validator'] ?? 'false',
            'satosa_spid_validator_metadata_url' => $body['satosa_spid_validator_metadata_url'] ?? '',
            'satosa_disable_cieoidc_backend'   => 'false', // legacy: sempre disabilitato
            'spid_cert_org_id'                 => $body['spid_cert_org_id'] ?? '',
            'spid_cert_entity_id'              => $body['spid_cert_entity_id'] ?? '',
            'spid_cert_key_size'               => $body['spid_cert_key_size'] ?? '2048',
            'spid_cert_days'                   => $body['spid_cert_days'] ?? '730',
            'satosa_org_display_name_it'       => $body['satosa_org_display_name_it'] ?? '',
            'satosa_org_display_name_en'       => $body['satosa_org_display_name_en'] ?? '',
            'satosa_org_url_it'                => $body['satosa_org_url_it'] ?? '',
            'satosa_org_url_en'                => $body['satosa_org_url_en'] ?? '',
            'satosa_org_identifier'            => $body['satosa_org_identifier'] ?? '',
            'satosa_contact_given_name'        => $body['satosa_contact_given_name'] ?? '',
            'satosa_contact_email'             => $body['satosa_contact_email'] ?? '',
            'satosa_contact_phone'             => $body['satosa_contact_phone'] ?? '',
            'satosa_contact_fiscalcode'        => $body['satosa_contact_fiscalcode'] ?? '',
            'satosa_contact_ipa_code'          => $body['satosa_contact_ipa_code'] ?? '',
            'satosa_contact_municipality'      => $body['satosa_contact_municipality'] ?? '',
            'satosa_ui_display_name_it'        => $body['satosa_ui_display_name_it'] ?? '',
            'satosa_ui_display_name_en'        => $body['satosa_ui_display_name_en'] ?? '',
            'satosa_ui_description_it'         => $body['satosa_ui_description_it'] ?? '',
            'satosa_ui_description_en'         => $body['satosa_ui_description_en'] ?? '',
            'satosa_ui_information_url_it'     => $body['satosa_ui_information_url_it'] ?? '',
            'satosa_ui_information_url_en'     => $body['satosa_ui_information_url_en'] ?? '',
            'satosa_ui_privacy_url_it'         => $body['satosa_ui_privacy_url_it'] ?? '',
            'satosa_ui_privacy_url_en'         => $body['satosa_ui_privacy_url_en'] ?? '',
            'satosa_ui_legal_url_it'           => $body['satosa_ui_legal_url_it'] ?? '',
            'satosa_ui_legal_url_en'           => $body['satosa_ui_legal_url_en'] ?? '',
            'satosa_ui_accessibility_url_it'   => $body['satosa_ui_accessibility_url_it'] ?? '',
            'satosa_ui_accessibility_url_en'   => $body['satosa_ui_accessibility_url_en'] ?? '',
            'satosa_ui_logo_url'               => $body['satosa_ui_logo_url'] ?? '',
            'satosa_ui_logo_width'             => $body['satosa_ui_logo_width'] ?? '200',
            'satosa_ui_logo_height'            => $body['satosa_ui_logo_height'] ?? '60',
            'cie_oidc_provider_url'            => $body['cie_oidc_provider_url'] ?? '',
            'cie_oidc_trust_anchor_url'        => $body['cie_oidc_trust_anchor_url'] ?? '',
            'cie_oidc_authority_hint_url'      => $body['cie_oidc_authority_hint_url'] ?? '',
            'cie_oidc_client_id'               => $body['cie_oidc_client_id'] ?? '',
            'cie_oidc_client_name'             => $body['cie_oidc_client_name'] ?? '',
            'cie_oidc_jwks_uri'                => $body['cie_oidc_jwks_uri'] ?? '',
            'cie_oidc_signed_jwks_uri'         => $body['cie_oidc_signed_jwks_uri'] ?? '',
            'cie_oidc_redirect_uri'            => $body['cie_oidc_redirect_uri'] ?? '',
            'cie_oidc_federation_resolve_endpoint'           => $body['cie_oidc_federation_resolve_endpoint'] ?? '',
            'cie_oidc_federation_fetch_endpoint'             => $body['cie_oidc_federation_fetch_endpoint'] ?? '',
            'cie_oidc_federation_trust_mark_status_endpoint' => $body['cie_oidc_federation_trust_mark_status_endpoint'] ?? '',
            'cie_oidc_federation_list_endpoint'              => $body['cie_oidc_federation_list_endpoint'] ?? '',
            'cie_oidc_homepage_uri'            => $body['cie_oidc_homepage_uri'] ?? '',
            'cie_oidc_policy_uri'              => $body['cie_oidc_policy_uri'] ?? '',
            'cie_oidc_logo_uri'                => $body['cie_oidc_logo_uri'] ?? '',
            'cie_oidc_contact_email'           => $body['cie_oidc_contact_email'] ?? '',
        ];

        // Campi derivabili: aggiorna i valori del DB con quelli calcolati se il form non li invia esplicitamente.
        // Questo garantisce che getIamProxyEnv() abbia sempre valori coerenti anche se i campi non
        // sono più presenti nel form (rimossi come ridondanti).
        $publicUrl = trim($plain['public_base_url']);
        $derivedHostname = $publicUrl !== '' && filter_var($publicUrl, FILTER_VALIDATE_URL)
            ? (string) parse_url($publicUrl, PHP_URL_HOST)
            : '';

        // hostname: usa quello inviato dal form (hidden) oppure deriva dall'URL pubblico
        $hostname = trim((string)($body['hostname'] ?? ''));
        if ($hostname === '' && $derivedHostname !== '') {
            $hostname = $derivedHostname;
        }
        $plain['hostname'] = $hostname;

        // spid_cert_common_name: usa quello inviato (hidden) oppure deriva dall'hostname
        $certCn = trim((string)($body['spid_cert_common_name'] ?? ''));
        if ($certCn === '' && $hostname !== '') {
            $certCn = $hostname;
        }
        $plain['spid_cert_common_name'] = $certCn;

        // spid_cert_org_name: usa quello inviato (hidden) oppure prende entity.name
        $certOrgName = trim((string)($body['spid_cert_org_name'] ?? ''));
        if ($certOrgName === '') {
            $sEntity = SettingsRepository::getSection('entity');
            $certOrgName = (string)($sEntity['name'] ?? '');
        }
        $plain['spid_cert_org_name'] = $certOrgName;

        // spid_cert_locality_name: usa quello inviato (hidden) oppure prende entity.city
        $certLocality = trim((string)($body['spid_cert_locality_name'] ?? ''));
        if ($certLocality === '') {
            if (!isset($sEntity)) {
                $sEntity = SettingsRepository::getSection('entity');
            }
            $certLocality = (string)($sEntity['city'] ?? '');
        }
        $plain['spid_cert_locality_name'] = $certLocality;

        // satosa_org_name_it: usa quello inviato (hidden) oppure prende entity.name
        $orgNameIt = trim((string)($body['satosa_org_name_it'] ?? ''));
        if ($orgNameIt === '') {
            if (!isset($sEntity)) {
                $sEntity = SettingsRepository::getSection('entity');
            }
            $orgNameIt = (string)($sEntity['name'] ?? '');
        }
        $plain['satosa_org_name_it'] = $orgNameIt;

        // satosa_org_name_en: usa quello inviato oppure rimane vuoto (opzionale)
        $plain['satosa_org_name_en'] = trim((string)($body['satosa_org_name_en'] ?? ''));

        // cie_oidc_organization_name: usa quello inviato (hidden) oppure prende entity.name
        $cieOrgName = trim((string)($body['cie_oidc_organization_name'] ?? ''));
        if ($cieOrgName === '') {
            if (!isset($sEntity)) {
                $sEntity = SettingsRepository::getSection('entity');
            }
            $cieOrgName = (string)($sEntity['name'] ?? '');
        }
        $plain['cie_oidc_organization_name'] = $cieOrgName;

        // Non reintrodurre default legacy se il campo non e' inviato dal form.
        if (array_key_exists('frontoffice_auth_proxy_type', $body)) {
            $plain['frontoffice_auth_proxy_type'] = (string)$body['frontoffice_auth_proxy_type'];
        }

        // Chiavi crittografiche SATOSA: cifrate in DB, aggiornate solo se il form le invia
        // (campo vuoto = non toccare il valore esistente)
        $encrypted = [
            'satosa_salt'               => $body['satosa_salt'] ?? '',
            'satosa_state_encryption_key' => $body['satosa_state_encryption_key'] ?? '',
            'satosa_encryption_key'     => $body['satosa_encryption_key'] ?? '',
            'satosa_user_id_hash_salt'  => $body['satosa_user_id_hash_salt'] ?? '',
        ];

        // Costruisce il dataset finale
        $iamData = $plain;
        foreach ($encrypted as $key => $val) {
            if ($val !== '') {
                $iamData[$key] = ['value' => $val, 'encrypted' => true];
            }
            // valore vuoto → si omette: SettingsRepository::setSection lo ignora se già presente
        }

        SettingsRepository::setSection('iam_proxy', $iamData, $by);

        return $this->jsonOk('Impostazioni Login Proxy salvate. Riavvia i container IAM proxy per applicarle.');
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
    // IAM PROXY ENV ENDPOINT (interno — chiamato da auth-proxy/startup.sh)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * GET /api/auth-proxy/env
     *
     * Restituisce le impostazioni iam_proxy come dizionario piatto di variabili
     * d'ambiente, da usare in auth-proxy/startup.sh tramite fetch HTTP interno.
     * Autenticazione: Bearer token (MASTER_TOKEN dall'ambiente del container).
     * Non richiede sessione PHP.
     */
    public function getIamProxyEnv(Request $request, Response $response): Response
    {
        $masterToken = $_ENV['MASTER_TOKEN'] ?? getenv('MASTER_TOKEN') ?: '';
        if (empty($masterToken)) {
            return $this->jsonResponse(['error' => 'MASTER_TOKEN non configurato'], 503);
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authHeader, 'Bearer ') || !hash_equals($masterToken, substr($authHeader, 7))) {
            return $this->jsonResponse(['error' => 'Non autorizzato'], 401);
        }

        $s            = SettingsRepository::getSection('iam_proxy');
        $sFrontoffice = SettingsRepository::getSection('frontoffice');
        $sEntity      = SettingsRepository::getSection('entity');
        $sUi          = SettingsRepository::getSection('ui');

        // Auto-genera e persiste le chiavi crittografiche SATOSA obbligatorie se mancanti.
        // Queste chiavi devono esistere per far partire SATOSA; se l'operatore non le ha
        // ancora configurate via UI vengono generate automaticamente al primo avvio.
        $satosaAutoKeys = [
            'satosa_salt'                 => 64,  // 32 bytes hex
            'satosa_state_encryption_key' => 32,  // 16 bytes hex
            'satosa_encryption_key'       => 32,  // 16 bytes hex
            'satosa_user_id_hash_salt'    => 64,  // 32 bytes hex
        ];
        $needsReload = false;
        foreach ($satosaAutoKeys as $dbKey => $hexLen) {
            if (empty($s[$dbKey])) {
                $generated = bin2hex(random_bytes(intdiv($hexLen, 2)));
                SettingsRepository::set('iam_proxy', $dbKey, $generated, true, 'system_autogen');
                $needsReload = true;
            }
        }
        if ($needsReload) {
            $s = SettingsRepository::getSection('iam_proxy');
        }

        // Garantisce URL di redirect errore SATOSA sempre valorizzato e assoluto.
        // Priorita: valore esplicito in iam_proxy; fallback su frontoffice + /accesso-negato.
        $satosaErrorRedirect = trim((string)($s['satosa_unknow_error_redirect_page'] ?? ''));
        if ($satosaErrorRedirect === '') {
            $frontofficeBase = rtrim((string)($sFrontoffice['public_base_url'] ?? ''), '/');
            if ($frontofficeBase === '') {
                $frontofficeBase = 'https://127.0.0.1:8444';
                error_log('[ImpostazioniController:getIamProxyEnv] FRONTOFFICE_PUBLIC_BASE_URL non configurato, uso fallback di sicurezza per SATOSA_UNKNOW_ERROR_REDIRECT_PAGE.');
            }

            $satosaErrorRedirect = $frontofficeBase . '/accesso-negato';
            $s['satosa_unknow_error_redirect_page'] = $satosaErrorRedirect;
            SettingsRepository::set('iam_proxy', 'satosa_unknow_error_redirect_page', $satosaErrorRedirect, false, 'system_autogen');
        }

        // Garantisce SATOSA_DISCO_SRV sempre valorizzato.
        // Priorità: valore esplicito in iam_proxy; fallback su {SATOSA_BASE}/static/disco.html.
        $satosaDiscoSrv = trim((string)($s['satosa_disco_srv'] ?? ''));
        if ($satosaDiscoSrv === '') {
            $satosaBase = rtrim((string)($s['public_base_url'] ?? ''), '/');
            if ($satosaBase !== '') {
                $satosaDiscoSrv = $satosaBase . '/static/disco.html';
                $s['satosa_disco_srv'] = $satosaDiscoSrv;
                SettingsRepository::set('iam_proxy', 'satosa_disco_srv', $satosaDiscoSrv, false, 'system_autogen');
            }
        }

        // Mappa chiave DB → nome variabile d'ambiente SATOSA/IAM-proxy
        $map = [
            'debug'                                          => 'IAM_PROXY_DEBUG',
            'frontoffice_auth_proxy_type'                   => 'FRONTOFFICE_AUTH_PROXY_TYPE',
            'hostname'                                       => 'IAM_PROXY_HOSTNAME',
            'http_port'                                      => 'IAM_PROXY_HTTP_PORT',
            'saml2_idp_metadata_url'                        => 'IAM_PROXY_SAML2_IDP_METADATA_URL',
            'saml2_idp_metadata_url_internal'               => 'IAM_PROXY_SAML2_IDP_METADATA_URL_INTERNAL',
            'public_base_url'                               => 'SATOSA_BASE',
            'satosa_disco_srv'                              => 'SATOSA_DISCO_SRV',
            'satosa_cancel_redirect_url'                    => 'SATOSA_CANCEL_REDIRECT_URL',
            'satosa_unknow_error_redirect_page'             => 'SATOSA_UNKNOW_ERROR_REDIRECT_PAGE',
            'satosa_salt'                                   => 'SATOSA_SALT',
            'satosa_state_encryption_key'                   => 'SATOSA_STATE_ENCRYPTION_KEY',
            'satosa_encryption_key'                         => 'SATOSA_ENCRYPTION_KEY',
            'satosa_user_id_hash_salt'                      => 'SATOSA_USER_ID_HASH_SALT',
            'satosa_org_display_name_it'                    => 'SATOSA_ORGANIZATION_DISPLAY_NAME_IT',
            'satosa_org_display_name_en'                    => 'SATOSA_ORGANIZATION_DISPLAY_NAME_EN',
            'satosa_org_name_it'                            => 'SATOSA_ORGANIZATION_NAME_IT',
            'satosa_org_name_en'                            => 'SATOSA_ORGANIZATION_NAME_EN',
            'satosa_org_url_it'                             => 'SATOSA_ORGANIZATION_URL_IT',
            'satosa_org_url_en'                             => 'SATOSA_ORGANIZATION_URL_EN',
            'satosa_org_identifier'                         => 'SATOSA_ORGANIZATION_IDENTIFIER',
            'satosa_contact_given_name'                     => 'SATOSA_CONTACT_PERSON_GIVEN_NAME',
            'satosa_contact_email'                          => 'SATOSA_CONTACT_PERSON_EMAIL_ADDRESS',
            'satosa_contact_phone'                          => 'SATOSA_CONTACT_PERSON_TELEPHONE_NUMBER',
            'satosa_contact_fiscalcode'                     => 'SATOSA_CONTACT_PERSON_FISCALCODE',
            'satosa_contact_ipa_code'                       => 'SATOSA_CONTACT_PERSON_IPA_CODE',
            'satosa_contact_municipality'                   => 'SATOSA_CONTACT_PERSON_MUNICIPALITY',
            'satosa_get_spid_idp_metadata'                  => 'SATOSA_GET_SPID_IDP_METADATA',
            'satosa_get_cie_idp_metadata'                   => 'SATOSA_GET_CIE_IDP_METADATA',
            'satosa_get_ficep_idp_metadata'                 => 'SATOSA_GET_FICEP_IDP_METADATA',
            'satosa_get_idem_mdq_key'                       => 'SATOSA_GET_IDEM_MDQ_KEY',
            'satosa_use_demo_spid_idp'                      => 'SATOSA_USE_DEMO_SPID_IDP',
            'satosa_use_spid_validator'                     => 'SATOSA_USE_SPID_VALIDATOR',
            'satosa_spid_validator_metadata_url'            => 'SATOSA_SPID_VALIDATOR_METADATA_URL',
            'satosa_disable_cieoidc_backend'                => 'SATOSA_DISABLE_CIEOIDC_BACKEND',
            'satosa_disable_pyeudiw_backend'                => 'SATOSA_DISABLE_PYEUDIW_BACKEND',
            'satosa_ui_display_name_it'                     => 'SATOSA_UI_DISPLAY_NAME_IT',
            'satosa_ui_display_name_en'                     => 'SATOSA_UI_DISPLAY_NAME_EN',
            'satosa_ui_description_it'                      => 'SATOSA_UI_DESCRIPTION_IT',
            'satosa_ui_description_en'                      => 'SATOSA_UI_DESCRIPTION_EN',
            'satosa_ui_information_url_it'                  => 'SATOSA_UI_INFORMATION_URL_IT',
            'satosa_ui_information_url_en'                  => 'SATOSA_UI_INFORMATION_URL_EN',
            'satosa_ui_privacy_url_it'                      => 'SATOSA_UI_PRIVACY_URL_IT',
            'satosa_ui_privacy_url_en'                      => 'SATOSA_UI_PRIVACY_URL_EN',
            'satosa_ui_legal_url_it'                        => 'SATOSA_UI_LEGAL_URL_IT',
            'satosa_ui_legal_url_en'                        => 'SATOSA_UI_LEGAL_URL_EN',
            'satosa_ui_accessibility_url_it'                => 'SATOSA_UI_ACCESSIBILITY_URL_IT',
            'satosa_ui_accessibility_url_en'                => 'SATOSA_UI_ACCESSIBILITY_URL_EN',
            'satosa_ui_logo_url'                            => 'SATOSA_UI_LOGO_URL',
            'satosa_ui_logo_width'                          => 'SATOSA_UI_LOGO_WIDTH',
            'satosa_ui_logo_height'                         => 'SATOSA_UI_LOGO_HEIGHT',
            'enable_spid'                                   => 'ENABLE_SPID',
            'enable_cie_oidc'                               => 'ENABLE_CIE_OIDC',
            'enable_it_wallet'                              => 'ENABLE_IT_WALLET',
            'enable_oidcop'                                 => 'ENABLE_OIDCOP',
            'enable_idem'                                   => 'ENABLE_IDEM',
            'enable_eidas'                                  => 'ENABLE_EIDAS',
            'spid_cert_entity_id'                           => 'SPID_CERT_ENTITY_ID',
            'spid_cert_common_name'                         => 'SPID_CERT_COMMON_NAME',
            'spid_cert_org_id'                              => 'SPID_CERT_ORG_ID',
            'spid_cert_org_name'                            => 'SPID_CERT_ORG_NAME',
            'spid_cert_locality_name'                       => 'SPID_CERT_LOCALITY_NAME',
            'spid_cert_key_size'                            => 'SPID_CERT_KEY_SIZE',
            'spid_cert_days'                                => 'SPID_CERT_DAYS',
            'cie_oidc_provider_url'                         => 'CIE_OIDC_PROVIDER_URL',
            'cie_oidc_trust_anchor_url'                     => 'CIE_OIDC_TRUST_ANCHOR_URL',
            'cie_oidc_authority_hint_url'                   => 'CIE_OIDC_AUTHORITY_HINT_URL',
            'cie_oidc_client_id'                            => 'CIE_OIDC_CLIENT_ID',
            'cie_oidc_client_name'                          => 'CIE_OIDC_CLIENT_NAME',
            'cie_oidc_organization_name'                    => 'CIE_OIDC_ORGANIZATION_NAME',
            'cie_oidc_jwks_uri'                             => 'CIE_OIDC_JWKS_URI',
            'cie_oidc_signed_jwks_uri'                      => 'CIE_OIDC_SIGNED_JWKS_URI',
            'cie_oidc_redirect_uri'                         => 'CIE_OIDC_REDIRECT_URI',
            'cie_oidc_federation_resolve_endpoint'          => 'CIE_OIDC_FEDERATION_RESOLVE_ENDPOINT',
            'cie_oidc_federation_fetch_endpoint'            => 'CIE_OIDC_FEDERATION_FETCH_ENDPOINT',
            'cie_oidc_federation_trust_mark_status_endpoint' => 'CIE_OIDC_FEDERATION_TRUST_MARK_STATUS_ENDPOINT',
            'cie_oidc_federation_list_endpoint'             => 'CIE_OIDC_FEDERATION_LIST_ENDPOINT',
            'cie_oidc_homepage_uri'                         => 'CIE_OIDC_HOMEPAGE_URI',
            'cie_oidc_policy_uri'                           => 'CIE_OIDC_POLICY_URI',
            'cie_oidc_logo_uri'                             => 'CIE_OIDC_LOGO_URI',
            'cie_oidc_contact_email'                        => 'CIE_OIDC_CONTACT_EMAIL',
        ];

        $env = [];
        foreach ($map as $dbKey => $envVar) {
            $val = $s[$dbKey] ?? null;
            if ($val !== null && $val !== '') {
                $env[$envVar] = $val;
            }
        }

        // Garantisce che tutti gli ENABLE_* abbiano un valore default esplicito (false se mancante).
        // Questo previene crash di SATOSA quando il valore è NULL nel DB.
        $enableFlags = ['ENABLE_SPID', 'ENABLE_CIE', 'ENABLE_CIE_OIDC', 'ENABLE_IT_WALLET', 'ENABLE_OIDCOP', 'ENABLE_IDEM', 'ENABLE_EIDAS'];
        foreach ($enableFlags as $flag) {
            if (!array_key_exists($flag, $env)) {
                $env[$flag] = 'false';  // Default esplicito a 'false' se non valorizzato in DB
            }
        }

        // SATOSA_BASE_STATIC e SATOSA_HOSTNAME derivati
        if (!empty($env['SATOSA_BASE'])) {
            $env['SATOSA_BASE_STATIC'] = rtrim($env['SATOSA_BASE'], '/') . '/static';
            // SATOSA_HOSTNAME: priorità al campo DB; fallback: parse dall'URL pubblico
            $hostname = $s['hostname'] ?? '';
            if ($hostname === '') {
                $hostname = (string) parse_url((string)($s['public_base_url'] ?? ''), PHP_URL_HOST);
            }
            $env['SATOSA_HOSTNAME'] = $hostname;
        }

        // Fallback per campi derivabili rimossi dal form: garantisce che le ENV var
        // abbiano sempre un valore anche su installazioni precedenti al refactor.
        if (empty($env['SPID_CERT_ORG_NAME'])) {
            $env['SPID_CERT_ORG_NAME'] = (string)($sEntity['name'] ?? '');
        }
        if (empty($env['SPID_CERT_COMMON_NAME'])) {
            $env['SPID_CERT_COMMON_NAME'] = $env['SATOSA_HOSTNAME'] ?? (string)($s['hostname'] ?? '');
        }
        if (empty($env['SPID_CERT_LOCALITY_NAME'])) {
            $env['SPID_CERT_LOCALITY_NAME'] = (string)($sEntity['city'] ?? '');
        }
        if (empty($env['SATOSA_ORGANIZATION_NAME_IT'])) {
            $env['SATOSA_ORGANIZATION_NAME_IT'] = (string)($sEntity['name'] ?? '');
        }
        if (empty($env['SATOSA_ORGANIZATION_DISPLAY_NAME_IT'])) {
            $env['SATOSA_ORGANIZATION_DISPLAY_NAME_IT'] = (string)($sEntity['name'] ?? '');
        }
        $globalLogoSrc = trim((string)($sUi['logo_src'] ?? ''));
        if ($globalLogoSrc !== '') {
            // Converti /img/... → /static/img/... (servito da auth-proxy-nginx dal volume gil_images)
            $proxyLogoSrc = (str_starts_with($globalLogoSrc, '/img/') || str_starts_with($globalLogoSrc, '/assets/'))
                ? '/static' . $globalLogoSrc
                : $globalLogoSrc;
            $env['APP_LOGO_SRC']       = $proxyLogoSrc;
            $env['SATOSA_UI_LOGO_URL'] = $proxyLogoSrc;
            // CIE OIDC metadata richiede URL assoluto
            $satosaBase = rtrim((string)($s['public_base_url'] ?? ''), '/');
            $env['CIE_OIDC_LOGO_URI'] = ($satosaBase !== '' && !str_starts_with($proxyLogoSrc, 'http'))
                ? $satosaBase . $proxyLogoSrc
                : $proxyLogoSrc;
        }
        if (empty($env['CIE_OIDC_ORGANIZATION_NAME'])) {
            $env['CIE_OIDC_ORGANIZATION_NAME'] = (string)($sEntity['name'] ?? '');
        }

        // Variabili cross-section: frontoffice e entity
        if (!empty($sFrontoffice['public_base_url'])) {
            $env['FRONTOFFICE_PUBLIC_BASE_URL'] = $sFrontoffice['public_base_url'];
        }
        if (!empty($sEntity['name'])) {
            $env['APP_ENTITY_NAME'] = $sEntity['name'];
        }
        if (!empty($sEntity['url'])) {
            $env['APP_ENTITY_URL'] = $sEntity['url'];
        }

        $env['SETUP_COMPLETE'] = \App\Config\ConfigLoader::isSetupComplete() ? 'true' : 'false';

        $resp = new SlimResponse(200);
        $resp->getBody()->write(json_encode($env, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $resp->withHeader('Content-Type', 'application/json');
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
        return $this->pingUrl(SettingsRepository::get('pagopa', 'checkout_ec_base_url', ''), 'pagoPA Checkout');
    }

    public function testPaymentOptions(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        return $this->pingUrl(SettingsRepository::get('pagopa', 'payment_options_url', ''), 'Payment Options API');
    }

    public function testBizEvents(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        return $this->pingUrl(SettingsRepository::get('pagopa', 'biz_events_host', ''), 'BizEvents');
    }

    public function testTassonomie(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        return $this->pingUrl(SettingsRepository::get('pagopa', 'tassonomie_url', ''), 'Tassonomie');
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

    public function getSpidCertInfo(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $certPath = self::SPID_CERTS_DIR . '/cert.pem';
        $keyPath  = self::SPID_CERTS_DIR . '/privkey.pem';

        if (!is_file($certPath)) {
            return $this->jsonResponse([
                'exists'  => false,
                'message' => 'Certificato SPID non ancora generato. Usa "Genera certificato SPID".',
            ]);
        }

        $info = [
            'exists'   => true,
            'has_key'  => is_file($keyPath),
            'file_mtime' => date('c', filemtime($certPath)),
        ];

        $certPem = @file_get_contents($certPath);
        if ($certPem !== false) {
            $cert = openssl_x509_read($certPem);
            if ($cert !== false) {
                $parsed = openssl_x509_parse($cert);
                $info['subject_cn']  = $parsed['subject']['CN'] ?? '';
                $info['subject_o']   = $parsed['subject']['O'] ?? '';
                $info['subject_l']   = $parsed['subject']['L'] ?? '';
                $info['subject_c']   = $parsed['subject']['C'] ?? '';
                $info['valid_from']  = isset($parsed['validFrom_time_t']) ? date('c', $parsed['validFrom_time_t']) : '';
                $info['valid_to']    = isset($parsed['validTo_time_t']) ? date('c', $parsed['validTo_time_t']) : '';
                $info['expired']     = isset($parsed['validTo_time_t']) ? ($parsed['validTo_time_t'] <= time()) : null;
                $info['days_left']   = isset($parsed['validTo_time_t']) ? max(0, (int)(($parsed['validTo_time_t'] - time()) / 86400)) : null;
                $info['fingerprint_sha256'] = openssl_x509_fingerprint($cert, 'sha256') ?: '';
                $pubKey = openssl_pkey_get_public($cert);
                if ($pubKey !== false) {
                    $keyDetails = openssl_pkey_get_details($pubKey);
                    $info['key_bits'] = $keyDetails['bits'] ?? null;
                    $info['key_type'] = match ($keyDetails['type'] ?? -1) {
                        OPENSSL_KEYTYPE_RSA => 'RSA',
                        OPENSSL_KEYTYPE_EC  => 'EC',
                        default => 'unknown',
                    };
                }
            }
        }

        return $this->jsonResponse($info);
    }

    public function getCieKeyDetails(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $keysDir     = self::CIEOIDC_KEYS_DIR;
        $generatedAt = $keysDir . '/GENERATED_AT';
        $files       = glob($keysDir . '/*.json') ?: [];

        if (empty($files)) {
            return $this->jsonResponse([
                'exists'  => false,
                'message' => 'Chiavi JWK CIE OIDC non ancora generate. Usa "Genera chiavi JWK".',
            ]);
        }

        $generatedTs = is_file($generatedAt) ? (int)trim((string)file_get_contents($generatedAt)) : null;

        $keys = [];
        foreach ($files as $filePath) {
            $content = @file_get_contents($filePath);
            if ($content === false) {
                continue;
            }
            $jwk = json_decode($content, true);
            if (!is_array($jwk)) {
                continue;
            }
            $keyData = isset($jwk['keys']) ? ($jwk['keys'][0] ?? null) : $jwk;
            if (!is_array($keyData)) {
                continue;
            }
            $entry = [
                'file'    => basename($filePath),
                'kid'     => $keyData['kid'] ?? '',
                'kty'     => $keyData['kty'] ?? '',
                'use'     => $keyData['use'] ?? '',
            ];
            if (isset($keyData['n'])) {
                $n = base64_decode(strtr($keyData['n'], '-_', '+/'));
                $entry['key_bits'] = strlen($n) * 8;
            } elseif (isset($keyData['crv'])) {
                $entry['crv'] = $keyData['crv'];
            }
            $keys[] = $entry;
        }

        return $this->jsonResponse([
            'exists'       => true,
            'keys'         => $keys,
            'key_count'    => count($keys),
            'generated_at' => $generatedTs ? date('c', $generatedTs) : null,
        ]);
    }

    public function getCieKeysAsJwks(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $keysDir = self::CIEOIDC_KEYS_DIR;
        $files   = glob($keysDir . '/*.json') ?: [];

        $aggregatedKeys = [];
        foreach ($files as $filePath) {
            $content = @file_get_contents($filePath);
            if ($content === false) {
                continue;
            }
            $jwk = json_decode($content, true);
            if (!is_array($jwk)) {
                continue;
            }
            // Se il file contiene un array "keys", estrai i dati da lì
            if (isset($jwk['keys']) && is_array($jwk['keys'])) {
                foreach ($jwk['keys'] as $keyItem) {
                    if (is_array($keyItem)) {
                        $aggregatedKeys[] = $keyItem;
                    }
                }
            } else {
                // Altrimenti il file è una singola chiave JWK
                $aggregatedKeys[] = $jwk;
            }
        }

        return $this->jsonResponse([
            'content_type' => 'application/json',
            'data'         => ['keys' => $aggregatedKeys],
        ]);
    }

    public function restoreBackupZip(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $uploadedFiles = $request->getUploadedFiles();

        $zipFile = $uploadedFiles['backup_zip'] ?? null;
        if (!$zipFile || $zipFile->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError('File ZIP mancante o errore upload.');
        }
        if (!class_exists('ZipArchive')) {
            return $this->jsonError('ZipArchive non disponibile nel container.', 500);
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'restore_zip_');
        $zipFile->moveTo($tmpZip);
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            return $this->jsonError('File ZIP non valido o corrotto.');
        }

        // Index archive contents
        $contents = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $contents[] = $zip->getNameIndex($i);
        }

        $results = [];

        // SPID certs
        $certContent = null;
        $keyContent  = null;
        foreach ($contents as $name) {
            if (str_ends_with($name, 'cert.pem')) {
                $certContent = $zip->getFromName($name);
            } elseif (str_ends_with($name, 'privkey.pem')) {
                $keyContent = $zip->getFromName($name);
            }
        }
        if ($certContent !== null && $keyContent !== null) {
            $cert = openssl_x509_read($certContent);
            $key  = openssl_pkey_get_private($keyContent);
            if ($cert && $key && openssl_x509_check_private_key($cert, $key)) {
                @mkdir(self::SPID_CERTS_DIR, 0755, true);
                file_put_contents(self::SPID_CERTS_DIR . '/cert.pem', $certContent);
                file_put_contents(self::SPID_CERTS_DIR . '/privkey.pem', $keyContent);
                @chmod(self::SPID_CERTS_DIR . '/cert.pem', 0644);
                @chmod(self::SPID_CERTS_DIR . '/privkey.pem', 0600);
                $results['spid_certs'] = ['success' => true, 'message' => 'Certificati SPID ripristinati.'];
            } else {
                $results['spid_certs'] = ['success' => false, 'message' => 'Certificati SPID nel ZIP non validi o non corrispondenti.'];
            }
        }

        // CIE OIDC keys
        $cieRestored = [];
        foreach ($contents as $name) {
            $basename = basename($name);
            if (str_starts_with($basename, 'jwk-') && str_ends_with($basename, '.json')) {
                $content = $zip->getFromName($name);
                if (is_array(json_decode($content, true))) {
                    @mkdir(self::CIEOIDC_KEYS_DIR, 0755, true);
                    file_put_contents(self::CIEOIDC_KEYS_DIR . '/' . $basename, $content);
                    $cieRestored[] = $basename;
                }
            }
        }
        if (!empty($cieRestored)) {
            $results['cieoidc_keys'] = ['success' => true, 'message' => 'Chiavi CIE OIDC ripristinate: ' . implode(', ', $cieRestored)];
        }

        // SPID public metadata
        foreach ($contents as $name) {
            if (str_ends_with($name, '.xml')) {
                $content = $zip->getFromName($name);
                if ($content !== false && str_contains($content, 'EntityDescriptor')) {
                    @mkdir(self::SPID_PUBLIC_META_DIR, 0775, true);
                    file_put_contents(self::PUBLIC_SPID_METADATA_PATH, $content);
                    @chmod(self::PUBLIC_SPID_METADATA_PATH, 0664);
                    $results['spid_metadata'] = ['success' => true, 'message' => 'Metadata pubblico SPID ripristinato.'];
                    break;
                }
            }
        }

        // CIE entity configuration
        foreach ($contents as $name) {
            $basename = basename($name);
            if (str_ends_with($basename, '.json') && !str_starts_with($basename, 'jwk-') && $basename !== 'auth-proxy-settings.json') {
                $content = $zip->getFromName($name);
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['sub'])) {
                    @mkdir(self::CIEOIDC_META_DIR, 0775, true);
                    file_put_contents(self::CIE_METADATA_PATH, $content);
                    $results['cie_metadata'] = ['success' => true, 'message' => 'Entity configuration CIE OIDC ripristinata.'];
                    break;
                }
            }
        }

        $zip->close();
        @unlink($tmpZip);

        return $this->jsonResponse([
            'success' => true,
            'results' => $results,
            'message' => 'Ripristino completato. ' . count($results) . ' elemento/i elaborato/i.',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // METADATA SPID / CIE (lettura diretta dai volumi montati)
    // ──────────────────────────────────────────────────────────────────────

    public function getSpidMetadataInfo(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $path = self::PUBLIC_SPID_METADATA_PATH;
        if (!is_file($path)) {
            return $this->jsonResponse(['exists' => false, 'message' => 'Metadata pubblico SPID non ancora esportato.']);
        }
        $info = ['exists' => true, 'size' => filesize($path), 'file_mtime' => date('c', filemtime($path))];
        if (!is_readable($path)) {
            $healed = $this->refreshSpidMetadataFromLiveEndpoint();
            clearstatcache(true, $path);
            if (!$healed || !is_file($path) || !is_readable($path)) {
                $info['read_error'] = 'Metadata presente ma non leggibile dal backoffice (permessi volume).';
                return $this->jsonResponse($info);
            }
            $info['size'] = filesize($path);
            $info['file_mtime'] = date('c', filemtime($path));
        }
        try {
            libxml_use_internal_errors(true);
            $raw = file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                $info['read_error'] = 'Metadata presente ma non leggibile dal backoffice (permessi volume).';
                return $this->jsonResponse($info);
            }
            $xml = simplexml_load_string($raw);
            if ($xml !== false) {
                $info['entity_id'] = (string) ($xml['entityID'] ?? '');
                $attrs = $xml->attributes();
                $validUntil = isset($attrs['validUntil']) ? (string) $attrs['validUntil'] : '';
                if ($validUntil !== '') {
                    $validTs = strtotime($validUntil);
                    $info['valid_until'] = $validUntil;
                    $info['valid_until_expired'] = $validTs !== false ? $validTs <= time() : null;
                }

                $namespaces = $xml->getNamespaces(true);
                $mdNs = $namespaces['md'] ?? 'urn:oasis:names:tc:SAML:2.0:metadata';
                $dsNs = $namespaces['ds'] ?? 'http://www.w3.org/2000/09/xmldsig#';
                $xml->registerXPathNamespace('md', $mdNs);
                $xml->registerXPathNamespace('ds', $dsNs);

                $spSsoNodes = $xml->xpath('//md:SPSSODescriptor');
                if (!empty($spSsoNodes)) {
                    $acsUrls = [];
                    foreach ($xml->xpath('//md:SPSSODescriptor/md:AssertionConsumerService') ?: [] as $acs) {
                        $location = (string) ($acs['Location'] ?? '');
                        if ($location !== '') {
                            $acsUrls[] = $location;
                        }
                    }
                    $info['acs_urls'] = $acsUrls;

                    $certNodes = $xml->xpath('//md:SPSSODescriptor/md:KeyDescriptor[@use="signing"]/ds:KeyInfo/ds:X509Data/ds:X509Certificate');
                    if (!$certNodes) {
                        $certNodes = $xml->xpath('//md:SPSSODescriptor/md:KeyDescriptor/ds:KeyInfo/ds:X509Data/ds:X509Certificate');
                    }
                    $keyCert = $certNodes[0] ?? null;
                    if ($keyCert) {
                        $cert = openssl_x509_read("-----BEGIN CERTIFICATE-----\n" . chunk_split((string) $keyCert, 64) . "-----END CERTIFICATE-----\n");
                        if ($cert) {
                            $parsed = openssl_x509_parse($cert);
                            if (isset($parsed['validTo_time_t'])) {
                                $info['cert_expiry'] = date('c', $parsed['validTo_time_t']);
                                $info['cert_expired'] = $parsed['validTo_time_t'] <= time();
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $info['parse_error'] = $e->getMessage();
        }
        return $this->jsonResponse($info);
    }

    public function exportSpidMetadata(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body  = $this->parseBody($request);
        $force = $this->requestForce($request, $body);

        // Evita lock di sessione durante una chiamata potenzialmente lunga al metadata-builder.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        $builderUrl    = rtrim((string)(getenv('METADATA_BUILDER_INTERNAL_URL') ?: 'http://metadata-builder:8081'), '/');
        $masterToken   = (string)(getenv('MASTER_TOKEN') ?: '');
        $iamProxy      = SettingsRepository::getSection('iam_proxy');
        $publicBaseUrl = trim((string)($iamProxy['public_base_url'] ?? ''));
        $query = [];
        if ($force) {
            $query['force'] = '1';
        }
        if ($publicBaseUrl !== '') {
            $hostHeader = (string)(parse_url($publicBaseUrl, PHP_URL_HOST) ?: '');
            if ($hostHeader !== '') {
                $query['host_header'] = $hostHeader;
            }
            $query['public_base_url'] = $publicBaseUrl;
        }
        $endpoint = "{$builderUrl}/run/export-agid";
        if (!empty($query)) {
            $endpoint .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if (!function_exists('curl_init')) {
            return $this->jsonError('Generazione metadata SPID fallita: estensione PHP cURL non disponibile.');
        }

        $raw = false;
        $httpCode = 0;
        $curlErr = '';
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return $this->jsonError('Generazione metadata SPID fallita: impossibile inizializzare la connessione HTTP.');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$masterToken}",
            "Content-Length: 0",
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string)curl_error($ch);

        if (!is_string($raw) || $raw === '') {
            $netErr = $curlErr !== '' ? $curlErr : "HTTP {$httpCode}";
            return $this->jsonError("Generazione metadata SPID fallita: metadata-builder non raggiungibile ({$netErr}).");
        }
        $result = $raw !== false ? json_decode($raw, true) : null;

        if (!($result['success'] ?? false)) {
            $err = $result['stderr'] ?? $result['error'] ?? 'risposta non valida dal metadata-builder';
            return $this->jsonError("Generazione metadata SPID fallita: {$err}");
        }

        // Riallinea il file locale dal metadata live per evitare drift di permessi sul volume condiviso.
        $this->refreshSpidMetadataFromLiveEndpoint();

        // Leggi entityID e validUntil dal file scritto sul volume condiviso oppure risincronizzato localmente.
        $info = [];
        $path = self::PUBLIC_SPID_METADATA_PATH;
        if (is_file($path) && is_readable($path)) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string((string)file_get_contents($path));
            if ($xml !== false) {
                $info['entity_id']   = (string)($xml['entityID'] ?? '');
                $info['valid_until'] = isset($xml['validUntil']) ? (string)$xml['validUntil'] : '';
            }
        } elseif (is_file($path)) {
            $info['read_error'] = 'Metadata aggiornato ma non leggibile dal backoffice (permessi volume).';
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => 'Metadata pubblico SPID rigenerato correttamente.',
            ...$info,
        ]);
    }

    private function refreshSpidMetadataFromLiveEndpoint(): bool
    {
        $iamProxy = SettingsRepository::getSection('iam_proxy');
        $publicBaseUrl = trim((string)($iamProxy['public_base_url'] ?? ''));
        $xml = $this->fetchSpidMetadataXml($publicBaseUrl);
        if ($xml === null) {
            return false;
        }

        if (!is_dir(self::SPID_PUBLIC_META_DIR) && !@mkdir(self::SPID_PUBLIC_META_DIR, 0775, true) && !is_dir(self::SPID_PUBLIC_META_DIR)) {
            return false;
        }

        $written = @file_put_contents(self::PUBLIC_SPID_METADATA_PATH, $xml, LOCK_EX);
        if ($written === false) {
            return false;
        }

        @chmod(self::PUBLIC_SPID_METADATA_PATH, 0664);
        return true;
    }

    private function fetchSpidMetadataXml(string $publicBaseUrl = ''): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $hostHeader = '';
        if ($publicBaseUrl !== '') {
            $hostHeader = (string)(parse_url($publicBaseUrl, PHP_URL_HOST) ?: '');
        }

        $attempts = [
            ['url' => 'http://auth-proxy-nginx:80/spidSaml2/metadata', 'host' => $hostHeader],
            ['url' => 'http://auth-proxy-nginx:80/spidSaml2/metadata', 'host' => ''],
            ['url' => 'https://auth-proxy-nginx:80/spidSaml2/metadata', 'host' => $hostHeader],
            ['url' => 'https://auth-proxy-nginx:80/spidSaml2/metadata', 'host' => ''],
        ];

        foreach ($attempts as $attempt) {
            $ch = curl_init($attempt['url']);
            if ($ch === false) {
                continue;
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            if ($attempt['host'] !== '') {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: ' . $attempt['host']]);
            }
            if (str_starts_with($attempt['url'], 'https://')) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }

            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (is_string($body) && $status === 200 && str_contains($body, 'EntityDescriptor')) {
                return $body;
            }
        }

        return null;
    }

    public function downloadSpidMetadata(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $path = self::PUBLIC_SPID_METADATA_PATH;
        if (!is_file($path)) {
            return $this->jsonError('Metadata pubblico SPID non trovato nel volume.');
        }
        $data = file_get_contents($path);
        $resp = new \Slim\Psr7\Response(200);
        $resp->getBody()->write($data);
        return $resp
            ->withHeader('Content-Type', 'application/xml')
            ->withHeader('Content-Disposition', 'attachment; filename="satosa_spid_public_metadata.xml"');
    }

    public function restoreSpidMetadata(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['metadata_file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError('File metadata SPID (metadata_file) mancante o errore upload.');
        }
        $content = (string)$file->getStream();
        // Basic XML validation
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false || !str_contains($content, 'EntityDescriptor')) {
            return $this->jsonError('File non valido: deve essere un XML metadata SAML2 con EntityDescriptor.');
        }
        @mkdir(self::SPID_PUBLIC_META_DIR, 0775, true);
        if (file_put_contents(self::PUBLIC_SPID_METADATA_PATH, $content) === false) {
            return $this->jsonError('Impossibile scrivere il file nel volume.');
        }
        @chmod(self::PUBLIC_SPID_METADATA_PATH, 0664);
        $entityId   = (string)($xml['entityID'] ?? '');
        $validUntil = isset($xml['validUntil']) ? (string)$xml['validUntil'] : '';
        return $this->jsonResponse([
            'success'     => true,
            'message'     => 'Metadata pubblico SPID ripristinato correttamente.',
            'entity_id'   => $entityId,
            'valid_until' => $validUntil,
        ]);
    }

    public function getCieMetadataInfo(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $path = self::CIE_METADATA_PATH;
        if (!is_file($path)) {
            return $this->jsonResponse(['exists' => false, 'message' => 'Entity configuration CIE non ancora esportata.']);
        }
        $info = ['exists' => true, 'size' => filesize($path), 'modified' => date('c', filemtime($path))];
        $json = json_decode((string)file_get_contents($path), true);
        if (is_array($json)) {
            $info['sub'] = $json['sub'] ?? '';
            $info['iss'] = $json['iss'] ?? '';
            if (isset($json['iat'])) {
                $info['iat'] = date('c', (int)$json['iat']);
            }
            if (isset($json['exp'])) {
                $expTs = (int)$json['exp'];
                $info['exp']           = date('c', $expTs);
                $info['exp_expired']   = $expTs <= time();
                $info['exp_days_left'] = max(0, (int)(($expTs - time()) / 86400));
            }
            // Federation JWK count
            $fedJwks = $json['jwks']['keys'] ?? [];
            $info['federation_jwk_count'] = count($fedJwks);
            // RP metadata
            $rpMeta = $json['metadata']['openid_relying_party'] ?? [];
            $info['client_name']   = $rpMeta['client_name'] ?? ($rpMeta['client_name#it'] ?? '');
            $info['redirect_uris'] = $rpMeta['redirect_uris'] ?? [];
        }
        return $this->jsonResponse($info);
    }

    public function downloadCieMetadata(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $path = self::CIE_METADATA_PATH;
        if (!is_file($path)) {
            return $this->jsonError('Entity configuration CIE non trovata nel volume.');
        }
        $data = file_get_contents($path);
        $resp = new \Slim\Psr7\Response(200);
        $resp->getBody()->write($data);
        return $resp
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="cie-entity-configuration.json"');
    }

    public function restoreCieMetadata(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->saveUploadedFile($request, 'metadata_file', self::CIE_METADATA_PATH, ['application/json', 'text/plain']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // GENERAZIONE CERT SPID / CHIAVI JWK CIE / EXPORT CIE OIDC
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Genera certificato SPID (cert.pem + privkey.pem) nel volume spid_certs.
     * Usa openssl CLI già presente nell'immagine PHP/Apache.
     *
     * Body JSON atteso:
     *   common_name, locality_name, org_id (PA:IT-...), org_name (optional),
     *   entity_id, days (default 730), key_size (default 3072)
     */
    public function generaSpidCerts(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);

        // Fallback robusto: se il body parser non ha decodificato JSON,
        // prova a leggere il payload raw per evitare errori sui campi obbligatori.
        if ($body === []) {
            $raw = trim((string)$request->getBody());
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        $iamSettings = SettingsRepository::getSection('iam_proxy');
        $foSettings  = SettingsRepository::getSection('frontoffice');
        $entity      = SettingsRepository::getSection('entity');

        $frontofficeBase = rtrim((string)($foSettings['public_base_url'] ?? ''), '/');
        if ($frontofficeBase === '') {
            $frontofficeBase = 'https://127.0.0.1:8444';
        }
        $derivedEntityId = $frontofficeBase . '/saml/sp';

        $commonName = trim((string)($body['common_name'] ?? $body['spid_cert_common_name'] ?? $iamSettings['spid_cert_common_name'] ?? $iamSettings['hostname'] ?? $entity['name'] ?? ''));
        $locality   = trim((string)($body['locality_name'] ?? $body['spid_cert_locality_name'] ?? $iamSettings['spid_cert_locality_name'] ?? ''));
        $orgId      = trim((string)($body['org_id'] ?? $body['spid_cert_org_id'] ?? $iamSettings['spid_cert_org_id'] ?? ''));
        $orgName    = trim((string)($body['org_name'] ?? $body['spid_cert_org_name'] ?? $iamSettings['spid_cert_org_name'] ?? $commonName));
        $entityId   = trim((string)($body['entity_id'] ?? $body['spid_cert_entity_id'] ?? $iamSettings['spid_cert_entity_id'] ?? $derivedEntityId));
        $days       = max(1, min(3650, (int)($body['days'] ?? $body['spid_cert_days'] ?? $iamSettings['spid_cert_days'] ?? 730)));
        $keySize    = (int)($body['key_size'] ?? $body['spid_cert_key_size'] ?? $iamSettings['spid_cert_key_size'] ?? 3072);

        foreach (['common_name' => $commonName, 'locality_name' => $locality, 'org_id' => $orgId, 'org_name' => $orgName, 'entity_id' => $entityId] as $field => $val) {
            if ($val === '') {
                return $this->jsonError("Campo obbligatorio mancante: {$field}");
            }
        }
        if (!preg_match('/^PA:IT-[A-Za-z0-9_]+$/', $orgId)) {

            return $this->jsonError("org_id deve avere il formato 'PA:IT-<codice IPA>' (es. PA:IT-c_f646)");
        }
        if (!in_array($keySize, [2048, 3072, 4096], true)) {
            return $this->jsonError("key_size deve essere 2048, 3072 o 4096");
        }
        if (filter_var($entityId, FILTER_VALIDATE_URL) === false) {
            return $this->jsonError("entity_id deve essere un URL valido (es. https://dominio/saml/sp)");
        }
        foreach ([$commonName, $locality, $orgId, $orgName, $entityId] as $val) {
            if (str_contains($val, "\0") || str_contains($val, "\r") || str_contains($val, "\n")) {
                return $this->jsonError("I campi non possono contenere caratteri speciali non ammessi.");
            }
        }

        // Persist parameters to DB so they survive modal close
        SettingsRepository::set('iam_proxy', 'spid_cert_common_name', $commonName);
        SettingsRepository::set('iam_proxy', 'spid_cert_locality_name', $locality);
        SettingsRepository::set('iam_proxy', 'spid_cert_org_id', $orgId);
        SettingsRepository::set('iam_proxy', 'spid_cert_org_name', $orgName);
        SettingsRepository::set('iam_proxy', 'spid_cert_entity_id', $entityId);
        SettingsRepository::set('iam_proxy', 'spid_cert_days', (string)$days);
        SettingsRepository::set('iam_proxy', 'spid_cert_key_size', (string)$keySize);

        $certsDir = self::SPID_CERTS_DIR;
        if (!is_dir($certsDir) && !mkdir($certsDir, 0755, true) && !is_dir($certsDir)) {
            return $this->jsonError("Impossibile creare la directory certificati SPID: {$certsDir}");
        }

        $tmpCnf = tempnam(sys_get_temp_dir(), 'spid_cnf_');

        $cnfContent = <<<CNF
            oid_section=spid_oids

            [ req ]
            default_bits={$keySize}
            default_md=sha512
            utf8=yes
            string_mask=utf8only
            distinguished_name=dn
            encrypt_key=no
            prompt=no
            req_extensions=req_ext

            [ spid_oids ]
            agidcert=1.3.76.16.6
            spid-publicsector-SP=1.3.76.16.4.2.1
            uri=2.5.4.83

            [ dn ]
            commonName={$commonName}
            countryName=IT
            localityName={$locality}
            organizationIdentifier={$orgId}
            organizationName={$orgName}
            uri={$entityId}

            [ req_ext ]
            basicConstraints=CA:FALSE
            keyUsage=critical,digitalSignature,nonRepudiation
            certificatePolicies=@agid_policies,@spid_policies

            [ agid_policies ]
            policyIdentifier=agidcert
            userNotice=@agidcert_notice

            [ agidcert_notice ]
            explicitText="agIDcert"

            [ spid_policies ]
            policyIdentifier=spid-publicsector-SP
            userNotice=@spid_notice

            [ spid_notice ]
            explicitText="cert_SP_Pub"
            CNF;

        file_put_contents($tmpCnf, $cnfContent);

        $certPath = $certsDir . '/cert.pem';
        $keyPath  = $certsDir . '/privkey.pem';

        $cmd = [
            'openssl', 'req', '-new', '-x509',
            '-config', $tmpCnf,
            '-days', (string)$days,
            '-keyout', $keyPath,
            '-out', $certPath,
            '-extensions', 'req_ext',
        ];

        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if ($proc === false) {
            @unlink($tmpCnf);
            return $this->jsonError('Impossibile avviare openssl.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr   = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        @unlink($tmpCnf);

        if ($exitCode !== 0) {
            return $this->jsonError('Generazione cert SPID fallita: ' . trim($stderr));
        }

        @chmod($certPath, 0644);
        @chmod($keyPath, 0600);

        $certInfo = [];
        $certPem = @file_get_contents($certPath);
        if ($certPem !== false) {
            $cert = openssl_x509_read($certPem);
            if ($cert !== false) {
                $parsed = openssl_x509_parse($cert);
                $certInfo['subject']    = $parsed['subject']['CN'] ?? '';
                $certInfo['valid_from'] = date('c', $parsed['validFrom_time_t']);
                $certInfo['valid_to']   = date('c', $parsed['validTo_time_t']);
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'message' => 'Certificati SPID generati correttamente.',
            'cert'    => $certInfo,
        ]);
    }

    /**
     * Genera le 3 chiavi RSA JWK per CIE OIDC nel volume cieoidc-keys.
     * Equivalente PHP di metadata/builder/gen-jwk.py.
     *
     * Body JSON: force (bool, default false) — sovrascrive chiavi esistenti recenti
     */
    public function generaCieKeys(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body    = $this->parseBody($request);
        $force   = $this->requestForce($request, $body);
        $keysDir = self::CIEOIDC_KEYS_DIR;

        $generatedAt = $keysDir . '/GENERATED_AT';
        if (!$force && is_file($generatedAt)) {
            $ts = (int)trim((string)file_get_contents($generatedAt));
            if ($ts > 0 && (time() - $ts) < 7 * 86400) {
                return $this->jsonError(
                    'Chiavi JWK CIE già presenti e generate meno di 7 giorni fa. ' .
                    'Usa force=true solo dopo aver revocato la federazione CIE.'
                );
            }
        }

        @mkdir($keysDir, 0700, true);

        $b64url = static fn(string $bin): string =>
            rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        $genJwk = static function(array $extra = []) use ($b64url): array {
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($key === false) {
                throw new \RuntimeException('openssl_pkey_new fallito: ' . openssl_error_string());
            }
            $details = openssl_pkey_get_details($key);
            $rsa     = $details['rsa'];

            $e_b64 = $b64url($rsa['e']);
            $n_b64 = $b64url($rsa['n']);

            // RFC 7638: SHA-256 del JSON canonico {"e":..., "kty":"RSA", "n":...}
            $canonical = json_encode(['e' => $e_b64, 'kty' => 'RSA', 'n' => $n_b64], JSON_UNESCAPED_SLASHES);
            $kid = rtrim(strtr(base64_encode(hash('sha256', (string)$canonical, true)), '+/', '-_'), '=');

            $jwk = array_merge($extra, [
                'kty' => 'RSA',
                'kid' => $kid,
                'e'   => $e_b64,
                'n'   => $n_b64,
                'd'   => $b64url($rsa['d']),
                'p'   => $b64url($rsa['p']),
                'q'   => $b64url($rsa['q']),
                // Parametri CRT obbligatori per jwcrypto/Python (RFC 7517 §9.3)
                'dp'  => $b64url($rsa['dmp1']),
                'dq'  => $b64url($rsa['dmq1']),
                'qi'  => $b64url($rsa['iqmp']),
            ]);
            openssl_pkey_free($key);
            return $jwk;
        };

        try {
            $jwkFed = $genJwk();
            $jwkSig = $genJwk(['use' => 'sig']);
            $jwkEnc = $genJwk(['use' => 'enc', 'alg' => 'RSA-OAEP']);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage());
        }

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        file_put_contents($keysDir . '/jwk-federation.json', json_encode($jwkFed, $flags));
        file_put_contents($keysDir . '/jwk-core-sig.json',   json_encode($jwkSig, $flags));
        file_put_contents($keysDir . '/jwk-core-enc.json',   json_encode($jwkEnc, $flags));
        file_put_contents($keysDir . '/GENERATED_AT',        (string)time());
        @chmod($keysDir . '/jwk-federation.json', 0600);
        @chmod($keysDir . '/jwk-core-sig.json',   0600);
        @chmod($keysDir . '/jwk-core-enc.json',   0600);

        return $this->jsonResponse([
            'success' => true,
            'message' => 'Chiavi JWK CIE OIDC generate correttamente.',
            'kids'    => [
                'federation' => $jwkFed['kid'],
                'sig'        => $jwkSig['kid'],
                'enc'        => $jwkEnc['kid'],
            ],
        ]);
    }

    /**
     * Esporta gli artefatti pubblici CIE OIDC dalla auth-proxy-nginx interna.
     * Equivalente PHP di metadata/builder/export-cieoidc.sh.
     *
     * Richiede auth-proxy attivo (profilo auth-proxy up).
     * Body JSON: force (bool, default false)
     */
    public function exportCieOidc(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body    = $this->parseBody($request);
        $force   = $this->requestForce($request, $body);
        $metaDir = self::CIEOIDC_META_DIR;

        // Evita lock di sessione durante una chiamata potenzialmente lunga al metadata-builder.
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        $compValues = $metaDir . '/component-values.env';
        if (!$force && is_file($compValues)) {
            $expEpoch = null;
            foreach (file($compValues, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with($line, 'ENTITY_STATEMENT_EXP_EPOCH=')) {
                    $expEpoch = (int)substr($line, strlen('ENTITY_STATEMENT_EXP_EPOCH='));
                    break;
                }
            }
            if ($expEpoch !== null && $expEpoch > time()) {
                $daysLeft = (int)(($expEpoch - time()) / 86400);
                return $this->jsonError(
                    "Export CIE OIDC già presente e non scaduto ({$daysLeft} giorni residui). " .
                    "Usa force=true solo dopo aver rigenerato le chiavi (renew-cieoidc)."
                );
            }
        }

        @mkdir($metaDir, 0755, true);

        $builderUrl  = rtrim((string)(getenv('METADATA_BUILDER_INTERNAL_URL') ?: 'http://metadata-builder:8081'), '/');
        $masterToken = (string)(getenv('MASTER_TOKEN') ?: '');
        $iamProxy    = SettingsRepository::getSection('iam_proxy');
        $publicBaseUrl = trim((string)($iamProxy['public_base_url'] ?? ''));
        $query = [];
        if ($force) {
            $query['force'] = '1';
        }
        if ($publicBaseUrl !== '') {
            $hostHeader = (string)(parse_url($publicBaseUrl, PHP_URL_HOST) ?: '');
            if ($hostHeader !== '') {
                $query['host_header'] = $hostHeader;
            }
            $query['public_base_url'] = $publicBaseUrl;
        }
        $endpoint = "{$builderUrl}/run/export-cieoidc";
        if (!empty($query)) {
            $endpoint .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if (!function_exists('curl_init')) {
            return $this->jsonError('Export CIE OIDC fallito: estensione PHP cURL non disponibile.');
        }

        $raw = false;
        $httpCode = 0;
        $curlErr = '';
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return $this->jsonError('Export CIE OIDC fallito: impossibile inizializzare la connessione HTTP.');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$masterToken}",
            'Content-Length: 0',
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = (string)curl_error($ch);

        if (!is_string($raw) || $raw === '') {
            $netErr = $curlErr !== '' ? $curlErr : "HTTP {$httpCode}";
            return $this->jsonError("Export CIE OIDC fallito: metadata-builder non raggiungibile ({$netErr}).");
        }
        $result = json_decode($raw, true);

        if (!($result['success'] ?? false)) {
            $err = $result['stderr'] ?? $result['error'] ?? 'risposta non valida dal metadata-builder';
            return $this->jsonError("Export CIE OIDC fallito: {$err}");
        }

        // Leggi i valori scritti da export-cieoidc.sh nel volume condiviso
        $expEpoch = 0;
        $expUtc   = '';
        $expDays  = 0;
        $clientId = '';
        if (is_file($compValues)) {
            foreach (file($compValues, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with($line, 'ENTITY_STATEMENT_EXP_EPOCH=')) {
                    $expEpoch = (int)substr($line, strlen('ENTITY_STATEMENT_EXP_EPOCH='));
                } elseif (str_starts_with($line, 'ENTITY_STATEMENT_EXP_UTC=')) {
                    $expUtc = substr($line, strlen('ENTITY_STATEMENT_EXP_UTC='));
                } elseif (str_starts_with($line, 'ENTITY_STATEMENT_EXP_DAYS_REMAINING=')) {
                    $expDays = (int)substr($line, strlen('ENTITY_STATEMENT_EXP_DAYS_REMAINING='));
                } elseif (str_starts_with($line, 'PUBLIC_COMPONENT_IDENTIFIER=')) {
                    $clientId = substr($line, strlen('PUBLIC_COMPONENT_IDENTIFIER='));
                }
            }
        }

        return $this->jsonResponse([
            'success'   => true,
            'message'   => 'Export CIE OIDC completato correttamente.',
            'sub'       => $clientId,
            'exp'       => $expUtc,
            'days_left' => $expDays,
        ]);
    }

    /**
     * GET /api/frontoffice/config
     *
     * Restituisce le impostazioni rilevanti per il frontoffice come dizionario piatto,
     * usato dal watchdog daemon in docker-setup.sh per rilevare cambi di configurazione.
     * Autenticazione: Bearer token (MASTER_TOKEN dall'ambiente del container).
     * Non richiede sessione PHP.
     */
    public function getFrontofficeConfig(Request $request, Response $response): Response
    {
        $masterToken = $_ENV['MASTER_TOKEN'] ?? getenv('MASTER_TOKEN') ?: '';
        if (empty($masterToken)) {
            return $this->jsonResponse(['error' => 'MASTER_TOKEN non configurato'], 503);
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authHeader, 'Bearer ') || !hash_equals($masterToken, substr($authHeader, 7))) {
            return $this->jsonResponse(['error' => 'Non autorizzato'], 401);
        }

        $frontoffice = SettingsRepository::getSection('frontoffice');
        $entity      = SettingsRepository::getSection('entity');
        $iamProxy    = SettingsRepository::getSection('iam_proxy');
        $ui          = SettingsRepository::getSection('ui');

        $config = [
            // frontoffice
            'FRONTOFFICE_PUBLIC_BASE_URL'  => $frontoffice['public_base_url']   ?? '',
            'FRONTOFFICE_AUTH_PROXY_TYPE'  => $frontoffice['auth_proxy_type']   ?? '',
            // entity
            'APP_ENTITY_NAME'              => $entity['name']                   ?? '',
            'APP_ENTITY_SUFFIX'            => $entity['suffix']                 ?? '',
            'APP_ENTITY_GOVERNMENT'        => $entity['government']             ?? '',
            'APP_SUPPORT_EMAIL'            => $entity['support_email']          ?? '',
            'APP_SUPPORT_PHONE'            => $entity['support_phone']          ?? '',
            'APP_SUPPORT_HOURS'            => $entity['support_hours']          ?? '',
            'APP_SUPPORT_LOCATION'         => $entity['support_location']       ?? '',
            // iam_proxy feature flags
            'ENABLE_SPID'                  => $iamProxy['enable_spid']          ?? '',
            'ENABLE_CIE_OIDC'              => $iamProxy['enable_cie_oidc']      ?? '',
            'IAM_PROXY_PUBLIC_BASE_URL'    => $iamProxy['public_base_url']      ?? '',
            // ui
            'APP_LOGO_SRC'                 => $ui['logo_src']                   ?? '',
            'APP_LOGO_TYPE'                => $ui['logo_type']                  ?? '',
        ];

        return $this->jsonResponse($config);
    }

    // ──────────────────────────────────────────────────────────────────────
    // BACKUP GLOBALE IAM PROXY
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Controlla se il container auth-proxy-nginx risponde.
     * GET /impostazioni/login-proxy/status
     */
    public function getAuthProxyStatus(Request $request, Response $response): Response
    {
        $url = 'http://auth-proxy-nginx:80/';
        $running = false;
        $detail  = 'Non raggiungibile';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr  = (string) curl_error($ch);
                curl_close($ch);

                if ($curlErr === '' && $httpCode > 0) {
                    $running = true;
                    $detail  = 'In esecuzione';
                } else {
                    $detail = $curlErr !== '' ? $curlErr : "HTTP {$httpCode}";
                }
            }
        }

        return $this->jsonResponse(['running' => $running, 'detail' => $detail]);
    }

    /**
     * Ritorna disponibilità dei file esportabili per guidare le checkbox UI.
     * GET /impostazioni/login-proxy/backup/status
     */
    public function getBackupStatus(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();

        $spidCerts = is_file(self::SPID_CERTS_DIR . '/cert.pem') && is_file(self::SPID_CERTS_DIR . '/privkey.pem');
        $cieKeys   = count(glob(self::CIEOIDC_KEYS_DIR . '/*.json') ?: []) > 0;

        return $this->jsonResponse([
            'config'        => true,
            'spid_certs'    => $spidCerts,
            'cieoidc_keys'  => $cieKeys,
            'spid_public_metadata' => is_file(self::PUBLIC_SPID_METADATA_PATH),
            'cie_metadata'  => is_file(self::CIE_METADATA_PATH),
        ]);
    }

    /**
     * Esporta i file selezionati come ZIP.
     * POST /impostazioni/login-proxy/backup/export
    * Body JSON: { "items": ["config","spid_certs","spid_metadata","cieoidc_keys","cie_metadata"] }
     */
    public function exportBackupZip(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        $body = $this->parseBody($request);
        $items = $body['items'] ?? [];
        if (empty($items) || !is_array($items)) {
            return $this->jsonError('Seleziona almeno un elemento da esportare.');
        }

        $allowed = ['config', 'spid_certs', 'spid_metadata', 'cieoidc_keys', 'cie_metadata'];
        $items   = array_values(array_intersect($items, $allowed));
        if (empty($items)) {
            return $this->jsonError('Nessun elemento valido selezionato.');
        }

        if (!class_exists('ZipArchive')) {
            return $this->jsonError('ZipArchive non disponibile nel container.', 500);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'iam-backup-');
        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            return $this->jsonError('Impossibile creare l\'archivio ZIP.', 500);
        }

        foreach ($items as $item) {
            match ($item) {
                'config' => $this->backupZipConfig($zip),
                'spid_certs' => $this->backupZipSpidCerts($zip),
                'spid_metadata' => $this->backupZipFile($zip, self::PUBLIC_SPID_METADATA_PATH, 'metadata/satosa_spid_public_metadata.xml'),
                'cieoidc_keys' => $this->backupZipCieKeys($zip),
                'cie_metadata' => $this->backupZipFile($zip, self::CIE_METADATA_PATH, 'metadata/cie-entity-configuration.json'),
                default => null,
            };
        }

        $zip->close();

        $data = file_get_contents($tmpFile);
        unlink($tmpFile);

        $filename = 'backup-auth-proxy-' . date('Y-m-d') . '.zip';
        $resp = new \Slim\Psr7\Response(200);
        $resp->getBody()->write($data);
        return $resp
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)strlen($data));
    }

    private function backupZipConfig(\ZipArchive $zip): void
    {
        $settings = SettingsRepository::getSection('iam_proxy') ?? [];
        // Escludi campi encrypted per sicurezza
        $encrypted = ['satosa_salt', 'satosa_state_encryption_key', 'satosa_encryption_key', 'satosa_user_id_hash_salt'];
        foreach ($encrypted as $k) {
            unset($settings[$k]);
        }
        $zip->addFromString('config/auth-proxy-settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function backupZipSpidCerts(\ZipArchive $zip): void
    {
        foreach (['cert.pem', 'privkey.pem'] as $file) {
            $path = self::SPID_CERTS_DIR . '/' . $file;
            if (is_file($path)) {
                $zip->addFile($path, 'spid-certs/' . $file);
            }
        }
    }

    private function backupZipCieKeys(\ZipArchive $zip): void
    {
        $files = glob(self::CIEOIDC_KEYS_DIR . '/*.json') ?: [];
        foreach ($files as $path) {
            $zip->addFile($path, 'cieoidc-keys/' . basename($path));
        }
    }

    private function backupZipFile(\ZipArchive $zip, string $path, string $entryName): void
    {
        if (is_file($path)) {
            $zip->addFile($path, $entryName);
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
        $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
        $options = [];
        if (in_array(strtolower((string)$authMethod), ['ssl', 'sslheader'], true)) {
            $cert = SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key  = SettingsRepository::get('govpay', 'tls_key_path', '');
            $pass = SettingsRepository::get('govpay', 'tls_key_password');
            if (!empty($cert) && !empty($key)) {
                $options['cert']    = $cert;
                $options['ssl_key'] = ($pass !== null && $pass !== '') ? [$key, $pass] : $key;
            }
        }
        return new \GuzzleHttp\Client($options);
    }

    private function applyGovpayCredentials(object $config): void
    {
        $user = SettingsRepository::get('govpay', 'user', '');
        $pass = SettingsRepository::get('govpay', 'password', '');
        if ($user !== '' && $pass !== '') {
            $config->setUsername($user);
            $config->setPassword($pass);
        }
    }

    private function govpayErrorDetail(mixed $body): string
    {
        if (empty($body)) {
            return 'errore sconosciuto';
        }
        $decoded = json_decode((string)$body, true);
        return $decoded['descrizione'] ?? $decoded['detail'] ?? $decoded['message'] ?? substr((string)$body, 0, 120);
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
        if ($httpCode === 200) {
            return $this->jsonOk("Connessione {$label}: HTTP 200 — OK.");
        }
        return $this->jsonError("Connessione {$label}: HTTP {$httpCode}.");
    }

    private function requireAdminOrAbove(): void
    {
        $role = $_SESSION['user']['role'] ?? '';
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            http_response_code(403);
            exit('Accesso non autorizzato');
        }
    }

    private function requireSuperadmin(): void
    {
        if (($_SESSION['user']['role'] ?? '') !== 'superadmin') {
            http_response_code(403);
            exit('Accesso riservato al superadmin');
        }
    }

    private function isSuperadmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'superadmin';
    }

    private function currentUser(): string
    {
        return $_SESSION['user']['email'] ?? 'system';
    }

    private function generateCsrf(): string
    {
        if (empty($_SESSION['impostazioni_csrf'])) {
            $_SESSION['impostazioni_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['impostazioni_csrf'];
    }

    private function validateCsrf(array $body): bool
    {
        $expected = $_SESSION['impostazioni_csrf'] ?? '';
        $provided = $body['csrf_token'] ?? '';
        return $expected !== '' && hash_equals($expected, $provided);
    }

    private function parseBody(Request $request): array
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $raw     = (string) $request->getBody();
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return (array)($request->getParsedBody() ?? []);
    }

    private function requestForce(Request $request, array $body): bool
    {
        if (!empty($body['force'])) {
            return true;
        }

        $q = $request->getQueryParams()['force'] ?? '';
        if (!is_string($q)) {
            return false;
        }

        return in_array(strtolower(trim($q)), ['1', 'true', 'yes', 'on'], true);
    }

    private function jsonOk(string $message): Response
    {
        return $this->jsonResponse(['success' => true, 'message' => $message]);
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
