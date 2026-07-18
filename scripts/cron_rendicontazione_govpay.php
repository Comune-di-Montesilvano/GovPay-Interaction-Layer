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
 * righe nuove PENDING (rendicontazione.scansioni_quiete_soglia, default 3).
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

$scansioniSenzaNovita = 0;
$log('Loop avviato.');

while (true) {
    $checkStop();

    $backofficeUrl = (string)SettingsRepository::get('govpay', 'backoffice_url', '');
    $scanInterval  = max(1, (int)SettingsRepository::get('rendicontazione', 'scan_interval_minuti', '15'));
    $soglia        = max(1, (int)SettingsRepository::get('rendicontazione', 'scansioni_quiete_soglia', '3'));
    $maxGiorniRetry = max(1, (int)SettingsRepository::get('rendicontazione', 'max_giorni_retry', '14'));
    $minDataPagamento = date('Y-m-d', strtotime("-{$maxGiorniRetry} days"));
    $geriMaxTentativi = max(1, (int)SettingsRepository::get('rendicontazione', 'geri_max_tentativi', '3'));

    if ($backofficeUrl === '') {
        $log('govpay.backoffice_url non configurato. Riprovo tra ' . $scanInterval . ' minuti...');
        for ($s = 0; $s < $scanInterval * 60; $s += 10) {
            $checkStop();
            sleep(10);
        }
        continue;
    }

    try {
        $govPayClient = buildGovPayClientForRendicontazione();
        $engine = new RendicontazioneEngineService($repo, $bridge, $govPayClient);
        $result = $engine->processaCiclo($idDominio, $idA2A, $backofficeUrl, 2000, $minDataPagamento, $geriMaxTentativi);
        $log(sprintf('Ciclo: processate=%d nuove=%d', $result['processate'], $result['nuove']));

        // Sweep per tentare/ritentare la regolarizzazione di tutti i flussi completi non ancora regolarizzati
        try {
            $flussiDaRegolarizzare = $repo->getFlussiDaRegolarizzare($idDominio);
            if (!empty($flussiDaRegolarizzare)) {
                $log(sprintf('Trovati %d flussi completati da regolarizzare in sweep...', count($flussiDaRegolarizzare)));
                foreach ($flussiDaRegolarizzare as $flussoId) {
                    $checkStop();
                    $rigaFlusso = $repo->getUnaRigaPerFlusso($idDominio, $flussoId);
                    if ($rigaFlusso) {
                        $engine->controllaERegolarizzaFlussoPerRiga($idDominio, (int)$rigaFlusso['id'], $backofficeUrl);
                    }
                }
            }
        } catch (\Throwable $e) {
            $log('ERRORE sweep regolarizzazione flussi: ' . $e->getMessage());
        }

        if ($result['nuove'] > 0) {
            $scansioniSenzaNovita = 0;
        } else {
            $scansioniSenzaNovita++;
        }
    } catch (\Throwable $e) {
        $log('ERRORE ciclo processaCiclo: ' . $e->getMessage());
        $scansioniSenzaNovita++;
    }

    $checkStop();

    if ($scansioniSenzaNovita >= $soglia) {
        try {
            $nonNotificate = $repo->getNonNotificate($idDominio);
            if (!empty($nonNotificate)) {
                $daConfermare = array_values(array_filter($nonNotificate, static fn($r) => $r['rendicontazione_stato'] === 'IN_ATTESA_CONFERMA'));
                $gestite      = array_values(array_filter($nonNotificate, static fn($r) => $r['rendicontazione_stato'] !== 'IN_ATTESA_CONFERMA'));

                $righeOperatoreInviate = 0;
                // Id delle righe effettivamente incluse in un digest INVIATO (operatore o admin).
                // Solo queste vengono marcate rendicontazione_notificato=1: una riga il cui
                // cod_entrata non matcha alcun gruppo (o GESTITO con notifica_admin_auto=false)
                // resta non marcata e ricompare a ogni ciclo finche' la configurazione non la copre.
                $idsNotificati = [];

                $baseUrlVista = rtrim((string)\App\Config\Config::get('BACKOFFICE_PUBLIC_BASE_URL', ''), '/') . '/rendicontazione/da-confermare';

                // Digest per gruppo: si assume una risoluzione gruppo->tipologie già a carico
                // della UI (Task 9); qui si notificano tutti i destinatari via query diretta.
                $userRepo = new UserRepository();
                $globalEmails = $userRepo->getGlobalNotificationEmails();

                $pdo = Connection::getPDO();
                $stmtGruppi = $pdo->prepare(
                    'SELECT DISTINCT group_id FROM rendicontazione_gruppo_tipologie WHERE id_dominio = :dom'
                );
                $stmtGruppi->execute([':dom' => $idDominio]);
                foreach ($stmtGruppi->fetchAll(\PDO::FETCH_ASSOC) as $g) {
                    $groupId = (int)$g['group_id'];
                    $tipologie = $groupRepo->getRendicontazioneTipologie($groupId, $idDominio);
                    $entrateSoloNotifica = array_column(array_filter($tipologie, static fn($t) => $t['modalita'] === 'SOLO_NOTIFICA'), 'id_entrata');
                    $entrateSmarcatura   = array_column(array_filter($tipologie, static fn($t) => $t['modalita'] === 'NOTIFICA_E_SMARCATURA'), 'id_entrata');

                    $righeDaConfermareGruppo = array_values(array_filter($daConfermare, static fn($r) => in_array((string)($r['cod_entrata'] ?? ''), $entrateSmarcatura, true)));
                    $righeInformativeGruppo  = array_values(array_filter($gestite, static fn($r) => in_array((string)($r['cod_entrata'] ?? ''), $entrateSoloNotifica, true)));

                    if (empty($righeDaConfermareGruppo) && empty($righeInformativeGruppo)) {
                        continue;
                    }

                    $emails = $groupRepo->getMemberEmails($groupId);
                    if (!empty($globalEmails)) {
                        $emails = array_unique(array_merge($emails, $globalEmails));
                    }
                    $groupInfo = $groupRepo->findById($groupId);
                    $esitoOperatore = $mailer->sendRendicontazioneOperatoreDigest(
                        $emails,
                        (string)($groupInfo['nome'] ?? "Gruppo {$groupId}"),
                        $righeDaConfermareGruppo,
                        $righeInformativeGruppo,
                        $baseUrlVista
                    );
                    if (($esitoOperatore['esito'] ?? '') === 'OK') {
                        $righeOperatoreInviate += count($righeDaConfermareGruppo) + count($righeInformativeGruppo);
                        foreach (array_merge($righeDaConfermareGruppo, $righeInformativeGruppo) as $r) {
                            $idsNotificati[(int)$r['id']] = true;
                        }
                    }
                }

                $notificaAdmin = (string)SettingsRepository::get('rendicontazione', 'notifica_admin_auto', 'false') === 'true';
                $righeAdminInviate = 0;
                if ($notificaAdmin) {
                    $rawAdminEmails = (string)SettingsRepository::get('rendicontazione', 'admin_emails', '');
                    $adminEmails = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $rawAdminEmails))));
                    if (!empty($adminEmails) && !empty($gestite)) {
                        $esitoAdmin = $mailer->sendRendicontazioneAdminDigest(array_values($adminEmails), $gestite);
                        if (($esitoAdmin['esito'] ?? '') === 'OK') {
                            $righeAdminInviate = count($gestite);
                            foreach ($gestite as $r) {
                                $idsNotificati[(int)$r['id']] = true;
                            }
                        }
                    }
                }

                $repo->marcaNotificate(array_keys($idsNotificati));
                $pdo->prepare(
                    'INSERT INTO rendicontazione_digest_log (righe_operatore, righe_admin) VALUES (:ro, :ra)'
                )->execute([':ro' => $righeOperatoreInviate, ':ra' => $righeAdminInviate]);

                $log(sprintf('Digest inviato: operatore=%d admin=%d', $righeOperatoreInviate, $righeAdminInviate));
            }
        } catch (\Throwable $e) {
            $log('ERRORE invio digest: ' . $e->getMessage());
        }
        $scansioniSenzaNovita = 0;
    }

    $log("Prossimo ciclo tra {$scanInterval} minuti...");
    for ($s = 0; $s < $scanInterval * 60; $s += 10) {
        $checkStop();
        sleep(10);
    }
}
