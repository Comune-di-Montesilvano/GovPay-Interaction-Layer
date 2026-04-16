<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use App\Config\ConfigLoader;

/**
 * Intercepta ogni richiesta e reindirizza al wizard /setup
 * se il sistema non è ancora configurato (config.json mancante o setup_complete=false).
 *
 * Deve essere registrato PRIMA di AuthMiddleware nello stack.
 */
class SetupMiddleware implements MiddlewareInterface
{
    /** Path sempre accessibili anche senza setup completato */
    private const BYPASS_PATHS = [
        '/setup',
        '/setup/*',
        '/api/*',
        '/assets/*',
        '/health',
        '/login',
        '/logout',
        '/password-dimenticata',
        '/reset-password',
        '/debug/*',
    ];

    public function process(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();

        // I path di bypass passano sempre
        foreach (self::BYPASS_PATHS as $bypass) {
            if ($this->matchPath($path, $bypass)) {
                return $handler->handle($request);
            }
        }

        // Se setup già completato, procedi normalmente
        if (ConfigLoader::isSetupComplete()) {
            return $handler->handle($request);
        }

        // Determina se è un upgrade (variabili .env ancora presenti) o installazione nuova
        $isUpgrade = $this->hasLegacyEnv();
        $mode = $isUpgrade ? 'upgrade' : 'fresh';

        $resp = new SlimResponse(302);
        return $resp->withHeader('Location', '/setup?mode=' . $mode);
    }

    private function matchPath(string $path, string $pattern): bool
    {
        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim(substr($pattern, 0, -1), '/');
            return str_starts_with($path, $prefix);
        }
        return rtrim($path, '/') === rtrim($pattern, '/');
    }

    /**
     * True se le variabili d'ambiente del .env legacy sono ancora presenti.
     * Indica un deployment esistente che deve fare upgrade, non una nuova installazione.
     */
    private function hasLegacyEnv(): bool
    {
        $legacyVars = ['DB_PASSWORD', 'GOVPAY_BACKOFFICE_URL', 'APP_ENTITY_NAME'];
        foreach ($legacyVars as $var) {
            $val = getenv($var);
            if ($val !== false && $val !== '') {
                return true;
            }
        }
        return false;
    }
}
