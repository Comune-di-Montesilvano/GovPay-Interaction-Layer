<?php
declare(strict_types=1);

namespace App\Services\Jobs\Message;

/**
 * Esegui un giro del lavoro periodico $job.
 *
 * Periodico (manual=false): emesso dal tick dello scheduler per i lavori scaduti e gestito subito
 * nello stesso processo. Manuale ("esegui ora" da UI): accodato sul trasporto Doctrine `jobs`,
 * ignora il flag di abilitazione (JobRunner).
 */
final class RunJob
{
    public function __construct(public readonly string $job, public readonly bool $manual = false) {}
}
