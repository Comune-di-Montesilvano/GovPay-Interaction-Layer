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
use App\Logger;

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
    private const MASTER_URL = 'http://govpay-interaction-master:8099';

    public function __construct(private readonly Twig $twig)
    {
    }

    // ──────────────────────────────────────────────────────────────────────
    // INDEX
    // ──────────────────────────────────────────────────────────────────────

    public function index(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();

        $tab = $request->getQueryParams()['tab'] ?? 'govpay';

        $data = [
            'active_tab'  => $tab,
            'is_superadmin' => $this->isSuperadmin(),
            'govpay'      => SettingsRepository::getSection('govpay'),
            'pagopa'      => SettingsRepository::getSection('pagopa'),
            'backoffice'  => SettingsRepository::getSection('backoffice'),
            'frontoffice' => SettingsRepository::getSection('frontoffice'),
            'entity'      => SettingsRepository::getSection('entity'),
            'iam_proxy'   => SettingsRepository::getSection('iam_proxy'),
            'ui'          => SettingsRepository::getSection('ui'),
            'csrf_token'  => $this->generateCsrf(),
        ];

        return $this->twig->render($response, 'impostazioni/index.html.twig', $data);
    }

    // ──────────────────────────────────────────────────────────────────────
    // SAVE ACTIONS
    // ──────────────────────────────────────────────────────────────────────

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

        // Aggiorna anche entity (ID_DOMINIO, ID_A2A esposti in questa sezione)
        if (!empty($body['id_dominio'])) {
            SettingsRepository::set('entity', 'id_dominio', $body['id_dominio'], false, $by);
        }
        if (!empty($body['id_a2a'])) {
            SettingsRepository::set('entity', 'id_a2a', $body['id_a2a'], false, $by);
        }

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
        SettingsRepository::setSection('pagopa', [
            'checkout_ec_base_url'      => $body['checkout_ec_base_url'] ?? '',
            'checkout_subscription_key' => ['value' => $body['checkout_subscription_key'] ?? '', 'encrypted' => true],
            'checkout_company_name'     => $body['checkout_company_name'] ?? '',
            'checkout_return_ok_url'    => $body['checkout_return_ok_url'] ?? '',
            'checkout_return_cancel_url'=> $body['checkout_return_cancel_url'] ?? '',
            'checkout_return_error_url' => $body['checkout_return_error_url'] ?? '',
            'payment_options_url'       => $body['payment_options_url'] ?? '',
            'payment_options_key'       => ['value' => $body['payment_options_key'] ?? '', 'encrypted' => true],
            'biz_events_host'           => $body['biz_events_host'] ?? '',
            'biz_events_api_key'        => ['value' => $body['biz_events_api_key'] ?? '', 'encrypted' => true],
            'tassonomie_url'            => $body['tassonomie_url'] ?? '',
        ], $by);

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
        SettingsRepository::setSection('backoffice', [
            'public_base_url'      => $body['public_base_url'] ?? '',
            'apache_server_name'   => $body['apache_server_name'] ?? '',
            'mailer_dsn'           => ['value' => $body['mailer_dsn'] ?? 'null://null', 'encrypted' => true],
            'mailer_from_address'  => $body['mailer_from_address'] ?? '',
            'mailer_from_name'     => $body['mailer_from_name'] ?? '',
        ], $by);

        SettingsRepository::setSection('entity', [
            'ipa_code'         => $body['entity_ipa_code'] ?? '',
            'name'             => $body['entity_name'] ?? '',
            'suffix'           => $body['entity_suffix'] ?? '',
            'government'       => $body['entity_government'] ?? '',
            'url'              => $body['entity_url'] ?? '',
            'support_email'    => $body['support_email'] ?? '',
            'support_phone'    => $body['support_phone'] ?? '',
            'support_hours'    => $body['support_hours'] ?? '',
            'support_location' => $body['support_location'] ?? '',
        ], $by);

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
        SettingsRepository::setSection('frontoffice', [
            'public_base_url'   => $body['public_base_url'] ?? '',
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

        // Mappa tutti i campi iam_proxy
        $iamData = [
            'public_base_url'                  => $body['public_base_url'] ?? '',
            'saml2_idp_metadata_url'           => $body['saml2_idp_metadata_url'] ?? '',
            'saml2_idp_metadata_url_internal'  => $body['saml2_idp_metadata_url_internal'] ?? '',
            'hostname'                         => $body['hostname'] ?? '',
            'http_port'                        => $body['http_port'] ?? '',
            'debug'                            => $body['debug'] ?? 'false',
            'enable_spid'                      => $body['enable_spid'] ?? 'false',
            'enable_cie_oidc'                  => $body['enable_cie_oidc'] ?? 'false',
            'enable_it_wallet'                 => $body['enable_it_wallet'] ?? 'false',
            'enable_oidcop'                    => $body['enable_oidcop'] ?? 'false',
            'satosa_base'                      => $body['satosa_base'] ?? '',
            'spid_cert_common_name'            => $body['spid_cert_common_name'] ?? '',
            'spid_cert_org_id'                 => $body['spid_cert_org_id'] ?? '',
            'spid_cert_org_name'               => $body['spid_cert_org_name'] ?? '',
            'spid_cert_entity_id'              => $body['spid_cert_entity_id'] ?? '',
            'spid_cert_locality_name'          => $body['spid_cert_locality_name'] ?? '',
            'spid_cert_key_size'               => $body['spid_cert_key_size'] ?? '2048',
            'spid_cert_days'                   => $body['spid_cert_days'] ?? '730',
            'satosa_org_display_name_it'       => $body['satosa_org_display_name_it'] ?? '',
            'satosa_org_name_it'               => $body['satosa_org_name_it'] ?? '',
            'satosa_contact_email'             => $body['satosa_contact_email'] ?? '',
            'satosa_contact_phone'             => $body['satosa_contact_phone'] ?? '',
            'satosa_contact_fiscalcode'        => $body['satosa_contact_fiscalcode'] ?? '',
            'satosa_contact_ipa_code'          => $body['satosa_contact_ipa_code'] ?? '',
            'satosa_org_identifier'            => $body['satosa_org_identifier'] ?? '',
            'cie_oidc_provider_url'            => $body['cie_oidc_provider_url'] ?? '',
            'cie_oidc_client_id'               => $body['cie_oidc_client_id'] ?? '',
            'cie_oidc_client_name'             => $body['cie_oidc_client_name'] ?? '',
            'cie_oidc_jwks_uri'                => $body['cie_oidc_jwks_uri'] ?? '',
            'cie_oidc_redirect_uri'            => $body['cie_oidc_redirect_uri'] ?? '',
        ];

        SettingsRepository::setSection('iam_proxy', $iamData, $by);

        return $this->jsonOk('Impostazioni Login Proxy salvate.');
    }

    // ──────────────────────────────────────────────────────────────────────
    // TEST ACTIONS
    // ──────────────────────────────────────────────────────────────────────

    public function testGovpayConnection(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();

        $url = SettingsRepository::get('govpay', 'backoffice_url');
        if (empty($url)) {
            return $this->jsonError('URL GovPay Backoffice non configurato.');
        }

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $result = @file_get_contents(rtrim($url, '/') . '/info', false, $ctx);
            $ok = $result !== false;
            return $this->jsonOk($ok ? 'Connessione riuscita.' : 'Server raggiungibile ma risposta inattesa.');
        } catch (\Throwable $e) {
            return $this->jsonError('Connessione fallita: ' . $e->getMessage());
        }
    }

    public function testEmail(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();

        $recipient = $_SESSION['user']['email'] ?? '';
        if (empty($recipient)) {
            return $this->jsonError('Email utente non trovata.');
        }

        try {
            $mailerService = new \App\Services\MailerService();
            $mailerService->sendTestEmail($recipient);
            return $this->jsonOk("Email di test inviata a {$recipient}.");
        } catch (\Throwable $e) {
            return $this->jsonError('Invio fallito: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // CONTAINER ACTIONS (via Master Container)
    // ──────────────────────────────────────────────────────────────────────

    public function restartFrontoffice(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->masterPost('/containers/restart', ['services' => ['govpay-interaction-frontoffice']]);
    }

    public function avviaIamProxy(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->masterPost('/containers/start-profile', ['profile' => 'iam-proxy']);
    }

    public function arrestaIamProxy(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->masterPost('/containers/stop-profile', ['profile' => 'iam-proxy']);
    }

    public function riavviaIamProxy(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->masterPost('/containers/restart', ['services' => ['iam-proxy-italia', 'satosa-nginx']]);
    }

    public function rigeneraSpMetadata(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();
        return $this->masterPost('/iam-proxy/regenerate-sp-metadata', []);
    }

    public function getContainersStatus(Request $request, Response $response): Response
    {
        $this->requireAdminOrAbove();
        $result = $this->masterGet('/containers/status');
        return $this->jsonResponse($result);
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
        return (array)($request->getParsedBody() ?? []);
    }

    private function jsonOk(string $message): Response
    {
        return $this->jsonResponse(['success' => true, 'message' => $message]);
    }

    private function jsonError(string $message, int $status = 400): Response
    {
        return $this->jsonResponse(['success' => false, 'message' => $message], $status);
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        $resp = new SlimResponse($status);
        $resp->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $resp->withHeader('Content-Type', 'application/json');
    }

    /**
     * Chiama un endpoint POST del Master Container.
     */
    private function masterPost(string $path, array $payload): Response
    {
        $token = ConfigLoader::get('master_token');
        if (empty($token)) {
            return $this->jsonError('Master Container non configurato (token mancante).');
        }

        $url = self::MASTER_URL . $path;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
                'content'       => json_encode($payload),
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            return $this->jsonError('Impossibile contattare il Master Container.');
        }

        $json = json_decode($result, true) ?? ['success' => false, 'message' => $result];
        return $this->jsonResponse($json, ($json['success'] ?? false) ? 200 : 500);
    }

    /**
     * Chiama un endpoint GET del Master Container.
     */
    private function masterGet(string $path): array
    {
        $token = ConfigLoader::get('master_token');
        if (empty($token)) {
            return ['error' => 'Master Container non configurato.'];
        }

        $url = self::MASTER_URL . $path;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer {$token}\r\n",
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        return $result ? (json_decode($result, true) ?? []) : ['error' => 'Risposta non valida'];
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
