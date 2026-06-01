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

$pidFile  = '/tmp/cron-mapping.pid';
$stopFile = '/tmp/cron-stop-mapping';

// Single-instance guard
if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $log("Istanza già attiva (PID $existingPid). Uscita.");
        exit(0);
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

$log("Demone mapping L1 (fornitore) avviato. Approccio: bulk UPDATE per prefisso IUV.");

$lastDiscoveryTime = 0;

while (true) {
    $checkStop();
    $now = time();

    // 1. Pattern Discovery ogni 60 secondi
    if ($now - $lastDiscoveryTime >= 60) {
        try {
            $discovered = $repo->discoverPatterns((string)$idDominio);
            $pending    = $repo->countTotalPendingMapping((string)$idDominio);
            $log("Discovery: {$discovered} prefissi IUV aggiornati. Coda PENDING: {$pending}.");
            $lastDiscoveryTime = $now;
        } catch (\Throwable $e) {
            $log("ERRORE Discovery: " . $e->getMessage());
        }

        try {
            $repo->refreshPatternDiagnostics((string)$idDominio);
            $log('Diagnostics cache pattern aggiornata.');
        } catch (\Throwable $e) {
            $log("ERRORE refresh diagnostics: " . $e->getMessage());
        }
    }

    $checkStop();

    // 2. Carica regole attive (già ordinate per CHAR_LENGTH DESC → longest-prefix-first)
    $rules = [];
    try {
        $rules = $repo->getRules((string)$idDominio);
    } catch (\Throwable $e) {
        $log("ERRORE caricamento regole: " . $e->getMessage());
        sleep(10);
        continue;
    }

    $activeRules = array_filter($rules, fn(array $r): bool =>
        (!empty($r['fornitore']) && ((bool)($r['is_custom'] ?? false) || (int)($r['transazioni_count'] ?? 0) >= 5)) ||
        (!empty($r['accorpato_a']) && !empty($r['fornitore']))
    );

    $checkStop();

    // 3. Bulk UPDATE per ogni pattern attivo (longest first = longest-prefix-match garantito)
    $totalAssigned = 0;
    foreach ($activeRules as $rule) {
        $checkStop();
        try {
            $n = $repo->bulkAssignL1((string)$idDominio, (string)$rule['pattern_iuv'], (string)$rule['fornitore']);
            if ($n > 0) {
                $log("'{$rule['pattern_iuv']}' → '{$rule['fornitore']}': {$n} righe assegnate.");
                $totalAssigned += $n;
            }
        } catch (\Throwable $e) {
            $log("ERRORE pattern '{$rule['pattern_iuv']}': " . $e->getMessage());
        }
    }

    // 4. Righe PENDING rimaste senza pattern attivo → NO_MATCH
    try {
        $noMatch = $repo->bulkSetL1NoMatch((string)$idDominio);
        if ($noMatch > 0) {
            $log("{$noMatch} righe senza pattern corrispondente → NO_MATCH.");
        }
    } catch (\Throwable $e) {
        $log("ERRORE bulk NO_MATCH: " . $e->getMessage());
    }

    if ($totalAssigned === 0) {
        $log('Nessuna nuova pendenza. Pausa 15s...');
        for ($s = 0; $s < 15; $s++) {
            $checkStop();
            sleep(1);
        }
    } else {
        $log("Ciclo completato. Totale L1 assegnate: {$totalAssigned}.");
        sleep(1);
    }
}
