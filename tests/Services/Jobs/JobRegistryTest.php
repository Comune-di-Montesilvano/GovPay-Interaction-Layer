<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Services\Jobs\JobContext;
use App\Services\Jobs\JobRegistry;
use App\Services\Jobs\JobResult;
use App\Services\Jobs\PeriodicJob;
use PHPUnit\Framework\TestCase;

final class JobRegistryTest extends TestCase
{
    private static function job(string $name): PeriodicJob
    {
        return new class ($name) implements PeriodicJob {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function emptyQueueIntervalSeconds(): int
            {
                return 900;
            }

            public function runOnce(JobContext $ctx): JobResult
            {
                return new JobResult(0, 0, 0, 0, false);
            }
        };
    }

    public function testRegistersAndLooksUpByName(): void
    {
        $a = self::job('biz');
        $reg = new JobRegistry([$a, self::job('tefa')]);

        self::assertSame(['biz', 'tefa'], $reg->names());
        self::assertSame($a, $reg->get('biz'));
        self::assertTrue($reg->has('tefa'));
        self::assertFalse($reg->has('nope'));
    }

    public function testRejectsDuplicatesAndReservedNames(): void
    {
        $reg = new JobRegistry([self::job('biz')]);
        try {
            $reg->add(self::job('biz'));
            self::fail('duplicato accettato');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $reg->add(self::job('__import__'));
    }

    public function testUnknownJobThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        (new JobRegistry())->get('nope');
    }
}
