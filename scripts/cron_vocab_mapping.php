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

$pidFile  = '/tmp/cron-vocab.pid';
$stopFile = '/tmp/cron-stop-vocab';

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

$log("Demone Vocab Mapping L2 (tipologia) avviato. Approccio: bulk UPDATE per keyword.");

while (true) {
    $checkStop();

    // 1. Carica vocabolario L2 (keyword per pattern + default cod_entrata)
    $vocabIndex = [];
    try {
        $vocabIndex = $repo->getVocabRules((string)$idDominio);
    } catch (\Throwable $e) {
        $log("ERRORE caricamento vocab: " . $e->getMessage());
        sleep(10);
        continue;
    }

    $checkStop();

    $totalAssigned = 0;

    // 2. Per ogni pattern: applica keyword in ordine di priorità, poi fallback default
    foreach ($vocabIndex as $patternIuv => $patternData) {
        $checkStop();
        $keywords   = $patternData['keywords'] ?? [];  // già ordinati per priorita DESC
        $defaultCod = $patternData['default_cod_entrata'] ?? null;

        // Keyword match: bulk UPDATE per ogni keyword (priorità già corretta — row già PROCESSED non viene ritoccata)
        foreach ($keywords as $kw) {
            $keyword    = trim((string)($kw['keyword'] ?? ''));
            $codEntrata = trim((string)($kw['cod_entrata'] ?? ''));
            if ($keyword === '' || $codEntrata === '') {
                continue;
            }
            try {
                $n = $repo->bulkAssignVocabKeyword((string)$idDominio, (string)$patternIuv, $keyword, $codEntrata);
                if ($n > 0) {
                    $log("'{$patternIuv}' keyword '{$keyword}' → '{$codEntrata}': {$n} righe.");
                    $totalAssigned += $n;
                }
            } catch (\Throwable $e) {
                $log("ERRORE vocab keyword '{$keyword}': " . $e->getMessage());
            }
        }

        // Fallback: righe del pattern senza keyword match → default cod_entrata del pattern L1
        if ($defaultCod !== null && $defaultCod !== '') {
            try {
                $n = $repo->bulkAssignVocabDefault((string)$idDominio, (string)$patternIuv, $defaultCod);
                if ($n > 0) {
                    $log("'{$patternIuv}' fallback → '{$defaultCod}': {$n} righe.");
                    $totalAssigned += $n;
                }
            } catch (\Throwable $e) {
                $log("ERRORE vocab default '{$patternIuv}': " . $e->getMessage());
            }
        }
    }

    // 3. Righe vocab PENDING rimaste senza alcun match → NO_MATCH
    try {
        $noMatch = $repo->bulkSetVocabNoMatch((string)$idDominio);
        if ($noMatch > 0) {
            $log("{$noMatch} righe L1-processate senza keyword match → vocab NO_MATCH.");
        }
    } catch (\Throwable $e) {
        $log("ERRORE bulk vocab NO_MATCH: " . $e->getMessage());
    }

    try {
        $repo->refreshPatternDiagnostics((string)$idDominio);
    } catch (\Throwable $e) {
        $log("ERRORE refresh diagnostics: " . $e->getMessage());
    }

    if ($totalAssigned === 0) {
        $log('Nessuna nuova pendenza vocab da classificare. Pausa 15s...');
        for ($s = 0; $s < 15; $s++) {
            $checkStop();
            sleep(1);
        }
    } else {
        $log("Ciclo vocab completato. Totale L2 classificate: {$totalAssigned}.");
        sleep(1);
    }
}
