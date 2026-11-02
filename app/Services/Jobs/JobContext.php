<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/** Contesto di esecuzione di un job: segnale di stop e log di debug. */
interface JobContext
{
    public function shouldStop(): bool;

    public function debug(string $msg): void;
}
