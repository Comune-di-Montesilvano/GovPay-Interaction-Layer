<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Services\Jobs\RagioneriaSyncJob;
use App\Services\Jobs\ScriptJobContext;
use Dotenv\Dotenv;

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

\App\Monitoring\SentryReporter::init('cron-ragioneria');
set_exception_handler(static function (\Throwable $e) use ($log): void {
    \Sentry\captureException($e);
    $log('ERRORE FATALE: ' . $e->getMessage());
    exit(1);
});

$pidFile    = '/tmp/cron-ragioneria.pid';
$stopFile   = '/tmp/cron-stop-ragioneria';
$rescanFile = '/tmp/cron-rescan-ragioneria';

if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_ragioneria.php') !== false) {
            $log("Istanza già attiva (PID $existingPid). Uscita.");
            exit(0);
        }
    }
}

file_put_contents($pidFile, (string)getmypid());
@unlink($stopFile);
register_shutdown_function(static function () use ($pidFile): void {
    @unlink($pidFile);
});

$checkStop = static function () use ($stopFile, $log): void {
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $log('Segnale di stop ricevuto. Uscita.');
        exit(0);
    }
};

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(15, static function () use ($pidFile, $log): void {
        $log('SIGTERM ricevuto. Uscita.');
        @unlink($pidFile);
        exit(0);
    });
}

$dbReady = false;
for ($i = 0; $i < 60; $i++) {
    try {
        Connection::getPDO()->query('SELECT 1');
        $dbReady = true;
        break;
    } catch (Throwable $_) {
        sleep(1);
    }
}
if (!$dbReady) {
    $log('DB non raggiungibile dopo 60s. Uscita.');
    exit(1);
}

$idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
$idA2A = (string)SettingsRepository::get('entity', 'id_a2a', '');
$backofficeUrl = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');

$configError = RagioneriaSyncJob::configError($idDominio, $backofficeUrl);
if ($configError !== null) {
    $log($configError);
    exit(1);
}

$job = new RagioneriaSyncJob($idDominio, $idA2A, $backofficeUrl);
$ctx = new ScriptJobContext($stopFile);

$log('Daemon ragioneria avviato.');

while (true) {
    $checkStop();

    // Segnale di rescan: il job azzera la finestra scorrevole e riparte dalla data di configurazione.
    if (file_exists($rescanFile)) {
        @unlink($rescanFile);
        $job->requestRescan();
    }

    $result = $job->runOnce($ctx);

    $checkStop();
    if ($result->hadWork) {
        continue;
    }

    for ($s = 0; $s < 1800; $s += 10) {
        $checkStop();
        sleep(10);
    }
}
