<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Database\DbalConnectionFactory;
use App\Database\JobStateRepository;
use App\Monitoring\SentryReporter;
use App\Services\ThrowableSummary;
use Doctrine\DBAL\Exception\ConnectionLost;
use Psr\Clock\ClockInterface;
use Sentry\State\Scope;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;

/**
 * Esegue un giro di un lavoro periodico: flag abilitazione, lock DB, ciclo dei batch, storico.
 *
 * - Disabilitato (e non manuale) o lock `job:<name>` gia' tenuto da altri -> null, nessuna riga.
 * - Ripete runOnce finche' hadWork; prima di ogni batch successivo rilegge il flag (disabilitare
 *   ha effetto entro un batch) e rinnova il TTL del lock.
 * - Cap per esecuzione (MAX_BATCHES_PER_RUN / MAX_RUN_SECONDS): difesa da un hadWork che non
 *   torna mai false; il prossimo giro e' pianificato subito e riprende da dove era.
 * - Eccezione: Sentry (tag suite, vedi suiteFor), esito `error`, nessun retry (prossimo giro a
 *   intervallo normale). run() non lancia mai: gli errori sono registrati/riportati.
 * - L'intero giro gira in uno scope Sentry con tag `suite` (withSuiteScope): anche le catture
 *   dirette dei lavori e Logger::error/warning portano la suite del lavoro.
 * - stdout: una riga di riepilogo per esecuzione + una per errore (solo ref/motivo).
 * - Esecuzione non manuale con soli batch `silent` (JobResult::$silent): nessuna riga storico ne'
 *   stdout, solo next_run_at.
 */
final class JobRunner
{
    public const LOCK_TTL_SECONDS = 900;
    public const MAX_BATCHES_PER_RUN = 1000;
    public const MAX_RUN_SECONDS = 1800;

    public const SKIP_DISABLED = 'disabilitato';
    public const SKIP_LOCKED = 'lock occupato (giro gia\' in esecuzione)';
    public const SKIP_START_ERROR = 'errore di avvio';

    private ?string $lastSkipReason = null;
    /** @var array<string, true> lavori con errore di avvio gia' segnalato (serie in corso) */
    private array $startFailing = [];

    private readonly ClockInterface $clock;
    /** @var \Closure(string): void */
    private readonly \Closure $output;
    /** @var \Closure(string, \Throwable): void */
    private readonly \Closure $reportException;
    /** @var \Closure(): bool */
    private readonly \Closure $stopSignal;

    /**
     * @param (\Closure(string): void)|null $output riga di log (default: stdout)
     * @param (\Closure(string, \Throwable): void)|null $reportException (suite, eccezione) (default: Sentry)
     * @param (\Closure(): bool)|null $stopSignal arresto richiesto dal worker (es. SIGTERM)
     */
    public function __construct(
        private readonly JobStateRepository $state,
        private readonly LockFactory $locks,
        ?ClockInterface $clock = null,
        ?\Closure $output = null,
        ?\Closure $reportException = null,
        ?\Closure $stopSignal = null
    ) {
        $this->clock = $clock ?? new NativeClock();
        $this->output = $output ?? static function (string $line): void {
            echo $line, "\n";
            flush();
        };
        $this->reportException = $reportException ?? static function (string $suite, \Throwable $e): void {
            \Sentry\withScope(static function (Scope $scope) use ($suite, $e): void {
                $scope->setTag('suite', $suite);
                \Sentry\captureException($e);
            });
        };
        $this->stopSignal = $stopSignal ?? static fn(): bool => false;
    }

    /** Lock factory sullo store DBAL (tabella lock_keys, creata dalla migrazione 036). */
    public static function defaultLockFactory(): LockFactory
    {
        return new LockFactory(new DoctrineDbalStore(DbalConnectionFactory::get()));
    }

    /** @param (\Closure(): bool)|null $stopSignal */
    public static function create(?\Closure $stopSignal = null): self
    {
        return new self(JobStateRepository::default(), self::defaultLockFactory(), null, null, null, $stopSignal);
    }

    private function startRecovered(string $name, string $suite): void
    {
        if (isset($this->startFailing[$name])) {
            unset($this->startFailing[$name]);
            $this->line($suite, 'avvio ripristinato');
        }
    }

    /**
     * Suite Sentry/GlitchTip per slug: i nomi dei vecchi demoni (scripts/cron_*.php) restano
     * invariati, cosi' ricerche salvate e alert su GlitchTip continuano a funzionare. Un lavoro
     * senza voce usa `cron-<slug>`.
     */
    public const LEGACY_SUITES = [
        'biz' => 'cron-biz-scanner',
        'tefa' => 'cron-tefa-scanner',
        'mapping' => 'cron-mapping-pendenze',
        'mapping-discovery' => 'cron-mapping-pendenze',
        'vocab' => 'cron-vocab-mapping',
        'govpay-debitore' => 'cron-govpay-debitore-scanner',
    ];

    public static function suiteFor(string $jobName): string
    {
        return self::LEGACY_SUITES[$jobName] ?? 'cron-' . $jobName;
    }

    /**
     * Esegue $fn con il tag Sentry `suite` sullo scope: ogni cattura durante $fn (Sentry diretto e
     * Logger::error/warning) porta la suite del lavoro, non quella del processo (`worker`).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function withSuiteScope(string $suite, callable $fn): mixed
    {
        return \Sentry\withScope(static function (Scope $scope) use ($suite, $fn): mixed {
            $scope->setTag('suite', $suite);
            return $fn();
        });
    }

    /** Motivo dell'ultimo run() che ha restituito null (SKIP_*), null se l'ultimo e' partito. */
    public function lastSkipReason(): ?string
    {
        return $this->lastSkipReason;
    }

    public function run(PeriodicJob $job, bool $manual = false): ?JobResult
    {
        $name = $job->name();
        $suite = self::suiteFor($name);
        $this->lastSkipReason = null;

        try {
            $enabledAtStart = $this->state->isEnabled($name);
            if (!$manual && !$enabledAtStart) {
                $this->startRecovered($name, $suite);
                $this->lastSkipReason = self::SKIP_DISABLED;
                return null;
            }
            $lock = $this->locks->createLock('job:' . $name, self::LOCK_TTL_SECONDS, false);
            $acquired = $this->acquire($lock);
            $this->startRecovered($name, $suite);
            if (!$acquired) {
                $this->lastSkipReason = self::SKIP_LOCKED;
                return null;
            }
        } catch (\Throwable $e) {
            $this->lastSkipReason = self::SKIP_START_ERROR;
            // Una segnalazione (Sentry + riga) per serie di errori: il tick riprova ogni 5 s.
            if (!isset($this->startFailing[$name])) {
                $this->startFailing[$name] = true;
                ($this->reportException)($suite, $e);
                $this->line($suite, 'ERRORE avvio: ' . self::errorMessage($e) . ' (segnalato una volta fino al ripristino)');
            }
            return null;
        }

        try {
            return self::withSuiteScope($suite, fn(): JobResult => $this->loop($job, $lock, $manual, $enabledAtStart, $suite));
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Lock gia' perso/scaduto: scade comunque per TTL.
            }
        }
    }

    /**
     * La connessione DBAL dello store e' condivisa e vive quanto il worker. Su connessione
     * caduta (riavvio db, wait_timeout) DBAL lancia ConnectionLost e chiude la connessione,
     * che si riapre da sola al primo uso successivo. DoctrineDbalStore pero' inghiotte
     * l'errore dell'INSERT come "lock gia' preso" e acquire() ritorna false: per questo si
     * ritenta una volta sia su false sia su ConnectionLost (non bloccante, costo trascurabile;
     * un conflitto reale resta false).
     */
    private function acquire(SharedLockInterface $lock): bool
    {
        try {
            if ($lock->acquire(false)) {
                return true;
            }
        } catch (\Throwable $e) {
            if (!self::isConnectionLost($e)) {
                throw $e;
            }
        }
        return $lock->acquire(false);
    }

    private static function isConnectionLost(\Throwable $e): bool
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof ConnectionLost) {
                return true;
            }
        }
        return false;
    }

    private function loop(PeriodicJob $job, SharedLockInterface $lock, bool $manual, bool $enabledAtStart, string $suite): JobResult
    {
        $name = $job->name();
        $startedAt = $this->clock->now();
        $stopSignal = $this->stopSignal;
        $ctx = new class ($stopSignal) implements JobContext {
            /** @param \Closure(): bool $stop */
            public function __construct(private readonly \Closure $stop) {}

            public function shouldStop(): bool
            {
                return ($this->stop)();
            }

            public function debug(string $msg): void
            {
                // stdout del worker: solo riepiloghi ed errori (scritti da JobRunner).
            }
        };

        $processed = $ok = $skipped = $errors = 0;
        /** @var list<array{ref:string,reason:string}> $details */
        $details = [];
        $batches = 0;
        $lastHadWork = false;
        $allSilent = true;
        $status = 'ok';
        $errorMessage = null;

        try {
            $this->state->markStarted($name, $startedAt);
            while (true) {
                $r = $job->runOnce($ctx);
                $batches++;
                $processed += $r->processed;
                $ok += $r->ok;
                $skipped += $r->skipped;
                $errors += $r->errors;
                foreach ($r->errorDetails as $d) {
                    if (count($details) >= JobStateRepository::MAX_STORED_ERRORS) {
                        break;
                    }
                    $details[] = $d;
                }
                $lastHadWork = $r->hadWork;
                $allSilent = $allSilent && $r->silent;

                if (!$r->hadWork || ($this->stopSignal)()) {
                    break;
                }
                $elapsed = $this->clock->now()->getTimestamp() - $startedAt->getTimestamp();
                if ($batches >= self::MAX_BATCHES_PER_RUN || $elapsed >= self::MAX_RUN_SECONDS) {
                    $this->line($suite, sprintf(
                        'limite per esecuzione raggiunto (%d batch, %d s): riprende al prossimo giro',
                        $batches,
                        $elapsed
                    ));
                    break;
                }
                // Manuale su lavoro disabilitato: ignora il flag. Altrimenti disabilitare ferma
                // il giro dopo il batch corrente.
                if ((!$manual || $enabledAtStart) && !$this->state->isEnabled($name)) {
                    break;
                }
                $lock->refresh();
            }
        } catch (\Throwable $e) {
            $status = 'error';
            $errorMessage = self::errorMessage($e);
            ($this->reportException)(self::suiteFor($name), $e);
        }

        $finishedAt = $this->clock->now();
        $nextRunAt = ($status === 'ok' && $lastHadWork)
            ? $finishedAt
            : $finishedAt->modify('+' . max(0, $job->emptyQueueIntervalSeconds()) . ' seconds');
        $totals = new JobResult($processed, $ok, $skipped, $errors, $lastHadWork, $details);

        if ($status === 'ok' && $allSilent && !$manual) {
            try {
                $this->state->setNextRunAt($name, $nextRunAt, $startedAt);
            } catch (\Throwable $e) {
                ($this->reportException)($suite, $e);
            }
            return $totals;
        }

        $runId = null;
        try {
            $runId = $this->state->recordRun(
                $name,
                $startedAt,
                $finishedAt,
                $batches,
                $totals,
                $status,
                $errorMessage,
                $details,
                $nextRunAt,
                $manual
            );
        } catch (\Throwable $e) {
            ($this->reportException)($suite, $e);
            $this->line($suite, 'ERRORE registrazione esecuzione: ' . self::errorMessage($e));
            // Ripiego: almeno il prossimo giro, altrimenti il lavoro resta scaduto e ripete il
            // lavoro reale (GovPay/ponte) a ogni tick finche' lo storico non torna scrivibile.
            try {
                $this->state->setNextRunAt($name, $nextRunAt, $startedAt);
            } catch (\Throwable) {
                // DB del tutto giu': il tick non legge nemmeno i lavori scaduti.
            }
        }

        $this->line($suite, sprintf(
            '%s%s%s: batch=%d processed=%d ok=%d skipped=%d errors=%d durata=%ds prossimo=%s%s',
            $runId !== null ? 'run #' . $runId . ' ' : '',
            $manual ? 'manuale ' : '',
            $status,
            $batches,
            $processed,
            $ok,
            $skipped,
            $errors,
            $finishedAt->getTimestamp() - $startedAt->getTimestamp(),
            $nextRunAt->format('Y-m-d H:i:s'),
            $errorMessage !== null ? ' (' . $errorMessage . ')' : ''
        ));
        foreach ($details as $d) {
            $this->line($suite, 'errore ' . $d['ref'] . ': ' . SentryReporter::scrubSecretsInString($d['reason']));
        }
        if ($errors > count($details)) {
            $this->line($suite, sprintf('(+%d errori non elencati)', $errors - count($details)));
        }

        return $totals;
    }

    /**
     * Classe + messaggio breve, senza corpi upstream: per le eccezioni HTTP (Guzzle) e DB il
     * messaggio contiene URL/estratti di risposta/valori SQL e non viene incluso.
     */
    public static function errorMessage(\Throwable $e): string
    {
        $summary = ThrowableSummary::of($e);
        if ($e instanceof \GuzzleHttp\Exception\GuzzleException || $e instanceof \PDOException) {
            return $summary;
        }
        $msg = trim(strtok($e->getMessage(), "\n") ?: '');
        if ($msg === '') {
            return $summary;
        }
        return $summary . ': ' . mb_substr(SentryReporter::scrubSecretsInString($msg), 0, 200);
    }

    private function line(string $suite, string $msg): void
    {
        ($this->output)('[' . $this->clock->now()->format('Y-m-d H:i:s') . '] [' . $suite . '] ' . $msg);
    }
}
