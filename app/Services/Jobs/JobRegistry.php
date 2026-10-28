<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/** Elenco dei lavori periodici per nome (popolato dal worker/scheduler e dalla UI Cron). */
final class JobRegistry
{
    /** @var array<string, PeriodicJob> */
    private array $jobs = [];

    /** @param iterable<PeriodicJob> $jobs */
    public function __construct(iterable $jobs = [])
    {
        foreach ($jobs as $job) {
            $this->add($job);
        }
    }

    public function add(PeriodicJob $job): void
    {
        $name = $job->name();
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name)) {
            throw new \InvalidArgumentException("Nome lavoro non valido: '{$name}'");
        }
        if (isset($this->jobs[$name])) {
            throw new \InvalidArgumentException("Lavoro gia' registrato: '{$name}'");
        }
        $this->jobs[$name] = $job;
    }

    public function has(string $name): bool
    {
        return isset($this->jobs[$name]);
    }

    public function get(string $name): PeriodicJob
    {
        if (!isset($this->jobs[$name])) {
            throw new \OutOfBoundsException("Lavoro sconosciuto: '{$name}'");
        }
        return $this->jobs[$name];
    }

    /** @return array<string, PeriodicJob> in ordine di registrazione */
    public function all(): array
    {
        return $this->jobs;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->jobs);
    }
}
