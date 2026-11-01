<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Database\DbalConnectionFactory;
use App\Database\JobStateRepository;
use App\Services\Jobs\JobContext;
use App\Services\Jobs\JobResult;
use App\Services\Jobs\JobRunner;
use App\Services\Jobs\PeriodicJob;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Tests\Support\DbTestCase;

/** Lavoro finto: ogni chiamata a runOnce esegue la closure successiva (l'ultima si ripete). */
final class FakePeriodicJob implements PeriodicJob
{
    public int $calls = 0;

    /** @param list<\Closure(int, JobContext): JobResult> $steps */
    public function __construct(private readonly array $steps, private readonly int $interval = 900) {}

    public function name(): string
    {
        return 'fake';
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return $this->interval;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $this->calls++;
        $step = $this->steps[min($this->calls, count($this->steps)) - 1];
        return $step($this->calls, $ctx);
    }
}

#[Group('db')]
final class JobRunnerTest extends DbTestCase
{
    protected static array $tables = ['gil_job_run_errors', 'gil_job_runs', 'gil_jobs', 'lock_keys'];

    private MockClock $clock;
    private JobStateRepository $repo;
    /** @var list<string> */
    private array $out = [];
    /** @var list<array{string, \Throwable}> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-28 10:00:00');
        $this->repo = new JobStateRepository(static fn(): \PDO => self::pdo(), $this->clock);
        $this->out = [];
        $this->reported = [];
    }

    private static function locks(): LockFactory
    {
        return new LockFactory(new DoctrineDbalStore(DbalConnectionFactory::create(), [], 0.0));
    }

    private function runner(?LockFactory $locks = null): JobRunner
    {
        return new JobRunner(
            $this->repo,
            $locks ?? self::locks(),
            $this->clock,
            function (string $line): void { $this->out[] = $line; },
            function (string $suite, \Throwable $e): void { $this->reported[] = [$suite, $e]; },
        );
    }

    private static function res(int $processed, bool $hadWork, int $errors = 0, array $details = []): JobResult
    {
        return new JobResult($processed, $processed - $errors, 0, $errors, $hadWork, $details);
    }

    /** @return list<array<string, mixed>> */
    private static function runs(): array
    {
        return self::pdo()->query('SELECT * FROM gil_job_runs ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private static function jobRow(string $name = 'fake'): array
    {
        $st = self::pdo()->prepare('SELECT * FROM gil_jobs WHERE name = ?');
        $st->execute([$name]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function testDisabledJobIsSkippedWithoutRunRow(): void
    {
        $job = new FakePeriodicJob([fn() => self::res(1, false)]);

        self::assertNull($this->runner()->run($job));
        self::assertSame(0, $job->calls);
        self::assertSame([], self::runs());
    }

    public function testEnabledJobRecordsRunWithCountersAndSchedulesInterval(): void
    {
        $this->repo->setEnabled('fake', true);
        $job = new FakePeriodicJob([fn() => new JobResult(5, 3, 1, 1, false, [['ref' => 'IUV1', 'reason' => 'boom']])], 600);

        $res = $this->runner()->run($job);

        self::assertNotNull($res);
        self::assertSame(5, $res->processed);
        self::assertSame(1, $job->calls);
        $runs = self::runs();
        self::assertCount(1, $runs);
        self::assertSame('fake', $runs[0]['job']);
        self::assertSame('ok', $runs[0]['status']);
        self::assertSame([5, 3, 1, 1, 1], [(int)$runs[0]['processed'], (int)$runs[0]['ok'], (int)$runs[0]['skipped'], (int)$runs[0]['errors'], (int)$runs[0]['batches']]);
        self::assertSame('2026-10-28 10:00:00', $runs[0]['started_at']);
        $errors = $this->repo->errorsForRun((int)$runs[0]['id']);
        self::assertSame([['ref' => 'IUV1', 'reason' => 'boom']], array_map(fn($r) => ['ref' => $r['ref'], 'reason' => $r['reason']], $errors));

        $row = self::jobRow();
        self::assertSame('ok', $row['last_status']);
        self::assertSame('2026-10-28 10:10:00', $row['next_run_at']);
        self::assertSame('2026-10-28 10:00:00', $row['last_started_at']);
        self::assertSame('2026-10-28 10:00:00', $row['last_finished_at']);

        self::assertCount(2, $this->out, 'una riga di riepilogo + una per errore');
        self::assertStringContainsString('processed=5', $this->out[0]);
        self::assertStringContainsString('IUV1', $this->out[1]);
        self::assertStringContainsString('boom', $this->out[1]);
    }

    /**
     * Fix round 1 (Task 11): un giro che non fa nulla per contropressione (risultato `silent`) non
     * lascia righe in gil_job_runs ne' righe su stdout, ma pianifica comunque il prossimo giro.
     */
    public function testSilentRunLeavesNoRunRowAndNoOutputButSchedulesNextRun(): void
    {
        $this->repo->setEnabled('fake', true);
        $job = new FakePeriodicJob([fn() => new JobResult(0, 0, 0, 0, false, [], true)], 30);

        $res = $this->runner()->run($job);

        self::assertNotNull($res);
        self::assertSame([], self::runs());
        self::assertSame([], $this->out);
        self::assertSame($this->clock->now()->modify('+30 seconds')->format('Y-m-d H:i:s'), self::jobRow()['next_run_at']);
    }

    /** Silenzioso solo se TUTTI i batch lo sono, e mai per un'esecuzione manuale. */
    public function testSilentBatchAfterWorkOrManualRunIsRecorded(): void
    {
        $this->repo->setEnabled('fake', true);
        $job = new FakePeriodicJob([fn() => self::res(3, true), fn() => new JobResult(0, 0, 0, 0, false, [], true)], 30);
        $this->runner()->run($job);
        self::assertCount(1, self::runs());
        self::assertSame(3, (int)self::runs()[0]['processed']);

        $silent = new FakePeriodicJob([fn() => new JobResult(0, 0, 0, 0, false, [], true)], 30);
        $this->runner()->run($silent, true);
        self::assertCount(2, self::runs(), 'manuale: sempre registrato');
        self::assertCount(2, $this->out);
    }

    public function testLoopsWhileHadWorkInOneRun(): void
    {
        $this->repo->setEnabled('fake', true);
        $job = new FakePeriodicJob([
            fn() => self::res(2, true),
            fn() => self::res(3, true),
            fn() => self::res(0, false),
        ]);

        $res = $this->runner()->run($job);

        self::assertSame(3, $job->calls);
        self::assertNotNull($res);
        self::assertSame(5, $res->processed);
        self::assertFalse($res->hadWork);
        $runs = self::runs();
        self::assertCount(1, $runs);
        self::assertSame(5, (int)$runs[0]['processed']);
        self::assertSame(3, (int)$runs[0]['batches']);
    }

    public function testDisablingDuringBatchStopsAfterThatBatch(): void
    {
        $this->repo->setEnabled('fake', true);
        $repo = $this->repo;
        $job = new FakePeriodicJob([
            fn() => self::res(1, true),
            function () use ($repo): JobResult {
                $repo->setEnabled('fake', false);
                return self::res(1, true);
            },
            fn() => self::res(1, true),
        ]);

        $this->runner()->run($job);

        self::assertSame(2, $job->calls);
        self::assertCount(1, self::runs());
    }

    public function testLockHeldByAnotherOwnerSkips(): void
    {
        $this->repo->setEnabled('fake', true);
        $other = self::locks()->createLock('job:fake', 900, false);
        self::assertTrue($other->acquire());
        try {
            $job = new FakePeriodicJob([fn() => self::res(1, false)]);
            self::assertNull($this->runner()->run($job));
            self::assertSame(0, $job->calls);
            self::assertSame([], self::runs());
        } finally {
            $other->release();
        }
    }

    public function testExpiredLockIsTakenOver(): void
    {
        $this->repo->setEnabled('fake', true);
        $other = self::locks()->createLock('job:fake', 900, false);
        self::assertTrue($other->acquire());
        // Owner morto (worker ucciso): la riga resta ma il TTL e' scaduto.
        self::pdo()->exec("UPDATE lock_keys SET key_expiration = UNIX_TIMESTAMP() - 1 WHERE key_id = 'job:fake'");

        $job = new FakePeriodicJob([fn() => self::res(1, false)]);
        self::assertNotNull($this->runner()->run($job));
        self::assertSame(1, $job->calls);
    }

    public function testLockReleasedAfterRunAndRefreshedBetweenBatches(): void
    {
        $this->repo->setEnabled('fake', true);
        $expirations = [];
        $probe = function () use (&$expirations): JobResult {
            $exp = self::pdo()->query("SELECT key_expiration - UNIX_TIMESTAMP() FROM lock_keys WHERE key_id = 'job:fake'")->fetchColumn();
            $expirations[] = (int)$exp;
            return self::res(1, count($expirations) < 2);
        };
        $job = new FakePeriodicJob([$probe]);

        $this->runner()->run($job);

        self::assertSame(2, $job->calls);
        foreach ($expirations as $ttl) {
            self::assertGreaterThan(800, $ttl, 'lock tenuto con TTL ~900 s durante i batch');
        }
        $free = self::locks()->createLock('job:fake', 10, false);
        self::assertTrue($free->acquire(), 'lock rilasciato a fine esecuzione');
        $free->release();
    }

    public function testExceptionRecordsErrorAndReportsToSentry(): void
    {
        $this->repo->setEnabled('fake', true);
        $job = new FakePeriodicJob([
            fn() => self::res(4, true),
            fn() => throw new \RuntimeException('GovPay non raggiungibile'),
        ], 300);

        $res = $this->runner()->run($job);

        self::assertNotNull($res);
        self::assertSame(2, $job->calls);
        $runs = self::runs();
        self::assertCount(1, $runs);
        self::assertSame('error', $runs[0]['status']);
        self::assertSame(4, (int)$runs[0]['processed']);
        self::assertStringContainsString('RuntimeException', (string)$runs[0]['error_message']);
        self::assertStringContainsString('GovPay non raggiungibile', (string)$runs[0]['error_message']);
        $row = self::jobRow();
        self::assertSame('error', $row['last_status']);
        self::assertSame('2026-10-28 10:05:00', $row['next_run_at'], 'nessun retry: prossimo giro a intervallo normale');
        self::assertCount(1, $this->reported);
        self::assertSame('cron-fake', $this->reported[0][0]);
        self::assertStringContainsString('RuntimeException', implode("\n", $this->out));
    }

    public function testErrorMessageOmitsHttpBodies(): void
    {
        $this->repo->setEnabled('fake', true);
        $req = new \GuzzleHttp\Psr7\Request('GET', 'https://govpay.example/pendenze?cf=RSSMRA80A01H501U');
        $resp = new \GuzzleHttp\Psr7\Response(500, [], '{"debitore":"RSSMRA80A01H501U"}');
        $job = new FakePeriodicJob([fn() => throw \GuzzleHttp\Exception\RequestException::create($req, $resp)]);

        $this->runner()->run($job);

        $msg = (string)self::runs()[0]['error_message'];
        self::assertStringContainsString('ServerException', $msg);
        self::assertStringContainsString('500', $msg);
        self::assertStringNotContainsString('RSSMRA80A01H501U', $msg);
        self::assertStringNotContainsString('RSSMRA80A01H501U', implode("\n", $this->out));
    }

    public function testManualRunIgnoresDisabledFlag(): void
    {
        $job = new FakePeriodicJob([fn() => self::res(1, true), fn() => self::res(1, false)]);

        $res = $this->runner()->run($job, true);

        self::assertNotNull($res);
        self::assertSame(2, $job->calls, 'il run manuale prosegue finche hadWork anche se disabilitato');
        $runs = self::runs();
        self::assertCount(1, $runs);
        self::assertSame(1, (int)$runs[0]['manual']);
        self::assertFalse($this->repo->isEnabled('fake'), 'il run manuale non abilita il lavoro');
    }

    public function testBatchCapStopsEndlessJobAndSchedulesImmediately(): void
    {
        $this->repo->setEnabled('fake', true);
        $job = new FakePeriodicJob([fn() => self::res(1, true)]);

        $res = $this->runner()->run($job);

        self::assertSame(JobRunner::MAX_BATCHES_PER_RUN, $job->calls);
        self::assertNotNull($res);
        self::assertTrue($res->hadWork);
        self::assertSame('2026-10-28 10:00:00', self::jobRow()['next_run_at'], 'prossimo giro subito: continua da dove era');
        $capLines = array_filter($this->out, fn($l) => str_contains($l, 'limite'));
        self::assertCount(1, $capLines, 'cap loggato una volta');
    }

    public function testDurationCapStopsLongRun(): void
    {
        $this->repo->setEnabled('fake', true);
        $clock = $this->clock;
        $job = new FakePeriodicJob([function () use ($clock): JobResult {
            $clock->modify('+10 minutes');
            return self::res(1, true);
        }]);

        $this->runner()->run($job);

        self::assertSame(3, $job->calls, '30 minuti = 3 batch da 10 minuti');
        self::assertSame('2026-10-28 10:30:00', self::jobRow()['next_run_at']);
    }

    public function testTopFiftyErrorsStored(): void
    {
        $this->repo->setEnabled('fake', true);
        $details = [];
        for ($i = 0; $i < 60; $i++) {
            $details[] = ['ref' => 'IUV' . $i, 'reason' => 'r' . $i];
        }
        $job = new FakePeriodicJob([fn() => new JobResult(60, 0, 0, 60, false, $details)]);

        $this->runner()->run($job);

        $run = self::runs()[0];
        self::assertSame(60, (int)$run['errors']);
        self::assertCount(50, $this->repo->errorsForRun((int)$run['id']));
    }

    public function testDueJobsAndRecentRuns(): void
    {
        $this->repo->setEnabled('a', true);
        $this->repo->setEnabled('b', true);
        $this->repo->setEnabled('c', false);
        $this->repo->markAutostartImported();
        self::pdo()->exec("UPDATE gil_jobs SET next_run_at = '2026-10-28 11:00:00' WHERE name = 'b'");

        self::assertSame(['a'], $this->repo->dueJobs(new \DateTimeImmutable('2026-10-28 10:00:00')));
        self::assertSame(['a', 'b'], $this->repo->dueJobs(new \DateTimeImmutable('2026-10-28 11:00:00')));
        self::assertTrue($this->repo->autostartImported());

        $job = new FakePeriodicJob([fn() => self::res(1, false)]);
        $this->repo->setEnabled('fake', true);
        $this->runner()->run($job);
        $this->clock->modify('+1 hour');
        $this->runner()->run($job);
        $recent = $this->repo->recentRuns('fake', 1);
        self::assertCount(1, $recent);
        self::assertSame('2026-10-28 11:00:00', $recent[0]['started_at']);
    }

    public function testPurgeRemovesRunsOlderThanThirtyDays(): void
    {
        $this->repo->setEnabled('fake', true);
        $job = new FakePeriodicJob([fn() => self::res(1, false, 1, [['ref' => 'X', 'reason' => 'y']])]);
        $this->clock->modify('-31 days');
        $this->runner()->run($job);
        $this->clock->modify('+31 days');
        $this->runner()->run($job);

        self::assertSame(1, $this->repo->purgeRunsOlderThan(30));

        $runs = self::runs();
        self::assertCount(1, $runs);
        self::assertSame('2026-10-28 10:00:00', $runs[0]['started_at']);
        self::assertSame(1, (int)self::pdo()->query('SELECT COUNT(*) FROM gil_job_run_errors')->fetchColumn());
    }

    private static function freshPdo(): \PDO
    {
        $p = \App\Database\Connection::params();
        return new \PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $p['host'], $p['port'], $p['dbname']),
            $p['user'],
            $p['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    public function testRepositoryResolvesPdoPerCallSoReconnectsAreSeen(): void
    {
        $old = self::freshPdo();
        $current = $old;
        $repo = new JobStateRepository(static function () use (&$current): \PDO {
            return $current;
        }, $this->clock);
        $repo->setEnabled('fake', true);
        self::assertTrue($repo->isEnabled('fake'));

        // Connessione caduta (riavvio db / wait_timeout): il provider ne restituisce una nuova.
        self::pdo()->exec('KILL ' . (int)$old->query('SELECT CONNECTION_ID()')->fetchColumn());
        $current = self::freshPdo();

        self::assertTrue($repo->isEnabled('fake'));
        $repo->setEnabled('fake', false);
        self::assertFalse($repo->isEnabled('fake'));
    }

    public function testLockStoreRecoversFromLostDbalConnection(): void
    {
        $this->repo->setEnabled('fake', true);
        $dbal = DbalConnectionFactory::create();
        $locks = new LockFactory(new DoctrineDbalStore($dbal, [], 0.0));
        $job = new FakePeriodicJob([fn() => self::res(1, false)]);
        self::assertNotNull($this->runner($locks)->run($job));

        self::pdo()->exec('KILL ' . (int)$dbal->fetchOne('SELECT CONNECTION_ID()'));

        $r2 = $this->runner($locks)->run($job);
        self::assertNotNull($r2, 'nuovo tentativo dopo ConnectionLost: ' . implode(' | ', $this->out) . ' ' . implode(' | ', array_map(fn($x) => get_class($x[1]) . ':' . $x[1]->getMessage() . ' prev=' . ($x[1]->getPrevious() ? get_class($x[1]->getPrevious()) : '-'), $this->reported)));
        self::assertSame(2, $job->calls);
        self::assertSame([], $this->reported);
    }

    public function testRecordRunFailureStillAdvancesNextRunAt(): void
    {
        $this->repo->setEnabled('fake', true);
        $job = new FakePeriodicJob([fn() => self::res(1, false)], 600);
        // storico non scrivibile in modo persistente: recordRun fallisce a ogni giro
        self::pdo()->exec('RENAME TABLE gil_job_runs TO gil_job_runs_bak');
        try {
            $this->runner()->run($job);
        } finally {
            self::pdo()->exec('RENAME TABLE gil_job_runs_bak TO gil_job_runs');
        }

        self::assertCount(1, $this->reported);
        // senza avanzamento il lavoro (GovPay/ponte) ripartirebbe a ogni tick da 5 s
        self::assertSame('2026-10-28 10:10:00', self::jobRow()['next_run_at']);
        self::assertSame([], $this->repo->dueJobs($this->clock->now()));
    }

    public function testStartErrorIsReportedOncePerStreakWithRecoveryLine(): void
    {
        $fail = true;
        $repo = new JobStateRepository(static function () use (&$fail): \PDO {
            if ($fail) {
                throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
            }
            return self::pdo();
        }, $this->clock);
        $runner = new JobRunner(
            $repo,
            self::locks(),
            $this->clock,
            function (string $line): void { $this->out[] = $line; },
            function (string $suite, \Throwable $e): void { $this->reported[] = [$suite, $e]; },
        );
        $job = new FakePeriodicJob([fn() => self::res(1, false)]);

        for ($i = 0; $i < 3; $i++) {
            self::assertNull($runner->run($job));
        }
        self::assertCount(1, $this->reported, 'una sola segnalazione Sentry per serie di errori');
        self::assertCount(1, $this->out);
        self::assertStringContainsString('ERRORE avvio', $this->out[0]);

        $fail = false;
        $this->repo->setEnabled('fake', true);
        self::assertNotNull($runner->run($job));
        self::assertStringContainsString('avvio ripristinato', $this->out[1]);

        // nuova serie dopo il ripristino: di nuovo segnalata una volta
        $fail = true;
        $runner->run($job);
        $runner->run($job);
        self::assertCount(2, $this->reported);
    }

    public function testSkipReasonDistinguishesLockFromStartError(): void
    {
        $this->repo->setEnabled('fake', true);
        $locks = self::locks();
        $held = $locks->createLock('job:fake', 60);
        self::assertTrue($held->acquire());
        $runner = $this->runner($locks);

        self::assertNull($runner->run(new FakePeriodicJob([fn() => self::res(1, false)]), true));
        self::assertSame(JobRunner::SKIP_LOCKED, $runner->lastSkipReason());
        $held->release();

        self::assertNotNull($runner->run(new FakePeriodicJob([fn() => self::res(1, false)]), true));
        self::assertNull($runner->lastSkipReason());
    }
}
