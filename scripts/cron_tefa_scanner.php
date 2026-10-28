<?php
declare(strict_types=1);

/**
 * Cron TEFA scanner — loop daemon.
 *
 * Uso: php cron_tefa_scanner.php
 *
 * Ogni iterazione accoda IUR da biz_ricevute (PROCESSED, non ancora in tefa_ricevute)
 * e li classifica come TEFA/non-TEFA. Non chiama Biz Events direttamente:
 * i dati vengono dal demone Biz (cron_biz_scanner.php).
 * Si ferma ricevendo il segnale /tmp/cron-stop-tefa.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\Connection;
use App\Database\TefaRepository;
use App\Services\Jobs\ScriptJobContext;
use App\Services\Jobs\TefaScanJob;
use App\Services\TefaScannerService;
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

\App\Monitoring\SentryReporter::init('cron-tefa-scanner');
set_exception_handler(static function (\Throwable $e) use ($log): void {
    \Sentry\captureException($e);
    $log('ERRORE FATALE: ' . $e->getMessage());
    exit(1);
});

$pidFile  = '/tmp/cron-tefa-scanner.pid';
$stopFile = '/tmp/cron-stop-tefa';

// Single-instance guard
if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_tefa_scanner.php') !== false) {
            $log("Istanza già attiva (PID $existingPid). Uscita.");
            exit(0);
        }
    }
}
file_put_contents($pidFile, (string)getmypid());
@unlink($stopFile);
register_shutdown_function(static function () use ($pidFile): void { @unlink($pidFile); });

$checkStop = static function () use ($stopFile, $log): void {
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $log('Segnale di stop ricevuto. Uscita.');
        exit(0);
    }
};

// Attendi DB prima di leggere settings per evitare false default al boot del container.
$dbReady = false;
for ($i = 0; $i < 60; $i++) {
    try {
        Connection::getPDO()->query('SELECT 1');
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

if (SettingsRepository::get('backoffice', 'tefa_enabled', 'false') !== 'true') {
    $log('TEFA non abilitato. Uscita.');
    exit(0);
}

$idDominio = SettingsRepository::get('entity', 'id_dominio', '');
if ($idDominio === '') {
    $log('ERRORE: id_dominio non configurato.');
    exit(1);
}

// Handle SIGTERM gracefully
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(15, static function () use ($pidFile, $log): void {
        $log('SIGTERM ricevuto. Uscita.');
        @unlink($pidFile);
        exit(0);
    });
}

$repo    = new TefaRepository();
$bizRepo = new BizRepository();
$service = new TefaScannerService($repo, $bizRepo);

// Allineamento retroattivo pendenze TEFA in flussi_rendicontazioni all'avvio del demone
try {
    $fixed = $repo->fixProcessedTefaMapping((string)$idDominio);
    if ($fixed > 0) {
        $log("Allineamento retroattivo TEFA completato: aggiornate {$fixed} pendenze in flussi_rendicontazioni.");
    }
} catch (\Throwable $e) {
    \Sentry\captureException($e);
    $log("ERRORE allineamento retroattivo TEFA: " . $e->getMessage());
}

$scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
$minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;
$initialBacklog = $bizRepo->countProcessedForTefa((string)$idDominio, $minDate);
$log('Loop avviato. Fonte queue: biz_ricevute (PROCESSED, data_pagamento >= ' . ($minDate ?? '-') . ', backlog iniziale non classificato=' . $initialBacklog . ').');

$job = new TefaScanJob($repo, $bizRepo, $service, (string)$idDominio);
$ctx = new ScriptJobContext($stopFile);

while (true) {
    $checkStop();

    $result = $job->runOnce($ctx);

    $checkStop();
    if (!$result->hadWork) {
        // Coda vuota: pausa 15 minuti (il job ha gia' loggato il motivo).
        for ($s = 0; $s < 900; $s += 10) {
            $checkStop();
            sleep(10);
        }
    }
}
