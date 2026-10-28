<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Esito di una singola iterazione (runOnce) di un job cron.
 *
 * errorDetails: lista di ['ref' => string, 'reason' => string]; ref = IUV/IUR/id,
 * mai dati personali (CF, nominativi).
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
        public readonly array $errorDetails = []
    ) {}
}
