<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Database;

use App\Services\Jobs\JobResult;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Flag abilitazione, stato e storico dei lavori periodici (gil_jobs, gil_job_runs,
 * gil_job_run_errors). PDO semplice. Tutti i timestamp vengono dal clock PHP (stessa sorgente
 * per scrittura e per dueJobs()); il TTL del lock usa invece l'orologio DB (DoctrineDbalStore).
 */
final class JobStateRepository
{
    public const IMPORT_MARKER = '__import__';
    public const MAX_STORED_ERRORS = 50;

    private const DT = 'Y-m-d H:i:s';

    private readonly ClockInterface $clock;
    /** @var \Closure(): \PDO */
    private readonly \Closure $pdoProvider;

    /**
     * Il PDO e' risolto a ogni chiamata (default Connection::getPDO(), che riconnette se la
     * connessione e' caduta): il repository vive per tutta la durata del worker.
     *
     * @param (\Closure(): \PDO)|null $pdoProvider
     */
    public function __construct(?\Closure $pdoProvider = null, ?ClockInterface $clock = null)
    {
        $this->pdoProvider = $pdoProvider ?? static fn(): \PDO => Connection::getPDO();
        $this->clock = $clock ?? new NativeClock();
    }

    public static function default(): self
    {
        return new self();
    }

    private function pdo(): \PDO
    {
        return ($this->pdoProvider)();
    }

    public function isEnabled(string $job): bool
    {
        $st = $this->pdo()->prepare('SELECT enabled FROM gil_jobs WHERE name = ?');
        $st->execute([$job]);
        return (int)$st->fetchColumn() === 1;
    }

    public function setEnabled(string $job, bool $enabled): void
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO gil_jobs (name, enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
        );
        $st->execute([$job, $enabled ? 1 : 0]);
    }

    /** @return array<string, mixed>|null riga gil_jobs */
    public function state(string $job): ?array
    {
        $st = $this->pdo()->prepare('SELECT * FROM gil_jobs WHERE name = ?');
        $st->execute([$job]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function markStarted(string $job, \DateTimeImmutable $at): void
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO gil_jobs (name, last_started_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_started_at = VALUES(last_started_at)'
        );
        $st->execute([$job, $at->format(self::DT)]);
    }

    /**
     * Registra un'esecuzione conclusa (riga storico + primi 50 errori) e aggiorna lo stato del
     * lavoro (ultimo esito, prossimo giro). Ritorna l'id della riga in gil_job_runs.
     *
     * @param 'ok'|'error' $status
     * @param list<array{ref:string,reason:string}> $errorDetails
     */
    public function recordRun(
        string $job,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $finishedAt,
        int $batches,
        JobResult $totals,
        string $status,
        ?string $errorMessage,
        array $errorDetails,
        \DateTimeImmutable $nextRunAt,
        bool $manual = false
    ): int {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO gil_job_runs (job, started_at, finished_at, batches, processed, ok, skipped, errors, status, manual, error_message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $st->execute([
                $job,
                $startedAt->format(self::DT),
                $finishedAt->format(self::DT),
                $batches,
                $totals->processed,
                $totals->ok,
                $totals->skipped,
                $totals->errors,
                $status,
                $manual ? 1 : 0,
                $errorMessage,
            ]);
            $runId = (int)$pdo->lastInsertId();

            if ($errorDetails !== []) {
                $ins = $pdo->prepare('INSERT INTO gil_job_run_errors (run_id, ref, reason) VALUES (?, ?, ?)');
                foreach (array_slice($errorDetails, 0, self::MAX_STORED_ERRORS) as $e) {
                    $ins->execute([$runId, mb_substr($e['ref'], 0, 64), mb_substr($e['reason'], 0, 512)]);
                }
            }

            $up = $pdo->prepare(
                'INSERT INTO gil_jobs (name, last_started_at, last_finished_at, last_status, next_run_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_started_at = VALUES(last_started_at), last_finished_at = VALUES(last_finished_at),
                   last_status = VALUES(last_status), next_run_at = VALUES(next_run_at)'
            );
            $up->execute([
                $job,
                $startedAt->format(self::DT),
                $finishedAt->format(self::DT),
                $status,
                $nextRunAt->format(self::DT),
            ]);

            $pdo->commit();
            return $runId;
        } catch (\Throwable $e) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (\Throwable) {
                // Connessione caduta: la transazione e' gia' annullata dal server.
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> esecuzioni piu' recenti prima */
    public function recentRuns(string $job, int $limit): array
    {
        $st = $this->pdo()->prepare('SELECT * FROM gil_job_runs WHERE job = ? ORDER BY started_at DESC, id DESC LIMIT ?');
        $st->bindValue(1, $job);
        $st->bindValue(2, max(1, $limit), \PDO::PARAM_INT);
        $st->execute();
        return array_values($st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** @return list<array{id:int|string, run_id:int|string, ref:string, reason:string}> */
    public function errorsForRun(int $runId): array
    {
        $st = $this->pdo()->prepare('SELECT id, run_id, ref, reason FROM gil_job_run_errors WHERE run_id = ? ORDER BY id');
        $st->execute([$runId]);
        return array_values($st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Lavori abilitati il cui prossimo giro e' scaduto (o mai pianificato).
     *
     * @return list<string>
     */
    public function dueJobs(\DateTimeImmutable $now): array
    {
        $st = $this->pdo()->prepare(
            'SELECT name FROM gil_jobs WHERE enabled = 1 AND name <> ? AND (next_run_at IS NULL OR next_run_at <= ?) ORDER BY name'
        );
        $st->execute([self::IMPORT_MARKER, $now->format(self::DT)]);
        return array_values(array_map('strval', $st->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** Elimina lo storico piu' vecchio di $days giorni (gli errori via FK ON DELETE CASCADE). */
    public function purgeRunsOlderThan(int $days = 30): int
    {
        $cutoff = $this->clock->now()->modify('-' . max(0, $days) . ' days');
        $st = $this->pdo()->prepare('DELETE FROM gil_job_runs WHERE started_at < ?');
        $st->execute([$cutoff->format(self::DT)]);
        return $st->rowCount();
    }

    /** Import .autostart gia' eseguito (riga marker in gil_jobs). */
    public function autostartImported(): bool
    {
        $st = $this->pdo()->prepare('SELECT autostart_imported FROM gil_jobs WHERE name = ?');
        $st->execute([self::IMPORT_MARKER]);
        return (int)$st->fetchColumn() === 1;
    }

    public function markAutostartImported(): void
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO gil_jobs (name, enabled, autostart_imported) VALUES (?, 0, 1) ON DUPLICATE KEY UPDATE autostart_imported = 1'
        );
        $st->execute([self::IMPORT_MARKER]);
    }
}
