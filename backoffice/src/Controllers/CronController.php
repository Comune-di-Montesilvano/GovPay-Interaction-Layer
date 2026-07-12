<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Database\TefaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CronController
{
    private const BASE_PATH = '/var/www/html';
    private const LOG_DIR   = '/var/www/cache';

    private const JOBS = [
        'biz' => [
            'label'       => 'Biz: Scanner ricevute',
            'description' => 'Loop continuo: accoda pendenze non-GovPay da flussi ragioneria, chiama Biz Events e salva descrizione/soggetto pagante/trasferimenti in biz_ricevute. Funziona per qualsiasi ente con Biz Events configurato.',
            'script'      => 'scripts/cron_biz_scanner.php',
            'args_tpl'    => '',
            'params'      => [],
            'icon'        => 'fa-receipt',
            'daemon'      => true,
            'stop_file'   => '/tmp/cron-stop-biz',
            'pid_file'    => '/tmp/cron-biz-scanner.pid',
        ],
        'tefa' => [
            'label'       => 'TEFA: Classificatore loop',
            'description' => 'Loop continuo: accoda IUR da biz_ricevute (PROCESSED) e li classifica come TEFA/non-TEFA. Richiede il demone Biz attivo. Si ferma con segnale di stop.',
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
        'mapping' => [
            'label'       => 'Ragioneria: Mapping L1 (fornitore)',
            'description' => 'Loop continuo: scopre prefissi IUV e assegna il fornitore alle pendenze esterne (Livello 1). Solo pattern con ≥5 transazioni sono usati. Deve precedere il demone Vocab.',
            'script'      => 'scripts/cron_mapping_pendenze.php',
            'args_tpl'    => '',
            'params'      => [],
            'icon'        => 'fa-route',
            'daemon'      => true,
            'stop_file'   => '/tmp/cron-stop-mapping',
            'pid_file'    => '/tmp/cron-mapping.pid',
        ],
        'vocab' => [
            'label'       => 'Ragioneria: Vocab L2 (tipologia)',
            'description' => 'Loop continuo: classifica le pendenze già identificate da L1 tramite vocabolario di keyword per assegnare la tipologia contabile (cod_entrata). Richiede il demone Mapping L1 attivo.',
            'script'      => 'scripts/cron_vocab_mapping.php',
            'args_tpl'    => '',
            'params'      => [],
            'icon'        => 'fa-book-open',
            'daemon'      => true,
            'stop_file'   => '/tmp/cron-stop-vocab',
            'pid_file'    => '/tmp/cron-vocab.pid',
        ],
        'govpay-debitore' => [
            'label'       => 'GovPay: Scanner debitore',
            'description' => 'Loop continuo: recupera dati debitore (CF/nominativo, causale) da GovPay Backoffice API per le pendenze interne (is_govpay=1) e li salva in biz_ricevute. Arricchisce il CSV ragioneria con i dati debitore per le pendenze GovPay.',
            'script'      => 'scripts/cron_govpay_debitore_scanner.php',
            'args_tpl'    => '',
            'params'      => [],
            'icon'        => 'fa-user-check',
            'daemon'      => true,
            'stop_file'   => '/tmp/cron-stop-govpay-debitore',
            'pid_file'    => '/tmp/cron-govpay-debitore.pid',
        ],
        'rendicontazione-govpay' => [
            'label'       => 'Rendicontazione: motore GovPay',
            'description' => 'Loop continuo: instrada le pendenze GovPay dei flussi non rendicontati (smarcatura automatica, manuale operatore, o handoff Geri/Dilazione via bridge legacy) e invia i digest mail.',
            'script'      => 'scripts/cron_rendicontazione_govpay.php',
            'args_tpl'    => '',
            'params'      => [],
            'icon'        => 'fa-scale-balanced',
            'daemon'      => true,
            'stop_file'   => '/tmp/cron-stop-rendicontazione-govpay',
            'pid_file'    => '/tmp/cron-rendicontazione-govpay.pid',
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

    public function index(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->exposeCurrentUser();

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $jobs = self::JOBS;

        $daemonStatus = [];
        foreach ($jobs as $key => $job) {
            if ($job['daemon'] ?? false) {
                $daemonStatus[$key] = self::isDaemonRunning($key);
            }
        }

        $ragioneriaScanDa = SettingsRepository::get(
            'backoffice',
            'ragioneria_scan_da',
            date('Y-01-01', strtotime('-1 year'))
        );

        return $this->twig->render($response, 'funzioni-avanzate/cron.html.twig', [
            'jobs' => $jobs,
            'daemon_status' => $daemonStatus,
            'ragioneria_scan_da' => $ragioneriaScanDa,
            'id_dominio' => $idDominio,
        ]);
    }

    public function run(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrSuperadmin();

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
        $this->requireAdminOrSuperadmin();

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
                posix_kill($pid, 15); // 15 = SIGTERM (graceful stop)
            }
        }

        @unlink(self::LOG_DIR . '/daemon-' . $jobKey . '.autostart');

        return $this->jsonResponse($response, ['ok' => true, 'message' => 'Segnale di stop inviato.']);
    }

    public function setScanDate(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();

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
            ->withHeader('Location', '/funzioni-avanzate/cron')
            ->withStatus(302);
    }

    public function resetDateRange(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $body = (array)($request->getParsedBody() ?? []);
        $dataDa = trim((string)($body['reset_data_da'] ?? ''));
        $dataA = trim((string)($body['reset_data_a'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDa) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataA)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Date non valide. Usa formato YYYY-MM-DD.'];
            return $response->withHeader('Location', '/funzioni-avanzate/cron')->withStatus(302);
        }

        if ($dataDa > $dataA) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Intervallo non valido: la data Da deve essere <= data A.'];
            return $response->withHeader('Location', '/funzioni-avanzate/cron')->withStatus(302);
        }

        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'id_dominio non configurato.'];
            return $response->withHeader('Location', '/funzioni-avanzate/cron')->withStatus(302);
        }

        $flussiRepo = new FlussiRendicontazioniRepository();
        $tefaRepo   = new TefaRepository();
        $bizRepo    = new BizRepository();

        $deletedBiz        = $bizRepo->deleteByDateRange($idDominio, $dataDa, $dataA);
        $deletedTefa       = $tefaRepo->deleteByDateRange($idDominio, $dataDa, $dataA);
        $deletedRagioneria = $flussiRepo->deleteByDateRange($idDominio, $dataDa, $dataA);

        $_SESSION['flash'][] = [
            'type' => 'success',
            'text' => sprintf(
                'Reset completato (%s -> %s). Cancellate %d righe Biz, %d righe TEFA e %d righe Ragioneria.',
                $dataDa,
                $dataA,
                $deletedBiz,
                $deletedTefa,
                $deletedRagioneria
            ),
        ];

        return $response
            ->withHeader('Location', '/funzioni-avanzate/cron')
            ->withStatus(302);
    }

    public function forceRescan(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();

        if (!self::isDaemonRunning('ragioneria')) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Daemon ragioneria non attivo.'], 400);
        }

        file_put_contents('/tmp/cron-rescan-ragioneria', '1');

        return $this->jsonResponse($response, ['ok' => true, 'message' => 'Segnale rescan inviato: il prossimo ciclo riscansionerà tutti i flussi dalla data configurata.']);
    }

    public function log(Request $request, Response $response, array $args): Response
    {
        $this->requireAuth();

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

    private function requireAuth(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
    }

    private function requireAdminOrSuperadmin(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            header('Location: /login');
            exit;
        }
        if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Accesso negato — richiesto ruolo amministrativo']);
            exit;
        }
    }

    private function exposeCurrentUser(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (isset($_SESSION['user'])) {
            $this->twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response = $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
