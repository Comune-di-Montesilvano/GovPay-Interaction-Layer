<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Un solo batch per giro, poi sempre l'intervallo del lavoro interno (hadWork forzato a false).
 *
 * Per la rendicontazione GovPay: lo script attendeva scan_interval_minuti dopo OGNI ciclo, e
 * processate > 0 resta vero finche' esistono righe ERRORE nella finestra di retry (ritentate a
 * ogni ciclo). Con "hadWork -> subito" quelle righe verrebbero ritentate a raffica (GovPay,
 * ponte GERI, contatore digest) invece che ogni N minuti: cadenza invariata rispetto ad oggi.
 */
final class FixedCadenceJob implements PeriodicJob, CountsRemaining
{
    public function __construct(private readonly PeriodicJob $inner) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return $this->inner->emptyQueueIntervalSeconds();
    }

    public function remaining(): ?int
    {
        return $this->inner instanceof CountsRemaining ? $this->inner->remaining() : null;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $r = $this->inner->runOnce($ctx);
        return new JobResult($r->processed, $r->ok, $r->skipped, $r->errors, false, $r->errorDetails);
    }
}
