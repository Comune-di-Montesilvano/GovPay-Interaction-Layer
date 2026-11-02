<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Database\DbalConnectionFactory;
use App\Database\JobStateRepository;
use App\Services\Jobs\CountsRemaining;
use App\Services\Jobs\JobContext;
use App\Services\Jobs\JobResult;
use App\Services\Jobs\JobRunner;
use App\Services\Jobs\PeriodicJob;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Tests\Support\DbTestCase;
use Tests\Support\StubPeriodicJob;

/** Lavoro con coda finta: ogni batch elabora fino a $batch elementi dalla coda $queue. */
final class QueueJob implements PeriodicJob, CountsRemaining
{
    public int $calls = 0;
    /** @var (\Closure(int): void)|null */
    public ?\Closure $onBatch = null;
    /** @var list<?int> conteggi restituiti da remaining() */
    public array $counted = [];

    public function __construct(private readonly string $n, public int $queue, private readonly int $batch = 100, public int $errorsPerBatch = 0) {}

    public function name(): string
    {
        return $this->n;
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return 900;
    }

    public function remaining(): ?int
    {
        $this->counted[] = $this->queue;
        return $this->queue;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $this->calls++;
        if ($this->onBatch !== null) {
            ($this->onBatch)($this->calls);
        }
        $n = min($this->batch, $this->queue);
        $this->queue -= $n;
        $err = min($this->errorsPerBatch, $n);
        return new JobResult($n, $n - $err, 0, $err, $this->queue > 0);
    }
}

/**
 * Storico "live" (riga running aggiornata per batch), avanzamento fatto/totale in stdout e su DB,
 * recupero delle righe running lasciate da un processo ucciso, due lavori diversi in parallelo.
 */
#[Group('db')]
final class JobRunnerProgressTest extends DbTestCase
{
    protected static array $tables = ['gil_job_run_errors', 'gil_job_runs', 'gil_jobs', 'lock_keys'];

    private MockClock $clock;
    private JobStateRepository $repo;
    /** @var list<string> */
    private array $out = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-28 10:00:00');
        $this->repo = new JobStateRepository(static fn(): \PDO => self::pdo(), $this->clock);
        $this->out = [];
    }

    private function runner(): JobRunner
    {
        return new JobRunner(
            $this->repo,
            new LockFactory(new DoctrineDbalStore(DbalConnectionFactory::create(), [], 0.0)),
            $this->clock,
            function (string $line): void { $this->out[] = $line; },
            static function (string $suite, \Throwable $e): void {},
        );
    }

    /** @return list<array<string, mixed>> */
    private static function runs(): array
    {
        return self::pdo()->query('SELECT * FROM gil_job_runs ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testRunningRowIsCreatedAtStartAndUpdatedAfterEachBatch(): void
    {
        $this->repo->setEnabled('coda', true);
        $job = new QueueJob('coda', 250, 100, 2);
        $seen = [];
        $job->onBatch = function (int $call) use (&$seen): void {
            $this->clock->sleep(10);
            $seen[$call] = self::runs();
        };

        $this->runner()->run($job);

        // durante il primo batch la riga c'e' gia', 'running', con il totale
        self::assertCount(1, $seen[1]);
        self::assertSame('running', $seen[1][0]['status']);
        self::assertNull($seen[1][0]['finished_at']);
        self::assertSame([250, 250, 0], [(int)$seen[1][0]['total_items'], (int)$seen[1][0]['remaining_items'], (int)$seen[1][0]['batches']]);
        // durante il terzo batch: contatori dei primi due
        self::assertSame('running', $seen[3][0]['status']);
        self::assertSame([2, 200, 4, 50], [(int)$seen[3][0]['batches'], (int)$seen[3][0]['processed'], (int)$seen[3][0]['errors'], (int)$seen[3][0]['remaining_items']]);

        $runs = self::runs();
        self::assertCount(1, $runs, 'stessa riga chiusa, nessuna seconda riga');
        self::assertSame($seen[1][0]['id'], $runs[0]['id']);
        self::assertSame('ok', $runs[0]['status']);
        self::assertSame('2026-10-28 10:00:30', $runs[0]['finished_at']);
        self::assertSame([3, 250, 6, 250, 0], [(int)$runs[0]['batches'], (int)$runs[0]['processed'], (int)$runs[0]['errors'], (int)$runs[0]['total_items'], (int)$runs[0]['remaining_items']]);
    }

    public function testStdoutShowsStartProgressAndSummaryWithoutPii(): void
    {
        $this->repo->setEnabled('biz', true);
        $job = new QueueJob('biz', 5400, 100, 2);

        $this->runner()->run($job);

        self::assertStringContainsString('[cron-biz-scanner] giro avviato: 5400 da elaborare', $this->out[0]);
        self::assertStringContainsString('batch 12: +100 (ok 98, saltati 0, errori 2) — elaborate 1200/5400, restanti 4200', $this->out[12]);
        $summary = array_values(array_filter($this->out, static fn(string $l): bool => str_contains($l, 'run #')));
        self::assertCount(1, $summary);
        self::assertStringContainsString('processed=5400', $summary[0]);
        self::assertStringContainsString('restanti=0', $summary[0]);
        self::assertStringContainsString('durata=', $summary[0]);
        // 1 avvio + 53 batch che proseguono (l'ultimo e' nel riepilogo) + riepilogo + "(+108 errori non elencati)"
        self::assertCount(56, $this->out);
        self::assertStringContainsString('(+108 errori non elencati)', end($this->out));
    }

    public function testTotalGrowsWhenTheQueueGrowsDuringTheRun(): void
    {
        $this->repo->setEnabled('coda', true);
        $job = new QueueJob('coda', 200, 100);
        $job->onBatch = static function (int $call) use ($job): void {
            if ($call === 1) {
                $job->queue += 50; // nuovi elementi arrivati durante il giro
            }
        };

        $this->runner()->run($job);

        self::assertStringContainsString('elaborate 100/250, restanti 150', $this->out[1]);
        self::assertSame(250, (int)self::runs()[0]['total_items']);
    }

    public function testKnownEmptyQueueOpensNoRunningRowAndPrintsNoStartLine(): void
    {
        $this->repo->setEnabled('coda', true);
        $seen = null;
        $job = new QueueJob('coda', 0);
        $job->onBatch = static function () use (&$seen): void {
            $seen = (int)self::pdo()->query('SELECT COUNT(*) FROM gil_job_runs')->fetchColumn();
        };

        $this->runner()->run($job);

        self::assertSame(0, $seen, 'coda vuota nota: nessuna riga running durante il batch');
        // batch non silenzioso (come i lavori reali a coda vuota): riga storico a fine giro, come prima
        self::assertSame(['ok'], array_column(self::runs(), 'status'));
        self::assertCount(1, $this->out, 'solo il riepilogo, nessuna riga di avvio');
        self::assertStringContainsString('run #', $this->out[0]);
    }

    public function testSilentRunDeletesItsRunningRow(): void
    {
        $this->repo->setEnabled('silenzioso', true);
        $seen = null;
        $job = new StubPeriodicJob('silenzioso', static function () use (&$seen): JobResult {
            $seen = (int)self::pdo()->query("SELECT COUNT(*) FROM gil_job_runs WHERE status = 'running'")->fetchColumn();
            return new JobResult(0, 0, 0, 0, false, [], true);
        }, 30);

        $this->runner()->run($job);

        self::assertSame(1, $seen, 'coda non misurabile: riga running durante il giro');
        self::assertSame([], self::runs(), 'giro silenzioso: riga eliminata');
        self::assertSame([], $this->out);
    }

    public function testStaleRunningRowOfAKilledProcessIsClosedAsInterrupted(): void
    {
        $this->repo->setEnabled('coda', true);
        // processo ucciso a meta' giro: riga running orfana (il lock e' scaduto)
        $orphan = $this->repo->startRun('coda', new \DateTimeImmutable('2026-10-28 09:00:00'), false, 500);
        $other = $this->repo->startRun('altro', new \DateTimeImmutable('2026-10-28 09:00:00'), false, 10);

        $this->runner()->run(new QueueJob('coda', 0));

        $byId = array_column(self::runs(), null, 'id');
        self::assertSame('error', $byId[$orphan]['status']);
        self::assertSame(JobStateRepository::INTERRUPTED_MESSAGE, $byId[$orphan]['error_message']);
        self::assertSame('2026-10-28 10:00:00', $byId[$orphan]['finished_at']);
        self::assertSame('running', $byId[$other]['status'], 'righe di altri lavori intatte');
        self::assertStringContainsString('giro precedente interrotto', $this->out[0]);
    }

    public function testTwoDifferentJobsRunConcurrentlyWithTheirOwnLocks(): void
    {
        $this->repo->setEnabled('lento', true);
        $this->repo->setEnabled('veloce', true);
        $slow = new QueueJob('lento', 300, 100);
        $fast = new QueueJob('veloce', 50, 100);
        $runnerB = $this->runner();
        $fastResult = null;
        // mentre 'lento' gira (lock job:lento tenuto) parte 'veloce' in un altro runner/processo
        $slow->onBatch = function (int $call) use ($runnerB, $fast, &$fastResult): void {
            if ($call === 2) {
                self::assertSame(['lento'], $this->repo->runningJobs());
                $fastResult = $runnerB->run($fast);
            }
        };

        $slowResult = $this->runner()->run($slow);

        self::assertNotNull($slowResult);
        self::assertNotNull($fastResult, 'lock diversi: il secondo lavoro non aspetta il primo');
        self::assertSame(300, $slowResult->processed);
        self::assertSame(50, $fastResult->processed);
        $runs = self::runs();
        self::assertSame(['lento', 'veloce'], array_column($runs, 'job'));
        self::assertSame(['ok', 'ok'], array_column($runs, 'status'));
    }

    public function testSameJobIsNeverRunTwiceConcurrently(): void
    {
        $this->repo->setEnabled('coda', true);
        $job = new QueueJob('coda', 200, 100);
        $runnerB = $this->runner();
        $second = 'non chiamato';
        $job->onBatch = static function (int $call) use ($runnerB, &$second): void {
            if ($call === 1) {
                $second = $runnerB->run(new QueueJob('coda', 10));
            }
        };

        $this->runner()->run($job);

        self::assertNull($second);
        self::assertSame(JobRunner::SKIP_LOCKED, $runnerB->lastSkipReason());
    }
}
