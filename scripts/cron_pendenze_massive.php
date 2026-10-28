<?php
declare(strict_types=1);

/**
 * Daemon pendenze massive.
 *
 * Loop infinito: processa batch di 50 righe (prima CANCEL_PENDING, poi PENDING),
 * attende 15 minuti quando la coda è vuota. Si ferma su segnale di stop (file /tmp/cron-stop-pendenze-massive)
 * o se un'altra istanza è già attiva (PID file /tmp/cron-pendenze-massive.pid).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\MassivePendenzeRepository;
use App\Controllers\PendenzeController;
use App\Services\Jobs\MassivePendenzeProcessor;
use App\Services\Jobs\ScriptJobContext;
use Dotenv\Dotenv;
use Slim\Views\Twig;

if (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}
if (!getenv('DB_HOST')) {
    putenv('DB_HOST=db');
}

set_time_limit(0);

$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    flush();
};

\App\Monitoring\SentryReporter::init('cron-pendenze-massive');
set_exception_handler(static function (\Throwable $e) use ($log): void {
    \Sentry\captureException($e);
    $log('ERRORE FATALE: ' . $e->getMessage());
    exit(1);
});

$pidFile  = '/tmp/cron-pendenze-massive.pid';
$stopFile = '/tmp/cron-stop-pendenze-massive';

// Wait for DB to be ready (max 60s — MariaDB may still be starting)
{
    $dbReady = false;
    for ($i = 0; $i < 60; $i++) {
        try {
            \App\Database\Connection::getPDO()->query('SELECT 1');
            $dbReady = true;
            break;
        } catch (\Throwable $_) {
            sleep(1);
        }
    }
    if (!$dbReady) {
        $log('DB non raggiungibile dopo 60s. Uscita.');
        exit(1);
    }
}

// Single-instance guard via PID file
if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_pendenze_massive.php') !== false) {
            $log("Istanza già attiva (PID $existingPid). Uscita.");
            exit(0);
        }
    }
}
file_put_contents($pidFile, (string)getmypid());

// Clear any leftover stop signal
@unlink($stopFile);

register_shutdown_function(static function () use ($pidFile): void {
    @unlink($pidFile);
});

// Handle SIGTERM gracefully
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(15, static function () use ($pidFile, $log): void { // 15 = SIGTERM
        $log('SIGTERM ricevuto. Uscita.');
        @unlink($pidFile);
        exit(0);
    });
}

$log('Daemon avviato (PID=' . getmypid() . ').');

$repo       = new MassivePendenzeRepository();
// PendenzeController richiede un Twig nel costruttore: qui non renderizza nulla (solo chiamate GovPay).
$twigMock   = Twig::create(dirname(__DIR__) . '/backoffice/templates');
$controller = new PendenzeController($twigMock, null);

// Selezione (CANCEL_PENDING prima, poi PENDING; batch 50), creazione/annullamento su GovPay e
// esito sulla riga: vedi MassivePendenzeProcessor.
$processor = new MassivePendenzeProcessor($controller, $repo);
$ctx       = new ScriptJobContext($stopFile);

while (true) {
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $log('Segnale di stop ricevuto. Uscita pulita.');
        exit(0);
    }

    // Le eccezioni del ramo creazione non sono catturate (come prima): arrivano al gestore globale.
    $result = $processor->runOnce($ctx);

    if (!$result->hadWork) {
        // Coda vuota (il processor ha gia' loggato "Attendo 900s..."): pausa a fette da 10s.
        for ($s = 0; $s < MassivePendenzeProcessor::IDLE_SECONDS; $s += 10) {
            if (file_exists($stopFile)) {
                break;
            }
            sleep(10);
        }
    }
}
