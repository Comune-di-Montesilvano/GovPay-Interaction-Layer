<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Database\Connection;
use PDOException;
use PHPUnit\Framework\TestCase;

final class ConnectionRetryOnDeadlockTest extends TestCase
{
    private function makeDeadlockException(): PDOException
    {
        $e = new PDOException("SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction");
        $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
        return $e;
    }

    public function testSucceedsImmediatelyWithoutRetry(): void
    {
        $calls = 0;
        $result = Connection::retryOnDeadlock(function () use (&$calls) {
            $calls++;
            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function testRetriesOnDeadlockThenSucceeds(): void
    {
        $calls = 0;
        $result = Connection::retryOnDeadlock(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw $this->makeDeadlockException();
            }
            return 'ok';
        }, 5);

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function testGivesUpAfterMaxAttemptsAndRethrows(): void
    {
        $calls = 0;

        $this->expectException(PDOException::class);
        try {
            Connection::retryOnDeadlock(function () use (&$calls) {
                $calls++;
                throw $this->makeDeadlockException();
            }, 3);
        } finally {
            $this->assertSame(3, $calls);
        }
    }

    public function testNonDeadlockExceptionIsNotRetried(): void
    {
        $calls = 0;
        $other = new PDOException('SQLSTATE[42S02]: Base table or view not found');
        $other->errorInfo = ['42S02', 1146, "Table 'x' doesn't exist"];

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage("Base table or view not found");
        try {
            Connection::retryOnDeadlock(function () use (&$calls, $other) {
                $calls++;
                throw $other;
            }, 5);
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testLockWaitTimeoutIsAlsoRetried(): void
    {
        $calls = 0;
        $e = new PDOException("SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded");
        $e->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

        $result = Connection::retryOnDeadlock(function () use (&$calls, $e) {
            $calls++;
            if ($calls < 2) {
                throw $e;
            }
            return 'ok';
        }, 5);

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }
}
