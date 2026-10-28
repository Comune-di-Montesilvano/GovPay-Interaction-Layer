<?php
declare(strict_types=1);

/**
 * Cron Biz scanner — loop daemon.
 *
 * Uso: php cron_biz_scanner.php
 *
 * Ogni iterazione accoda IUR non-GovPay dalla cache flussi_rendicontazioni,
 * chiama Biz Events per ogni PENDING e salva tutti i dati ricevuta in biz_ricevute.
 * Funziona per qualsiasi ente con Biz Events configurato (non solo province).
 * Si ferma ricevendo il segnale /tmp/cron-stop-biz.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\Connection;
use App\Database\FlussiRendicontazioniRepository;
use App\Services\BizScannerService;
use App\Services\Jobs\BizScanJob;
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

\App\Monitoring\SentryReporter::init('cron-biz-scanner');
set_exception_handler(static function (\Throwable $e) use ($log): void {
    \Sentry\captureException($e);
    $log('ERRORE FATALE: ' . $e->getMessage());
    exit(1);
});

$pidFile  = '/tmp/cron-biz-scanner.pid';
$stopFile = '/tmp/cron-stop-biz';

// Single-instance guard
if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_biz_scanner.php') !== false) {
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

$bizHost   = (string)SettingsRepository::get('pagopa', 'biz_events_host', '');
$bizApiKey = (string)SettingsRepository::get('pagopa', 'biz_events_api_key', '');
if ($bizHost === '' || $bizApiKey === '') {
    $log('Biz Events non configurato (biz_events_host o biz_events_api_key mancanti). Uscita.');
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

$repo       = new BizRepository();
$flussiRepo = new FlussiRendicontazioniRepository();
$service    = new BizScannerService($repo, $flussiRepo);

$scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
$minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;
$initialBacklog = $flussiRepo->countUnprocessedForBiz((string)$idDominio, $minDate);
$log('Loop avviato. Fonte queue: flussi_rendicontazioni (is_govpay=0, data_pagamento >= ' . ($minDate ?? '-') . ', backlog iniziale=' . $initialBacklog . ').');

$job = new BizScanJob($repo, $flussiRepo, $service, (string)$idDominio);
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
