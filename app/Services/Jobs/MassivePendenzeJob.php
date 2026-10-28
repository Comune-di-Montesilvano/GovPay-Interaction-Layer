<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Adattatore periodico della coda pendenze massive (stesso giro di cron_pendenze_massive.php:
 * claimBatch + processRow per riga, batch BATCH_SIZE, IDLE_SECONDS a coda vuota). L'unica
 * istanza e' garantita dal lock `job:pendenze-massive` di JobRunner (prima: PID file).
 *
 * Transitorio: Task 11 lo sostituisce con un messaggio Messenger per riga + claim atomico.
 */
final class MassivePendenzeJob implements PeriodicJob
{
    public function __construct(private readonly MassivePendenzeProcessor $processor) {}

    public function name(): string
    {
        return 'pendenze-massive';
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return MassivePendenzeProcessor::IDLE_SECONDS;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        return $this->processor->runOnce($ctx);
    }
}
