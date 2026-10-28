<?php
declare(strict_types=1);

/**
 * Cron rendicontazione GovPay — loop daemon.
 *
 * Uso: php cron_rendicontazione_govpay.php
 *
 * Ogni ciclo (rendicontazione.scan_interval_minuti, default 15) processa le righe
 * is_govpay=1 in PENDING/ERRORE con data_pagamento nella finestra
 * rendicontazione.max_giorni_retry (default 14 giorni: oltre non si ritenta piu',
 * evita retry infinito su flussi irrecuperabili) tramite RendicontazioneEngineService.
 * Il digest (mail operatori + admin) parte quando N cicli consecutivi non trovano
 * righe nuove PENDING (rendicontazione.scansioni_quiete_soglia, default 3); contatore
 * persistito in DB. Logica dell'iterazione in App\Services\Jobs\RendicontazioneJob.
 * Si ferma ricevendo il segnale /tmp/cron-stop-rendicontazione-govpay.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Auth\UserRepository;
use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\RendicontazioneRepository;
use App\Database\UserGroupRepository;
use App\Services\GovPayClientFactory;
use App\Services\LegacyRendicontazioneBridgeClient;
use App\Services\MailerService;
use App\Services\Jobs\RendicontazioneJob;
use App\Services\Jobs\ScriptJobContext;
use App\Services\RendicontazioneEngineService;
use Dotenv\Dotenv;

if (class_exists(\Dotenv\Dotenv::class) && file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
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

\App\Monitoring\SentryReporter::init('cron-rendicontazione-govpay');
set_exception_handler(static function (\Throwable $e) use ($log): void {
    \Sentry\captureException($e);
    $log('ERRORE FATALE: ' . $e->getMessage());
    exit(1);
});

$pidFile  = '/tmp/cron-rendicontazione-govpay.pid';
$stopFile = '/tmp/cron-stop-rendicontazione-govpay';

if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_rendicontazione_govpay.php') !== false) {
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

$idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
$idA2A     = (string)SettingsRepository::get('entity', 'id_a2a', '');
if ($idDominio === '' || $idA2A === '') {
    $log('ERRORE: entity.id_dominio o entity.id_a2a non configurati.');
    exit(1);
}

function buildGovPayClientForRendicontazione(): \GuzzleHttp\Client
{
    $opts = ['headers' => ['Accept' => 'application/json'], 'connect_timeout' => 10, 'timeout' => 30];
    $username = (string)SettingsRepository::get('govpay', 'user', '');
    $password = (string)SettingsRepository::get('govpay', 'password', '');
    if ($username !== '' && $password !== '') {
        $opts['auth'] = [$username, $password];
    }
    return GovPayClientFactory::makeBackofficeClient($opts);
}

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(15, static function () use ($pidFile, $log): void {
        $log('SIGTERM ricevuto. Uscita.');
        @unlink($pidFile);
        exit(0);
    });
}

$repo   = new RendicontazioneRepository();
$bridge = new LegacyRendicontazioneBridgeClient();
$groupRepo = new UserGroupRepository();
$mailer = MailerService::forSuite('backoffice');

// Il contatore dei cicli senza novita' (digest) e' persistito in DB da RendicontazioneJob
// (settings rendicontazione.scansioni_senza_novita): sopravvive al riavvio del processo.
$job = new RendicontazioneJob(
    $repo,
    static fn(): RendicontazioneEngineService => new RendicontazioneEngineService($repo, $bridge, buildGovPayClientForRendicontazione()),
    $groupRepo,
    new UserRepository(),
    $mailer,
    $idDominio,
    $idA2A
);
$jobCtx = new ScriptJobContext($stopFile);

$log('Loop avviato.');

while (true) {
    $checkStop();

    $scanInterval = max(1, (int)SettingsRepository::get('rendicontazione', 'scan_interval_minuti', '15'));

    $job->runOnce($jobCtx);

    $checkStop();

    $log("Prossimo ciclo tra {$scanInterval} minuti...");
    for ($s = 0; $s < $scanInterval * 60; $s += 10) {
        $checkStop();
        sleep(10);
    }
}
