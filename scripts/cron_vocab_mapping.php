<?php
declare(strict_types=1);

/**
 * Cron Vocab Mapping — Livello 2 — loop daemon.
 *
 * Uso: php cron_vocab_mapping.php
 *
 * Seconda fase del mapping pendenze esterne. Prende in ingresso le sole pendenze
 * già classificate da L1 (mapping_stato='PROCESSED', fornitore set) e ne determina
 * la tipologia contabile (cod_entrata) tramite il vocabolario di keyword configurato
 * per ogni pattern IUV (mapping_pendenze_vocab).
 *
 * Algoritmo per ogni pendenza:
 *   1. Trova il pattern L1 corrispondente (longest prefix match sull'IUV)
 *   2. Scansiona le keyword vocab di quel pattern per trovare match nella descrizione
 *   3. Se match → cod_entrata dalla keyword; se no match → fallback al default L1
 *   4. Se nessun fallback → vocab_stato='NO_MATCH'
 *
 * Si ferma ricevendo il segnale /tmp/cron-stop-vocab.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\MappingPendenzeRepository;
use App\Services\Jobs\MappingL2Job;
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

\App\Monitoring\SentryReporter::init('cron-vocab-mapping');
set_exception_handler(static function (\Throwable $e) use ($log): void {
    \Sentry\captureException($e);
    $log('ERRORE FATALE: ' . $e->getMessage());
    exit(1);
});

$pidFile  = '/tmp/cron-vocab.pid';
$stopFile = '/tmp/cron-stop-vocab';

// Single-instance guard
if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_vocab_mapping.php') !== false) {
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

// Attendi DB
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
$job  = new MappingL2Job($repo, (string)$idDominio);
$ctx  = new ScriptJobContext($stopFile);

$log("Demone Vocab Mapping L2 (tipologia) avviato. Approccio: bulk UPDATE per keyword.");

while (true) {
    $checkStop();

    // Un ciclo L2: keyword per priorità + fallback default, poi vocab NO_MATCH e refresh diagnostics
    try {
        $result = $job->runOnce($ctx);
    } catch (\Throwable $e) {
        \Sentry\captureException($e);
        $log("ERRORE caricamento vocab: " . $e->getMessage());
        sleep(10);
        continue;
    }

    $checkStop();

    $totalAssigned = $result->ok;

    if ($totalAssigned === 0) {
        $log('Nessuna nuova pendenza vocab da classificare. Pausa 15 minuti...');
        for ($s = 0; $s < 900; $s += 10) {
            $checkStop();
            sleep(10);
        }
    } else {
        $log("Ciclo vocab completato. Totale L2 classificate: {$totalAssigned}.");
        sleep(1);
    }
}
