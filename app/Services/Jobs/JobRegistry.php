<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Elenco dei lavori periodici per nome (popolato dal worker/scheduler e dalla UI Cron).
 *
 * Ogni voce e' una fabbrica: get() restituisce un'istanza per esecuzione. Le voci registrate con
 * register() sono LazyJob nuovi a ogni get(): il lavoro reale viene costruito al primo batch del
 * giro, cosi' settings e gate letti nel costruttore (dominio, URL, tefa_enabled...) sono quelli
 * correnti e non quelli dell'avvio del worker. add() registra un'istanza fissa (test).
 */
final class JobRegistry
{
    /** @var array<string, \Closure(): PeriodicJob> */
    private array $factories = [];

    /** @param iterable<PeriodicJob> $jobs */
    public function __construct(iterable $jobs = [])
    {
        foreach ($jobs as $job) {
            $this->add($job);
        }
    }

    public function add(PeriodicJob $job): void
    {
        $this->put($job->name(), static fn(): PeriodicJob => $job);
    }

    /**
     * @param int $fallbackIntervalSeconds intervallo "coda vuota" se il lavoro non e' costruibile
     * @param \Closure(): PeriodicJob $factory costruisce il lavoro (chiamata una volta per esecuzione)
     */
    public function register(string $name, int $fallbackIntervalSeconds, \Closure $factory): void
    {
        $this->put($name, static fn(): PeriodicJob => new LazyJob($name, $fallbackIntervalSeconds, $factory));
    }

    /** @param \Closure(): PeriodicJob $factory */
    private function put(string $name, \Closure $factory): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name)) {
            throw new \InvalidArgumentException("Nome lavoro non valido: '{$name}'");
        }
        if (isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Lavoro gia' registrato: '{$name}'");
        }
        $this->factories[$name] = $factory;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /** Istanza per una esecuzione (nuova a ogni chiamata per le voci register()). */
    public function get(string $name): PeriodicJob
    {
        if (!isset($this->factories[$name])) {
            throw new \OutOfBoundsException("Lavoro sconosciuto: '{$name}'");
        }
        return ($this->factories[$name])();
    }

    /** @return list<string> in ordine di registrazione */
    public function names(): array
    {
        return array_keys($this->factories);
    }
}
