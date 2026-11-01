<?php
declare(strict_types=1);

namespace App\Services\Jobs\Handler;

use App\Database\JobStateRepository;
use App\Services\Jobs\JobRegistry;
use App\Services\Jobs\JobRunner;
use App\Services\Jobs\Message\RunJob;
use Psr\Clock\ClockInterface;

/**
 * Esegue RunJob con JobRunner (flag, lock, storico). Non lancia mai: max_retries=0, un giro
 * fallito e' gia' registrato da JobRunner e riparte all'intervallo normale.
 *
 * Periodico: eseguito solo se il lavoro e' ancora scaduto (un altro worker puo' averlo appena
 * eseguito, o un messaggio duplicato arriva dopo il giro). Manuale: sempre (JobRunner ignora il
 * flag di abilitazione, il lock evita comunque esecuzioni parallele).
 */
final class RunJobHandler
{
    /** @param \Closure(string): void $output */
    public function __construct(
        private readonly JobRegistry $registry,
        private readonly JobRunner $runner,
        private readonly JobStateRepository $state,
        private readonly ClockInterface $clock,
        private readonly \Closure $output
    ) {}

    public function __invoke(RunJob $message): void
    {
        $name = $message->job;
        if (!$this->registry->has($name)) {
            ($this->output)("lavoro sconosciuto ignorato: '" . mb_substr($name, 0, 64) . "'");
            return;
        }
        if (!$message->manual) {
            try {
                $due = in_array($name, $this->state->dueJobs($this->clock->now()), true);
            } catch (\Throwable $e) {
                // DB non disponibile: il tick successivo riprova (il tick logga il problema una volta).
                return;
            }
            if (!$due) {
                return;
            }
        }
        $this->runner->run($this->registry->get($name), $message->manual);
    }
}
