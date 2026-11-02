<?php
declare(strict_types=1);

namespace App\Services\Jobs\Handler;

use App\Database\JobStateRepository;
use App\Services\Jobs\JobRegistry;
use App\Services\Jobs\JobRunner;
use App\Services\Jobs\Message\RunJob;
use App\Services\Jobs\Message\SchedulerTick;
use Psr\Clock\ClockInterface;

/**
 * Tick dello scheduler: un RunJob periodico per ogni lavoro scaduto, gestito subito (in
 * sequenza, nello stesso processo). Silenzioso quando non c'e' nulla da fare; un errore di
 * lettura (es. DB giu') produce una riga sola finche' non cambia, poi una riga al ripristino.
 */
final class SchedulerTickHandler
{
    private ?string $lastError = null;

    /**
     * @param \Closure(RunJob): void $dispatch
     * @param \Closure(): bool $stopRequested
     * @param \Closure(string): void $output
     */
    public function __construct(
        private readonly JobStateRepository $state,
        private readonly JobRegistry $registry,
        private readonly ClockInterface $clock,
        private readonly \Closure $dispatch,
        private readonly \Closure $stopRequested,
        private readonly \Closure $output
    ) {}

    public function __invoke(SchedulerTick $tick): void
    {
        try {
            $due = $this->state->dueJobs($this->clock->now());
        } catch (\Throwable $e) {
            $msg = JobRunner::errorMessage($e);
            if ($msg !== $this->lastError) {
                ($this->output)('ERRORE lettura lavori scaduti: ' . $msg);
                $this->lastError = $msg;
            }
            return;
        }
        if ($this->lastError !== null) {
            ($this->output)('lettura lavori scaduti ripristinata');
            $this->lastError = null;
        }

        foreach ($due as $name) {
            if (($this->stopRequested)()) {
                return;
            }
            // Righe di lavori non registrati (es. rimossi) ignorate in silenzio: niente log a ogni tick.
            if ($this->registry->has($name)) {
                ($this->dispatch)(new RunJob($name));
            }
        }
    }
}
