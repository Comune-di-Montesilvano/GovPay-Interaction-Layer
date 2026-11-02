<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Middleware;

use App\Security\JobControlCsrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;

/**
 * CSRF globale del backoffice: ogni richiesta POST/PUT/PATCH/DELETE deve portare il token di
 * sessione (campo `csrf_token` nel corpo, anche multipart, oppure header `X-CSRF-Token`).
 *
 * Token globale = `JobControlCsrf` (sessione `cron_csrf`), esposto nelle pagine da `<meta name="csrf-token">`
 * e iniettato da /assets/js/csrf-guard.js. Per non rompere i controller con un proprio token
 * (Impostazioni, Backup, Mapping, Rendicontazione) il campo `csrf_token` e' accettato anche se
 * corrisponde a uno di quei token di sessione: i loro controller lo riverificano comunque.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * Allowlist esplicita (path esatto o prefisso `/*`). Ogni voce va motivata.
     * Nuove route /api con Bearer MASTER_TOKEN: aggiungerle qui.
     */
    public const EXCLUDED_PATHS = [
        // Sidecar frontoffice: autenticato da BearerTokenMiddleware (MASTER_TOKEN), chiamato server-to-server,
        // non raggiungibile da un browser con cookie di sessione.
        '/api/frontoffice/*',
        // Wizard di setup/ripristino: ha un proprio token (`$_SESSION['wizard']['csrf_token']`) verificato da SetupController.
        '/setup',
        '/setup/*',
    ];

    /** Chiavi di sessione dei token per-controller accettati nel campo `csrf_token`. */
    private const LEGACY_SESSION_KEYS = ['impostazioni_csrf', 'backup_csrf', 'mapping_csrf', 'rendicontazione_csrf'];

    public function process(Request $request, RequestHandler $handler): Response
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            // Sessione ancora aperta (ReleaseSessionLockMiddleware gira dopo): crea il token cosi' le pagine lo leggono senza riaprirla.
            JobControlCsrf::token();
            return $handler->handle($request);
        }
        if (self::isExcluded($request->getUri()->getPath())) {
            return $handler->handle($request);
        }
        if (self::hasValidToken($request)) {
            return $handler->handle($request);
        }
        return self::deny($request);
    }

    public static function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_PATHS as $rule) {
            if ($rule === $path) {
                return true;
            }
            if (str_ends_with($rule, '/*') && str_starts_with($path, substr($rule, 0, -1))) {
                return true;
            }
        }
        return false;
    }

    private static function hasValidToken(Request $request): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $global = $_SESSION[JobControlCsrf::SESSION_KEY] ?? '';
        if (!is_string($global) || $global === '') {
            return false;
        }
        $header = $request->getHeaderLine('X-CSRF-Token');
        if ($header !== '' && hash_equals($global, $header)) {
            return true;
        }
        $body = $request->getParsedBody();
        $field = is_array($body) ? ($body[JobControlCsrf::FIELD] ?? '') : '';
        if (!is_string($field) || $field === '') {
            return false;
        }
        if (hash_equals($global, $field)) {
            return true;
        }
        foreach (self::LEGACY_SESSION_KEYS as $key) {
            $t = $_SESSION[$key] ?? '';
            if (is_string($t) && $t !== '' && hash_equals($t, $field)) {
                return true;
            }
        }
        return false;
    }

    private static function wantsJson(Request $request): bool
    {
        return stripos($request->getHeaderLine('Accept'), 'application/json') !== false
            || $request->getHeaderLine('X-Requested-With') !== ''
            || $request->hasHeader('X-CSRF-Token')
            || stripos($request->getHeaderLine('Content-Type'), 'application/json') !== false;
    }

    private static function deny(Request $request): Response
    {
        $response = new SlimResponse();
        if (self::wantsJson($request)) {
            $response->getBody()->write((string)json_encode(JobControlCsrf::errorPayload(), JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }
        $back = self::sameOriginReferer($request);
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $response->getBody()->write(JobControlCsrf::ERROR_MESSAGE);
            return $response->withHeader('Content-Type', 'text/plain; charset=UTF-8')->withStatus(403);
        }
        $_SESSION['flash'][] = ['type' => 'error', 'text' => JobControlCsrf::ERROR_MESSAGE];
        return $response->withHeader('Location', $back)->withStatus(303);
    }

    /** Referer solo se dello stesso host (path+query), altrimenti `/` (niente open redirect). */
    private static function sameOriginReferer(Request $request): string
    {
        $referer = $request->getHeaderLine('Referer');
        if ($referer === '') {
            return '/';
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || empty($parts['host'])) {
            return '/';
        }
        $host = strtolower($request->getUri()->getHost());
        if ($host === '') {
            $host = strtolower(explode(':', $request->getHeaderLine('Host'))[0]);
        }
        if ($host === '' || strtolower((string)$parts['host']) !== $host) {
            return '/';
        }
        $path = (string)($parts['path'] ?? '/');
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return '/';
        }
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
