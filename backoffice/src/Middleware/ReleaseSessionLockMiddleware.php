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
 */
class ReleaseSessionLockMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $method = strtoupper($request->getMethod());

        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }

        return $handler->handle($request);
    }
}
