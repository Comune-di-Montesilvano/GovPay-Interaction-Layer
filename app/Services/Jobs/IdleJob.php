<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Lavoro con gate chiuso per questo giro (es. TEFA non abilitato, Biz Events non configurato):
 * nessun lavoro, prossimo giro all'intervallo normale. Sostituisce l'"Uscita." (exit 0) degli
 * script, che con il worker diventerebbe un giro saltato in silenzio: il motivo resta leggibile
 * da reason() (UI/test), stdout riporta solo la riga di riepilogo di JobRunner.
 */
final class IdleJob implements PeriodicJob
{
    public function __construct(
        private readonly string $name,
        private readonly int $intervalSeconds,
        private readonly string $reason
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $ctx->debug($this->reason);
        return new JobResult(0, 0, 0, 0, false);
    }
}
