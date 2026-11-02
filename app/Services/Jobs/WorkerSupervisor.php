<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Supervisore del worker (`bin/worker gil:worker`, PID 1 del container `worker`): un processo
 * PHP figlio per gruppo di lavori (`gil:worker:job <gruppo>`) e uno per la coda `massive`
 * (`gil:worker:massive`), cosi' i lavori girano IN PARALLELO come i vecchi demoni cron, in un
 * solo container.
 *
 * - Avvio: $prepare (attesa DB, import .autostart, coda legacy, pulizia storico: una sola volta,
 *   qui e non nei figli), poi tutti i figli. false da $prepare = arresto richiesto, exit 0.
 * - Figlio terminato (crash, kill, exit != 0 o anche 0) senza arresto richiesto: una riga e
 *   riavvio dopo una pausa crescente (RESTART_INITIAL_SECONDS raddoppiata fino a
 *   RESTART_MAX_SECONDS; torna a quella iniziale se il figlio era rimasto vivo almeno
 *   STABLE_SECONDS).
 * - SIGTERM/SIGINT: inoltrato a ogni figlio vivo (SIGTERM), nessun riavvio; ogni figlio finisce
 *   il batch corrente ed esce; il supervisore esce quando sono usciti tutti ("worker arrestato.").
 *   Compose: stop_signal SIGTERM + stop_grace_period 60s.
 * - $periodic: richiamata a ogni giro del ciclo (es. pulizia storico, che si limita da sola a
 *   una volta al giorno). Non deve lanciare: un errore e' scritto su stdout e ignorato.
 * - proc_open con argv (nessuna shell): il PID del figlio e' quello del processo PHP; stdout e
 *   stderr dei figli sono quelli del supervisore (righe gia' prefissate dal figlio).
 */
final class WorkerSupervisor
{
    public const RESTART_INITIAL_SECONDS = 5.0;
    public const RESTART_MAX_SECONDS = 60.0;
    public const STABLE_SECONDS = 60.0;
    public const POLL_MICROSECONDS = 200_000;

    private bool $stopRequested = false;

    /** @var array<string, array{argv: list<string>, proc: resource|null, pid: int, startedAt: float, restartAt: float|null, backoff: float, starts: int}> */
    private array $children = [];

    /** @var \Closure(string): void */
    private readonly \Closure $output;

    /**
     * @param array<string, list<string>> $commands nome figlio => argv
     * @param (\Closure(string): void)|null $output riga di log gia' completa (default: stdout con data e [worker])
     * @param (\Closure(): bool)|null $prepare prima dell'avvio dei figli; false = arresto richiesto
     * @param (\Closure(): void)|null $periodic a ogni giro del ciclo
     * @param (\Closure(): void)|null $onStop richiamata alla richiesta di arresto (es. interrompe $prepare)
     * @param array<int, mixed>|null $descriptors descrittori proc_open dei figli (default: stdin nullo, stdout/stderr ereditati)
     */
    public function __construct(
        array $commands,
        ?\Closure $output = null,
        private readonly ?\Closure $prepare = null,
        private readonly ?\Closure $periodic = null,
        private readonly ?\Closure $onStop = null,
        private readonly float $restartInitialSeconds = self::RESTART_INITIAL_SECONDS,
        private readonly float $restartMaxSeconds = self::RESTART_MAX_SECONDS,
        private readonly float $stableSeconds = self::STABLE_SECONDS,
        private readonly ?array $descriptors = null
    ) {
        $this->output = $output ?? static function (string $line): void {
            echo '[' . date('Y-m-d H:i:s') . '] [worker] ' . $line, "\n";
            flush();
        };
        foreach ($commands as $name => $argv) {
            $this->children[$name] = [
                'argv' => $argv,
                'proc' => null,
                'pid' => 0,
                'startedAt' => 0.0,
                'restartAt' => null,
                'backoff' => $this->restartInitialSeconds,
                'starts' => 0,
            ];
        }
    }

    /**
     * Comandi di produzione: un figlio per gruppo di JobCatalog::processGroups() + `massive`.
     *
     * @return array<string, list<string>>
     */
    public static function defaultCommands(string $workerScript, string $php = PHP_BINARY): array
    {
        $commands = [];
        foreach (array_keys(JobCatalog::processGroups()) as $group) {
            $commands[$group] = [$php, $workerScript, 'gil:worker:job', $group];
        }
        $commands[MassiveEnqueueJob::QUEUE] = [$php, $workerScript, 'gil:worker:massive'];
        return $commands;
    }

    /** SIGTERM/SIGINT -> requestStop() (senza pcntl: nessun gestore). */
    public function installSignalHandlers(): bool
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return false;
        }
        pcntl_async_signals(true);
        foreach ([\SIGTERM, \SIGINT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->requestStop();
            });
        }
        return true;
    }

    public function requestStop(): void
    {
        if ($this->stopRequested) {
            return;
        }
        $this->stopRequested = true;
        if ($this->onStop !== null) {
            ($this->onStop)();
        }
        $alive = 0;
        foreach ($this->children as $name => $child) {
            if ($child['proc'] !== null) {
                $alive++;
                @proc_terminate($child['proc'], 15);
            }
            $this->children[$name]['restartAt'] = null;
        }
        if ($alive > 0) {
            $this->line("arresto: SIGTERM inoltrato a {$alive} processi, attesa fine batch in corso");
        }
    }

    public function isStopRequested(): bool
    {
        return $this->stopRequested;
    }

    /** @return array<string, int> nome => PID dei figli vivi (diagnostica/test) */
    public function childPids(): array
    {
        $out = [];
        foreach ($this->children as $name => $child) {
            if ($child['proc'] !== null) {
                $out[$name] = $child['pid'];
            }
        }
        return $out;
    }

    /** @return array<string, int> nome => avvii (diagnostica/test) */
    public function startCounts(): array
    {
        return array_map(static fn(array $c): int => $c['starts'], $this->children);
    }

    /** @param float|null $timeLimit secondi, poi arresto come da SIGTERM (diagnostica/test) */
    public function run(?float $timeLimit = null): int
    {
        $this->line(sprintf('avvio supervisore: %d processi (%s)', count($this->children), implode(', ', array_keys($this->children))));
        $began = microtime(true);
        if ($this->prepare !== null && !$this->stopRequested && !($this->prepare)()) {
            $this->line('arresto richiesto durante l\'avvio.');
            return 0;
        }
        if ($this->stopRequested) {
            $this->line('worker arrestato.');
            return 0;
        }
        foreach (array_keys($this->children) as $name) {
            $this->start($name);
        }

        while (true) {
            if ($timeLimit !== null && !$this->stopRequested && microtime(true) - $began >= $timeLimit) {
                $this->requestStop();
            }
            $alive = $this->reap();
            if ($this->stopRequested) {
                if ($alive === 0) {
                    break;
                }
            } else {
                $this->restartDue();
                if ($this->periodic !== null) {
                    try {
                        ($this->periodic)();
                    } catch (\Throwable $e) {
                        $this->line('ERRORE manutenzione periodica: ' . JobRunner::errorMessage($e));
                    }
                }
            }
            usleep(self::POLL_MICROSECONDS);
        }

        $this->line('worker arrestato.');
        return 0;
    }

    private function start(string $name): void
    {
        $child = &$this->children[$name];
        $child['restartAt'] = null;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = $this->descriptors ?? [0 => ['file', $null, 'r'], 1 => STDOUT, 2 => STDERR];
        $proc = @proc_open($child['argv'], $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->scheduleRestart($name, 'avvio fallito');
            return;
        }
        $status = proc_get_status($proc);
        $child['proc'] = $proc;
        $child['pid'] = (int)$status['pid'];
        $child['startedAt'] = microtime(true);
        $child['starts']++;
        if ($child['starts'] > 1) {
            $this->line("processo {$name} riavviato (pid {$child['pid']})");
        }
    }

    /** Raccoglie i figli usciti; ritorna quanti sono ancora vivi. */
    private function reap(): int
    {
        $alive = 0;
        foreach ($this->children as $name => $child) {
            if ($child['proc'] === null) {
                continue;
            }
            $status = proc_get_status($child['proc']);
            if ($status['running']) {
                $alive++;
                continue;
            }
            $how = $status['signaled'] ? 'segnale ' . $status['termsig'] : 'exit ' . $status['exitcode'];
            proc_close($child['proc']);
            $this->children[$name]['proc'] = null;
            if ($this->stopRequested) {
                $this->line("processo {$name} terminato ({$how})");
                continue;
            }
            if (microtime(true) - $child['startedAt'] >= $this->stableSeconds) {
                $this->children[$name]['backoff'] = $this->restartInitialSeconds;
            }
            $this->scheduleRestart($name, "terminato ({$how})");
        }
        return $alive;
    }

    private function scheduleRestart(string $name, string $why): void
    {
        $backoff = $this->children[$name]['backoff'];
        $this->children[$name]['restartAt'] = microtime(true) + $backoff;
        $this->children[$name]['backoff'] = min($this->restartMaxSeconds, $backoff * 2);
        $this->line(sprintf('processo %s %s: riavvio tra %s s', $name, $why, rtrim(rtrim(number_format($backoff, 1, '.', ''), '0'), '.')));
    }

    private function restartDue(): void
    {
        $now = microtime(true);
        foreach ($this->children as $name => $child) {
            if ($child['proc'] === null && $child['restartAt'] !== null && $now >= $child['restartAt']) {
                $this->start($name);
            }
        }
    }

    private function line(string $msg): void
    {
        ($this->output)($msg);
    }
}
