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

    /**
     * Inizio giro. Un "esegui subito" gia' in attesa (next_run_at NULL) e' consumato da questo giro:
     * next_run_at diventa l'ora di avvio, cosi' un NULL trovato a fine giro significa "richiesto
     * DURANTE il giro" (scheduleNow) e viene conservato (vedi FINISH_NEXT_RUN_SQL).
     */
    public function markStarted(string $job, \DateTimeImmutable $at): void
    {
        $st = $this->pdo()->prepare(
            'INSERT INTO gil_jobs (name, last_started_at, next_run_at) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE next_run_at = COALESCE(next_run_at, VALUES(last_started_at)), last_started_at = VALUES(last_started_at)'
        );
        $st->execute([$job, $at->format(self::DT), $at->format(self::DT)]);
    }

    /**
     * Espressione SQL del prossimo giro a fine esecuzione: next_run_at NULL dopo markStarted
     * (stesso last_started_at, quindi va valutata PRIMA di riassegnare last_started_at) = scheduleNow
     * arrivato mentre il giro girava -> resta NULL (riparte subito). Parametri: startedAt, nextRunAt.
     */
    private const FINISH_NEXT_RUN_SQL = 'IF(next_run_at IS NULL AND last_started_at = ?, NULL, ?)';

    /**
     * Registra un'esecuzione conclusa (riga storico + primi 50 errori) e aggiorna lo stato del
     * lavoro (ultimo esito, prossimo giro). Ritorna l'id della riga in gil_job_runs.
     *
     * Con $runId (riga 'running' di startRun) la riga esistente viene chiusa invece di inserirne
     * una nuova; se nel frattempo non e' piu' 'running' (es. chiusa come interrotta) se ne
     * inserisce una nuova.
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
        bool $manual = false,
        ?int $runId = null,
        ?int $totalItems = null,
        ?int $remainingItems = null
    ): int {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $updated = 0;
            if ($runId !== null) {
                // Riga 'running' creata da startRun(): la chiude (stesso id visto in UI durante il giro).
                $st = $pdo->prepare(
                    "UPDATE gil_job_runs SET finished_at = ?, batches = ?, processed = ?, ok = ?, skipped = ?, errors = ?,
                       total_items = ?, remaining_items = ?, status = ?, error_message = ?
                     WHERE id = ? AND job = ? AND status = 'running'"
                );
                $st->execute([
                    $finishedAt->format(self::DT),
                    $batches,
                    $totals->processed,
                    $totals->ok,
                    $totals->skipped,
                    $totals->errors,
                    $totalItems,
                    $remainingItems,
                    $status,
                    $errorMessage,
                    $runId,
                    $job,
                ]);
                $updated = $st->rowCount();
            }
            if ($updated === 0) {
                $st = $pdo->prepare(
                    'INSERT INTO gil_job_runs (job, started_at, finished_at, batches, processed, ok, skipped, errors, total_items, remaining_items, status, manual, error_message)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                    $totalItems,
                    $remainingItems,
                    $status,
                    $manual ? 1 : 0,
                    $errorMessage,
                ]);
                $runId = (int)$pdo->lastInsertId();
            }

            if ($errorDetails !== []) {
                $ins = $pdo->prepare('INSERT INTO gil_job_run_errors (run_id, ref, reason) VALUES (?, ?, ?)');
                foreach (array_slice($errorDetails, 0, self::MAX_STORED_ERRORS) as $e) {
                    $ins->execute([$runId, mb_substr($e['ref'], 0, 64), mb_substr($e['reason'], 0, 512)]);
                }
            }

            // next_run_at per primo: le assegnazioni successive vedono gia' il nuovo last_started_at
            $up = $pdo->prepare(
                'INSERT INTO gil_jobs (name, last_started_at, last_finished_at, last_status, next_run_at) VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE next_run_at = ' . self::FINISH_NEXT_RUN_SQL . ',
                   last_started_at = VALUES(last_started_at), last_finished_at = VALUES(last_finished_at),
                   last_status = VALUES(last_status)'
            );
            $up->execute([
                $job,
                $startedAt->format(self::DT),
                $finishedAt->format(self::DT),
                $status,
                $nextRunAt->format(self::DT),
                $startedAt->format(self::DT),
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

    /** Motivo registrato sulle righe 'running' lasciate da un processo terminato durante il giro. */
    public const INTERRUPTED_MESSAGE = 'Interrotto: processo del worker terminato durante il giro';

    /**
     * Riga storico all'AVVIO del giro (status 'running', finished_at NULL): visibile in UI con i
     * contatori aggiornati da updateRunProgress(). Chiusa da recordRun($runId) o rimossa da
     * deleteRun() se il giro si rivela silenzioso.
     */
    public function startRun(string $job, \DateTimeImmutable $startedAt, bool $manual, ?int $totalItems = null): int
    {
        // Stesso PDO per INSERT e lastInsertId(): il provider puo' fare un ping (riconnessione).
        $pdo = $this->pdo();
        $st = $pdo->prepare(
            "INSERT INTO gil_job_runs (job, started_at, finished_at, status, manual, total_items, remaining_items)
             VALUES (?, ?, NULL, 'running', ?, ?, ?)"
        );
        $st->execute([$job, $startedAt->format(self::DT), $manual ? 1 : 0, $totalItems, $totalItems]);
        return (int)$pdo->lastInsertId();
    }

    /** Contatori del giro in corso dopo un batch (solo righe ancora 'running'). */
    public function updateRunProgress(int $runId, int $batches, JobResult $totals, ?int $totalItems, ?int $remainingItems): void
    {
        $st = $this->pdo()->prepare(
            "UPDATE gil_job_runs SET batches = ?, processed = ?, ok = ?, skipped = ?, errors = ?, total_items = ?, remaining_items = ?
             WHERE id = ? AND status = 'running'"
        );
        $st->execute([$batches, $totals->processed, $totals->ok, $totals->skipped, $totals->errors, $totalItems, $remainingItems, $runId]);
    }

    /** Giro rivelatosi silenzioso (nessun lavoro): la riga 'running' non resta nello storico. */
    public function deleteRun(int $runId): void
    {
        $st = $this->pdo()->prepare("DELETE FROM gil_job_runs WHERE id = ? AND status = 'running'");
        $st->execute([$runId]);
    }

    /**
     * Chiude come 'error' (INTERRUPTED_MESSAGE) le righe 'running' di $job: chiamato da JobRunner
     * con il lock `job:<name>` appena preso, quindi nessun altro giro di $job e' vivo.
     */
    public function failStaleRunning(string $job): int
    {
        $st = $this->pdo()->prepare(
            "UPDATE gil_job_runs SET status = 'error', finished_at = ?, error_message = ? WHERE job = ? AND status = 'running'"
        );
        $st->execute([$this->clock->now()->format(self::DT), self::INTERRUPTED_MESSAGE, $job]);
        return $st->rowCount();
    }

    /**
     * Ultima riga 'running' per lavoro (giro in corso, o lasciata da un processo ucciso: vale
     * solo insieme al lock, vedi JobControl::statuses()).
     *
     * @return array<string, array<string, mixed>>
     */
    public function runningRuns(): array
    {
        $st = $this->pdo()->query("SELECT * FROM gil_job_runs WHERE status = 'running' ORDER BY id");
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['job']] = $row;
        }
        return $out;
    }

    /**
     * Solo il prossimo giro, senza storico: ripiego quando recordRun() fallisce (altrimenti il
     * lavoro resterebbe scaduto e ripartirebbe a ogni tick).
     */
    /**
     * Con $runStartedAt (fine di un giro avviato con markStarted) conserva un scheduleNow arrivato
     * durante il giro, come recordRun().
     */
    public function setNextRunAt(string $job, \DateTimeImmutable $at, ?\DateTimeImmutable $runStartedAt = null): void
    {
        if ($runStartedAt === null) {
            $st = $this->pdo()->prepare('UPDATE gil_jobs SET next_run_at = ? WHERE name = ?');
            $st->execute([$at->format(self::DT), $job]);
            return;
        }
        $st = $this->pdo()->prepare('UPDATE gil_jobs SET next_run_at = ' . self::FINISH_NEXT_RUN_SQL . ' WHERE name = ?');
        $st->execute([$runStartedAt->format(self::DT), $at->format(self::DT), $job]);
    }

    /** Il prossimo tick (entro 5 s) fa partire il lavoro, se abilitato. */
    public function scheduleNow(string $job): void
    {
        $st = $this->pdo()->prepare('UPDATE gil_jobs SET next_run_at = NULL WHERE name = ?');
        $st->execute([$job]);
    }

    /** @return array<string, array<string, mixed>> righe gil_jobs per nome (marker escluso) */
    public function allStates(): array
    {
        $st = $this->pdo()->prepare('SELECT * FROM gil_jobs WHERE name <> ?');
        $st->execute([self::IMPORT_MARKER]);
        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['name']] = $row;
        }
        return $out;
    }

    /**
     * Lavori con un giro in corso: lock `job:<name>` (JobRunner) tenuto e non scaduto, con
     * l'orologio DB come DoctrineDbalStore. Un worker ucciso lo lascia fino al TTL (900 s).
     *
     * @return list<string>
     */
    public function runningJobs(): array
    {
        $st = $this->pdo()->query("SELECT key_id FROM lock_keys WHERE key_id LIKE 'job:%' AND key_expiration > UNIX_TIMESTAMP(NOW(6))");
        $names = [];
        foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $key) {
            $names[] = substr((string)$key, 4);
        }
        sort($names);
        return $names;
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
