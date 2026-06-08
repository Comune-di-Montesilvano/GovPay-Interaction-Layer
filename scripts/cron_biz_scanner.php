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

$pidFile  = '/tmp/cron-biz-scanner.pid';
$stopFile = '/tmp/cron-stop-biz';

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

while (true) {
    $checkStop();

    $scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
    $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;

    try {
        $backlogBefore = $flussiRepo->countUnprocessedForBiz((string)$idDominio, $minDate);
        $queueResult   = $service->queueFromCache($idDominio);
        $log(sprintf(
            'queueFromCache: min_date=%s backlog_before=%d from_cache=%d queued=%d sample_iur=%s sample_flusso=%s',
            $queueResult['min_date'] !== '' ? $queueResult['min_date'] : '-',
            $backlogBefore,
            $queueResult['from_cache'],
            $queueResult['queued'],
            $queueResult['sample_iur'] !== '' ? $queueResult['sample_iur'] : '-',
            $queueResult['sample_flusso'] !== '' ? $queueResult['sample_flusso'] : '-'
        ));
    } catch (\Throwable $e) {
        $queueResult = ['queued' => 0, 'from_cache' => 0, 'sample_iur' => '', 'sample_flusso' => '', 'min_date' => ''];
        $log('ERRORE queueFromCache: ' . $e->getMessage());
    }

    $checkStop();
    $counts         = $repo->getCounts($idDominio);
    $pendingInScope = $repo->countPendingForProcessing((string)$idDominio, $minDate);
    $log(sprintf(
        'Coda: PENDING=%d (in-scope=%d, min_date=%s) PROCESSED=%d ERROR=%d SKIPPED=%d',
        $counts['PENDING'],
        $pendingInScope,
        $minDate ?? '-',
        $counts['PROCESSED'],
        $counts['ERROR'],
        $counts['SKIPPED']
    ));

    // Enrich tutti i PENDING flusso per flusso
    while (true) {
        $checkStop();
        $idFlusso = $repo->getNextPendingFlusso($idDominio, $minDate);
        if ($idFlusso === null) {
            $log('Tutti i flussi in-scope elaborati.');
            break;
        }

        $rows  = $repo->fetchPendingByFlusso($idFlusso, $idDominio, $minDate);
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
                for ($attempt = 1; $attempt < 6 && $result['status'] === 'RATE_LIMITED'; $attempt++) {
                    $log("  IUR={$iur}: rate limit 429 (tentativo {$attempt}/6) - pausa " . ($attempt+1) . "s...");
                    sleep($attempt+1); // 2s, 3s, 4s, 5s
                    try {
                        $result = $service->enrichOne($row, $idDominio);
                    } catch (\Throwable $e) {
                        $result = ['status' => 'ERROR', 'reason' => $e->getMessage()];
                        break;
                    }
                }
                if ($result['status'] === 'RATE_LIMITED') {
                    $log("  IUR={$iur}: rate limit persistente - errore");
                    $repo->markError((int)$row['id'], 'Rate limit Biz Events (429) dopo 5 tentativi');
                    sleep(10);
                    continue;
                }
            }

            switch ($result['status']) {
                case 'PROCESSED':
                    $log("  IUR={$iur}: OK — ricevuta salvata");
                    break;
                case 'SKIPPED':
                    $log("  IUR={$iur}: skip - {$result['reason']}");
                    break;
                case 'ERROR':
                    $log("  IUR={$iur}: errore - {$result['reason']}");
                    break;
            }

            sleep(5);
        }

        $log("Flusso {$idFlusso} completato. Proseguo al flusso successivo...");
    }

    if ((int)$queueResult['queued'] === 0 && (int)$pendingInScope === 0) {
        $resetErrors = $repo->resetErrors((string)$idDominio);
        if ($resetErrors > 0) {
            $log("Reset ERROR Biz -> PENDING: {$resetErrors} record. Riavvio ciclo immediato...");
            continue;
        }

        if ((int)$counts['PENDING'] > 0) {
            $log('Nessun elemento nuovo e nessun PENDING in-scope. Restano PENDING fuori filtro data. Prossimo tra 15 minuti...');
        } else {
            $log('Nessun elemento nuovo e nessun PENDING. Prossimo tra 15 minuti...');
        }
        for ($s = 0; $s < 900; $s += 10) {
            $checkStop();
            sleep(10);
        }
        continue;
    }

    $log('Nuovi elementi processati. Avvio ciclo successivo immediato...');
}
