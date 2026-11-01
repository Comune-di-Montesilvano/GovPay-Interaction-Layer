<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Database\DbalConnectionFactory;
use App\Database\JobStateRepository;
use App\Services\Jobs\Handler\RunJobHandler;
use App\Services\Jobs\Handler\SchedulerTickHandler;
use App\Services\Jobs\Message\RunJob;
use App\Services\Jobs\Message\SchedulerTick;
use Doctrine\DBAL\Connection as DbalConnection;
use Sentry\State\Scope;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineTransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

/**
 * Processo worker (bin/worker): Symfony Scheduler + Messenger su MariaDB.
 *
 * - Ricevitori del Worker Messenger, in ordine: trasporto Doctrine `jobs` (RunJob manuali da UI,
 *   auto_setup disattivato: tabelle dalla migrazione 036) e scheduler (SchedulerTick ogni 5 s,
 *   in memoria, solo l'ultimo tick perso). `jobs` per primo: il Worker riparte dal primo
 *   ricevitore dopo ogni messaggio e, con un lavoro in arretrato, lo scheduler ha sempre un
 *   tick perso pronto: in seconda posizione la coda manuale non verrebbe mai letta. Nessun retry (max_retries=0: nessun listener
 *   di retry/failure transport), un messaggio fallito viene scartato.
 * - SchedulerTick -> RunJob periodico per ogni lavoro scaduto, gestito subito in sequenza:
 *   un solo processo, nessun giro in parallelo nello stesso worker; tra worker diversi decide
 *   il lock `job:<name>` di JobRunner.
 * - Una sola connessione DBAL condivisa per trasporto e store del lock.
 * - Avvio: attende DB e tabelle (riprova ogni 5 s, una riga per tentativo, mai resa), poi
 *   import una tantum dei file .autostart.
 * - SIGTERM/SIGINT: requestStop(); il batch in corso finisce, il giro si chiude (JobRunner
 *   rilegge lo stop tra un batch e l'altro), il Worker esce, exit 0.
 * - stdout: riepiloghi di JobRunner, errori, righe di avvio/arresto; tick silenzioso.
 */
final class WorkerKernel
{
    public const QUEUE = 'jobs';
    public const TICK_SECONDS = 5;
    public const DB_RETRY_SECONDS = 5;
    public const DEFAULT_CACHE_DIR = '/var/www/cache';
    /** Pausa del Worker quando nessun ricevitore ha messaggi (poll del trasporto Doctrine). */
    public const IDLE_SLEEP_MICROSECONDS = 1_000_000;

    /** Tabelle senza le quali il worker non parte (migrazione 036 non ancora applicata = attesa). */
    public const REQUIRED_TABLES = ['gil_jobs', 'gil_job_runs', 'gil_job_run_errors', 'messenger_messages', 'lock_keys'];

    private bool $stopRequested = false;
    private ?Worker $worker = null;

    private readonly ClockInterface $clock;
    /** @var \Closure(string): void */
    private readonly \Closure $output;
    /** @var \Closure(string, \Throwable): void */
    private readonly \Closure $reportException;
    /** @var \Closure(): void */
    private readonly \Closure $databaseProbe;
    private readonly JobRunner $runner;
    // Non readonly: il gestore del tick lo richiama da una closure creata prima dell'assegnazione.
    private MessageBus $bus;
    private readonly DoctrineTransport $jobsTransport;

    /**
     * @param (\Closure(string): void)|null $output riga di log (default: stdout)
     * @param (\Closure(string, \Throwable): void)|null $reportException (suite, eccezione) (default: Sentry)
     * @param (\Closure(): void)|null $databaseProbe lancia se DB/tabelle non pronti (default: REQUIRED_TABLES)
     */
    public function __construct(
        private readonly JobRegistry $registry,
        private readonly JobStateRepository $state,
        private readonly DbalConnection $dbal,
        ?ClockInterface $clock = null,
        ?\Closure $output = null,
        ?\Closure $reportException = null,
        ?\Closure $databaseProbe = null,
        ?LockFactory $locks = null,
        private readonly string $cacheDir = self::DEFAULT_CACHE_DIR
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->output = $output ?? static function (string $line): void {
            echo $line, "\n";
            flush();
        };
        $this->reportException = $reportException ?? static function (string $suite, \Throwable $e): void {
            \Sentry\withScope(static function (Scope $scope) use ($suite, $e): void {
                $scope->setTag('suite', $suite);
                \Sentry\captureException($e);
            });
        };
        $this->databaseProbe = $databaseProbe ?? fn() => self::probeTables($this->dbal, self::REQUIRED_TABLES);

        $this->runner = new JobRunner(
            $this->state,
            $locks ?? new LockFactory(new DoctrineDbalStore($this->dbal)),
            $this->clock,
            $this->output,
            $this->reportException,
            fn(): bool => $this->stopRequested
        );
        $this->jobsTransport = self::createJobsTransport($this->dbal);

        $line = fn(string $msg) => $this->line($msg);
        $this->bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SchedulerTick::class => [new SchedulerTickHandler(
                $this->state,
                $this->registry,
                $this->clock,
                fn(RunJob $m) => $this->bus->dispatch($m),
                fn(): bool => $this->stopRequested,
                $line
            )],
            RunJob::class => [new RunJobHandler($this->registry, $this->runner, $this->state, $this->clock, $line)],
        ]))]);
    }

    /** Worker di produzione: catalogo lavori, connessioni applicative, stdout + Sentry. */
    public static function create(string $cacheDir = self::DEFAULT_CACHE_DIR): self
    {
        $out = static function (string $line): void {
            echo $line, "\n";
            flush();
        };
        $catalogOut = static function (string $msg) use ($out): void {
            $out('[' . date('Y-m-d H:i:s') . '] [worker] ' . $msg);
        };
        return new self(
            JobCatalog::registry($catalogOut),
            JobStateRepository::default(),
            DbalConnectionFactory::get(),
            null,
            $out,
            null,
            null,
            null,
            $cacheDir
        );
    }

    /** Trasporto Doctrine `jobs` (tabella messenger_messages, auto_setup disattivato). */
    public static function createJobsTransport(DbalConnection $dbal): DoctrineTransport
    {
        return new DoctrineTransport(
            new DoctrineTransportConnection([
                'table_name' => 'messenger_messages',
                'queue_name' => self::QUEUE,
                'auto_setup' => false,
            ], $dbal),
            new PhpSerializer()
        );
    }

    /** "Esegui ora" (UI): accoda RunJob manuale, eseguito dal worker anche se il lavoro e' disabilitato. */
    public static function enqueueManualRun(DbalConnection $dbal, string $job): void
    {
        self::createJobsTransport($dbal)->send(new Envelope(new RunJob($job, true)));
    }

    /** Lancia se il DB non risponde o manca una delle tabelle. */
    public static function probeTables(DbalConnection $dbal, array $tables): void
    {
        foreach ($tables as $table) {
            $dbal->executeQuery('SELECT 1 FROM ' . $dbal->quoteSingleIdentifier($table) . ' LIMIT 0')->free();
        }
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
        $this->worker?->stop();
    }

    public function isStopRequested(): bool
    {
        return $this->stopRequested;
    }

    public function bus(): MessageBusInterface
    {
        return $this->bus;
    }

    public function runner(): JobRunner
    {
        return $this->runner;
    }

    /** SIGTERM/SIGINT -> requestStop() (senza pcntl: nessun gestore, arresto solo da kill). */
    public function installSignalHandlers(): bool
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return false;
        }
        pcntl_async_signals(true);
        foreach ([\SIGTERM, \SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->requestStop();
            });
        }
        return true;
    }

    /**
     * Attende DB e tabelle: riprova ogni DB_RETRY_SECONDS, una riga per tentativo fallito, senza
     * stack trace e senza arrendersi. false solo se nel frattempo e' stato chiesto l'arresto.
     */
    public function waitForDatabase(): bool
    {
        $failed = false;
        while (!$this->stopRequested) {
            try {
                ($this->databaseProbe)();
                if ($failed) {
                    $this->line('DB pronto.');
                }
                return true;
            } catch (\Throwable $e) {
                $failed = true;
                $this->line(sprintf(
                    'DB non pronto (%s): nuovo tentativo tra %d s',
                    JobRunner::errorMessage($e),
                    self::DB_RETRY_SECONDS
                ));
            }
            if (!$this->stopRequested) {
                $this->clock->sleep(self::DB_RETRY_SECONDS);
            }
        }
        return false;
    }

    /**
     * Ciclo del worker fino all'arresto. Un errore del trasporto (es. DB caduto durante il
     * poll) non termina il processo: una riga, attesa del DB, Worker ricostruito.
     *
     * @param float|null $timeLimit secondi (test); null = senza limite
     */
    public function run(?float $timeLimit = null): int
    {
        $this->line('avvio worker (' . count($this->registry->names()) . ' lavori, tick ' . self::TICK_SECONDS . ' s)');
        if (!$this->waitForDatabase()) {
            $this->line('arresto richiesto durante l\'attesa del DB.');
            return 0;
        }

        try {
            $imported = (new AutostartImporter($this->state, $this->registry->names()))->importOnce($this->cacheDir);
            if ($imported > 0) {
                $this->line("import .autostart: {$imported} lavori abilitati");
            }
        } catch (\Throwable $e) {
            ($this->reportException)('worker', $e);
            $this->line('ERRORE import .autostart: ' . JobRunner::errorMessage($e));
        }

        $started = $this->clock->now();
        while (!$this->stopRequested) {
            $remaining = null;
            if ($timeLimit !== null) {
                $remaining = $timeLimit - ((float)$this->clock->now()->format('U.u') - (float)$started->format('U.u'));
                if ($remaining <= 0) {
                    break;
                }
            }
            $this->worker = $this->buildWorker();
            try {
                $this->worker->run(['sleep' => self::IDLE_SLEEP_MICROSECONDS, 'time_limit' => $remaining]);
                if ($timeLimit !== null) {
                    break;
                }
            } catch (\Throwable $e) {
                ($this->reportException)('worker', $e);
                $this->line('ERRORE worker: ' . JobRunner::errorMessage($e));
                if (!$this->stopRequested) {
                    $this->clock->sleep(self::DB_RETRY_SECONDS);
                }
                $this->waitForDatabase();
            } finally {
                $this->worker = null;
            }
        }

        $this->line('worker arrestato.');
        return 0;
    }

    private function buildWorker(): Worker
    {
        $schedule = (new Schedule())
            ->add(RecurringMessage::every(self::TICK_SECONDS . ' seconds', new SchedulerTick(), $this->clock->now()))
            ->processOnlyLastMissedRun(true);

        return new Worker(
            [
                // Ordine rilevante: vedi docblock della classe (coda manuale prima dello scheduler).
                self::QUEUE => $this->jobsTransport,
                'scheduler' => new SchedulerTransport(new MessageGenerator($schedule, 'gil', $this->clock)),
            ],
            $this->bus,
            null,
            null,
            null,
            $this->clock
        );
    }

    private function line(string $msg): void
    {
        ($this->output)('[' . $this->clock->now()->format('Y-m-d H:i:s') . '] [worker] ' . $msg);
    }
}
