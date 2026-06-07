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

class BearerTokenMiddleware implements MiddlewareInterface
{
    private bool $allowSession;

    public function __construct(bool $allowSession = false)
    {
        $this->allowSession = $allowSession;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        if ($this->allowSession) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            if (isset($_SESSION['user'])) {
                return $handler->handle($request);
            }
        }

        $masterToken = $_ENV['MASTER_TOKEN'] ?? getenv('MASTER_TOKEN') ?: '';
        if ($masterToken === '') {
            $response = new SlimResponse(503);
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'MASTER_TOKEN non configurato',
                'error_status' => 503
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $authHeader = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authHeader, 'Bearer ') || !hash_equals($masterToken, substr($authHeader, 7))) {
            $response = new SlimResponse(401);
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Non autorizzato',
                'error_status' => 401
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
