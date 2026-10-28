<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Database\MappingPendenzeRepository;
use App\Services\Jobs\FixedCadenceJob;
use App\Services\Jobs\IdleJob;
use App\Services\Jobs\JobCatalog;
use App\Services\Jobs\JobContext;
use App\Services\Jobs\JobRegistry;
use App\Services\Jobs\JobResult;
use App\Services\Jobs\LazyJob;
use App\Services\Jobs\MappingDiscoveryJob;
use App\Services\Jobs\MappingL1Job;
use App\Services\Jobs\PeriodicJob;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingJobContext;

/** Lavoro finto con nome e risultato configurabili. */
final class StubPeriodicJob implements PeriodicJob
{
    public int $calls = 0;

    /** @param \Closure(int): JobResult $result */
    public function __construct(
        private readonly string $n,
        private readonly \Closure $result,
        private readonly int $interval = 900
    ) {}

    public function name(): string
    {
        return $this->n;
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return $this->interval;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $this->calls++;
        return ($this->result)($this->calls);
    }
}

final class LazyJobTest extends TestCase
{
    public function testCatalogRegistersExactlyTheWorkerJobSlugs(): void
    {
        $reg = JobCatalog::registry();

        self::assertSame([
            'ragioneria', 'biz', 'tefa', 'mapping', 'mapping-discovery', 'vocab',
            'govpay-debitore', 'rendicontazione-govpay', 'pendenze-massive',
        ], $reg->names());
        self::assertSame(JobCatalog::NAMES, $reg->names());
        // get() non costruisce nulla (nessun DB/settings): il lavoro reale nasce al primo batch
        foreach ($reg->names() as $name) {
            $job = $reg->get($name);
            self::assertInstanceOf(LazyJob::class, $job);
            self::assertSame($name, $job->name());
        }
        self::assertSame(1800, $reg->get('ragioneria')->emptyQueueIntervalSeconds());
        self::assertSame(60, $reg->get('mapping-discovery')->emptyQueueIntervalSeconds());
        self::assertSame(900, $reg->get('biz')->emptyQueueIntervalSeconds());
    }

    public function testRegistryBuildsAFreshLazyJobPerGetAndFactoryRunsAtFirstBatchOnly(): void
    {
        $built = 0;
        $reg = new JobRegistry();
        $reg->register('x', 120, function () use (&$built): PeriodicJob {
            $built++;
            return new StubPeriodicJob('x', static fn(int $c) => new JobResult($c, $c, 0, 0, $c < 2), 300);
        });

        $a = $reg->get('x');
        $b = $reg->get('x');
        self::assertNotSame($a, $b);
        self::assertSame(0, $built);
        self::assertSame(120, $a->emptyQueueIntervalSeconds(), 'ripiego prima della costruzione');

        $ctx = new RecordingJobContext();
        $a->runOnce($ctx);
        $a->runOnce($ctx);
        self::assertSame(1, $built, 'una costruzione per esecuzione, non per batch');
        self::assertSame(300, $a->emptyQueueIntervalSeconds());

        $b->runOnce($ctx);
        self::assertSame(2, $built);
    }

    public function testFactoryBuildingAnotherJobIsRejected(): void
    {
        $job = new LazyJob('x', 60, static fn(): PeriodicJob => new IdleJob('y', 60, 'no'));
        $this->expectException(\LogicException::class);
        $job->runOnce(new RecordingJobContext());
    }

    public function testIdleJobHasNoWork(): void
    {
        $r = (new IdleJob('tefa', 900, 'TEFA non abilitato.'))->runOnce(new RecordingJobContext());
        self::assertFalse($r->hadWork);
        self::assertSame(0, $r->processed);
    }

    public function testFixedCadenceForcesHadWorkFalseKeepingCounters(): void
    {
        $inner = new StubPeriodicJob('rendicontazione-govpay', static fn() => new JobResult(4, 3, 0, 1, true, [['ref' => 'r', 'reason' => 'x']]), 600);
        $job = new FixedCadenceJob($inner);

        $r = $job->runOnce(new RecordingJobContext());

        self::assertFalse($r->hadWork);
        self::assertSame([4, 3, 0, 1], [$r->processed, $r->ok, $r->skipped, $r->errors]);
        self::assertCount(1, $r->errorDetails);
        self::assertSame('rendicontazione-govpay', $job->name());
        self::assertSame(600, $job->emptyQueueIntervalSeconds());
    }

    public function testMappingDiscoveryRunsDiagnosticsEvenIfDiscoveryFailsAndNeverHasWork(): void
    {
        $repo = $this->createMock(MappingPendenzeRepository::class);
        $repo->method('discoverPatterns')->willThrowException(new \RuntimeException('boom'));
        $repo->expects(self::once())->method('refreshPatternDiagnostics')->with('80000000001');
        $job = new MappingDiscoveryJob(new MappingL1Job($repo, '80000000001'), $repo, '80000000001');

        $r = $job->runOnce(new RecordingJobContext());

        self::assertFalse($r->hadWork);
        self::assertSame(1, $r->errors);
        self::assertSame('discovery', $r->errorDetails[0]['ref']);
        self::assertSame(60, $job->emptyQueueIntervalSeconds());
    }

    public function testMappingDiscoveryCountsDiscoveredPrefixes(): void
    {
        $repo = $this->createStub(MappingPendenzeRepository::class);
        $repo->method('discoverPatterns')->willReturn(7);
        $r = (new MappingDiscoveryJob(new MappingL1Job($repo, 'D'), $repo, 'D'))->runOnce(new RecordingJobContext());

        self::assertSame([7, 7, 0, false], [$r->processed, $r->ok, $r->errors, $r->hadWork]);
    }
}
