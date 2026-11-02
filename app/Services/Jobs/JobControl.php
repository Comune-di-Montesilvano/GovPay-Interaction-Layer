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
 * (servizio compose `worker`, un processo per lavoro) legge i flag a ogni tick (5 s) e tra un
 * batch e l'altro. Giro in corso: riga gil_job_runs 'running' con contatori e avanzamento
 * (fatto/totale) aggiornati a ogni batch (current_run in statuses()).
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

    public const QUEUED_MESSAGE = "Esecuzione manuale già in coda: partirà al termine del giro in corso di questo servizio (gli altri servizi non la ritardano).";
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
     * Stato per lavoro visibile: enabled, running (lock tenuto), ultimo giro/esito, prossimo giro,
     * current_run (giro in corso: riga 'running' + avanzamento, solo con il lock tenuto; per
     * mapping anche il giro di mapping-discovery).
     *
     * @return array<string, array{enabled:bool, running:bool, last_started_at:?string, last_finished_at:?string, last_status:?string, next_run_at:?string, current_run:?array<string, mixed>}>
     */
    public function statuses(): array
    {
        $rows = $this->state->allStates();
        $running = $this->state->runningJobs();
        $runningRows = $this->state->runningRuns();
        $out = [];
        foreach (self::VISIBLE as $name) {
            $row = $rows[$name] ?? [];
            $isRunning = in_array($name, $running, true);
            $current = $isRunning && isset($runningRows[$name]) ? self::progress($runningRows[$name]) : null;
            foreach (JobCatalog::COMPANIONS[$name] ?? [] as $companion) {
                if (in_array($companion, $running, true)) {
                    $isRunning = true;
                    $current ??= isset($runningRows[$companion]) ? self::progress($runningRows[$companion]) : null;
                }
            }
            $out[$name] = [
                'enabled' => (int)($row['enabled'] ?? 0) === 1,
                'running' => $isRunning,
                'last_started_at' => self::str($row['last_started_at'] ?? null),
                'last_finished_at' => self::str($row['last_finished_at'] ?? null),
                'last_status' => self::str($row['last_status'] ?? null),
                'next_run_at' => self::str($row['next_run_at'] ?? null),
                'current_run' => $current,
            ];
        }
        return $out;
    }

    /**
     * Avanzamento di una riga gil_job_runs: started_at, batches, contatori, total/remaining
     * (null se non misurabili) e percent (0-100, null senza totale).
     *
     * @param array<string, mixed> $run
     * @return array{id:int, job:string, started_at:string, manual:bool, batches:int, processed:int, ok:int, skipped:int, errors:int, total:?int, remaining:?int, percent:?int}
     */
    public static function progress(array $run): array
    {
        $processed = (int)($run['processed'] ?? 0);
        $total = isset($run['total_items']) ? (int)$run['total_items'] : null;
        $percent = null;
        if ($total !== null) {
            $percent = $total > 0 ? (int)min(100, floor($processed * 100 / $total)) : 100;
        }
        return [
            'id' => (int)($run['id'] ?? 0),
            'job' => (string)($run['job'] ?? ''),
            'started_at' => (string)($run['started_at'] ?? ''),
            'manual' => (int)($run['manual'] ?? 0) === 1,
            'batches' => (int)($run['batches'] ?? 0),
            'processed' => $processed,
            'ok' => (int)($run['ok'] ?? 0),
            'skipped' => (int)($run['skipped'] ?? 0),
            'errors' => (int)($run['errors'] ?? 0),
            'total' => $total,
            'remaining' => isset($run['remaining_items']) ? (int)$run['remaining_items'] : null,
            'percent' => $percent,
        ];
    }

    /** "1200 / 5400 (22%)" o "1200" senza totale. */
    public static function progressText(array $p): string
    {
        if ($p['total'] === null) {
            return (string)$p['processed'];
        }
        return sprintf('%d / %d (%d%%)', $p['processed'], $p['total'], (int)$p['percent']);
    }

    /** Barra testuale per il pannello log, es. "[#####...............]". */
    private static function textBar(?int $percent, int $width = 20): string
    {
        $filled = $percent === null ? 0 : (int)round($width * $percent / 100);
        return '[' . str_repeat('#', $filled) . str_repeat('.', $width - $filled) . ']';
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
     * Accoda un'esecuzione manuale (coda `jobs.<name>`, consumata dal processo del lavoro): parte
     * anche se il lavoro e' in pausa, tranne pendenze-massive (in pausa il gestore rilascerebbe
     * subito le righe prese: solo giri a vuoto). Con un giro dello STESSO lavoro in corso parte
     * al suo termine; gli altri lavori girano in processi separati e non la ritardano. Una
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
            return self::QUEUED_MESSAGE;
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
            $run['top_errors'] = $run['status'] !== 'running' && ((int)$run['errors'] > 0 || $run['status'] === 'error')
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
            $cur = $status['current_run'];
            if ($cur !== null) {
                $lines[] = sprintf(
                    'Giro in corso dal %s: %s %s, %d batch, ok %d, saltati %d, errori %d%s',
                    $cur['started_at'],
                    self::textBar($cur['percent']),
                    self::progressText($cur),
                    $cur['batches'],
                    $cur['ok'],
                    $cur['skipped'],
                    $cur['errors'],
                    $cur['remaining'] !== null ? ', restanti ' . $cur['remaining'] : ''
                );
            }
            $lines[] = '';
        }
        $runs = $this->recentRuns($job, $limit);
        if ($runs === []) {
            $lines[] = '(nessuna esecuzione registrata)';
            return implode("\n", $lines);
        }
        foreach ($runs as $run) {
            $running = $run['status'] === 'running';
            $p = self::progress($run);
            $lines[] = sprintf(
                '[%s -> %s] %s%s: %d batch, elaborati %s, ok %d, saltati %d, errori %d',
                $run['started_at'],
                $running ? 'in corso' : substr((string)$run['finished_at'], 11),
                $running ? 'IN CORSO' : strtoupper((string)$run['status']),
                (int)$run['manual'] === 1 ? ' (manuale)' : '',
                (int)$run['batches'],
                $p['total'] !== null ? $p['processed'] . '/' . $p['total'] : (string)$p['processed'],
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
