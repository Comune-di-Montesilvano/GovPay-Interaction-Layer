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

class SessionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            $https = strtolower((string)($_SERVER['HTTPS'] ?? 'off'));
            $xfp = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
            $xfSsl = strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''));
            $secure = ($https === 'on' || $https === '1' || $xfp === 'https' || $xfSsl === 'on');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        return $handler->handle($request);
    }
}
