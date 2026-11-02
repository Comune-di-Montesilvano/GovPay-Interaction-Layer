<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Config\SettingsRepository;

/**
 * Lavoro costruito al primo batch dell'esecuzione (un LazyJob per esecuzione, vedi JobRegistry).
 *
 * La fabbrica legge i settings dopo un flush della cache: modifiche da UI (dominio, URL, gate
 * come tefa_enabled) valgono dal giro successivo senza riavviare il worker. Un'eccezione della
 * fabbrica e' un errore del giro (registrato da JobRunner, prossimo giro all'intervallo di
 * ripiego), non un crash del worker.
 */
final class LazyJob implements PeriodicJob, CountsRemaining
{
    private ?PeriodicJob $inner = null;

    /** @param \Closure(): PeriodicJob $factory */
    public function __construct(
        private readonly string $name,
        private readonly int $fallbackIntervalSeconds,
        private readonly \Closure $factory
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return $this->inner?->emptyQueueIntervalSeconds() ?? $this->fallbackIntervalSeconds;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        return $this->inner()->runOnce($ctx);
    }

    /**
     * Costruisce il lavoro reale se serve (come farebbe il primo runOnce). Un errore della
     * fabbrica (configurazione) qui da' null: si ripresenta identico nel runOnce e diventa
     * l'errore del giro, segnalato una volta sola.
     */
    public function remaining(): ?int
    {
        try {
            $inner = $this->inner();
        } catch (\Throwable) {
            // Configurazione mancante: non e' un errore di conteggio, runOnce la riporta come errore del giro.
            return null;
        }
        return $inner instanceof CountsRemaining ? $inner->remaining() : null;
    }

    /** Lavoro reale dell'esecuzione (costruito alla prima chiamata). */
    public function inner(): PeriodicJob
    {
        if ($this->inner === null) {
            SettingsRepository::flush();
            $job = ($this->factory)();
            if ($job->name() !== $this->name) {
                throw new \LogicException("Fabbrica di '{$this->name}' ha costruito '{$job->name()}'");
            }
            $this->inner = $job;
        }
        return $this->inner;
    }
}
