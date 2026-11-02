<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Esito di una singola iterazione (runOnce) di un job cron.
 *
 * errorDetails: lista di ['ref' => string, 'reason' => string]; ref = IUV/IUR/id,
 * mai dati personali (CF, nominativi).
 *
 * silent: giro "a vuoto" senza nulla da registrare (es. contropressione): se tutti i batch di
 * un'esecuzione non manuale sono silent, JobRunner non scrive gil_job_runs ne' stdout e aggiorna
 * solo il prossimo giro.
 */
final class JobResult
{
    /**
     * @param list<array{ref:string,reason:string}> $errorDetails
     */
    public function __construct(
        public readonly int $processed,
        public readonly int $ok,
        public readonly int $skipped,
        public readonly int $errors,
        public readonly bool $hadWork,
        public readonly array $errorDetails = [],
        public readonly bool $silent = false
    ) {}
}
