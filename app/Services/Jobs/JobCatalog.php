<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Auth\UserRepository;
use App\Config\SettingsRepository;
use App\Controllers\PendenzeController;
use App\Database\BizRepository;
use App\Database\DbalConnectionFactory;
use App\Database\FlussiRendicontazioniRepository;
use App\Database\MappingPendenzeRepository;
use App\Database\MassivePendenzeRepository;
use App\Database\RendicontazioneRepository;
use App\Database\TefaRepository;
use App\Database\UserGroupRepository;
use App\Services\BizScannerService;
use App\Services\GovPayClientFactory;
use App\Services\GovPayDebitoreService;
use App\Services\LegacyRendicontazioneBridgeClient;
use App\Services\MailerService;
use App\Services\RendicontazioneEngineService;
use App\Services\TefaScannerService;
use Slim\Views\Twig;

/**
 * I lavori periodici del worker (ex demoni cron_*.php), con le guardie di avvio degli script
 * spostate nella fabbrica di ogni giro (vedi LazyJob):
 * - configurazione assente con "Uscita." / exit 0 nello script -> IdleJob (giro vuoto);
 * - configurazione assente con "ERRORE" / exit 1 nello script -> eccezione (giro in errore).
 *
 * Nomi = slug di CronController::JOBS e dei file daemon-<slug>.autostart, piu'
 * `mapping-discovery` (discovery pattern L1, ogni 60 s).
 */
final class JobCatalog
{
    public const NAMES = [
        'ragioneria',
        'biz',
        'tefa',
        'mapping',
        'mapping-discovery',
        'vocab',
        'govpay-debitore',
        'rendicontazione-govpay',
        'pendenze-massive',
    ];

    private const EMPTY_QUEUE = 900;

    /** Lavori che si abilitano insieme a uno slug importato da .autostart (UI: un solo interruttore). */
    public const COMPANIONS = ['mapping' => ['mapping-discovery']];

    /** @param (\Closure(string): void)|null $output righe informative (default: nessuna) */
    public static function registry(?\Closure $output = null): JobRegistry
    {
        $output ??= static function (string $line): void {};
        $tefaAligned = false;

        $r = new JobRegistry();

        $r->register('ragioneria', 1800, static function (): PeriodicJob {
            $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
            $idA2A = (string)SettingsRepository::get('entity', 'id_a2a', '');
            $backofficeUrl = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');
            $configError = RagioneriaSyncJob::configError($idDominio, $backofficeUrl);
            if ($configError !== null) {
                throw new \RuntimeException(self::stripErrorPrefix($configError));
            }
            // il rescan chiesto da UI lo legge (e azzera) runOnce quando il giro parte davvero
            return new RagioneriaSyncJob($idDominio, $idA2A, $backofficeUrl);
        });

        $r->register('biz', self::EMPTY_QUEUE, static function (): PeriodicJob {
            $bizHost = (string)SettingsRepository::get('pagopa', 'biz_events_host', '');
            $bizApiKey = (string)SettingsRepository::get('pagopa', 'biz_events_api_key', '');
            if ($bizHost === '' || $bizApiKey === '') {
                return new IdleJob('biz', self::EMPTY_QUEUE, 'Biz Events non configurato (biz_events_host o biz_events_api_key mancanti).');
            }
            $idDominio = self::requireDominio();
            $repo = new BizRepository();
            $flussiRepo = new FlussiRendicontazioniRepository();
            return new BizScanJob($repo, $flussiRepo, new BizScannerService($repo, $flussiRepo), $idDominio);
        });

        $r->register('tefa', self::EMPTY_QUEUE, static function () use (&$tefaAligned, $output): PeriodicJob {
            if (!TefaScanJob::isEnabled()) {
                return new IdleJob('tefa', self::EMPTY_QUEUE, 'TEFA non abilitato.');
            }
            $idDominio = self::requireDominio();
            $repo = new TefaRepository();
            $bizRepo = new BizRepository();
            // Allineamento retroattivo TEFA: lo script lo faceva all'avvio del demone, qui al
            // primo giro abilitato del processo worker.
            if (!$tefaAligned) {
                $tefaAligned = true;
                try {
                    $fixed = $repo->fixProcessedTefaMapping($idDominio);
                    if ($fixed > 0) {
                        $output("Allineamento retroattivo TEFA completato: aggiornate {$fixed} pendenze in flussi_rendicontazioni.");
                    }
                } catch (\Throwable $e) {
                    \Sentry\captureException($e);
                    $output('ERRORE allineamento retroattivo TEFA: ' . JobRunner::errorMessage($e));
                }
            }
            return new TefaScanJob($repo, $bizRepo, new TefaScannerService($repo, $bizRepo), $idDominio);
        });

        $r->register('mapping', self::EMPTY_QUEUE, static function (): PeriodicJob {
            return new MappingL1Job(new MappingPendenzeRepository(), self::requireDominio());
        });

        $r->register('mapping-discovery', MappingDiscoveryJob::INTERVAL_SECONDS, static function (): PeriodicJob {
            $idDominio = self::requireDominio();
            $repo = new MappingPendenzeRepository();
            return new MappingDiscoveryJob(new MappingL1Job($repo, $idDominio), $repo, $idDominio);
        });

        $r->register('vocab', self::EMPTY_QUEUE, static function (): PeriodicJob {
            return new MappingL2Job(new MappingPendenzeRepository(), self::requireDominio());
        });

        $r->register('govpay-debitore', self::EMPTY_QUEUE, static function (): PeriodicJob {
            $backofficeUrl = (string)SettingsRepository::get('govpay', 'backoffice_url', '');
            $idA2A = (string)SettingsRepository::get('entity', 'id_a2a', '');
            if ($backofficeUrl === '' || $idA2A === '') {
                return new IdleJob('govpay-debitore', self::EMPTY_QUEUE, 'GovPay Backoffice non configurato (backoffice_url o id_a2a mancanti).');
            }
            $idDominio = self::requireDominio();
            return new GovPayDebitoreJob(
                new GovPayDebitoreService(new BizRepository(), new FlussiRendicontazioniRepository()),
                $idDominio
            );
        });

        $r->register('rendicontazione-govpay', self::EMPTY_QUEUE, static function (): PeriodicJob {
            $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
            $idA2A = (string)SettingsRepository::get('entity', 'id_a2a', '');
            if ($idDominio === '' || $idA2A === '') {
                throw new \RuntimeException('entity.id_dominio o entity.id_a2a non configurati.');
            }
            $repo = new RendicontazioneRepository();
            $bridge = new LegacyRendicontazioneBridgeClient();
            return new FixedCadenceJob(new RendicontazioneJob(
                $repo,
                static fn(): RendicontazioneEngineService => new RendicontazioneEngineService($repo, $bridge, self::rendicontazioneGovPayClient()),
                new UserGroupRepository(),
                new UserRepository(),
                MailerService::forSuite('backoffice'),
                $idDominio,
                $idA2A
            ));
        });

        // Solo presa in carico + accodamento (coda `massive`): l'elaborazione GovPay e' nel
        // gestore ProcessMassiveRowHandler del worker.
        $r->register(MassiveEnqueueJob::NAME, MassiveEnqueueJob::IDLE_SECONDS, static function (): PeriodicJob {
            $dbal = DbalConnectionFactory::get();
            return new MassiveEnqueueJob($dbal, WorkerKernel::createMassiveTransport($dbal));
        });

        return $r;
    }

    private static function requireDominio(): string
    {
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            throw new \RuntimeException('id_dominio non configurato.');
        }
        return $idDominio;
    }

    private static function stripErrorPrefix(string $msg): string
    {
        return str_starts_with($msg, 'ERRORE: ') ? substr($msg, 8) : $msg;
    }

    /** Client GovPay del motore rendicontazione (come buildGovPayClientForRendicontazione dello script). */
    private static function rendicontazioneGovPayClient(): \GuzzleHttp\Client
    {
        $opts = ['headers' => ['Accept' => 'application/json'], 'connect_timeout' => 10, 'timeout' => 30];
        $username = (string)SettingsRepository::get('govpay', 'user', '');
        $password = (string)SettingsRepository::get('govpay', 'password', '');
        if ($username !== '' && $password !== '') {
            $opts['auth'] = [$username, $password];
        }
        return GovPayClientFactory::makeBackofficeClient($opts);
    }
}
