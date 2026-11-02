<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Services\Jobs\JobContext;
use App\Services\Jobs\JobResult;
use App\Services\Jobs\PeriodicJob;

/** Lavoro finto con nome, risultato (per numero di chiamata) e intervallo configurabili. */
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
