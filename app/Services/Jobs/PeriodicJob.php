<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Lavoro periodico eseguito da JobRunner (ex demone cron).
 *
 * runOnce esegue UN batch; JobRunner lo richiama subito finche' JobResult::$hadWork e' true,
 * poi pianifica il prossimo giro a now + emptyQueueIntervalSeconds().
 */
interface PeriodicJob
{
    /** Nome stabile (chiave in gil_jobs, lock `job:<name>`, tag Sentry `cron-<name>`), max 64 char. */
    public function name(): string;

    /** Attesa prima del prossimo giro quando l'ultimo batch non ha trovato lavoro utile. */
    public function emptyQueueIntervalSeconds(): int;

    public function runOnce(JobContext $ctx): JobResult;
}
