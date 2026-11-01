<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Database\MappingPendenzeRepository;

/**
 * Discovery pattern IUV del mapping L1 (blocco "ogni 60 secondi" di cron_mapping_pendenze.php),
 * lavoro separato da `mapping` con cadenza propria: discover() poi refreshPatternDiagnostics(),
 * ciascuno con il proprio catch come nello script (un errore della discovery non salta il
 * refresh diagnostics). hadWork sempre false: il prossimo giro e' tra 60 s.
 *
 * processed/ok = prefissi scoperti; errors = passi falliti (ref discovery/diagnostics).
 * Giro senza errori = JobResult silent (nessuna riga gil_job_runs ne' stdout); gli errori no.
 */
final class MappingDiscoveryJob implements PeriodicJob
{
    public const INTERVAL_SECONDS = 60;

    public function __construct(
        private readonly MappingL1Job $l1,
        private readonly MappingPendenzeRepository $repo,
        private readonly string $idDominio
    ) {}

    public function name(): string
    {
        return 'mapping-discovery';
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return self::INTERVAL_SECONDS;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $discovered = 0;
        $details = [];

        try {
            $discovered = $this->l1->discover();
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $details[] = ['ref' => 'discovery', 'reason' => 'Errore discovery pattern IUV'];
        }

        try {
            $this->repo->refreshPatternDiagnostics($this->idDominio);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $details[] = ['ref' => 'diagnostics', 'reason' => 'Errore refresh diagnostics pattern'];
        }

        // Senza errori il giro e' silenzioso: girando ogni 60 s scriverebbe 1440 righe storico e
        // stdout al giorno senza informazione (i pattern sono visibili in UI). Gli errori no.
        return new JobResult($discovered, $discovered, 0, count($details), false, $details, $details === []);
    }
}
