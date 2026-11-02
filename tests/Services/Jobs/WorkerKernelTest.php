<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\DbalConnectionFactory;
use App\Database\JobStateRepository;
use App\Services\Jobs\IdleJob;
use App\Services\Jobs\JobCatalog;
use App\Services\Jobs\JobRegistry;
use App\Services\Jobs\JobResult;
use App\Services\Jobs\LazyJob;
use App\Services\Jobs\Message\SchedulerTick;
use App\Services\Jobs\PeriodicJob;
use App\Services\Jobs\TefaScanJob;
use App\Services\Jobs\Message\RunJob;
use App\Services\Jobs\WorkerKernel;
use App\Services\Jobs\WorkerScope;
use Symfony\Component\Messenger\Envelope;
use Doctrine\DBAL\Connection as DbalConnection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Tests\Support\DbTestCase;
use Tests\Support\StubPeriodicJob;

/**
 * Worker su DB usa-e-getta, lavori finti (nessuna chiamata esterna). Orologio MockClock: le
 * pause del Worker/attese DB avanzano il tempo finto invece di dormire.
 */
#[Group('db')]
final class WorkerKernelTest extends DbTestCase
{
    protected static array $tables = ['gil_job_run_errors', 'gil_job_runs', 'gil_jobs', 'lock_keys', 'messenger_messages', 'settings'];

    private MockClock $clock;
    private JobStateRepository $state;
    private DbalConnection $dbal;
    /** @var list<string> */
    private array $out = [];
    /** @var list<\Throwable> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        $this->clock = new MockClock('2026-10-28 10:00:00');
        $this->state = new JobStateRepository(static fn(): \PDO => self::pdo(), $this->clock);
        $this->dbal = DbalConnectionFactory::create();
        $this->out = [];
        $this->reported = [];
    }

    protected function tearDown(): void
    {
        SettingsRepository::flush();
        parent::tearDown();
    }

    /** @param (\Closure(): void)|null $probe */
    private function kernel(JobRegistry $registry, ?\Closure $probe = null, ?JobStateRepository $state = null): WorkerKernel
    {
        return new WorkerKernel(
            $registry,
            $state ?? $this->state,
            $this->dbal,
            $this->clock,
            function (string $line): void { $this->out[] = $line; },
            function (string $suite, \Throwable $e): void { $this->reported[] = $e; },
            $probe,
            new LockFactory(new DoctrineDbalStore($this->dbal, [], 0.0)),
            sys_get_temp_dir() . '/gil-worker-test-cache-assente'
        );
    }

    private static function job(string $name, bool $hadWork = false, ?\Closure $onRun = null): StubPeriodicJob
    {
        return new StubPeriodicJob($name, static function (int $call) use ($hadWork, $onRun): JobResult {
            if ($onRun !== null) {
                $onRun($call);
            }
            return new JobResult(1, 1, 0, 0, $hadWork);
        });
    }

    /** @return list<array<string, mixed>> */
    private static function runs(): array
    {
        return self::pdo()->query('SELECT * FROM gil_job_runs ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function queued(): int
    {
        return (int)self::pdo()->query('SELECT COUNT(*) FROM messenger_messages')->fetchColumn();
    }

    public function testTickRunsOnlyEnabledDueJobsAndIsSilentWhenNothingIsDue(): void
    {
        $due = self::job('due');
        $future = self::job('future');
        $disabled = self::job('disabled');
        $kernel = $this->kernel(new JobRegistry([$due, $future, $disabled]));
        $this->state->setEnabled('due', true);
        $this->state->setEnabled('future', true);
        self::pdo()->exec("UPDATE gil_jobs SET next_run_at = '2026-10-28 11:00:00' WHERE name = 'future'");
        // riga di un lavoro non registrato (es. rimosso): ignorata senza log
        $this->state->setEnabled('rimosso', true);

        $kernel->bus()->dispatch(new SchedulerTick());

        self::assertSame([1, 0, 0], [$due->calls, $future->calls, $disabled->calls]);
        self::assertCount(1, self::runs());
        self::assertSame('due', self::runs()[0]['job']);
        self::assertCount(1, $this->out, 'solo la riga di riepilogo del giro');
        self::assertStringContainsString('[cron-due] run #', $this->out[0]);

        // stesso istante: 'due' ripianificato a +900 s, nulla da fare -> nessuna riga
        $this->out = [];
        $kernel->bus()->dispatch(new SchedulerTick());
        self::assertSame(1, $due->calls);
        self::assertSame([], $this->out);

        $this->clock->modify('+901 seconds');
        $kernel->bus()->dispatch(new SchedulerTick());
        self::assertSame(2, $due->calls);
    }

    public function testTickReadErrorIsLoggedOnceUntilRecovered(): void
    {
        $fail = true;
        $state = new JobStateRepository(static function () use (&$fail): \PDO {
            if ($fail) {
                throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
            }
            return self::pdo();
        }, $this->clock);
        $kernel = $this->kernel(new JobRegistry([self::job('a')]), null, $state);

        $kernel->bus()->dispatch(new SchedulerTick());
        $kernel->bus()->dispatch(new SchedulerTick());
        $kernel->bus()->dispatch(new SchedulerTick());
        self::assertCount(1, $this->out);
        self::assertStringContainsString('ERRORE lettura lavori scaduti', $this->out[0]);

        $fail = false;
        $kernel->bus()->dispatch(new SchedulerTick());
        $kernel->bus()->dispatch(new SchedulerTick());
        self::assertCount(2, $this->out);
        self::assertStringContainsString('ripristinata', $this->out[1]);
    }

    public function testManualMessageFromQueueRunsDisabledJob(): void
    {
        $job = self::job('manuale');
        $kernel = $this->kernel(new JobRegistry([$job]));
        self::assertFalse($this->state->isEnabled('manuale'));

        WorkerKernel::enqueueManualRun($this->dbal, 'manuale');
        self::assertSame(1, self::queued());

        self::assertSame(0, $kernel->run(3.0));

        self::assertSame(1, $job->calls);
        $runs = self::runs();
        self::assertCount(1, $runs);
        self::assertSame(1, (int)$runs[0]['manual']);
        self::assertSame(0, self::queued(), 'messaggio confermato (ack) e rimosso');
        self::assertFalse($this->state->isEnabled('manuale'), 'il manuale non abilita il lavoro');
    }

    public function testWorkerSchedulerTickRunsDueJobs(): void
    {
        $job = self::job('periodico');
        $this->state->setEnabled('periodico', true);
        $kernel = $this->kernel(new JobRegistry([$job]));

        $kernel->run(12.0);

        // primo tick a +5 s: giro, poi prossimo a +900 s (nessun altro giro entro 12 s)
        self::assertSame(1, $job->calls);
        self::assertCount(1, self::runs());
    }

    private function insertRun(string $job, string $startedAt): void
    {
        $start = new \DateTimeImmutable($startedAt);
        $this->state->recordRun($job, $start, $start->modify('+1 second'), 1, new JobResult(1, 1, 0, 0, false), 'ok', null, [], $start->modify('+15 minutes'), false);
    }

    public function testStartupPurgesOldRunHistoryAndLogsOneLine(): void
    {
        $this->insertRun('vecchio', '2026-09-01 10:00:00');
        $this->insertRun('vecchio', '2026-09-02 10:00:00');
        $this->insertRun('recente', '2026-10-20 10:00:00');
        $kernel = $this->kernel(new JobRegistry());

        $kernel->run(2.0);

        self::assertSame(['recente'], array_column(self::runs(), 'job'));
        $purgeLines = array_values(array_filter($this->out, static fn(string $l): bool => str_contains($l, 'storico')));
        self::assertCount(1, $purgeLines);
        self::assertStringContainsString('2 esecuzioni', $purgeLines[0]);
    }

    public function testPurgeWritesNoLineWhenNothingDeleted(): void
    {
        $this->insertRun('recente', '2026-10-20 10:00:00');
        $kernel = $this->kernel(new JobRegistry());

        $kernel->run(2.0);

        self::assertCount(1, self::runs());
        self::assertSame([], array_values(array_filter($this->out, static fn(string $l): bool => str_contains($l, 'storico'))));
    }

    public function testPurgeRepeatsRoughlyEvery24Hours(): void
    {
        $kernel = $this->kernel(new JobRegistry());
        $kernel->purgeHistoryIfDue();
        $this->insertRun('vecchio', '2026-09-01 10:00:00');

        $this->clock->sleep(3600);
        $kernel->purgeHistoryIfDue();
        self::assertCount(1, self::runs(), 'non ancora trascorse 24 ore');

        $this->clock->sleep(23 * 3600);
        $kernel->purgeHistoryIfDue();
        self::assertCount(0, self::runs());
        self::assertCount(1, array_filter($this->out, static fn(string $l): bool => str_contains($l, 'storico')));
    }

    public function testStopSignalFinishesCurrentBatchAndExitsWorker(): void
    {
        $kernel = null;
        $job = self::job('lungo', true, static function () use (&$kernel): void {
            // SIGTERM durante il batch: il gestore pcntl chiama requestStop()
            $kernel->requestStop();
        });
        // 'zeta' viene dopo 'lungo' (dueJobs ordina per nome): non deve partire dopo lo stop
        $other = self::job('zeta');
        $this->state->setEnabled('zeta', true);
        $this->state->setEnabled('lungo', true);
        $kernel = $this->kernel(new JobRegistry([$job, $other]));

        $start = $this->clock->now();
        self::assertSame(0, $kernel->run(600.0));

        self::assertSame(1, $job->calls, 'hadWork=true ma nessun batch successivo dopo lo stop');
        $runs = self::runs();
        self::assertCount(1, $runs, 'nessun altro lavoro avviato dopo lo stop');
        self::assertSame('lungo', $runs[0]['job']);
        self::assertSame(1, (int)$runs[0]['batches']);
        self::assertSame('ok', $runs[0]['status']);
        self::assertSame(0, $other->calls);
        self::assertLessThan(60, $this->clock->now()->getTimestamp() - $start->getTimestamp(), 'uscita subito, non al time limit');
        self::assertStringContainsString('worker arrestato', end($this->out));
        self::assertTrue($kernel->isStopRequested());
    }

    public function testJobIsConstructedPerRunAndSeesSettingsChangedOnDb(): void
    {
        $built = 0;
        $registry = new JobRegistry();
        $registry->register('cfg', 900, function () use (&$built): PeriodicJob {
            $built++;
            $n = (int)SettingsRepository::get('test', 'batch', '1');
            return new StubPeriodicJob('cfg', static fn() => new JobResult($n, $n, 0, 0, false));
        });
        $this->state->setEnabled('cfg', true);
        SettingsRepository::set('test', 'batch', '1');
        $kernel = $this->kernel($registry);

        $first = $kernel->runner()->run($registry->get('cfg'));
        // modifica diretta su DB (come la UI di un altro processo): la cache del worker e' stale
        self::pdo()->exec("UPDATE settings SET value = '5' WHERE section = 'test' AND key_name = 'batch'");
        self::assertSame('1', SettingsRepository::get('test', 'batch'), 'premessa: cache stale');
        $second = $kernel->runner()->run($registry->get('cfg'));

        self::assertSame(1, $first?->processed);
        self::assertSame(5, $second?->processed);
        self::assertSame(2, $built);
    }

    public function testTefaGateIsEvaluatedPerRun(): void
    {
        SettingsRepository::set('entity', 'id_dominio', '80000000001');
        SettingsRepository::set('backoffice', 'tefa_enabled', 'false');
        $registry = JobCatalog::registry();

        $first = $registry->get('tefa');
        self::assertInstanceOf(LazyJob::class, $first);
        self::assertInstanceOf(IdleJob::class, $first->inner());

        self::pdo()->exec("UPDATE settings SET value = 'true' WHERE section = 'backoffice' AND key_name = 'tefa_enabled'");
        $second = $registry->get('tefa');
        self::assertInstanceOf(LazyJob::class, $second);
        self::assertInstanceOf(TefaScanJob::class, $second->inner());
    }

    public function testMissingDominioIsARunErrorNotACrash(): void
    {
        $registry = JobCatalog::registry();
        $this->state->setEnabled('vocab', true);
        $kernel = $this->kernel($registry);

        $kernel->bus()->dispatch(new SchedulerTick());

        $runs = self::runs();
        self::assertCount(1, $runs);
        self::assertSame('error', $runs[0]['status']);
        self::assertStringContainsString('id_dominio non configurato', (string)$runs[0]['error_message']);
        // prossimo giro all'intervallo normale: niente errore a ogni tick
        self::assertSame('2026-10-28 10:15:00', $this->state->state('vocab')['next_run_at']);
    }

    public function testWaitsForDatabaseRetryingEvery5sWithOneLinePerAttempt(): void
    {
        $attempts = 0;
        $kernel = $this->kernel(new JobRegistry(), function () use (&$attempts): void {
            if (++$attempts <= 3) {
                throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
            }
        });
        $start = $this->clock->now();

        self::assertTrue($kernel->waitForDatabase());

        self::assertSame(4, $attempts);
        self::assertSame(15, $this->clock->now()->getTimestamp() - $start->getTimestamp());
        self::assertCount(4, $this->out);
        foreach (array_slice($this->out, 0, 3) as $line) {
            self::assertStringContainsString('DB non pronto (PDOException', $line);
            self::assertStringContainsString('nuovo tentativo tra 5 s', $line);
            self::assertStringNotContainsString('#0 ', $line, 'nessuno stack trace');
        }
        self::assertStringContainsString('DB pronto', $this->out[3]);
    }

    public function testWaitForDatabaseNeverGivesUpButHonoursStop(): void
    {
        $attempts = 0;
        $kernel = null;
        $kernel = $this->kernel(new JobRegistry(), function () use (&$attempts, &$kernel): void {
            if (++$attempts === 200) {
                $kernel->requestStop();
            }
            throw new \RuntimeException('Table gil_jobs doesn\'t exist');
        });

        self::assertSame(0, $kernel->run());
        self::assertSame(200, $attempts, 'nessun limite di tentativi');
        self::assertStringContainsString('arresto richiesto', end($this->out));
    }

    public function testMissingTablesCountAsNotReady(): void
    {
        WorkerKernel::probeTables($this->dbal, WorkerKernel::REQUIRED_TABLES);

        $this->expectException(\Throwable::class);
        WorkerKernel::probeTables($this->dbal, ['gil_jobs', 'tabella_migrazione_mancante']);
    }

    /** Task 11: senza le colonne di claim (migrazione 037 non applicata) il worker attende. */
    public function testMissingClaimColumnsCountAsNotReady(): void
    {
        WorkerKernel::probeColumns($this->dbal, WorkerKernel::REQUIRED_COLUMNS);

        $this->expectException(\Throwable::class);
        WorkerKernel::probeColumns($this->dbal, ['pendenze_massive' => ['claimed_at', 'colonna_mancante']]);
    }

    public function testManualMessageIsNotStarvedByBusyScheduler(): void
    {
        $kernel = null;
        $enqueued = false;
        // backlog: hadWork sempre, ogni batch 60 s -> giri da 1800 s, sempre di nuovo scaduto,
        // tick persi a raffica (processOnlyLastMissedRun) dopo ogni giro
        $busy = new StubPeriodicJob('busy', function () use (&$enqueued): JobResult {
            $this->clock->sleep(60);
            if (!$enqueued) {
                $enqueued = true;
                WorkerKernel::enqueueManualRun($this->dbal, 'manuale');
            }
            return new JobResult(1, 1, 0, 0, true);
        });
        $manual = self::job('manuale', false, static function () use (&$kernel): void {
            $kernel->requestStop();
        });
        $this->state->setEnabled('busy', true);
        $kernel = $this->kernel(new JobRegistry([$busy, $manual]));

        $kernel->run(20000.0);

        $jobs = array_column(self::runs(), 'job');
        self::assertSame(['busy', 'manuale'], array_slice($jobs, 0, 2), 'il manuale parte subito dopo il giro in corso');
        self::assertSame(1, $manual->calls);
    }

    public function testSkippedManualRunIsLoggedWithReason(): void
    {
        $job = self::job('occupato');
        $locks = new LockFactory(new DoctrineDbalStore($this->dbal, [], 0.0));
        $held = (new LockFactory(new DoctrineDbalStore(DbalConnectionFactory::create(), [], 0.0)))->createLock('job:occupato', 60);
        self::assertTrue($held->acquire());
        $kernel = new WorkerKernel(
            new JobRegistry([$job]),
            $this->state,
            $this->dbal,
            $this->clock,
            function (string $line): void { $this->out[] = $line; },
            function (string $suite, \Throwable $e): void { $this->reported[] = $e; },
            null,
            $locks,
            sys_get_temp_dir() . '/gil-worker-test-cache-assente'
        );
        try {
            $kernel->bus()->dispatch(new \App\Services\Jobs\Message\RunJob('occupato', true));
        } finally {
            $held->release();
        }

        self::assertSame(0, $job->calls);
        self::assertCount(1, $this->out);
        self::assertStringContainsString("esecuzione manuale di 'occupato' ignorata", $this->out[0]);
        self::assertStringContainsString('lock occupato', $this->out[0]);
    }

    private function scoped(JobRegistry $registry, WorkerScope $scope): WorkerKernel
    {
        return new WorkerKernel(
            $registry,
            $this->state,
            $this->dbal,
            $this->clock,
            function (string $line): void { $this->out[] = $line; },
            function (string $suite, \Throwable $e): void { $this->reported[] = $e; },
            null,
            new LockFactory(new DoctrineDbalStore($this->dbal, [], 0.0)),
            sys_get_temp_dir() . '/gil-worker-test-cache-assente',
            null,
            null,
            $scope
        );
    }

    /** @return list<string> */
    private static function queueNames(): array
    {
        return self::pdo()->query('SELECT queue_name FROM messenger_messages ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Worker parallelo: "esegui ora" va sulla coda del lavoro e la consuma solo il processo del
     * suo gruppo; il processo di un altro gruppo non la tocca (ne' esegue quel lavoro).
     */
    public function testManualRunIsRoutedToTheJobsOwnProcess(): void
    {
        $a = self::job('aaa');
        $b = self::job('bbb');
        $registry = new JobRegistry([$a, $b]);
        WorkerKernel::enqueueManualRun($this->dbal, 'aaa');
        WorkerKernel::enqueueManualRun($this->dbal, 'bbb');
        self::assertSame(['jobs.aaa', 'jobs.bbb'], self::queueNames());
        self::assertTrue(WorkerKernel::manualRunQueued($this->dbal, 'aaa'));

        $this->scoped($registry, WorkerScope::jobGroup('aaa', ['aaa']))->run(3.0);

        self::assertSame([1, 0], [$a->calls, $b->calls]);
        self::assertSame(['jobs.bbb'], self::queueNames());
        self::assertFalse(WorkerKernel::manualRunQueued($this->dbal, 'aaa'));
        self::assertTrue(WorkerKernel::manualRunQueued($this->dbal, 'bbb'));
        self::assertStringContainsString('[worker:aaa] avvio processo (lavori: aaa)', $this->out[0]);
        self::assertStringContainsString('[worker:aaa] processo arrestato.', end($this->out));

        $this->scoped($registry, WorkerScope::jobGroup('bbb', ['bbb']))->run(3.0);
        self::assertSame([1, 1], [$a->calls, $b->calls]);
        self::assertSame([], self::queueNames());
    }

    public function testJobProcessSchedulesOnlyItsGroup(): void
    {
        $mine = self::job('mio');
        $companion = self::job('mio-discovery');
        $other = self::job('altro');
        foreach (['mio', 'mio-discovery', 'altro'] as $n) {
            $this->state->setEnabled($n, true);
        }

        $this->scoped(new JobRegistry([$mine, $companion, $other]), WorkerScope::jobGroup('mio', ['mio', 'mio-discovery']))->run(12.0);

        self::assertSame([1, 1, 0], [$mine->calls, $companion->calls, $other->calls]);
        self::assertNull($this->state->state('altro')['next_run_at'], 'lavoro di un altro gruppo intatto');
    }

    /** Coda legacy `jobs` (worker sequenziale): spostata sulle code per lavoro, senza duplicati. */
    public function testLegacyManualQueueIsDrainedToPerJobQueues(): void
    {
        $legacy = WorkerKernel::createJobsTransport($this->dbal);
        $legacy->send(new Envelope(new RunJob('biz', true)));
        $legacy->send(new Envelope(new RunJob('tefa', true)));
        $legacy->send(new Envelope(new RunJob('rimosso', true)));
        WorkerKernel::enqueueManualRun($this->dbal, 'tefa'); // gia' in coda per lavoro

        $moved = WorkerKernel::drainLegacyManualQueue($this->dbal, ['biz', 'tefa']);

        self::assertSame(1, $moved);
        self::assertSame(['jobs.tefa', 'jobs.biz'], self::queueNames());
    }

    public function testSupervisorHousekeepingImportsAutostartAndDrainsLegacyQueueOnce(): void
    {
        $cache = sys_get_temp_dir() . '/gil-worker-test-cache-' . bin2hex(random_bytes(4));
        mkdir($cache);
        touch($cache . '/daemon-biz.autostart');
        WorkerKernel::createJobsTransport($this->dbal)->send(new Envelope(new RunJob('biz', true)));
        try {
            $kernel = new WorkerKernel(
                new JobRegistry([self::job('biz')]),
                $this->state,
                $this->dbal,
                $this->clock,
                function (string $line): void { $this->out[] = $line; },
                function (string $suite, \Throwable $e): void { $this->reported[] = $e; },
                null,
                null,
                $cache,
                null,
                null,
                WorkerScope::housekeeping()
            );
            $kernel->housekeepingAtStartup();
        } finally {
            @unlink($cache . '/daemon-biz.autostart');
            @rmdir($cache);
        }

        self::assertTrue($this->state->isEnabled('biz'));
        self::assertTrue($this->state->autostartImported());
        self::assertSame(['jobs.biz'], self::queueNames());
        self::assertStringContainsString('1 esecuzioni manuali spostate', implode("
", $this->out));
    }

    public function testAllScopeStillConsumesTheLegacyQueue(): void
    {
        $job = self::job('vecchio');
        WorkerKernel::createJobsTransport($this->dbal)->send(new Envelope(new RunJob('vecchio', true)));

        $this->kernel(new JobRegistry([$job]))->run(3.0);

        self::assertSame(1, $job->calls);
        self::assertSame([], self::queueNames());
    }
}
