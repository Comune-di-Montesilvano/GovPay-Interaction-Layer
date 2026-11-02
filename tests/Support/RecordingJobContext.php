<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Services\Jobs\JobContext;

/** JobContext per i test: registra i messaggi di debug e permette di simulare lo stop. */
final class RecordingJobContext implements JobContext
{
    /** @var list<string> */
    public array $messages = [];

    public bool $stop = false;

    public function shouldStop(): bool
    {
        return $this->stop;
    }

    public function debug(string $msg): void
    {
        $this->messages[] = $msg;
    }
}
