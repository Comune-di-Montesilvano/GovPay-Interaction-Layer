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

class AuthMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $publicPaths;

    public function __construct(array $publicPaths = [])
    {
        $this->publicPaths = $publicPaths;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();
        foreach ($this->publicPaths as $pub) {
            if ($this->matchPath($path, $pub)) {
                return $handler->handle($request);
            }
        }

        if (isset($_SESSION['user'])) {
            if (!empty($_SESSION['user']['is_disabled'])) {
                $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Account disabilitato: effettua di nuovo l\'accesso dopo la riattivazione'];
                unset($_SESSION['user']);
                unset($_SESSION['session_token']);
                $resp = new SlimResponse(302);
                return $resp->withHeader('Location', '/login');
            }

            // Single-session: kick session soppiantata da login più recente
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $sessionToken = $_SESSION['session_token'] ?? null;
            if ($userId > 0) {
                $repo = new \App\Auth\UserRepository();
                $dbToken = $repo->getSessionToken($userId);
                if ($dbToken === null || $sessionToken === null || !hash_equals($dbToken, $sessionToken)) {
                    unset($_SESSION['user']);
                    unset($_SESSION['session_token']);
                    $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Sessione terminata: l\'account è stato usato da un\'altra postazione'];
                    $resp = new SlimResponse(302);
                    return $resp->withHeader('Location', '/login');
                }
            }

            return $handler->handle($request);
        }

        // Redirect a /login
        $resp = new SlimResponse(302);
        return $resp->withHeader('Location', '/login');
    }

    private function matchPath(string $path, string $pattern): bool
    {
        // simple wildcard suffix support like /assets/* or /debug/*
        if (str_ends_with($pattern, '*')) {
            $prefix = rtrim(substr($pattern, 0, -1), '/');
            return str_starts_with($path, $prefix);
        }
        return rtrim($path, '/') === rtrim($pattern, '/');
    }
}
