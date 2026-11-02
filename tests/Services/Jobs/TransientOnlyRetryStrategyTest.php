<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Services\Jobs\Message\ProcessMassiveRow;
use App\Services\Jobs\Messenger\TransientOnlyRetryStrategy;
use App\Services\Jobs\TransientMassiveFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/** Retry della coda `massive`: solo transitori, 3 retry, 30 s x2 (30/60/120 s), senza jitter. */
final class TransientOnlyRetryStrategyTest extends TestCase
{
    private static function envelope(int $retries): Envelope
    {
        $e = new Envelope(new ProcessMassiveRow(1, str_repeat('a', 32)));
        return $retries > 0 ? $e->with(new RedeliveryStamp($retries)) : $e;
    }

    private static function failed(\Throwable $e): HandlerFailedException
    {
        return new HandlerFailedException(self::envelope(0), [$e]);
    }

    public function testTransientIsRetriedThreeTimesWith30sTimes2Backoff(): void
    {
        $s = new TransientOnlyRetryStrategy();
        $err = self::failed(new TransientMassiveFailure('HTTP 503'));

        $delays = [];
        for ($n = 0; $n < 3; $n++) {
            self::assertTrue($s->isRetryable(self::envelope($n), $err), "retry #" . ($n + 1));
            $delays[] = $s->getWaitingTime(self::envelope($n), $err);
        }
        self::assertSame([30000, 60000, 120000], $delays);
        self::assertFalse($s->isRetryable(self::envelope(3), $err), 'dopo 3 retry: stato di errore');
    }

    public function testNonTransientErrorsAreNeverRetried(): void
    {
        $s = new TransientOnlyRetryStrategy();
        self::assertFalse($s->isRetryable(self::envelope(0), self::failed(new \RuntimeException('bug'))));
        self::assertFalse($s->isRetryable(self::envelope(0), self::failed(new \PDOException('db'))));
        self::assertFalse($s->isRetryable(self::envelope(0), null));
    }

    public function testTransientFailureDoesNotForceUnboundedRetries(): void
    {
        self::assertFalse((new TransientMassiveFailure('x'))->forceRetry());
        self::assertNull((new TransientMassiveFailure('x'))->getRetryDelay());
    }

    public function testUnwrapReturnsHandlerException(): void
    {
        $inner = new \LogicException('x');
        self::assertSame($inner, TransientOnlyRetryStrategy::unwrap(self::failed($inner)));
        self::assertSame($inner, TransientOnlyRetryStrategy::unwrap($inner));
    }
}
