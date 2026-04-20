<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

use App\Auth\UserRepository;
use App\Config\SettingsRepository;
use App\Controllers\BackupController;
use App\Controllers\ConfigurazioneController;
use App\Controllers\HomeController;
use App\Controllers\FlussiController;
use App\Controllers\ImpostazioniController;
use App\Controllers\PendenzeController;
use App\Controllers\SetupController;
use App\Controllers\StatisticheController;
use App\Controllers\UsersController;
use App\Database\PendenzaTemplateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;

return function (App $app, Twig $twig): void {

    // ── Setup Wizard (accessibile senza autenticazione, bypassato da SetupMiddleware) ──
    $app->get('/setup', function (Request $request, Response $response) use ($twig): Response {
        return (new SetupController($twig))->welcome($request, $response);
    });
    $app->get('/setup/step/{step:[1-7]}', function (Request $request, Response $response, array $args) use ($twig): Response {
        return (new SetupController($twig))->showStep($request, $response, $args);
    });
    $app->post('/setup/step/{step:[1-7]}', function (Request $request, Response $response, array $args) use ($twig): Response {
        return (new SetupController($twig))->saveStep($request, $response, $args);
    });
    $app->post('/setup/complete', function (Request $request, Response $response) use ($twig): Response {
        return (new SetupController($twig))->complete($request, $response);
    });
    $app->get('/setup/done', function (Request $request, Response $response) use ($twig): Response {
        return (new SetupController($twig))->done($request, $response);
    });
    $app->get('/setup/error', function (Request $request, Response $response) use ($twig): Response {
        return (new SetupController($twig))->error($request, $response);
    });
    $app->get('/setup/restore', function (Request $request, Response $response) use ($twig): Response {
        return (new SetupController($twig))->restoreForm($request, $response);
    });
    $app->post('/setup/restore', function (Request $request, Response $response) use ($twig): Response {
        return (new SetupController($twig))->restoreUpload($request, $response);
    });
    $app->get('/setup/restore/review', function (Request $request, Response $response) use ($twig): Response {
        return (new SetupController($twig))->restoreReview($request, $response);
    });
    $app->post('/setup/restore/confirm', function (Request $request, Response $response) use ($twig): Response {
        return (new SetupController($twig))->restoreConfirm($request, $response);
    });

    // ── API interna: endpoint senza sessione (autenticati via Bearer token) ──────
    $app->get('/api/auth-proxy/env', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->getIamProxyEnv($request, $response);
    });

    $app->get('/api/frontoffice/config', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->getFrontofficeConfig($request, $response);
    });

    // ── Impostazioni (nuovo config panel, richiede auth) ──────────────────────────
    $app->get('/impostazioni', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->index($request, $response);
    });
    $app->post('/impostazioni/generale/save', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->saveGenerale($request, $response);
    });
    $app->post('/impostazioni/govpay/save', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->saveGovpay($request, $response);
    });
    $app->post('/impostazioni/api-esterne/save', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->saveApiEsterne($request, $response);
    });
    $app->post('/impostazioni/backoffice/save', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->saveBackoffice($request, $response);
    });
    $app->post('/impostazioni/frontoffice/save', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->saveFrontoffice($request, $response);
    });
    $app->post('/impostazioni/login-proxy/save', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->saveLoginProxy($request, $response);
    });
    $app->get('/impostazioni/govpay/test-connection', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testGovpayConnection($request, $response);
    });
    $app->get('/impostazioni/govpay/test-pendenze', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testGovpayPendenze($request, $response);
    });
    $app->get('/impostazioni/govpay/test-pagamenti', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testGovpayPagamenti($request, $response);
    });
    $app->get('/impostazioni/govpay/test-ragioneria', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testGovpayRagioneria($request, $response);
    });
    $app->get('/impostazioni/govpay/test-pendenze-patch', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testGovpayPendenzePatch($request, $response);
    });
    $app->get('/impostazioni/api-esterne/test-checkout', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testCheckout($request, $response);
    });
    $app->get('/impostazioni/api-esterne/test-payment-options', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testPaymentOptions($request, $response);
    });
    $app->get('/impostazioni/api-esterne/test-biz-events', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testBizEvents($request, $response);
    });
    $app->get('/impostazioni/api-esterne/test-tassonomie', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testTassonomie($request, $response);
    });
    $app->post('/impostazioni/backoffice/test-email', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->testEmail($request, $response);
    });
    $app->get('/impostazioni/login-proxy/spid-certs/info', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->getSpidCertInfo($request, $response);
    });
    $app->get('/impostazioni/login-proxy/cie-keys/info', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->getCieKeyDetails($request, $response);
    });
    $app->post('/impostazioni/login-proxy/backup/restore', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->restoreBackupZip($request, $response);
    });
    // SPID metadata
    $app->get('/impostazioni/login-proxy/spid-metadata/info', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->getSpidMetadataInfo($request, $response);
    });
    $app->post('/impostazioni/login-proxy/spid-metadata/export', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->exportSpidMetadata($request, $response);
    });
    $app->get('/impostazioni/login-proxy/spid-metadata/export', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->exportSpidMetadata($request, $response);
    });
    $app->get('/impostazioni/login-proxy/spid-metadata/download', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->downloadSpidMetadata($request, $response);
    });
    $app->post('/impostazioni/login-proxy/spid-metadata/restore', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->restoreSpidMetadata($request, $response);
    });
    // CIE metadata
    $app->get('/impostazioni/login-proxy/cie-metadata/info', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->getCieMetadataInfo($request, $response);
    });
    $app->get('/impostazioni/login-proxy/cie-metadata/download', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->downloadCieMetadata($request, $response);
    });
    $app->post('/impostazioni/login-proxy/cie-metadata/restore', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->restoreCieMetadata($request, $response);
    });
    // Generazione SPID / CIE
    $app->post('/impostazioni/login-proxy/spid-certs/genera', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->generaSpidCerts($request, $response);
    });
    $app->post('/impostazioni/login-proxy/cie-keys/genera', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->generaCieKeys($request, $response);
    });
    $app->get('/impostazioni/login-proxy/cie-keys/genera', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->generaCieKeys($request, $response);
    });
    $app->post('/impostazioni/login-proxy/cie-metadata/export', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->exportCieOidc($request, $response);
    });
    $app->get('/impostazioni/login-proxy/cie-metadata/export', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->exportCieOidc($request, $response);
    });
    // Backup globale IAM proxy
    $app->get('/impostazioni/login-proxy/backup/status', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->getBackupStatus($request, $response);
    });
    $app->post('/impostazioni/login-proxy/backup/export', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->exportBackupZip($request, $response);
    });
    $app->post('/impostazioni/logo/upload', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->uploadLogo($request, $response);
    });
    $app->post('/impostazioni/favicon/upload', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->uploadFavicon($request, $response);
    });
    $app->post('/impostazioni/govpay/upload-cert', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->uploadGovpayCert($request, $response);
    });
    $app->post('/impostazioni/govpay/upload-key', function (Request $request, Response $response) use ($twig): Response {
        return (new ImpostazioniController($twig))->uploadGovpayKey($request, $response);
    });

    // Basic route
    $app->get('/', function (Request $request, Response $response) use ($twig): Response {
        $controller = new HomeController($twig);
        return $controller->index($request, $response);
    });

    // Guida rapida
    $app->get('/guida', function(Request $request, Response $response) use ($twig): Response {
        $controller = new HomeController($twig);
        return $controller->guida($request, $response);
    });

    // Statistiche
    $app->get('/statistiche', function(Request $request, Response $response) use ($twig): Response {
        $controller = new StatisticheController($twig);
        return $controller->index($request, $response);
    });

    // Pendenze
    $app->any('/pendenze', function(Request $request, Response $response) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->index($request, $response);
    });

    $app->get('/pendenze/ricerca', function(Request $request, Response $response) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->search($request, $response);
    });

    $app->get('/pendenze/inserimento', function(Request $request, Response $response) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->showInsert($request, $response);
    });
    // Support POST back to inserimento so 'Modifica' from preview can resend params
    $app->post('/pendenze/inserimento', function(Request $request, Response $response) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->showInsert($request, $response);
    });

    // Anteprima/preview prima della creazione pendenza
    $app->post('/pendenze/preview', function(Request $request, Response $response) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->preview($request, $response);
    });

    // Rateizzazione: mostra form per generare le rate della pendenza
    $app->post('/pendenze/rateizza', function(Request $request, Response $response) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->showRateizzazione($request, $response);
    });

    $app->post('/pendenze/create-rateizzazione', function(Request $request, Response $response) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->createRateizzazione($request, $response);
    });

    $app->get('/pendenze/massivo/inserimento', function(Request $request, Response $response) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->index($request, $response);
    });

    // Massive pendenze extra routes
    $app->get('/pendenze/massivo/template-csv', function(Request $request, Response $response) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->templateCsv($request, $response);
    });
    $app->post('/pendenze/massivo/upload', function(Request $request, Response $response) use ($twig): Response {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->upload($request, $response);
    });
    $app->get('/pendenze/massivo/errori-csv', function(Request $request, Response $response) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->downloadErroriCsv($request, $response);
    });
    $app->post('/pendenze/massivo/conferma', function(Request $request, Response $response) use ($twig): Response {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->conferma($request, $response);
    });
    $app->get('/pendenze/massivo/dettaglio', function(Request $request, Response $response) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->dettaglio($request, $response);
    });

    $app->get('/pendenze/massivo/storico', function(Request $request, Response $response) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->storico($request, $response);
    });

    $app->post('/pendenze/massivo/{batchId}/pausa', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->azioneBatch($request, $response, $args['batchId'] ?? '', 'PAUSE');
    });

    $app->post('/pendenze/massivo/{batchId}/riprendi', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->azioneBatch($request, $response, $args['batchId'] ?? '', 'RESUME');
    });

    $app->post('/pendenze/massivo/{batchId}/elimina', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->azioneBatch($request, $response, $args['batchId'] ?? '', 'DELETE');
    });

    $app->post('/pendenze/massivo/{batchId}/annulla', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new \App\Controllers\MassivePendenzeController($twig);
        return $controller->azioneBatch($request, $response, $args['batchId'] ?? '', 'CANCEL');
    });

    $app->get('/pendenze/dettaglio/{idPendenza}', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->showDetail($request, $response, $args);
    });

    $app->get('/pendenze/modifica/{idPendenza}', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->showEdit($request, $response, $args);
    });

    $app->post('/pendenze/annulla/{idPendenza}', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->annullaPendenza($request, $response, $args);
    });

    $app->post('/pendenze/riattiva/{idPendenza}', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->riattivaPendenza($request, $response, $args);
    });

    $app->post('/pendenze/aggiorna/{idPendenza}', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->aggiornaPendenza($request, $response, $args);
    });

    $app->post('/pendenze/notifiche/reinvia/{idPendenza}', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->reinviaNotifica($request, $response, $args);
    });

    // Preview/print multi-rate document stored in session after createRateizzazione
    $app->get('/pendenze/multirata/preview', function(Request $request, Response $response) use ($twig): Response {
        // only in session
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $doc = $_SESSION['multi_rate_document'] ?? null;
        if (!$doc) return $response->withStatus(404);
        return $twig->render($response, 'pendenze/multirata.html.twig', ['multi' => $doc]);
    });

    $app->get('/avvisi/{idDominio}/{numeroAvviso}', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->downloadAvviso($request, $response, $args);
    });

    // Scarica il PDF che contiene uno o più avvisi raggruppati per documento
    // Support both GET (querystring) and POST (JSON) clients for requesting
    // the document that aggregates one or more avvisi. The frontend posts
    // JSON { numeriAvviso: [ ... ] } so we expose a POST route in addition
    // to the existing GET to keep compatibility with direct links.
    $app->get('/documenti/{numeroDocumento}/avvisi', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->downloadAvvisiDocumento($request, $response, $args);
    });
    $app->post('/documenti/{numeroDocumento}/avvisi', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->downloadAvvisiDocumento($request, $response, $args);
    });

    $app->get('/pendenze/rpp/{idDominio}/{iuv}/{ccp}/rt', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->downloadRicevuta($request, $response, $args);
    });

    // Dominio - Logo proxy: scarica il logo del dominio dal Backoffice (o decodifica base64)
    $app->get('/domini/{idDominio}/logo', function(Request $request, Response $response, array $args) use ($twig): Response {
        $controller = new PendenzeController($twig);
        return $controller->downloadDominioLogo($request, $response, $args);
    });

    // Profile
    $app->get('/profile', function($request, $response) use ($twig) {
        $sessionUser = $_SESSION['user'] ?? null;
        if (!$sessionUser) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $twig->getEnvironment()->addGlobal('current_user', $sessionUser);
        $userId = (int)($sessionUser['id'] ?? 0);
        $userRepo = new UserRepository();
        $profileUser = $userId > 0 ? $userRepo->findById($userId) : $sessionUser;
        $templateRepo = new PendenzaTemplateRepository();
        $userTemplates = $userId > 0 ? $templateRepo->getTemplatesForUser($userId) : [];
        
        // Load default tipologia description
        $userDefaultTipologiaDesc = null;
        if (!empty($profileUser['default_id_entrata'])) {
            $entrateRepo = new EntrateRepository();
            $tipologia = $entrateRepo->findById($profileUser['default_id_entrata']);
            $userDefaultTipologiaDesc = $tipologia ? $tipologia['descrizione'] : null;
        }
        
        $tab = (string)($request->getQueryParams()['tab'] ?? 'info');
        if (!in_array($tab, ['info', 'password', 'templates'], true)) {
            $tab = 'info';
        }
        return $twig->render($response, 'profile.html.twig', [
            'profile_user'               => $profileUser,
            'user_templates'             => $userTemplates,
            'user_default_tipologia_desc' => $userDefaultTipologiaDesc,
            'tab'                        => $tab,
            'flash_profile'              => null,
            'error_profile'              => null,
        ]);
    });

    // Profile - cambio password
    $app->post('/profile/change-password', function($request, $response) use ($twig) {
        $sessionUser = $_SESSION['user'] ?? null;
        if (!$sessionUser) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        $twig->getEnvironment()->addGlobal('current_user', $sessionUser);
        $userId = (int)($sessionUser['id'] ?? 0);
        $userRepo = new UserRepository();
        $profileUser = $userRepo->findById($userId);
        $templateRepo = new PendenzaTemplateRepository();
        $userTemplates = $templateRepo->getTemplatesForUser($userId);

        $data            = (array)($request->getParsedBody() ?? []);
        $currentPassword = $data['current_password'] ?? '';
        $newPassword     = $data['new_password'] ?? '';
        $confirmPassword = $data['new_password_confirm'] ?? '';

        $renderError = function(string $msg) use ($twig, $response, $profileUser, $userTemplates): Response {
            return $twig->render($response, 'profile.html.twig', [
                'profile_user'   => $profileUser,
                'user_templates' => $userTemplates,
                'tab'            => 'password',
                'flash_profile'  => null,
                'error_profile'  => $msg,
            ]);
        };

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            return $renderError('Tutti i campi sono obbligatori.');
        }
        if (!$userRepo->verifyPassword($currentPassword, $profileUser['password_hash'] ?? '')) {
            return $renderError('La password attuale non è corretta.');
        }
        if (strlen($newPassword) < 8) {
            return $renderError('La nuova password deve essere di almeno 8 caratteri.');
        }
        if ($newPassword !== $confirmPassword) {
            return $renderError('La nuova password e la conferma non coincidono.');
        }

        $userRepo->updatePasswordById($userId, $newPassword);
        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Password aggiornata con successo.'];
        return $response->withHeader('Location', '/profile?tab=password')->withStatus(302);
    });


    // Aggiungi/aggiorna operatore - solo superadmin
    $app->post('/configurazione/operatori/add', function($request, $response) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->addOperatore($request, $response);
    });

    // Abilita/disabilita operatore - solo superadmin
    $app->post('/configurazione/operatori/{principal}/abilitato', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->toggleOperatoreAbilitato($request, $response, $args);
    });

    // Aggiorna dati dominio (Backoffice addDominio) - solo superadmin
    $app->post('/configurazione/dominio', function($request, $response) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->updateDominio($request, $response);
    });

    // Tipologie di pagamento esterne - crea
    $app->post('/configurazione/tipologie-esterne', function($request, $response) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->createExternalPaymentType($request, $response);
    });

    // Tipologie di pagamento esterne - elimina
    $app->post('/configurazione/tipologie-esterne/{id}/delete', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->deleteExternalPaymentType($request, $response, $args);
    });

    // Tipologie di pagamento esterne - aggiorna
    $app->post('/configurazione/tipologie-esterne/{id}/update', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->updateExternalPaymentType($request, $response, $args);
    });

    // Endpoint per override locale tipologie (solo superadmin)
    $app->post('/configurazione/tipologie/{idEntrata}/override', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->overrideTipologia($request, $response, $args);
    });

    // Endpoint per salvare l'URL esterna di una tipologia (solo superadmin)
    $app->post('/configurazione/tipologie/{idEntrata}/url', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->updateTipologiaUrl($request, $response, $args);
    });

    // Endpoint per aggiornare la descrizione locale della tipologia (solo superadmin)
    $app->post('/configurazione/tipologie/{idEntrata}/descrizione', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->updateTipologiaDescrizione($request, $response, $args);
    });

    // Ripristina la descrizione originale di GovPay (cancella descrizione_locale)
    $app->post('/configurazione/tipologie/{idEntrata}/descrizione/restore', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->restoreTipologiaDescrizione($request, $response, $args);
    });

    // Endpoint per aggiornare la descrizione estesa (locale) della tipologia (solo superadmin)
    $app->post('/configurazione/tipologie/{idEntrata}/descrizione-estesa', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->updateTipologiaDescrizioneEstesa($request, $response, $args);
    });

    // Ripristina/cancella la descrizione estesa (set NULL)
    $app->post('/configurazione/tipologie/{idEntrata}/descrizione-estesa/restore', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->restoreTipologiaDescrizioneEstesa($request, $response, $args);
    });

    // Copia descrizioni estese vuote dalla tassonomia PagoPA (DESCRIZIONE TIPO SERVIZIO)
    $app->post('/configurazione/tipologie/descrizione-estesa/copia-da-tassonomia', function($request, $response) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->copyTipologieDescrizioneEstesaFromTassonomie($request, $response);
    });

    // Pendenza Templates (CRUD)
    $app->post('/configurazione/templates/add', function($request, $response) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->addPendenzaTemplate($request, $response);
    });
    $app->post('/configurazione/templates/{id}/update', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->updatePendenzaTemplate($request, $response, $args);
    });
    $app->post('/configurazione/templates/{id}/delete', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->deletePendenzaTemplate($request, $response, $args);
    });
    $app->post('/configurazione/templates/{id}/assign-users', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->assignUsersToPendenzaTemplate($request, $response, $args);
    });

    // Endpoint per attivare/disattivare la tipologia direttamente su GovPay (solo superadmin)
    $app->post('/configurazione/tipologie/{idEntrata}/govpay', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->updateTipologiaGovpay($request, $response, $args);
    });

    // Endpoint reset: cancella URL esterna e, se GovPay è attivo, riallinea lo stato locale a GovPay (override=null)

    $app->post('/configurazione/tipologie/{idEntrata}/reset', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->resetTipologia($request, $response, $args);
    });

    // App IO Services - CRUD
    $app->post('/configurazione/io-services', function($request, $response) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->createIoService($request, $response);
    });

    $app->post('/configurazione/io-services/{id}/update', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->updateIoService($request, $response, $args);
    });

    $app->post('/configurazione/io-services/{id}/delete', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->deleteIoService($request, $response, $args);
    });

    $app->post('/configurazione/io-services/{id}/set-default', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->setDefaultIoService($request, $response, $args);
    });

    // Associa servizio IO a tipologia (singola)
    $app->post('/configurazione/tipologie/{idEntrata}/io-service', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->setTipologiaIoService($request, $response, $args);
    });

    // Salva tutti i parametri di una tipologia in un colpo solo (descrizione, descr. estesa, servizio IO, URL esterna)
    $app->post('/configurazione/tipologie/{idEntrata}/save', function($request, $response, $args) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->saveTipologia($request, $response, $args);
    });

    // Salvataggio massivo associazioni tipologie <-> servizi IO (mantenuto per retrocompatibilità)
    $app->post('/configurazione/tipologie/io-service/bulk-save', function($request, $response) use ($twig) {
        $controller = new ConfigurazioneController($twig);
        return $controller->bulkSetTipologieIoService($request, $response);
    });

    // Backup di sistema (logica locale nel backoffice)
    $app->post('/backup/sistema/crea', function ($request, $response) use ($twig) {
        return (new BackupController($twig))->systemBackupCreate($request, $response);
    });

    $app->get('/backup/sistema/lista', function ($request, $response) use ($twig) {
        return (new BackupController($twig))->systemBackupList($request, $response);
    });

    $app->get('/backup/sistema/download', function ($request, $response) use ($twig) {
        return (new BackupController($twig))->systemBackupDownload($request, $response);
    });

    $app->post('/backup/sistema/elimina', function ($request, $response) use ($twig) {
        return (new BackupController($twig))->systemBackupDelete($request, $response);
    });

    $app->post('/backup/sistema/ripristina', function ($request, $response) use ($twig) {
        return (new BackupController($twig))->systemBackupRestore($request, $response);
    });

    $app->get('/users/new', function($request, $response) use ($twig) {
        if (isset($_SESSION['user'])) {
            $twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }
        return $twig->render($response, 'users/new.html.twig');
    });

    $app->post('/users/new', function($request, $response) use ($twig) {
        $controller = new UsersController();
        $resOrReq = $controller->create($request, $response, []);
        if ($resOrReq instanceof Response) {
            return $resOrReq; // redirect già pronto (flash impostato nel controller)
        }
        $error = $resOrReq->getAttribute('error');
        if ($error) {
            if (isset($_SESSION['user'])) {
                $twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
            }
            return $twig->render($response, 'users/new.html.twig', ['error' => $error]);
        }
        // fallback: torna alla lista
        return $response->withHeader('Location', '/users')->withStatus(302);
    });

    $app->get('/users/{id}/edit', function($request, $response, $args) use ($twig) {
        $controller = new UsersController();
        $req = $controller->edit($request, $response, $args);
        $editUser = $req->getAttribute('edit_user');
        if (isset($_SESSION['user'])) {
            $twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }
        
        // Carica tipologie per il dominio
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $allTipologie = [];
        $userTipologie = [];
        if ($idDominio && $editUser) {
            try {
                $entrateRepo = new \App\Database\EntrateRepository();
                $allTipologie = $entrateRepo->listAbilitateByDominio($idDominio);
                $userId = (int)($editUser['id'] ?? 0);
                if ($userId > 0) {
                    $userTipologie = $entrateRepo->getEnabledTipologieForUser($userId, $idDominio);
                }
            } catch (\Throwable $e) {}
        }
        
        return $twig->render($response, 'users/edit.html.twig', [
            'edit_user' => $editUser,
            'all_tipologie' => $allTipologie,
            'user_tipologie_ids' => $userTipologie,
            'id_dominio' => $idDominio,
        ]);
    });

    $app->post('/users/{id}/edit', function($request, $response, $args) use ($twig) {
        $controller = new UsersController();
        $resOrReq = $controller->update($request, $response, $args);
        if ($resOrReq instanceof Response) {
            return $resOrReq; // redirect già pronto (flash impostato nel controller)
        }
        $error = $resOrReq->getAttribute('error');
        if ($error) {
            $editUser = $resOrReq->getAttribute('edit_user');
            if (isset($_SESSION['user'])) {
                $twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
            }
            
            // Ricaricare tipologie in caso di errore
            $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
            $allTipologie = [];
            $userTipologie = [];
            if ($idDominio && $editUser) {
                try {
                    $entrateRepo = new \App\Database\EntrateRepository();
                    $allTipologie = $entrateRepo->listAbilitateByDominio($idDominio);
                    $userId = (int)($editUser['id'] ?? 0);
                    if ($userId > 0) {
                        $userTipologie = $entrateRepo->getEnabledTipologieForUser($userId, $idDominio);
                    }
                } catch (\Throwable $e) {}
            }
            
            return $twig->render($response, 'users/edit.html.twig', [
                'error' => $error,
                'edit_user' => $editUser,
                'all_tipologie' => $allTipologie,
                'user_tipologie_ids' => $userTipologie,
                'id_dominio' => $idDominio,
            ]);
        }
        
        // Successo: salvare la tipologia default e il filtro tipologie
        $userId = (int)($args['id'] ?? 0);
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $data = (array)($request->getParsedBody() ?? []);
        $defaultTipologia = $data['default_id_entrata'] ?? null;
        $tipologieIds = (array)($data['enabled_tipologie'] ?? []);
        
        try {
            $userRepo = new \App\Auth\UserRepository();
            if (!empty($defaultTipologia)) {
                $userRepo->setDefaultTipologia($userId, (string)$defaultTipologia);
            } else {
                $userRepo->setDefaultTipologia($userId, null);
            }
            
            if ($idDominio) {
                $entrateRepo = new \App\Database\EntrateRepository();
                $entrateRepo->setEnabledTipologieForUser($userId, $idDominio, $tipologieIds);
            }
        } catch (\Throwable $e) {
            // Log ma prosegui (non è critico)
        }
        
        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Utente aggiornato'];
        return $response->withHeader('Location', '/impostazioni?tab=utenti')->withStatus(302);
    });

    $app->post('/users/{id}/disable', function($request, $response, $args) {
        $controller = new UsersController();
        return $controller->disable($request, $response, $args);
    });

    $app->post('/users/{id}/enable', function($request, $response, $args) {
        $controller = new UsersController();
        return $controller->enable($request, $response, $args);
    });
    $app->post('/users/{id}/send-reset-password', function($request, $response, $args) {
        $controller = new UsersController();
        return $controller->sendPasswordResetLink($request, $response, $args);
    });
    // Login routes
    $app->get('/login', function($request, $response) use ($twig) {
        if (isset($_SESSION['user'])) {
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $twig->render($response, 'login.html.twig', [
            'error' => null,
            'last_email' => ''
        ]);
    });

    $app->post('/login', function($request, $response) use ($twig) {
        $data = (array)($request->getParsedBody() ?? []);
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $repo = new UserRepository();
        $user = $email !== '' ? $repo->findByEmail($email) : null;
        if ($user && $repo->verifyPassword($password, $user['password_hash'])) {
            if (!empty($user['is_disabled'])) {
                return $twig->render($response, 'login.html.twig', [
                    'error' => 'Account disabilitato: contatta un amministratore',
                    'last_email' => $email,
                ]);
            }
            // Set session user (include name fields for templates)
            $_SESSION['user'] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $user['role'],
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'is_disabled' => !empty($user['is_disabled']),
            ];
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Accesso effettuato'];
            return $response->withHeader('Location', '/')->withStatus(302);
        }
        return $twig->render($response, 'login.html.twig', [
            'error' => 'Credenziali non valide',
            'last_email' => $email,
        ]);
    });

    $app->get('/logout', function($request, $response) {
        // Mantieni la sessione per mostrare il flash dopo il redirect
        $_SESSION['flash'][] = ['type' => 'info', 'text' => 'Sei stato disconnesso'];
        unset($_SESSION['user']);
        // Rigenera l'ID di sessione per sicurezza
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        return $response->withHeader('Location', '/login')->withStatus(302);
    });

    // Password reset (route pubbliche)
    $app->get('/password-dimenticata', function($request, $response) use ($twig) {
        $controller = new \App\Controllers\PasswordResetController($twig);
        return $controller->showForgot($request, $response);
    });
    $app->post('/password-dimenticata', function($request, $response) use ($twig) {
        $controller = new \App\Controllers\PasswordResetController($twig);
        return $controller->sendReset($request, $response);
    });
    $app->get('/reset-password', function($request, $response) use ($twig) {
        $controller = new \App\Controllers\PasswordResetController($twig);
        return $controller->showReset($request, $response);
    });
    $app->post('/reset-password', function($request, $response) use ($twig) {
        $controller = new \App\Controllers\PasswordResetController($twig);
        return $controller->doReset($request, $response);
    });

    $displayErrorDetails = \App\Config\SettingsRepository::get('app', 'debug', 'false') === 'true';
    // Espone un flag globale a Twig per consentire controlli condizionali lato template
    $twig->getEnvironment()->addGlobal('app_debug', $displayErrorDetails);
    if ($displayErrorDetails) {
        $app->get('/_test-error', function() {
            throw new \RuntimeException('Errore di test intenzionale');
        });
    }

    // Error handling personalizzato per 404
    $appDebugRaw = getenv('APP_DEBUG');
    $displayErrorDetails = \App\Config\SettingsRepository::get('app', 'debug', 'false') === 'true'
        || ($appDebugRaw !== false && in_array(strtolower((string)$appDebugRaw), ['1','true','yes','on'], true));
    $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
    $errorMiddleware->setErrorHandler(HttpNotFoundException::class, function (
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($twig) : Response {
        $response = new \Slim\Psr7\Response();
        return $twig->render($response->withStatus(404), 'errors/404.html.twig', [
            'path' => $request->getUri()->getPath()
        ]);
    });

    // Handler generico 500
    $errorMiddleware->setDefaultErrorHandler(function (
        Request $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($twig) : Response {
        // Log esteso per diagnosi (sempre) - evita leak in output se non in debug
        error_log('[APP ERROR] ' . $exception::class . ': ' . $exception->getMessage() . " in " . $exception->getFile() . ':' . $exception->getLine());
        foreach ($exception->getTrace() as $i => $t) {
            if ($i > 15) { break; }
            $fn = ($t['class'] ?? '') . ($t['type'] ?? '') . ($t['function'] ?? '');
            error_log("  #$i $fn (" . ($t['file'] ?? '?') . ':' . ($t['line'] ?? '?') . ")");
        }
        $status = $exception instanceof HttpInternalServerErrorException ? 500 : 500;
        $response = new \Slim\Psr7\Response();
        return $twig->render($response->withStatus($status), 'errors/500.html.twig', [
            'exception' => $exception,
            'displayErrorDetails' => $displayErrorDetails,
        ]);
    });

    // Rotta diagnostica per verificare i template caricabili (solo in debug)
    if ($displayErrorDetails) {
        $app->get('/_diag/templates', function($request, $response) use ($twig) {
            $candidates = [
                'base.html.twig',
                'pendenze.html.twig',
                'home.html.twig',
                'partials/header.html.twig',
                'partials/footer.html.twig',
                'errors/404.html.twig',
                'errors/500.html.twig'
            ];
            $loader = $twig->getLoader();
            $rows = [];
            foreach ($candidates as $tpl) {
                $ok = 'missing';
                try { if ($loader->exists($tpl)) { $ok = 'ok'; } } catch (\Throwable $e) { $ok = 'error:' . $e->getMessage(); }
                $rows[] = [$tpl, $ok];
            }
            $body = "<h1>Template Diagnostic</h1><table border='1' cellpadding='4'><tr><th>Template</th><th>Status</th></tr>";
            foreach ($rows as [$t,$s]) { $body .= "<tr><td>" . htmlspecialchars($t) . "</td><td>" . htmlspecialchars($s) . "</td></tr>"; }
            $body .= '</table>';
            $response->getBody()->write($body);
            return $response;
        });

        // Simple session diagnostic (only in debug)
        $app->get('/_diag/session', function($request, $response) use ($twig) {
            $sess = session_status() === PHP_SESSION_ACTIVE ? ($_SESSION ?? []) : null;
            $payload = [
                'session_active' => session_status() === PHP_SESSION_ACTIVE,
                'session' => $sess,
                'current_user' => $sess['user'] ?? null,
            ];
            $response->getBody()->write('<pre>' . htmlspecialchars(print_r($payload, true)) . '</pre>');
            return $response;
        });

        // Debug helper: login as seeded superadmin (only in debug)
        $app->get('/_diag/login-as-admin', function($request, $response) use ($twig) {
            try {
                $repo = new UserRepository();
                $user = $repo->findByEmail('admin@example.com');
                if (!$user) {
                    $response->getBody()->write('Admin user not found');
                    return $response->withStatus(404);
                }
                // Ensure session active
                if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'first_name' => $user['first_name'] ?? '',
                    'last_name' => $user['last_name'] ?? '',
                ];
                $response->getBody()->write('<p>Logged in as admin. <a href="/">Go to home</a></p>');
                return $response;
            } catch (\Throwable $e) {
                $response->getBody()->write('Error: ' . htmlspecialchars($e->getMessage()));
                return $response->withStatus(500);
            }
        });

        // Rotta di debug: elenca le ricevute disponibili per {idDominio}/{iuv}
        $app->get('/_diag/ricevute/{idDominio}/{iuv}', function($request, $response, $args) {
            $idDominio = $args['idDominio'] ?? '';
            $iuv = $args['iuv'] ?? '';
            if ($idDominio === '' || $iuv === '') {
                $response->getBody()->write('Parametri mancanti');
                return $response->withStatus(400);
            }

            $pagamentiUrl = SettingsRepository::get('govpay', 'pagamenti_url', '');
            if (empty($pagamentiUrl)) {
                $response->getBody()->write('GOVPAY_PAGAMENTI_URL non impostata');
                return $response->withStatus(500);
            }

            try {
                $username = SettingsRepository::get('govpay', 'user', '');
                $password = SettingsRepository::get('govpay', 'password', '');
                $guzzleOptions = [
                    'headers' => ['Accept' => 'application/json']
                ];
                $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
                if (in_array(strtolower($authMethod), ['ssl', 'sslheader'], true)) {
                    $cert    = SettingsRepository::get('govpay', 'tls_cert_path', '');
                    $key     = SettingsRepository::get('govpay', 'tls_key_path', '');
                    $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
                    if (!empty($cert) && !empty($key)) {
                        $guzzleOptions['cert'] = $cert;
                        $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                    } else {
                        $response->getBody()->write('mTLS abilitato ma tls_cert_path/tls_key_path non impostati');
                        return $response->withStatus(500);
                    }
                }
                if ($username !== '' && $password !== '') {
                    $guzzleOptions['auth'] = [$username, $password];
                }

                $http = new \GuzzleHttp\Client($guzzleOptions);
                $url = rtrim($pagamentiUrl, '/') . '/ricevute/' . rawurlencode($idDominio) . '/' . rawurlencode($iuv);
                $resp = $http->request('GET', $url, ['query' => ['esito' => 'ESEGUITO']]);
                $json = (string)$resp->getBody();
                $response = $response->withHeader('Content-Type', 'application/json');
                $response->getBody()->write($json);
                return $response;
            } catch (\GuzzleHttp\Exception\ClientException $ce) {
                $code = $ce->getResponse() ? $ce->getResponse()->getStatusCode() : 0;
                $response->getBody()->write('Errore client diag ricevute: ' . $ce->getMessage());
                return $response->withStatus($code ?: 500);
            } catch (\Throwable $e) {
                $response->getBody()->write('Errore diag ricevute: ' . $e->getMessage());
                return $response->withStatus(500);
            }
        });
    }

    // Pagamenti - Ricerca Flussi (utenti autenticati)

    $app->get('/pagamenti/ricerca-flussi', function($request, $response) use ($twig) {
        $controller = new FlussiController($twig);
        return $controller->search($request, $response);
    });

    $app->get('/pagamenti/ricerca-flussi/dettaglio/{idFlusso}', function($request, $response, $args) use ($twig) {
        $controller = new FlussiController($twig);
        return $controller->detail($request, $response, $args);
    });

    // AJAX: fetch single Biz Events receipt on-demand
    $app->get('/api/biz-event', function($request, $response) use ($twig) {
        $controller = new FlussiController($twig);
        return $controller->fetchBizEvent($request, $response);
    });

};
