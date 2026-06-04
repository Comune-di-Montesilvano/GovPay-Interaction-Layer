<?php
declare(strict_types=1);

/**
 * Cron GovPay Debitore scanner — loop daemon.
 *
 * Uso: php cron_govpay_debitore_scanner.php
 *
 * Ogni iterazione recupera IUR GovPay (is_govpay=1) da flussi_rendicontazioni
 * senza entry in biz_ricevute, chiama GovPay Backoffice API per ogni pendenza
 * e salva i dati debitore (cf/nominativo, causale) in biz_ricevute come PROCESSED.
 * Si ferma ricevendo il segnale /tmp/cron-stop-govpay-debitore.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\Connection;
use App\Database\FlussiRendicontazioniRepository;
use App\Services\GovPayDebitoreService;
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

$pidFile  = '/tmp/cron-govpay-debitore.pid';
$stopFile = '/tmp/cron-stop-govpay-debitore';

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

// Attendi DB prima di leggere settings
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

$backofficeUrl = (string)SettingsRepository::get('govpay', 'backoffice_url', '');
$idA2A         = (string)SettingsRepository::get('entity', 'id_a2a', '');
if ($backofficeUrl === '' || $idA2A === '') {
    $log('GovPay Backoffice non configurato (backoffice_url o id_a2a mancanti). Uscita.');
    exit(0);
}

$idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
if ($idDominio === '') {
    $log('ERRORE: id_dominio non configurato.');
    exit(1);
}

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(15, static function () use ($pidFile, $log): void {
        $log('SIGTERM ricevuto. Uscita.');
        @unlink($pidFile);
        exit(0);
    });
}

$bizRepo    = new BizRepository();
$flussiRepo = new FlussiRendicontazioniRepository();
$service    = new GovPayDebitoreService($bizRepo, $flussiRepo);

$scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
$minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;
$initialBacklog = $service->countPending($idDominio, $minDate);
$log('Loop avviato. Fonte: flussi_rendicontazioni (is_govpay=1, data_pagamento >= ' . ($minDate ?? '-') . ', backlog iniziale=' . $initialBacklog . ').');

const BATCH_SIZE   = 100;
const SLEEP_IDLE_S = 900; // 15 min quando coda vuota

while (true) {
    $checkStop();

    $scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
    $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;

    try {
        $batch = $service->getBatch($idDominio, BATCH_SIZE, $minDate);
    } catch (\Throwable $e) {
        $log('ERRORE getBatch: ' . $e->getMessage());
        sleep(30);
        continue;
    }

    if (count($batch) === 0) {
        $log('Coda vuota. Prossimo controllo tra ' . (SLEEP_IDLE_S / 60) . ' minuti...');
        for ($s = 0; $s < SLEEP_IDLE_S; $s += 10) {
            $checkStop();
            sleep(10);
        }
        continue;
    }

    $total = count($batch);
    $log("Batch: {$total} pendenze GovPay da arricchire.");

    foreach ($batch as $i => $row) {
        $checkStop();
        $n   = $i + 1;
        $iur = (string)$row['iur'];
        $idP = (string)($row['id_pendenza'] ?? '');
        $log("  {$n}/{$total} IUR={$iur} id_pendenza={$idP}");

        try {
            $result = $service->enrichOne($row);
        } catch (\Throwable $e) {
            $log("  IUR={$iur}: eccezione - " . $e->getMessage());
            sleep(2);
            continue;
        }

        switch ($result['status']) {
            case 'PROCESSED':
                $log("  IUR={$iur}: OK — debitore salvato");
                break;
            case 'SKIPPED':
                $log("  IUR={$iur}: skip - {$result['reason']}");
                break;
            case 'SKIP':
                $log("  IUR={$iur}: configurazione mancante - {$result['reason']}");
                break;
            case 'ERROR':
                $log("  IUR={$iur}: errore - {$result['reason']}");
                break;
        }

        if ($i < $total - 1) {
            sleep(1);
        }
    }

    $log("Batch completato ({$total} record). Avvio ciclo successivo...");
}
