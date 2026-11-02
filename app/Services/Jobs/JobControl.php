<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Database\DbalConnectionFactory;
use App\Database\JobStateRepository;
use App\Database\MassivePendenzeRepository;

/**
 * Azioni della pagina Cron / Servizi e del GIL Services Hub sui lavori del worker:
 * stato (gil_jobs + lock), abilita/pausa, esegui ora, storico esecuzioni, rescan ragioneria,
 * "riprova" delle righe massive. Nessun processo, PID o file di stop: tutto via DB, il worker
 * (servizio compose `worker`) legge i flag a ogni tick (5 s) e tra un batch e l'altro.
 */
final class JobControl
{
    /** Lavori mostrati come interruttore separato. mapping-discovery segue mapping (COMPANIONS). */
    public const VISIBLE = [
        'biz',
        'tefa',
        'ragioneria',
        'pendenze-massive',
        'mapping',
        'vocab',
        'govpay-debitore',
        'rendicontazione-govpay',
    ];

    public const LOG_RUNS = 20;
    public const LOG_ERRORS_PER_RUN = 10;

    /** @var \Closure(string): void */
    private readonly \Closure $enqueueManual;

    /** @var \Closure(string): bool */
    private readonly \Closure $manualQueued;

    /**
     * @param (\Closure(string): void)|null $enqueueManual default WorkerKernel::enqueueManualRun
     * @param (\Closure(string): bool)|null $manualQueued default WorkerKernel::manualRunQueued
     */
    public function __construct(
        private readonly JobStateRepository $state,
        ?\Closure $enqueueManual = null,
        private readonly ?\Closure $massiveRepo = null,
        ?\Closure $manualQueued = null
    ) {
        $this->enqueueManual = $enqueueManual ?? static function (string $job): void {
            WorkerKernel::enqueueManualRun(DbalConnectionFactory::get(), $job);
        };
        $this->manualQueued = $manualQueued ?? static function (string $job): bool {
            return WorkerKernel::manualRunQueued(DbalConnectionFactory::get(), $job);
        };
    }

    public static function default(): self
    {
        return new self(JobStateRepository::default());
    }

    public static function isKnown(string $job): bool
    {
        return in_array($job, self::VISIBLE, true);
    }

    /**
     * Stato per lavoro visibile: enabled, running (lock tenuto), ultimo giro/esito, prossimo giro.
     *
     * @return array<string, array{enabled:bool, running:bool, last_started_at:?string, last_finished_at:?string, last_status:?string, next_run_at:?string}>
     */
    public function statuses(): array
    {
        $rows = $this->state->allStates();
        $running = $this->state->runningJobs();
        $out = [];
        foreach (self::VISIBLE as $name) {
            $row = $rows[$name] ?? [];
            $isRunning = in_array($name, $running, true);
            foreach (JobCatalog::COMPANIONS[$name] ?? [] as $companion) {
                $isRunning = $isRunning || in_array($companion, $running, true);
            }
            $out[$name] = [
                'enabled' => (int)($row['enabled'] ?? 0) === 1,
                'running' => $isRunning,
                'last_started_at' => self::str($row['last_started_at'] ?? null),
                'last_finished_at' => self::str($row['last_finished_at'] ?? null),
                'last_status' => self::str($row['last_status'] ?? null),
                'next_run_at' => self::str($row['next_run_at'] ?? null),
            ];
        }
        return $out;
    }

    public function isEnabled(string $job): bool
    {
        return $this->state->isEnabled($job);
    }

    /**
     * Abilita/mette in pausa il lavoro e i lavori associati. In pausa: il giro in corso termina
     * dopo il batch corrente. Abilitato: parte al prossimo tick (next_run_at azzerato).
     */
    public function setEnabled(string $job, bool $enabled): void
    {
        self::assertKnown($job);
        foreach (array_merge([$job], JobCatalog::COMPANIONS[$job] ?? []) as $name) {
            $this->state->setEnabled($name, $enabled);
            if ($enabled) {
                $this->state->scheduleNow($name);
            }
        }
    }

    /**
     * Accoda un'esecuzione manuale (coda `jobs` del worker): parte anche se il lavoro e' in pausa,
     * tranne pendenze-massive (in pausa il gestore rilascerebbe subito le righe prese: solo
     * giri a vuoto). Con un giro in corso parte al suo termine (worker a processo singolo). Una
     * richiesta gia' in coda per lo stesso lavoro non ne accoda un'altra (clic ripetuti).
     * Ritorna null se accodata, altrimenti il motivo del rifiuto.
     */
    public function runNow(string $job): ?string
    {
        self::assertKnown($job);
        if ($job === MassiveEnqueueJob::NAME && !$this->state->isEnabled($job)) {
            return 'Pendenze massive in pausa: riattivare il servizio per elaborare le righe.';
        }
        if (($this->manualQueued)($job)) {
            return "Esecuzione manuale già in coda: partirà appena il worker è libero (al termine del giro in corso, se ce n'è uno).";
        }
        ($this->enqueueManual)($job);
        return null;
    }

    /** Rescan ragioneria al prossimo giro (che parte subito). Motivo del rifiuto o null. */
    public function requestRagioneriaRescan(): ?string
    {
        if (!$this->state->isEnabled('ragioneria')) {
            return 'Servizio ragioneria in pausa: attivarlo prima di chiedere il rescan.';
        }
        RagioneriaSyncJob::storeRescanRequest();
        $this->state->scheduleNow('ragioneria');
        return null;
    }

    /**
     * Ultime esecuzioni (piu' recenti prima) con i primi errori di ciascuna.
     *
     * @return list<array<string, mixed>>
     */
    public function recentRuns(string $job, int $limit = self::LOG_RUNS): array
    {
        self::assertKnown($job);
        $runs = [];
        foreach ($this->state->recentRuns($job, $limit) as $run) {
            $run['top_errors'] = (int)$run['errors'] > 0 || $run['status'] === 'error'
                ? array_slice($this->state->errorsForRun((int)$run['id']), 0, self::LOG_ERRORS_PER_RUN)
                : [];
            $runs[] = $run;
        }
        return $runs;
    }

    /** Storico in testo per il pannello log della pagina Cron. */
    public function logText(string $job, int $limit = self::LOG_RUNS): string
    {
        $status = $this->statuses()[$job] ?? null;
        $lines = [];
        if ($status !== null) {
            $lines[] = sprintf(
                'Stato: %s%s | prossimo giro: %s',
                $status['enabled'] ? 'attivo' : 'in pausa',
                $status['running'] ? ' (giro in esecuzione)' : '',
                $status['enabled'] ? ($status['next_run_at'] ?? 'al prossimo tick') : '-'
            );
            $lines[] = '';
        }
        $runs = $this->recentRuns($job, $limit);
        if ($runs === []) {
            $lines[] = '(nessuna esecuzione registrata)';
            return implode("\n", $lines);
        }
        foreach ($runs as $run) {
            $lines[] = sprintf(
                '[%s -> %s] %s%s: %d batch, elaborati %d, ok %d, saltati %d, errori %d',
                $run['started_at'],
                substr((string)$run['finished_at'], 11),
                strtoupper((string)$run['status']),
                (int)$run['manual'] === 1 ? ' (manuale)' : '',
                (int)$run['batches'],
                (int)$run['processed'],
                (int)$run['ok'],
                (int)$run['skipped'],
                (int)$run['errors']
            );
            if (($run['error_message'] ?? '') !== '' && $run['error_message'] !== null) {
                $lines[] = '    errore: ' . $run['error_message'];
            }
            foreach ($run['top_errors'] as $e) {
                $lines[] = '    - ' . $e['ref'] . ': ' . $e['reason'];
            }
            $extra = (int)$run['errors'] - count($run['top_errors']);
            if ($run['top_errors'] !== [] && $extra > 0) {
                $lines[] = '    ... altri ' . $extra . ' errori';
            }
        }
        return implode("\n", $lines);
    }

    /**
     * "Riprova" di una riga massiva in errore: la rimette in coda (stesso idPendenza salvato).
     *
     * @return array{ok:bool, message:string}
     */
    public function retryMassiveRow(int $rowId): array
    {
        $repo = $this->massiveRepo !== null ? ($this->massiveRepo)() : new MassivePendenzeRepository();
        $row = $rowId > 0 ? $repo->findById($rowId) : null;
        if ($row === null) {
            return ['ok' => false, 'message' => 'Riga non trovata.'];
        }
        $reason = MassivePendenzeRepository::retryBlockReason($row);
        if ($reason !== null) {
            return ['ok' => false, 'message' => 'Non riprovabile: ' . $reason . '.'];
        }
        if (!$repo->retry($rowId)) {
            return ['ok' => false, 'message' => 'Non riprovabile: stato della riga cambiato nel frattempo.'];
        }
        $paused = !$this->state->isEnabled(MassiveEnqueueJob::NAME);
        // Senza, la riga aspetterebbe la pausa "coda vuota" (15 min) del giro di presa in carico.
        $this->state->scheduleNow(MassiveEnqueueJob::NAME);
        return [
            'ok' => true,
            'message' => $paused
                ? 'Riga rimessa in coda: verra\' elaborata quando il servizio pendenze massive sara\' riattivato.'
                : 'Riga rimessa in coda: verra\' rielaborata a breve.',
        ];
    }

    private static function assertKnown(string $job): void
    {
        if (!self::isKnown($job)) {
            throw new \InvalidArgumentException('Lavoro sconosciuto: ' . $job);
        }
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string)$v;
    }
}
