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
            self::configureCookieParams($_SERVER);
            session_start();
        }
        return $handler->handle($request);
    }

    /**
     * Parametri del cookie di sessione (HttpOnly, SameSite=Lax, Secure se la richiesta arriva in
     * HTTPS diretto o dietro proxy TLS). Chiamato dal bootstrap prima dei middleware, perche'
     * qualunque session_start() successivo li usi. No-op con una sessione gia' attiva.
     *
     * @param array<string, mixed> $server $_SERVER della richiesta
     */
    public static function configureCookieParams(array $server): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = strtolower((string)($server['HTTPS'] ?? 'off'));
        $xfp = strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $xfSsl = strtolower((string)($server['HTTP_X_FORWARDED_SSL'] ?? ''));
        $secure = ($https === 'on' || $https === '1' || $xfp === 'https' || $xfSsl === 'on');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
