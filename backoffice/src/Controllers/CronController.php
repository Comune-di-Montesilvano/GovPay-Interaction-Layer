<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CronController
{
    private const BASE_PATH = '/var/www/html';
    private const LOG_DIR   = '/var/www/cache';

    private const JOBS = [
        'tefa' => [
            'label'       => 'TEFA: Scanner loop',
            'description' => 'Loop continuo: accoda IUR da cache flussi ragioneria e processa PENDING con Biz Events. Si ferma con segnale di stop.',
            'script'      => 'scripts/cron_tefa_scanner.php',
            'args_tpl'    => '',
            'params'      => [],
            'icon'        => 'fa-building-columns',
            'daemon'      => true,
            'stop_file'   => '/tmp/cron-stop-tefa',
            'pid_file'    => '/tmp/cron-tefa-scanner.pid',
        ],
        'ragioneria' => [
            'label'       => 'Ragioneria: sincronizza flussi',
            'description' => 'Daemon: scarica flussi FDR da GovPay dalla data impostata e li salva in DB. Report ragioneria e TEFA leggono da qui.',
            'script'      => 'scripts/cron_ragioneria.php',
            'args_tpl'    => '',
            'params'      => [],
            'icon'        => 'fa-database',
            'daemon'      => true,
            'stop_file'   => '/tmp/cron-stop-ragioneria',
            'pid_file'    => '/tmp/cron-ragioneria.pid',
        ],
        'pendenze-massive' => [
            'label'       => 'Pendenze massive: elabora batch',
            'description' => 'Daemon continuo: processa pendenze massive in stato PENDING, attende 30s quando la coda è vuota.',
            'script'      => 'scripts/cron_pendenze_massive.php',
            'args_tpl'    => '',
            'params'      => [],
            'icon'        => 'fa-list-check',
            'daemon'      => true,
            'stop_file'   => '/tmp/cron-stop-pendenze-massive',
            'pid_file'    => '/tmp/cron-pendenze-massive.pid',
        ],
    ];

    public function __construct(private readonly Twig $twig) {}

    public static function getJobs(): array
    {
        return self::JOBS;
    }

    public static function isDaemonRunning(string $jobKey): bool
    {
        $job = self::JOBS[$jobKey] ?? null;
        if ($job === null || !($job['daemon'] ?? false)) {
            return false;
        }
        $pidFile = $job['pid_file'] ?? '';
        if ($pidFile === '' || !file_exists($pidFile)) {
            return false;
        }
        $pid = (int)file_get_contents($pidFile);
        return $pid > 0 && file_exists('/proc/' . $pid);
    }

    public function run(Request $request, Response $response, array $args): Response
    {
        $this->requireSuperadmin();

        $jobKey = (string)($args['job'] ?? '');
        if (!isset(self::JOBS[$jobKey])) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Job non trovato'], 404);
        }

        $job = self::JOBS[$jobKey];

        $body    = (array)($request->getParsedBody() ?? []);
        $argsStr = $job['args_tpl'] ?? '';
        foreach ($job['params'] ?? [] as $paramDef) {
            $name = $paramDef['name'];
            $val  = trim((string)($body[$name] ?? ''));
            if ($val !== '') {
                $argsStr = str_replace('{' . $name . '}', escapeshellarg($val), $argsStr);
            } else {
                $argsStr = trim(preg_replace('/--\w+=\{' . preg_quote($name, '/') . '\}/', '', $argsStr));
            }
        }

        $logFile = self::LOG_DIR . '/daemon-' . $jobKey . '.log';
        $script  = self::BASE_PATH . '/' . $job['script'];
        $cmd     = sprintf(
            'php %s %s < /dev/null >> %s 2>&1 &',
            escapeshellarg($script),
            $argsStr,
            escapeshellarg($logFile)
        );
        exec($cmd);
        file_put_contents(self::LOG_DIR . '/daemon-' . $jobKey . '.autostart', '1');

        return $this->jsonResponse($response, ['ok' => true, 'message' => 'Daemon avviato.']);
    }

    public function stop(Request $request, Response $response, array $args): Response
    {
        $this->requireSuperadmin();

        $jobKey = (string)($args['job'] ?? '');
        if (!isset(self::JOBS[$jobKey])) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Job non trovato'], 404);
        }

        $job      = self::JOBS[$jobKey];
        $stopFile = $job['stop_file'] ?? '';
        if ($stopFile === '') {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Stop non supportato per questo job'], 400);
        }

        // Graceful stop signal (checked between iterations)
        file_put_contents($stopFile, '1');

        // Immediate SIGTERM if process is alive (handles hangs)
        $pidFile = $job['pid_file'] ?? '';
        if ($pidFile !== '' && file_exists($pidFile)) {
            $pid = (int)file_get_contents($pidFile);
            if ($pid > 0 && function_exists('posix_kill') && file_exists('/proc/' . $pid)) {
                posix_kill($pid, 15); // 15 = SIGTERM (avoid relying on pcntl for the constant)
            }
        }

        @unlink(self::LOG_DIR . '/daemon-' . $jobKey . '.autostart');

        return $this->jsonResponse($response, ['ok' => true, 'message' => 'Segnale di stop inviato.']);
    }

    public function setScanDate(Request $request, Response $response): Response
    {
        $this->requireSuperadmin();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $body = (array)($request->getParsedBody() ?? []);
        $date = trim((string)($body['ragioneria_scan_da'] ?? ''));

        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            SettingsRepository::set('backoffice', 'ragioneria_scan_da', $date);
        }
        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Data inizio scansione salvata.'];

        return $response
            ->withHeader('Location', '/impostazioni?tab=cron')
            ->withStatus(302);
    }

    public function log(Request $request, Response $response, array $args): Response
    {
        $this->requireSuperadmin();

        $jobKey = (string)($args['job'] ?? '');
        if (!isset(self::JOBS[$jobKey])) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Job non trovato'], 404);
        }

        $logFile = self::LOG_DIR . '/daemon-' . $jobKey . '.log';
        $lines   = 300;

        if (!file_exists($logFile)) {
            return $this->jsonResponse($response, ['ok' => true, 'content' => '(nessun log ancora)']);
        }

        // Read last $lines lines efficiently
        $handle = fopen($logFile, 'r');
        if ($handle === false) {
            return $this->jsonResponse($response, ['ok' => true, 'content' => '(impossibile leggere il log)']);
        }

        $buffer = [];
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line !== false) {
                $buffer[] = rtrim($line);
                if (count($buffer) > $lines) {
                    array_shift($buffer);
                }
            }
        }
        fclose($handle);

        return $this->jsonResponse($response, ['ok' => true, 'content' => implode("\n", $buffer)]);
    }

    private function requireSuperadmin(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            header('Location: /login');
            exit;
        }
        if (($user['role'] ?? '') !== 'superadmin') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Accesso negato — richiesto superadmin']);
            exit;
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response = $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
