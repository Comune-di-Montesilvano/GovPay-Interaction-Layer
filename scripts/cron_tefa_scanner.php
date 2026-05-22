<?php
declare(strict_types=1);

/**
 * Cron TEFA scanner — loop daemon.
 *
 * Uso: php cron_tefa_scanner.php
 *
 * Ogni iterazione accoda IUR dalla cache condivisa flussi_rendicontazioni,
 * elabora tutti i PENDING con Biz Events e poi attende 15 min solo se la coda e' vuota.
 * Si ferma ricevendo il segnale /tmp/cron-stop-tefa.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\FlussiRendicontazioniRepository;
use App\Database\TefaRepository;
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

$pidFile  = '/tmp/cron-tefa-scanner.pid';
$stopFile = '/tmp/cron-stop-tefa';

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

// Wait DB before reading feature flags/settings to avoid false defaults at container boot.
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

// Handle SIGTERM gracefully (sent by stop endpoint for immediate stop)
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(15, static function () use ($pidFile, $log): void { // 15 = SIGTERM
        $log('SIGTERM ricevuto. Uscita.');
        @unlink($pidFile);
        exit(0);
    });
}

$repo       = new TefaRepository();
$flussiRepo = new FlussiRendicontazioniRepository();
$service    = new TefaScannerService($repo, $flussiRepo);

$initialBacklog = $flussiRepo->countUnprocessedForTefa((string)$idDominio);
$log('Loop avviato. Fonte queue: flussi_rendicontazioni (backlog iniziale non processato=' . $initialBacklog . ').');

while (true) {
    $checkStop();

    try {
        $backlogBefore = $flussiRepo->countUnprocessedForTefa((string)$idDominio);
        $queueResult = $service->queueFromCache($idDominio);
        $log(sprintf(
            'queueFromCache: source=flussi_rendicontazioni backlog_before=%d from_cache=%d queued=%d sample_iur=%s sample_flusso=%s',
            $backlogBefore,
            $queueResult['from_cache'],
            $queueResult['queued'],
            $queueResult['sample_iur'] !== '' ? $queueResult['sample_iur'] : '-',
            $queueResult['sample_flusso'] !== '' ? $queueResult['sample_flusso'] : '-'
        ));
    } catch (\Throwable $e) {
        $queueResult = ['queued' => 0, 'from_cache' => 0, 'sample_iur' => '', 'sample_flusso' => ''];
        $log('ERRORE queueFromCache: ' . $e->getMessage());
    }

    $checkStop();
    $counts = $repo->getCounts($idDominio);
    $log(sprintf(
        'Coda: PENDING=%d PROCESSED=%d ERROR=%d SKIPPED=%d',
        $counts['PENDING'], $counts['PROCESSED'], $counts['ERROR'], $counts['SKIPPED']
    ));

    // Enrich tutti i PENDING flusso per flusso
    while (true) {
        $checkStop();
        $idFlusso = $repo->getNextPendingFlusso($idDominio);
        if ($idFlusso === null) {
            $log('Tutti i flussi elaborati.');
            break;
        }

        $rows  = $repo->fetchPendingByFlusso($idFlusso, $idDominio);
        $total = count($rows);
        $log("Flusso {$idFlusso}: {$total} pendenze");

        foreach ($rows as $i => $row) {
            $checkStop();
            $n   = $i + 1;
            $iur = (string)$row['iur'];
            $log("  Pendenza {$n}/{$total} IUR={$iur}: interrogo Biz Events...");

            try {
                $result = $service->enrichOne($row, $idDominio);
            } catch (\Throwable $e) {
                $log("  IUR={$iur}: eccezione - " . $e->getMessage());
                continue;
            }

            if ($result['status'] === 'RATE_LIMITED') {
                for ($attempt = 1; $attempt < 5 && $result['status'] === 'RATE_LIMITED'; $attempt++) {
                    $log("  IUR={$iur}: rate limit 429 (tentativo {$attempt}/5) - pausa 10s...");
                    sleep(10);
                    try {
                        $result = $service->enrichOne($row, $idDominio);
                    } catch (\Throwable $e) {
                        $result = ['status' => 'ERROR', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $e->getMessage()];
                        break;
                    }
                }
                if ($result['status'] === 'RATE_LIMITED') {
                    $log("  IUR={$iur}: rate limit persistente - errore");
                    $repo->markError((int)$row['id'], 'Rate limit Biz Events (429) dopo 5 tentativi');
                    continue;
                }
            }

            switch ($result['status']) {
                case 'PROCESSED':
                    $cf  = $result['cf_comune'];
                    $imp = number_format($result['importo_tefa'], 2, ',', '.');
                    $log("  IUR={$iur}: TEFA OK  EUR {$imp} -> comune {$cf}");
                    break;
                case 'SKIPPED':
                    $log("  IUR={$iur}: skip - {$result['reason']}");
                    break;
                case 'ERROR':
                    $log("  IUR={$iur}: errore - {$result['reason']}");
                    break;
            }

            if ($i < $total - 1) {
                sleep(6);
            }
        }

        $log("Flusso {$idFlusso} completato. Pausa 10s...");
        sleep(10);
    }

    if ((int)$queueResult['queued'] === 0 && (int)$counts['PENDING'] === 0) {
        $log('Nessun elemento nuovo e nessun PENDING. Prossimo tra 15 minuti...');
        for ($s = 0; $s < 900; $s += 10) {
            $checkStop();
            sleep(10);
        }
        continue;
    }

    $log('Nuovi elementi processati. Avvio ciclo successivo immediato...');
}
