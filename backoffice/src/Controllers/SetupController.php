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
use App\Config\ConfigLoader;
use App\Config\SettingsRepository;
use App\Auth\UserRepository;

/**
 * Gestisce il wizard di primo avvio (setup) e il flusso di ripristino da backup.
 *
 * Routing:
 *   GET  /setup                  → step 0 (welcome)
 *   GET  /setup/step/{n}         → step n (1-7)
 *   POST /setup/step/{n}         → salva step n, redirect a step n+1
 *   POST /setup/complete         → completa il setup (scrive config.json + settings)
 *   GET  /setup/restore          → form upload backup
 *   POST /setup/restore          → accetta upload, redirect a review
 *   GET  /setup/restore/review   → anteprima del backup
 *   POST /setup/restore/confirm  → esegue restore via master container
 *   GET  /setup/complete         → schermata di successo
 *   GET  /setup/error            → schermata di errore
 */
class SetupController
{
    private const MASTER_URL = 'http://govpay-interaction-master:8099';
    private const TOTAL_STEPS = 7;
    private const UPLOAD_TMP_DIR = '/tmp/gil-restore';

    public function __construct(private readonly Twig $twig)
    {
    }

    // ──────────────────────────────────────────────────────────────────────
    // STEP 0 – Welcome (scelta modalità)
    // ──────────────────────────────────────────────────────────────────────

    public function welcome(Request $request, Response $response): Response
    {
        // Se già configurato non mostrare il wizard
        if (ConfigLoader::isSetupComplete()) {
            return $this->redirect('/');
        }

        $mode = $request->getQueryParams()['mode'] ?? 'fresh';
        $this->initWizardSession($mode);
        $this->generateCsrf();

        return $this->twig->render($response, 'setup/welcome.html.twig', [
            'mode' => $mode,
            'csrf_token' => $_SESSION['wizard']['csrf_token'],
            'total_steps' => self::TOTAL_STEPS,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // STEP N – Get/Post
    // ──────────────────────────────────────────────────────────────────────

    public function showStep(Request $request, Response $response, array $args): Response
    {
        if (ConfigLoader::isSetupComplete()) {
            return $this->redirect('/');
        }

        $step = (int)($args['step'] ?? 1);
        if ($step < 1 || $step > self::TOTAL_STEPS) {
            return $this->redirect('/setup');
        }

        $this->initWizardSession();

        // Non saltare avanti di più di uno step
        $maxReached = $_SESSION['wizard']['max_reached'] ?? 0;
        if ($step > $maxReached + 1) {
            return $this->redirect('/setup/step/' . ($maxReached + 1));
        }

        $mode = $_SESSION['wizard']['mode'] ?? 'fresh';
        $stepData = $_SESSION['wizard']['data']['step' . $step] ?? [];

        // In modalità upgrade, pre-popola i dati dallo step corrente se vuoti
        if ($mode === 'upgrade' && empty($stepData)) {
            $stepData = $this->prefillFromEnv($step);
        }

        return $this->twig->render($response, 'setup/step' . $step . '.html.twig', [
            'step'        => $step,
            'total_steps' => self::TOTAL_STEPS,
            'mode'        => $mode,
            'data'        => $stepData,
            'csrf_token'  => $_SESSION['wizard']['csrf_token'] ?? '',
            'all_data'    => $_SESSION['wizard']['data'] ?? [],
            'errors'      => $_SESSION['wizard']['errors'][$step] ?? [],
        ]);
    }

    public function saveStep(Request $request, Response $response, array $args): Response
    {
        $step = (int)($args['step'] ?? 1);
        $body = (array)$request->getParsedBody();

        // Validazione CSRF
        if (!$this->validateCsrf($body['csrf_token'] ?? '')) {
            return $this->redirect('/setup/step/' . $step . '?error=csrf');
        }

        $this->initWizardSession();

        // Gestione upload file (prima della validazione, per persistere subito i file)
        $uploadErrors = $this->handleStepFileUploads($step, $request->getUploadedFiles());
        if (!empty($uploadErrors)) {
            $_SESSION['wizard']['errors'][$step] = $uploadErrors;
            $_SESSION['wizard']['data']['step' . $step] = $this->sanitizeStep($step, $body);
            return $this->redirect('/setup/step/' . $step);
        }

        // Valida i dati dello step
        $errors = $this->validateStep($step, $body);
        if (!empty($errors)) {
            $_SESSION['wizard']['errors'][$step] = $errors;
            $_SESSION['wizard']['data']['step' . $step] = $this->sanitizeStep($step, $body);
            return $this->redirect('/setup/step/' . $step);
        }

        // Salva i dati nella sessione
        $_SESSION['wizard']['data']['step' . $step] = $this->sanitizeStep($step, $body);
        $_SESSION['wizard']['errors'][$step] = [];

        $maxReached = $_SESSION['wizard']['max_reached'] ?? 0;
        if ($step > $maxReached) {
            $_SESSION['wizard']['max_reached'] = $step;
        }

        // Ultimo step → vai al riepilogo
        if ($step === self::TOTAL_STEPS) {
            return $this->redirect('/setup/step/' . self::TOTAL_STEPS);
        }

        return $this->redirect('/setup/step/' . ($step + 1));
    }

    // ──────────────────────────────────────────────────────────────────────
    // COMPLETAMENTO SETUP
    // ──────────────────────────────────────────────────────────────────────

    public function complete(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();

        if (!$this->validateCsrf($body['csrf_token'] ?? '')) {
            return $this->redirect('/setup?error=csrf');
        }

        $this->initWizardSession();
        $data = $_SESSION['wizard']['data'] ?? [];

        try {
            // 1. Scrive i settings applicativi nel DB
            $this->writeSettings($data);

            // 2. Crea il superadmin
            $step2 = $data['step2'] ?? [];
            if (!empty($step2['admin_email']) && !empty($step2['admin_password'])) {
                $this->createSuperadmin($step2['admin_email'], $step2['admin_password']);
            }

            // 3. Segna setup come completato nel DB
            SettingsRepository::set('setup', 'complete', '1');

            // Pulisce sessione wizard
            unset($_SESSION['wizard']);

        } catch (\Throwable $e) {
            $_SESSION['setup_error'] = $e->getMessage();
            return $this->redirect('/setup/error');
        }

        return $this->redirect('/setup/done');
    }

    public function done(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'setup/complete.html.twig', []);
    }

    public function error(Request $request, Response $response): Response
    {
        $errorMsg = $_SESSION['setup_error'] ?? 'Errore sconosciuto durante il setup.';
        unset($_SESSION['setup_error']);
        return $this->twig->render($response, 'setup/error.html.twig', ['error' => $errorMsg]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // RESTORE DA BACKUP
    // ──────────────────────────────────────────────────────────────────────

    public function restoreForm(Request $request, Response $response): Response
    {
        $this->generateCsrf();
        return $this->twig->render($response, 'setup/restore.html.twig', [
            'csrf_token' => $_SESSION['wizard']['csrf_token'] ?? '',
        ]);
    }

    public function restoreUpload(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        if (!$this->validateCsrf($body['csrf_token'] ?? '')) {
            return $this->redirect('/setup/restore?error=csrf');
        }

        $files = $request->getUploadedFiles();
        $backup = $files['backup_file'] ?? null;

        if (!$backup || $backup->getError() !== UPLOAD_ERR_OK) {
            return $this->redirect('/setup/restore?error=upload');
        }

        if (!str_ends_with($backup->getClientFilename() ?? '', '.zip')) {
            return $this->redirect('/setup/restore?error=format');
        }

        @mkdir(self::UPLOAD_TMP_DIR, 0700, true);
        $tmpPath = self::UPLOAD_TMP_DIR . '/' . basename($backup->getClientFilename());
        $backup->moveTo($tmpPath);

        $_SESSION['restore_file'] = $tmpPath;
        $_SESSION['restore_filename'] = basename($backup->getClientFilename());

        return $this->redirect('/setup/restore/review');
    }

    public function restoreReview(Request $request, Response $response): Response
    {
        $tmpPath = $_SESSION['restore_file'] ?? null;
        if (!$tmpPath || !file_exists($tmpPath)) {
            return $this->redirect('/setup/restore?error=missing');
        }

        $manifest = $this->readBackupManifest($tmpPath);
        $this->generateCsrf();

        return $this->twig->render($response, 'setup/restore-review.html.twig', [
            'manifest'   => $manifest,
            'filename'   => $_SESSION['restore_filename'] ?? '',
            'csrf_token' => $_SESSION['wizard']['csrf_token'] ?? '',
        ]);
    }

    public function restoreConfirm(Request $request, Response $response): Response
    {
        $body = (array)$request->getParsedBody();
        if (!$this->validateCsrf($body['csrf_token'] ?? '')) {
            return $this->redirect('/setup/restore?error=csrf');
        }

        $tmpPath = $_SESSION['restore_file'] ?? null;
        $filename = $_SESSION['restore_filename'] ?? null;

        if (!$tmpPath || !file_exists($tmpPath)) {
            return $this->redirect('/setup/restore?error=missing');
        }

        try {
            // Copia il backup nel volume /backups
            $backupsDir = '/backups';
            if (!is_dir($backupsDir)) {
                $backupsDir = sys_get_temp_dir();
            }
            $destPath = $backupsDir . '/' . $filename;
            copy($tmpPath, $destPath);

            // Chiama il master per eseguire il restore
            // Nota: il master_token non è ancora noto qui (primo avvio)
            // Il restore viene eseguito direttamente dal master senza auth
            // (il token viene impostato solo dopo il setup — questa è l'unica eccezione)
            $this->callMasterRestore($filename);

            unset($_SESSION['restore_file'], $_SESSION['restore_filename']);

        } catch (\Throwable $e) {
            $_SESSION['setup_error'] = $e->getMessage();
            return $this->redirect('/setup/error');
        }

        return $this->redirect('/setup/done');
    }

    // ──────────────────────────────────────────────────────────────────────
    // HELPERS PRIVATI
    // ──────────────────────────────────────────────────────────────────────

    private function initWizardSession(string $mode = 'fresh'): void
    {
        if (!isset($_SESSION['wizard'])) {
            $_SESSION['wizard'] = [
                'mode'        => $mode,
                'max_reached' => 0,
                'data'        => [],
                'errors'      => [],
                'csrf_token'  => $this->newCsrfToken(),
            ];
        }
    }

    private function generateCsrf(): void
    {
        if (!isset($_SESSION['wizard']['csrf_token'])) {
            $_SESSION['wizard']['csrf_token'] = $this->newCsrfToken();
        }
    }

    private function newCsrfToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function validateCsrf(string $token): bool
    {
        $expected = $_SESSION['wizard']['csrf_token'] ?? '';
        return $expected !== '' && hash_equals($expected, $token);
    }

    private function redirect(string $url): Response
    {
        $resp = new SlimResponse(302);
        return $resp->withHeader('Location', $url);
    }

    /**
     * Valida i dati di uno step. Ritorna array di messaggi di errore (vuoto = ok).
     */
    private function validateStep(int $step, array $body): array
    {
        $errors = [];

        switch ($step) {
            case 1:
                if (empty(trim($body['entity_name'] ?? ''))) {
                    $errors['entity_name'] = 'Il nome dell\'ente è obbligatorio.';
                }
                if (empty(trim($body['id_dominio'] ?? ''))) {
                    $errors['id_dominio'] = 'L\'ID Dominio è obbligatorio.';
                }
                break;

            case 2:
                if (empty(trim($body['admin_email'] ?? '')) || !filter_var($body['admin_email'], FILTER_VALIDATE_EMAIL)) {
                    $errors['admin_email'] = 'Email superadmin non valida.';
                }
                if (strlen($body['admin_password'] ?? '') < 8) {
                    $errors['admin_password'] = 'La password deve essere di almeno 8 caratteri.';
                }
                if (($body['admin_password'] ?? '') !== ($body['admin_password_confirm'] ?? '')) {
                    $errors['admin_password_confirm'] = 'Le password non corrispondono.';
                }
                if (empty(trim($body['backoffice_url'] ?? ''))) {
                    $errors['backoffice_url'] = 'L\'URL del backoffice è obbligatorio.';
                }
                break;

            case 5:
                if (empty(trim($body['govpay_backoffice_url'] ?? ''))) {
                    $errors['govpay_backoffice_url'] = 'L\'URL GovPay Backoffice è obbligatorio.';
                }
                break;
        }

        return $errors;
    }

    /**
     * Filtra e normalizza i dati dello step per salvarli in sessione.
     */
    private function sanitizeStep(int $step, array $body): array
    {
        // Rimuove CSRF e normalizza stringhe
        unset($body['csrf_token']);
        return array_map(fn($v) => is_string($v) ? trim($v) : $v, $body);
    }

    /**
     * Gestisce gli upload file per gli step che li prevedono.
     * - Step 1: logo ente (opzionale)
     * - Step 5: certificato + chiave GovPay (opzionali, solo se sslheader)
     *
     * Ritorna array di errori (vuoto = ok).
     */
    private function handleStepFileUploads(int $step, array $uploadedFiles): array
    {
        $errors = [];

        if ($step === 1) {
            $logo = $uploadedFiles['logo_file'] ?? null;
            if ($logo && $logo->getError() === UPLOAD_ERR_OK) {
                $mime = $logo->getClientMediaType();
                $allowed = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/x-icon'];
                if (!in_array($mime, $allowed, true)) {
                    $errors['logo_file'] = 'Formato logo non supportato (png, jpg, svg).';
                } else {
                    @mkdir('/var/www/html/public/img', 0755, true);
                    try {
                        $logo->moveTo('/var/www/html/public/img/stemma_ente.png');
                    } catch (\Throwable $e) {
                        $errors['logo_file'] = 'Impossibile salvare il logo: ' . $e->getMessage();
                    }
                }
            }
            // UPLOAD_ERR_NO_FILE = nessun file selezionato → opzionale, nessun errore
        }

        if ($step === 5) {
            $cert = $uploadedFiles['govpay_cert'] ?? null;
            $key  = $uploadedFiles['govpay_key']  ?? null;

            @mkdir('/var/www/certificate', 0755, true);

            if ($cert && $cert->getError() === UPLOAD_ERR_OK) {
                try {
                    $cert->moveTo('/var/www/certificate/govpay-cert.pem');
                } catch (\Throwable $e) {
                    $errors['govpay_cert'] = 'Impossibile salvare il certificato: ' . $e->getMessage();
                }
            }

            if ($key && $key->getError() === UPLOAD_ERR_OK) {
                try {
                    $key->moveTo('/var/www/certificate/govpay-key.pem');
                } catch (\Throwable $e) {
                    $errors['govpay_key'] = 'Impossibile salvare la chiave: ' . $e->getMessage();
                }
            }
        }

        return $errors;
    }

    /**
     * Pre-popola i dati di uno step dai valori getenv() (modalità upgrade).
     */
    private function prefillFromEnv(int $step): array
    {
        $map = [
            1 => [
                'entity_ipa_code'  => 'APP_ENTITY_IPA_CODE',
                'entity_name'      => 'APP_ENTITY_NAME',
                'entity_suffix'    => 'APP_ENTITY_SUFFIX',
                'entity_government'=> 'APP_ENTITY_GOVERNMENT',
                'entity_url'       => 'APP_ENTITY_URL',
                'id_dominio'       => 'ID_DOMINIO',
                'id_a2a'           => 'ID_A2A',
            ],
            2 => [
                'admin_email'      => 'ADMIN_EMAIL',
                'backoffice_url'   => 'BACKOFFICE_PUBLIC_BASE_URL',
                'frontoffice_url'  => 'FRONTOFFICE_PUBLIC_BASE_URL',
                'apache_server_name' => 'APACHE_SERVER_NAME',
            ],
            3 => [
                'db_password'           => 'BACKOFFICE_DB_PASSWORD',
                'db_password_cittadini' => 'FRONTOFFICE_DB_PASSWORD',
            ],
            4 => [
                'encryption_key' => 'APP_ENCRYPTION_KEY',
            ],
            5 => [
                'govpay_pendenze_url'     => 'GOVPAY_PENDENZE_URL',
                'govpay_pagamenti_url'    => 'GOVPAY_PAGAMENTI_URL',
                'govpay_ragioneria_url'   => 'GOVPAY_RAGIONERIA_URL',
                'govpay_backoffice_url'   => 'GOVPAY_BACKOFFICE_URL',
                'govpay_patch_url'        => 'GOVPAY_PENDENZE_PATCH_URL',
                'authentication_govpay'   => 'AUTHENTICATION_GOVPAY',
                'govpay_user'             => 'GOVPAY_USER',
            ],
            6 => [
                'mailer_dsn'             => 'BACKOFFICE_MAILER_DSN',
                'mailer_from_address'    => 'BACKOFFICE_MAILER_FROM_ADDRESS',
                'mailer_from_name'       => 'BACKOFFICE_MAILER_FROM_NAME',
                'pagopa_checkout_url'    => 'PAGOPA_CHECKOUT_EC_BASE_URL',
                'pagopa_checkout_key'    => 'PAGOPA_CHECKOUT_SUBSCRIPTION_KEY',
                'biz_events_host'        => 'BIZ_EVENTS_HOST',
                'biz_events_key'         => 'BIZ_EVENTS_API_KEY',
            ],
        ];

        $result = [];
        foreach (($map[$step] ?? []) as $field => $envKey) {
            $val = getenv($envKey);
            if ($val !== false && $val !== '') {
                $result[$field] = $val;
            }
        }
        return $result;
    }

    /**
     * Scrive tutti i settings applicativi nel DB.
     */
    private function writeSettings(array $data): void
    {
        $step1 = $data['step1'] ?? [];
        $step2 = $data['step2'] ?? [];
        $step5 = $data['step5'] ?? [];
        $step6 = $data['step6'] ?? [];

        SettingsRepository::setSection('entity', [
            'ipa_code'         => $step1['entity_ipa_code'] ?? '',
            'name'             => $step1['entity_name'] ?? '',
            'suffix'           => $step1['entity_suffix'] ?? '',
            'government'       => $step1['entity_government'] ?? '',
            'url'              => $step1['entity_url'] ?? '',
            'id_dominio'       => $step1['id_dominio'] ?? '',
            'id_a2a'           => $step1['id_a2a'] ?? '',
        ], 'wizard');

        SettingsRepository::setSection('backoffice', [
            'public_base_url'      => $step2['backoffice_url'] ?? '',
            'apache_server_name'   => $step2['apache_server_name'] ?? 'localhost',
            'mailer_dsn'           => ['value' => $step6['mailer_dsn'] ?? 'null://null', 'encrypted' => true],
            'mailer_from_address'  => $step6['mailer_from_address'] ?? '',
            'mailer_from_name'     => $step6['mailer_from_name'] ?? '',
        ], 'wizard');

        SettingsRepository::setSection('frontoffice', [
            'public_base_url'   => $step2['frontoffice_url'] ?? '',
            'auth_proxy_type'   => 'none',
        ], 'wizard');

        $authMethod = $step5['authentication_govpay'] ?? 'basic';
        $govpaySection = [
            'pendenze_url'          => $step5['govpay_pendenze_url'] ?? '',
            'pagamenti_url'         => $step5['govpay_pagamenti_url'] ?? '',
            'ragioneria_url'        => $step5['govpay_ragioneria_url'] ?? '',
            'backoffice_url'        => $step5['govpay_backoffice_url'] ?? '',
            'pendenze_patch_url'    => $step5['govpay_patch_url'] ?? '',
            'authentication_method' => $authMethod,
            'user'                  => ['value' => $step5['govpay_user'] ?? '', 'encrypted' => true],
            'password'              => ['value' => $step5['govpay_password'] ?? '', 'encrypted' => true],
        ];
        // Per mTLS: registra i percorsi fissi del volume git_certs
        if ($authMethod === 'sslheader') {
            $govpaySection['tls_cert_path'] = '/var/www/certificate/govpay-cert.pem';
            $govpaySection['tls_key_path']  = '/var/www/certificate/govpay-key.pem';
            $govpaySection['tls_key_password'] = ['value' => $step5['govpay_key_password'] ?? '', 'encrypted' => true];
        }
        SettingsRepository::setSection('govpay', $govpaySection, 'wizard');

        SettingsRepository::setSection('pagopa', [
            'checkout_ec_base_url'      => $step6['pagopa_checkout_url'] ?? '',
            'checkout_subscription_key' => ['value' => $step6['pagopa_checkout_key'] ?? '', 'encrypted' => true],
            'biz_events_host'           => $step6['biz_events_host'] ?? '',
            'biz_events_api_key'        => ['value' => $step6['biz_events_key'] ?? '', 'encrypted' => true],
        ], 'wizard');
    }

    /**
     * Crea il superadmin nel DB.
     */
    private function createSuperadmin(string $email, string $password): void
    {
        $pdo = \App\Database\Connection::getPDO();

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetchColumn()) {
            return; // già esiste
        }

        $pwdHash = password_hash($password, PASSWORD_BCRYPT);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $ins = $pdo->prepare(
            'INSERT INTO users (email, password_hash, role, first_name, last_name, created_at, updated_at)
             VALUES (:email, :pwd, :role, :first, :last, :created, :updated)'
        );
        $ins->execute([
            ':email'   => $email,
            ':pwd'     => $pwdHash,
            ':role'    => 'superadmin',
            ':first'   => 'Super',
            ':last'    => 'Admin',
            ':created' => $now,
            ':updated' => $now,
        ]);
    }

    /**
     * Chiama il master container per riavviare backoffice e frontoffice.
     */
    private function triggerMasterRestart(string $token): void
    {
        if (empty($token)) {
            return;
        }

        $url = self::MASTER_URL . '/containers/restart';
        $payload = json_encode(['services' => ['govpay-interaction-backoffice', 'govpay-interaction-frontoffice']]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
                'content' => $payload,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $ctx);
        // Non lanciare eccezioni se il master non risponde — il restart è best-effort
    }

    /**
     * Chiama il master per eseguire il restore di un backup.
     */
    private function callMasterRestore(string $filename): void
    {
        $url = self::MASTER_URL . '/backup/restore';
        $payload = json_encode(['filename' => $filename]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 300,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            throw new \RuntimeException('Impossibile contattare il Master Container per il restore.');
        }

        $json = json_decode($result, true);
        if (!($json['success'] ?? false)) {
            throw new \RuntimeException('Restore fallito: ' . ($json['detail'] ?? 'errore sconosciuto'));
        }
    }

    /**
     * Legge il manifest di un archivio ZIP di backup.
     */
    private function readBackupManifest(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['error' => 'Archivio non leggibile'];
        }

        $manifest = $zip->getFromName('manifest.json');
        $settings = $zip->getFromName('settings.json');
        $zip->close();

        $result = $manifest ? json_decode($manifest, true) : [];
        if ($settings) {
            $settingsData = json_decode($settings, true);
            $result['entity_name'] = $settingsData['entity']['name'] ?? '';
        }

        return $result;
    }

}
