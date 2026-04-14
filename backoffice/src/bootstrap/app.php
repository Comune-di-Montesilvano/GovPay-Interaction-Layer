<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

// Never output raw PHP errors/warnings to HTTP responses (prevents HTML leaking into JSON)
ini_set('display_errors', '0');

use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use App\Middleware\SessionMiddleware;
use App\Middleware\AuthMiddleware;
use App\Middleware\SetupMiddleware;
use App\Middleware\FlashMiddleware;
use App\Middleware\CurrentPathMiddleware;
use App\Auth\UserRepository;
use App\Logger;

return (function (): array {
    $app = AppFactory::create();
    $app->setBasePath('');

    $templateCandidates = [
        __DIR__ . '/../templates',
        __DIR__ . '/../../templates',
        '/var/www/html/src/templates',
        '/var/www/html/templates',
    ];
    $templatePaths = [];
    foreach ($templateCandidates as $candidate) {
        $normalized = realpath($candidate) ?: $candidate;
        if (is_dir($normalized) && !in_array($normalized, $templatePaths, true)) {
            $templatePaths[] = $normalized;
        }
    }
    if ($templatePaths === []) {
        throw new \RuntimeException('Nessuna directory templates trovata nelle path configurate.');
    }

    $twig = Twig::create($templatePaths, ['cache' => false]);

    // Legge i parametri di configurazione: prima dalla tabella settings (via Config facade),
    // poi da getenv() come fallback per compatibilità legacy con .env
    $entityName = \App\Config\Config::get('APP_ENTITY_NAME') ?: 'Comune di Esempio';
    $entitySuffix = \App\Config\Config::get('APP_ENTITY_SUFFIX') ?: 'Servizi ai cittadini';
    $entityGovernment = \App\Config\Config::get('APP_ENTITY_GOVERNMENT') ?: '';
    $logoSrc  = trim((string)(\App\Config\Config::get('APP_LOGO_SRC')  ?: '/img/stemma_ente.png'));
    $logoType = trim((string)(\App\Config\Config::get('APP_LOGO_TYPE') ?: 'img'));
    if ($logoType === 'img') {
        // Percorso locale: verifica che il file esista nel container
        $logoFs = '/var/www/html/public' . $logoSrc;
        $appLogo = file_exists($logoFs)
            ? ['type' => 'img', 'src' => $logoSrc]
            : ['type' => 'sprite', 'src' => '/assets/bootstrap-italia/svg/sprites.svg#it-pa'];
    } else {
        // URL esterno (APP_LOGO_TYPE = MIME type, es. "image/png")
        $appLogo = ['type' => 'img', 'src' => $logoSrc];
    }
    $customFaviconIco = '/var/www/html/public/img/favicon.ico';
    $customFaviconPng = '/var/www/html/public/img/favicon.png';
    $appFavicon = file_exists($customFaviconIco)
        ? ['href' => '/img/favicon.ico', 'type' => 'image/x-icon']
        : (file_exists($customFaviconPng)
            ? ['href' => '/img/favicon.png', 'type' => 'image/png']
            : ['href' => '/img/favicon_default.png', 'type' => 'image/png']);

    $twig->getEnvironment()->addGlobal('app_entity', [
        'name' => $entityName,
        'suffix' => $entitySuffix,
        'full' => $entityName . ' - ' . $entitySuffix,
        'government' => $entityGovernment,
    ]);
    $twig->getEnvironment()->addGlobal('app_logo', $appLogo);
    $twig->getEnvironment()->addGlobal('app_favicon', $appFavicon);

    $twig->getEnvironment()->addGlobal('app_version', (string)(getenv('GIL_IMAGE_TAG') ?: 'dev'));
    $twig->getEnvironment()->addGlobal('app_commit', (string)(getenv('GIT_COMMIT_SHA') ?: 'unknown'));


    $pendenzaStates = [
        'NON_ESEGUITA' => ['label' => 'Da pagare', 'color' => 'secondary'],
        'NON_ESEGUITO' => ['label' => 'Da pagare', 'color' => 'secondary'],
        'TENTATIVO_DI_PAGAMENTO' => ['label' => 'Tentativo di pagamento in corso', 'color' => 'warning'],
        'ESEGUITA' => ['label' => 'Pagato', 'color' => 'success'],
        'ESEGUITO' => ['label' => 'Pagato', 'color' => 'success'],
        'ESEGUITA_PARZIALE' => ['label' => 'Pagamento parziale', 'color' => 'warning'],
        'ESEGUITO_PARZIALE' => ['label' => 'Pagamento parziale', 'color' => 'warning'],
        'ANNULLATA' => ['label' => 'Annullato', 'color' => 'light'],
        'ANNULLATO' => ['label' => 'Annullato', 'color' => 'light'],
        'SCADUTA' => ['label' => 'Scaduto', 'color' => 'danger'],
        'SCADUTO' => ['label' => 'Scaduto', 'color' => 'danger'],
        'ANOMALA' => ['label' => 'Anomalia', 'color' => 'danger'],
        'ANOMALO' => ['label' => 'Anomalia', 'color' => 'danger'],
        'ERRORE' => ['label' => 'Errore', 'color' => 'danger'],
        'RENDICONTATA' => ['label' => 'Rendicontato', 'color' => 'primary'],
        'RENDICONTATO' => ['label' => 'Rendicontato', 'color' => 'primary'],
        'INCASSATA' => ['label' => 'Incassato', 'color' => 'primary'],
        'INCASSATO' => ['label' => 'Incassato', 'color' => 'primary'],
        'DECORRENZA' => ['label' => 'Decorrenza termini', 'color' => 'warning'],
        'DECORRENZA_PARZIALE' => ['label' => 'Decorrenza parziale', 'color' => 'warning'],
        'RISCOSSA' => ['label' => 'Riscossione completata', 'color' => 'success'],
    ];
    $twig->getEnvironment()->addGlobal('pendenza_states', $pendenzaStates);

    $app->add(TwigMiddleware::create($app, $twig));

    // Ensure storage logs directory exists and register logger global
    @mkdir(__DIR__ . '/../../storage/logs', 0775, true);
    $twig->getEnvironment()->addGlobal('app_logger', Logger::getInstance());

    // Register global error/exception handlers to also write to our application log
    set_error_handler(function (int $severity, string $message, string $file, int $line) {
        try {
            $context = [
                'file' => $file,
                'line' => $line,
                'severity' => $severity,
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'user_id' => isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null,
            ];
            Logger::getInstance()->error('PHP error: ' . $message, $context);
        } catch (\Throwable $_) {
            // swallow
        }
        // Suppress PHP internal handler (prevents HTML error output in JSON responses)
        return true;
    });

    set_exception_handler(function (\Throwable $e) {
        try {
            $context = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                'user_id' => isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null,
            ];
            Logger::getInstance()->error('Uncaught exception: ' . $e->getMessage(), $context);
        } catch (\Throwable $_) {
            // swallow
        }
        // Fallback to default exception handling
        http_response_code(500);
        // Optionally rethrow or exit
        // echo "An error occurred";
    });

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            try {
                $context = [
                    'file' => $err['file'] ?? null,
                    'line' => $err['line'] ?? null,
                    'type' => $err['type'] ?? null,
                    'message' => $err['message'] ?? null,
                    'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
                    'user_id' => isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null,
                ];
                Logger::getInstance()->error('Fatal shutdown error: ' . ($err['message'] ?? ''), $context);
            } catch (\Throwable $_) {
                // swallow
            }
        }
    });

    // Percorsi pubblici che non richiedono sessione (AuthMiddleware li salta).
    // Aggiungiamo '/api/*' per permettere chiamate interne autenticata via Bearer (es. auth-proxy/startup.sh)
    $publicPaths = ['/login', '/logout', '/assets/*', '/debug/*', '/guida', '/password-dimenticata', '/reset-password', '/setup', '/setup/*', '/health', '/api/*'];
    $app->add(new AuthMiddleware($publicPaths));
    // SetupMiddleware deve essere PRIMA di AuthMiddleware nello stack
    // (in Slim il middleware viene eseguito in ordine inverso all'aggiunta — l'ultimo aggiunto è il primo eseguito)
    $app->add(new SetupMiddleware());
    $app->add(new FlashMiddleware($twig));
    $app->add(new SessionMiddleware());
    $app->add(new CurrentPathMiddleware($twig));
    // ErrorMiddleware deve essere aggiunta PER ULTIMA (LIFO: eseguita per prima) per catturare
    // HttpMethodNotAllowedException e restituire 405 invece di far risalire a set_exception_handler (500).
    $app->addErrorMiddleware(false, false, false);

    // current_user is populated per-request by CurrentPathMiddleware to ensure
    // session is started and DB enrichment (if needed) can run safely.

    return [$app, $twig];
})();
