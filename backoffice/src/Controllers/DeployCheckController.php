<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Config;
use App\Database\Connection;
use App\Database\MigrationRunner;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/internal/deploy-check (Bearer MASTER_TOKEN, BearerTokenMiddleware): stato per il servizio
 * compose post-deploy-check. Sempre 200: DB giu' = `db: false`, non un 500. Nessun dato di
 * business, nessun messaggio d'errore del DB nella risposta (solo log).
 */
final class DeployCheckController
{
    public function status(Request $request, Response $response): Response
    {
        $info = Config::getVersionInfo();
        $db = false;
        $migrations = null;
        try {
            $pdo = Connection::getPDO();
            $pdo->query('SELECT 1')->fetchColumn();
            $db = true;
            $dir = MigrationRunner::migrationsDir();
            $runner = new MigrationRunner($pdo, $dir, null, static function (string $line): void {
            });
            $status = $runner->status();
            $total = count(glob($dir . '/*.sql') ?: []);
            $migrations = [
                'registered' => $total - count($status['pending']),
                'pending' => $status['pending'],
                'mismatched' => $status['mismatched'],
            ];
        } catch (\Throwable $e) {
            \App\Logger::getInstance()->warning('deploy-check: DB non verificabile', ['error' => $e->getMessage()]);
        }

        $response->getBody()->write((string)json_encode([
            'success' => true,
            'version' => (string)($info['version'] ?? 'development'),
            'commit' => (string)($info['commit'] ?? 'unknown'),
            'db' => $db,
            'migrations' => $migrations,
        ], JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
