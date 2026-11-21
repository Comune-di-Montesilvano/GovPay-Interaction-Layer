<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Middleware che rilascia il lock di scrittura sulla sessione PHP (session_write_close)
 * per le richieste di sola lettura (GET, HEAD, OPTIONS).
 * Questo previene il blocco concorrente delle pagine (Session Concurrency Bottleneck).
 *
 * Eccezione: il wizard di setup (/setup, /setup/*) scrive in sessione anche nelle GET (stato
 * del wizard e token CSRF creati da SetupController::initWizardSession): chiudendo prima la
 * sessione quei dati non venivano salvati e su un'installazione nuova ogni step tornava con
 * ?error=csrf. Pagine a utente singolo, nessun problema di concorrenza.
 */
class ReleaseSessionLockMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $method = strtoupper($request->getMethod());
        $path = $request->getUri()->getPath();
        $isSetup = $path === '/setup' || str_starts_with($path, '/setup/');

        if (!$isSetup && in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }

        return $handler->handle($request);
    }
}
