<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Controllers\PendenzeController;
use App\Database\DbalConnectionFactory;
use App\Database\JobStateRepository;
use App\Database\MassivePendenzeRepository;
use App\Services\Jobs\Handler\ProcessMassiveRowHandler;
use App\Services\Jobs\Handler\RunJobHandler;
use App\Services\Jobs\Handler\SchedulerTickHandler;
use App\Services\Jobs\Message\ProcessMassiveRow;
use App\Services\Jobs\Message\RunJob;
use App\Services\Jobs\Message\SchedulerTick;
use App\Services\Jobs\Messenger\ArrayContainer;
use App\Services\Jobs\Messenger\TransientOnlyRetryStrategy;
use Doctrine\DBAL\Connection as DbalConnection;
use Sentry\State\Scope;
use Slim\Views\Twig;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineTransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;

/**
 * Un processo del worker (bin/worker): Symfony Scheduler + Messenger su MariaDB, limitato a un
 * WorkerScope. In produzione WorkerSupervisor (PID 1, `gil:worker`) avvia un processo figlio per
 * gruppo di lavori (`gil:worker:job <gruppo>`, JobCatalog::processGroups) e uno per la coda
 * `massive` (`gil:worker:massive`): i lavori girano IN PARALLELO, come i vecchi demoni.
 *
 * - Ricevitori del Worker Messenger, in ordine: code "esegui ora" `jobs.<name>` dei lavori del
 *   processo (RunJob manuali da UI; in scope all() anche la coda legacy `jobs`), coda `massive`
 *   (solo scope massive()/all(): un ProcessMassiveRow per riga, accodati da MassiveEnqueueJob) e
 *   scheduler (SchedulerTick ogni 5 s, in memoria, solo l'ultimo tick perso; solo con lavori).
 *   Le code manuali per prime: il Worker riparte dal primo ricevitore dopo ogni messaggio e, con
 *   un lavoro in arretrato, lo scheduler ha sempre un tick perso pronto.
 *   Trasporti Doctrine con auto_setup disattivato (tabelle dalla migrazione 036; queue_name e'
 *   una colonna: le code per lavoro non richiedono schema).
 * - Retry: code manuali e scheduler nessuno (max_retries=0, messaggio fallito scartato).
 *   `massive`: solo TransientMassiveFailure, 3 retry a 30/60/120 s (TransientOnlyRetryStrategy,
 *   listener standard di Messenger che rimette il messaggio in coda con DelayStamp). Tentativi
 *   esauriti o errore non ritentabile: riga nello stato di errore (onMassiveFailed).
 * - SchedulerTick -> RunJob periodico per ogni lavoro del processo scaduto, gestito subito. Il
 *   lock `job:<name>` di JobRunner garantisce un solo giro per lavoro anche tra processi.
 * - Una sola connessione DBAL condivisa per trasporti e store del lock (per processo).
 * - Avvio: attende DB e tabelle (riprova ogni 5 s, una riga per tentativo, mai resa). Solo con
 *   housekeeping (supervisore o all()): import una tantum dei file .autostart e pulizia dello
 *   storico esecuzioni oltre 30 giorni (ripetuta circa ogni 24 ore).
 * - SIGTERM/SIGINT: requestStop(); il batch in corso finisce, il giro si chiude (JobRunner
 *   rilegge lo stop tra un batch e l'altro), il Worker esce, exit 0.
 * - stdout: avvio/avanzamento/riepiloghi di JobRunner, errori, righe di avvio/arresto (prefisso
 *   [worker:<gruppo>]); tick silenzioso.
 */
final class WorkerKernel
{
    /**
     * Coda "esegui ora" legacy (worker sequenziale, immagini fino alla 1.7.x): svuotata dal
     * supervisore all'avvio (drainLegacyManualQueue), consumata direttamente solo in scope all().
     */
    public const QUEUE = 'jobs';
    /** Prefisso delle code "esegui ora" per lavoro: `jobs.<name>` (queueFor). */
    public const QUEUE_PREFIX = 'jobs.';
    public const MASSIVE_QUEUE = MassiveEnqueueJob::QUEUE;
    public const TICK_SECONDS = 5;
    public const DB_RETRY_SECONDS = 5;
    public const DEFAULT_CACHE_DIR = '/var/www/cache';
    /** Pausa del Worker quando nessun ricevitore ha messaggi (poll del trasporto Doctrine). */
    public const IDLE_SLEEP_MICROSECONDS = 1_000_000;
    /** Giorni di storico esecuzioni (gil_job_runs) conservati; il resto e' eliminato. */
    public const HISTORY_RETENTION_DAYS = 30;
    /** Intervallo minimo tra due pulizie dello storico. */
    public const HISTORY_PURGE_INTERVAL_SECONDS = 86400;

    /** Tabelle senza le quali il worker non parte (migrazione 036 non ancora applicata = attesa). */
    public const REQUIRED_TABLES = ['gil_jobs', 'gil_job_runs', 'gil_job_run_errors', 'messenger_messages', 'lock_keys'];

    /**
     * Colonne senza le quali il worker non parte (migrazione 037: claim delle pendenze massive;
     * 038: avanzamento dei giri in gil_job_runs).
     */
    public const REQUIRED_COLUMNS = [
        'pendenze_massive' => ['claimed_at', 'claim_token'],
        'gil_job_runs' => ['total_items', 'remaining_items'],
    ];

    private bool $stopRequested = false;
    private ?Worker $worker = null;
    private ?\DateTimeImmutable $lastHistoryPurge = null;

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
    private readonly DoctrineTransport $massiveTransport;
    /** @var array<string, DoctrineTransport> coda => trasporto "esegui ora" dei lavori del processo */
    private readonly array $manualTransports;
    private readonly WorkerScope $scope;
    /** Registro dei soli lavori del processo (scheduler e RunJob). */
    private readonly JobRegistry $scopedRegistry;
    private readonly EventDispatcher $events;
    /** @var \Closure(): MassivePendenzeProcessor */
    private readonly \Closure $massiveProcessorFactory;

    /**
     * @param (\Closure(string): void)|null $output riga di log (default: stdout)
     * @param (\Closure(string, \Throwable): void)|null $reportException (suite, eccezione) (default: Sentry)
     * @param (\Closure(): void)|null $databaseProbe lancia se DB/tabelle non pronti (default: REQUIRED_TABLES)
     * @param (\Closure(): MassivePendenzeProcessor)|null $massiveProcessorFactory processore per messaggio (default: GovPay reale)
     * @param RetryStrategyInterface|null $massiveRetryStrategy retry della coda `massive` (default: TransientOnlyRetryStrategy)
     * @param WorkerScope|null $scope cosa esegue il processo (default: all(), tutto in un processo)
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
        private readonly string $cacheDir = self::DEFAULT_CACHE_DIR,
        ?\Closure $massiveProcessorFactory = null,
        ?RetryStrategyInterface $massiveRetryStrategy = null,
        ?WorkerScope $scope = null
    ) {
        $this->scope = $scope ?? WorkerScope::all();
        $this->scopedRegistry = $this->scope->jobs === null ? $this->registry : $this->registry->only($this->scope->jobs);
        $this->clock = $clock ?? new NativeClock();
        $this->output = $output ?? static function (string $line): void {
            echo $line . "\n"; // una sola write(): righe dei processi paralleli non si mescolano
            flush();
        };
        $this->reportException = $reportException ?? static function (string $suite, \Throwable $e): void {
            \Sentry\withScope(static function (Scope $scope) use ($suite, $e): void {
                $scope->setTag('suite', $suite);
                \Sentry\captureException($e);
            });
        };
        $this->databaseProbe = $databaseProbe ?? function (): void {
            self::probeTables($this->dbal, self::REQUIRED_TABLES);
            self::probeColumns($this->dbal, self::REQUIRED_COLUMNS);
        };

        $this->runner = new JobRunner(
            $this->state,
            $locks ?? new LockFactory(new DoctrineDbalStore($this->dbal)),
            $this->clock,
            $this->output,
            $this->reportException,
            fn(): bool => $this->stopRequested
        );
        $manual = [];
        if ($this->scope->legacyQueue) {
            $manual[self::QUEUE] = self::createJobsTransport($this->dbal);
        }
        foreach ($this->scopedRegistry->names() as $name) {
            $manual[self::queueFor($name)] = self::createJobsTransport($this->dbal, $name);
        }
        $this->manualTransports = $manual;
        $this->massiveTransport = self::createMassiveTransport($this->dbal);
        $this->massiveProcessorFactory = $massiveProcessorFactory ?? static fn(): MassivePendenzeProcessor => self::defaultMassiveProcessor();

        $this->events = new EventDispatcher();
        $this->events->addSubscriber(new SendFailedMessageForRetryListener(
            new ArrayContainer([self::MASSIVE_QUEUE => $this->massiveTransport]),
            new ArrayContainer([self::MASSIVE_QUEUE => $massiveRetryStrategy ?? new TransientOnlyRetryStrategy()])
        ));
        // Dopo il listener di retry (priorita' 100): willRetry() e' gia' deciso.
        $this->events->addListener(WorkerMessageFailedEvent::class, fn(WorkerMessageFailedEvent $e) => $this->onMassiveFailed($e), -100);
        // Ad ogni giro del Worker (anche a vuoto): la pulizia dello storico e' a cadenza giornaliera.
        if ($this->scope->housekeeping) {
            $this->events->addListener(WorkerRunningEvent::class, fn() => $this->purgeHistoryIfDue());
        }

        $line = fn(string $msg) => $this->line($msg);
        $massiveLine = fn(string $msg) => ($this->output)('[' . $this->clock->now()->format('Y-m-d H:i:s') . '] [' . JobRunner::suiteFor(MassiveEnqueueJob::NAME) . '] ' . $msg);
        $this->bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            SchedulerTick::class => [new SchedulerTickHandler(
                $this->state,
                $this->scopedRegistry,
                $this->clock,
                fn(RunJob $m) => $this->bus->dispatch($m),
                fn(): bool => $this->stopRequested,
                $line
            )],
            RunJob::class => [new RunJobHandler($this->scopedRegistry, $this->runner, $this->state, $this->clock, $line)],
            ProcessMassiveRow::class => [new ProcessMassiveRowHandler(
                $this->massiveProcessorFactory,
                $massiveLine,
                fn(): bool => $this->state->isEnabled(MassiveEnqueueJob::NAME)
            )],
        ]))]);
    }

    /** Processore pendenze massive di produzione (GovPay reale), uno per messaggio. */
    public static function defaultMassiveProcessor(): MassivePendenzeProcessor
    {
        // PendenzeController richiede un Twig nel costruttore: qui non renderizza nulla (solo chiamate GovPay).
        $controller = new PendenzeController(Twig::create(dirname(__DIR__, 3) . '/backoffice/templates'), null);
        return new MassivePendenzeProcessor($controller, new MassivePendenzeRepository());
    }

    /** Trasporto Doctrine `massive` (stessa tabella messenger_messages, auto_setup disattivato). */
    public static function createMassiveTransport(DbalConnection $dbal): DoctrineTransport
    {
        return new DoctrineTransport(
            new DoctrineTransportConnection([
                'table_name' => 'messenger_messages',
                'queue_name' => self::MASSIVE_QUEUE,
                'auto_setup' => false,
            ], $dbal),
            new PhpSerializer()
        );
    }

    /**
     * Messaggio `massive` fallito senza ulteriori tentativi (retry esauriti o errore non
     * ritentabile): la riga va nello stato di errore di oggi (creazione ERROR con l'errore
     * dell'ultimo tentativo; errore imprevisto -> "Errore di elaborazione (<classe>)", mai il
     * messaggio). Solo se il claim e' ancora quello del messaggio. Non lancia: se il DB non
     * risponde la riga resta in lavorazione e il reset dei claim abbandonati la rimette in coda.
     */
    private function onMassiveFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry() || $event->getReceiverName() !== self::MASSIVE_QUEUE) {
            return;
        }
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof ProcessMassiveRow) {
            return;
        }
        $suite = JobRunner::suiteFor(MassiveEnqueueJob::NAME);
        $transient = TransientOnlyRetryStrategy::isTransient($event->getThrowable());
        $cause = TransientOnlyRetryStrategy::unwrap($event->getThrowable());
        $short = (new \ReflectionClass($cause))->getShortName();
        $errore = $transient ? null : 'Errore di elaborazione (' . $short . ')';
        $now = '[' . $this->clock->now()->format('Y-m-d H:i:s') . '] [' . $suite . '] ';
        try {
            $updated = ($this->massiveProcessorFactory)()->failClaimedRow($message->rowId, $message->claimToken, $errore);
        } catch (\Throwable $e) {
            ($this->reportException)($suite, $e);
            ($this->output)($now . "ERRORE esito riga {$message->rowId}: " . JobRunner::errorMessage($e));
            return;
        }
        if (!$transient) {
            ($this->reportException)($suite, $cause);
        }
        if ($updated) {
            $retries = RedeliveryStamp::getRetryCountFromEnvelope($event->getEnvelope());
            ($this->output)($now . "riga {$message->rowId}: " . ($transient
                ? "tentativi esauriti ({$retries} retry), stato di errore"
                : "errore non ritentabile ({$short}), stato di errore"));
        }
    }

    /**
     * Processo di produzione: catalogo lavori, connessioni applicative, stdout + Sentry.
     *
     * @param WorkerScope|null $scope default all()
     */
    public static function create(string $cacheDir = self::DEFAULT_CACHE_DIR, ?WorkerScope $scope = null): self
    {
        $out = static function (string $line): void {
            echo $line . "\n"; // una sola write(): righe dei processi paralleli non si mescolano
            flush();
        };
        $label = ($scope ?? WorkerScope::all())->label;
        $catalogOut = static function (string $msg) use ($out, $label): void {
            $out('[' . date('Y-m-d H:i:s') . '] [' . $label . '] ' . $msg);
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
            $cacheDir,
            null,
            null,
            $scope
        );
    }

    /** Coda "esegui ora" del lavoro $job (consumata dal processo figlio del suo gruppo). */
    public static function queueFor(string $job): string
    {
        return self::QUEUE_PREFIX . $job;
    }

    /**
     * Trasporto Doctrine "esegui ora" (tabella messenger_messages, auto_setup disattivato): coda
     * del lavoro $job (`jobs.<name>`), o la coda legacy `jobs` con $job null.
     */
    public static function createJobsTransport(DbalConnection $dbal, ?string $job = null): DoctrineTransport
    {
        return new DoctrineTransport(
            new DoctrineTransportConnection([
                'table_name' => 'messenger_messages',
                'queue_name' => $job === null ? self::QUEUE : self::queueFor($job),
                'auto_setup' => false,
            ], $dbal),
            new PhpSerializer()
        );
    }

    /**
     * "Esegui ora" (UI): accoda RunJob manuale sulla coda del lavoro, eseguito dal suo processo
     * (anche se il lavoro e' disabilitato e mentre altri lavori girano).
     */
    public static function enqueueManualRun(DbalConnection $dbal, string $job): void
    {
        self::createJobsTransport($dbal, $job)->send(new Envelope(new RunJob($job, true)));
    }

    /**
     * C'e' gia' un "esegui ora" di $job in attesa (non ancora consegnato) sulla sua coda o sulla
     * coda legacy `jobs`? Serve a non accodare giri manuali uno dietro l'altro a ogni clic.
     */
    public static function manualRunQueued(DbalConnection $dbal, string $job): bool
    {
        foreach ([self::createJobsTransport($dbal, $job), self::createJobsTransport($dbal)] as $transport) {
            foreach ($transport->all(200) as $envelope) {
                $msg = $envelope->getMessage();
                if ($msg instanceof RunJob && $msg->manual && $msg->job === $job) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Sposta i RunJob rimasti sulla coda legacy `jobs` (accodati con il worker sequenziale) sulle
     * code per lavoro, senza duplicare un "esegui ora" gia' in attesa. Messaggi di lavori
     * sconosciuti o di altro tipo scartati. Ritorna i messaggi spostati.
     *
     * @param list<string> $known lavori del catalogo
     */
    public static function drainLegacyManualQueue(DbalConnection $dbal, array $known): int
    {
        $legacy = self::createJobsTransport($dbal);
        $moved = 0;
        // Limite di sicurezza: get() consegna un messaggio alla volta.
        for ($i = 0; $i < 1000; $i++) {
            $envelopes = [...$legacy->get()];
            if ($envelopes === []) {
                break;
            }
            foreach ($envelopes as $envelope) {
                $msg = $envelope->getMessage();
                if ($msg instanceof RunJob && in_array($msg->job, $known, true)
                    && !self::queuedOn(self::createJobsTransport($dbal, $msg->job), $msg->job)) {
                    self::createJobsTransport($dbal, $msg->job)->send(new Envelope(new RunJob($msg->job, $msg->manual)));
                    $moved++;
                }
                $legacy->ack($envelope);
            }
        }
        return $moved;
    }

    private static function queuedOn(DoctrineTransport $transport, string $job): bool
    {
        foreach ($transport->all(200) as $envelope) {
            $msg = $envelope->getMessage();
            if ($msg instanceof RunJob && $msg->job === $job) {
                return true;
            }
        }
        return false;
    }

    /** Lancia se il DB non risponde o manca una delle tabelle. */
    public static function probeTables(DbalConnection $dbal, array $tables): void
    {
        foreach ($tables as $table) {
            $dbal->executeQuery('SELECT 1 FROM ' . $dbal->quoteSingleIdentifier($table) . ' LIMIT 0')->free();
        }
    }

    /**
     * Lancia se manca una delle colonne (migrazione non ancora applicata).
     *
     * @param array<string, list<string>> $columns tabella => colonne
     */
    public static function probeColumns(DbalConnection $dbal, array $columns): void
    {
        foreach ($columns as $table => $cols) {
            $list = implode(', ', array_map(static fn(string $c): string => $dbal->quoteSingleIdentifier($c), $cols));
            $dbal->executeQuery('SELECT ' . $list . ' FROM ' . $dbal->quoteSingleIdentifier($table) . ' LIMIT 0')->free();
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
        $names = $this->scopedRegistry->names();
        $this->line($this->scope->label === 'worker'
            ? 'avvio worker (' . count($names) . ' lavori, tick ' . self::TICK_SECONDS . ' s)'
            : 'avvio processo (' . ($names !== [] ? 'lavori: ' . implode(', ', $names) : 'coda ' . self::MASSIVE_QUEUE) . ')');
        if (!$this->waitForDatabase()) {
            $this->line('arresto richiesto durante l\'attesa del DB.');
            return 0;
        }

        if ($this->scope->housekeeping) {
            $this->housekeepingAtStartup();
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

        $this->line($this->scope->label === 'worker' ? 'worker arrestato.' : 'processo arrestato.');
        return 0;
    }

    /**
     * Import una tantum dei file .autostart (in gil_jobs) e pulizia dello storico. Una sola volta
     * per avvio, in un solo processo (il supervisore, o il processo all()): mai in concorrenza.
     * Non lancia. In scope all() anche la coda legacy e' consumata direttamente: non serve drenarla.
     */
    public function housekeepingAtStartup(): void
    {
        try {
            $imported = (new AutostartImporter($this->state, $this->registry->names()))->importOnce($this->cacheDir);
            if ($imported > 0) {
                $this->line("import .autostart: {$imported} lavori abilitati");
            }
        } catch (\Throwable $e) {
            ($this->reportException)('worker', $e);
            $this->line('ERRORE import .autostart: ' . JobRunner::errorMessage($e));
        }

        if (!$this->scope->legacyQueue) {
            try {
                $moved = self::drainLegacyManualQueue($this->dbal, $this->registry->names());
                if ($moved > 0) {
                    $this->line("coda legacy '" . self::QUEUE . "': {$moved} esecuzioni manuali spostate sulle code per lavoro");
                }
            } catch (\Throwable $e) {
                ($this->reportException)('worker', $e);
                $this->line('ERRORE coda legacy: ' . JobRunner::errorMessage($e));
            }
        }

        $this->purgeHistoryIfDue();
    }

    /**
     * Elimina lo storico esecuzioni oltre HISTORY_RETENTION_DAYS: all'avvio e poi al massimo una
     * volta ogni HISTORY_PURGE_INTERVAL_SECONDS. Una riga di log solo se ha eliminato qualcosa.
     * Non lancia: un errore e' segnalato e riprovato alla pulizia successiva.
     */
    public function purgeHistoryIfDue(): void
    {
        $now = $this->clock->now();
        if ($this->lastHistoryPurge !== null
            && $now->getTimestamp() - $this->lastHistoryPurge->getTimestamp() < self::HISTORY_PURGE_INTERVAL_SECONDS) {
            return;
        }
        $this->lastHistoryPurge = $now;
        try {
            $deleted = $this->state->purgeRunsOlderThan(self::HISTORY_RETENTION_DAYS);
        } catch (\Throwable $e) {
            ($this->reportException)('worker', $e);
            $this->line('ERRORE pulizia storico esecuzioni: ' . JobRunner::errorMessage($e));
            return;
        }
        if ($deleted > 0) {
            $this->line(sprintf('pulizia storico: %d esecuzioni oltre %d giorni eliminate', $deleted, self::HISTORY_RETENTION_DAYS));
        }
    }

    private function buildWorker(): Worker
    {
        // Ordine rilevante: vedi docblock della classe (code manuali, poi massive, poi scheduler).
        $receivers = $this->manualTransports;
        if ($this->scope->massive) {
            $receivers[self::MASSIVE_QUEUE] = $this->massiveTransport;
        }
        if ($this->scopedRegistry->names() !== []) {
            $schedule = (new Schedule())
                ->add(RecurringMessage::every(self::TICK_SECONDS . ' seconds', new SchedulerTick(), $this->clock->now()))
                ->processOnlyLastMissedRun(true);
            $receivers['scheduler'] = new SchedulerTransport(new MessageGenerator($schedule, 'gil', $this->clock));
        }

        return new Worker(
            $receivers,
            $this->bus,
            $this->events,
            null,
            null,
            $this->clock
        );
    }

    private function line(string $msg): void
    {
        ($this->output)('[' . $this->clock->now()->format('Y-m-d H:i:s') . '] [' . $this->scope->label . '] ' . $msg);
    }
}
