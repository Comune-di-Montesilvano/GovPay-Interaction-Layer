<?php
declare(strict_types=1);

/**
 * Cron Mapping Pendenze Esterne — Livello 1 — loop daemon.
 *
 * Uso: php cron_mapping_pendenze.php
 *
 * Ogni iterazione: scoperta automatica prefissi IUV, poi assegna il fornitore
 * alle pendenze esterne (is_govpay=0) tramite longest-prefix-match.
 * Solo pattern con almeno 5 transazioni sono usati per il matching.
 * La classificazione cod_entrata è di competenza del demone L2 (cron_vocab_mapping.php).
 * Si ferma ricevendo il segnale /tmp/cron-stop-mapping.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\MappingPendenzeRepository;
use App\Services\Jobs\MappingL1Job;
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

\App\Monitoring\SentryReporter::init('cron-mapping-pendenze');
set_exception_handler(static function (\Throwable $e) use ($log): void {
    \Sentry\captureException($e);
    $log('ERRORE FATALE: ' . $e->getMessage());
    exit(1);
});

$pidFile  = '/tmp/cron-mapping.pid';
$stopFile = '/tmp/cron-stop-mapping';

// Single-instance guard
if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_mapping_pendenze.php') !== false) {
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

// Attendi DB prima di partire
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

$repo = new MappingPendenzeRepository();
$job  = new MappingL1Job($repo, (string)$idDominio);
$ctx  = new ScriptJobContext($stopFile);

$log("Demone mapping L1 (fornitore) avviato. Approccio: bulk UPDATE per prefisso IUV.");

$lastDiscoveryTime = 0;

while (true) {
    $checkStop();
    $now = time();

    // 1. Pattern Discovery ogni 60 secondi
    if ($now - $lastDiscoveryTime >= 60) {
        try {
            $discovered = $job->discover();
            $pending    = $repo->countTotalPendingMapping((string)$idDominio);
            $log("Discovery: {$discovered} prefissi IUV aggiornati. Coda PENDING: {$pending}.");
            $lastDiscoveryTime = $now;
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $log("ERRORE Discovery: " . $e->getMessage());
        }

        try {
            $repo->refreshPatternDiagnostics((string)$idDominio);
            $log('Diagnostics cache pattern aggiornata.');
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $log("ERRORE refresh diagnostics: " . $e->getMessage());
        }
    }

    $checkStop();

    // 2-4. Un ciclo di assegnazione: regole attive (bulk UPDATE per prefisso), poi PENDING rimaste → NO_MATCH
    try {
        $result = $job->runOnce($ctx);
    } catch (\Throwable $e) {
        \Sentry\captureException($e);
        $log("ERRORE caricamento regole: " . $e->getMessage());
        sleep(10);
        continue;
    }

    $checkStop();

    $totalAssigned = $result->ok;
    if ($totalAssigned === 0) {
        $log('Nessuna nuova pendenza. Pausa 15 minuti...');
        for ($s = 0; $s < 900; $s += 10) {
            $checkStop();
            sleep(10);
        }
    } else {
        $log("Ciclo completato. Totale L1 assegnate: {$totalAssigned}.");
        sleep(1);
    }
}
