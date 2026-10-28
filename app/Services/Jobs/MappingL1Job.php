<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Database\MappingPendenzeRepository;

/**
 * Mapping L1 (fornitore): corpo del loop di cron_mapping_pendenze.php.
 *
 * discover() = scoperta pattern IUV (lo script la richiama ogni >= 60 s, poi conta la coda e
 * aggiorna le diagnostics). runOnce() = un ciclo di assegnazione: bulk UPDATE per ogni regola
 * attiva, poi le PENDING rimaste passano a NO_MATCH. Loop, pause (15 min a vuoto), stop file,
 * log e gestione errore di caricamento regole restano nello script.
 *
 * Ordine di applicazione: MappingPendenzeRepository::sortForAssignment() (prefisso piu' lungo
 * prima, a parita' di lunghezza il custom; transazioni_count non conta).
 */
final class MappingL1Job
{
    public function __construct(
        private readonly MappingPendenzeRepository $repo,
        private readonly string $idDominio
    ) {}

    /** Scoperta pattern a cascata 5 -> 4 -> 3 char. Ritorna il numero di prefissi scoperti. */
    public function discover(): int
    {
        return $this->repo->discoverPatterns($this->idDominio);
    }

    /**
     * Un ciclo di assegnazione. Un'eccezione di getRules() si propaga (lo script la logga e ritenta).
     *
     * processed = righe assegnate + righe passate a NO_MATCH; ok = assegnate; skipped = NO_MATCH;
     * hadWork = assegnazioni > 0.
     */
    public function runOnce(JobContext $ctx): JobResult
    {
        $ok = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        if ($ctx->shouldStop()) {
            return new JobResult(0, 0, 0, 0, false);
        }

        $rules = $this->repo->getRules($this->idDominio);

        $activeRules = MappingPendenzeRepository::sortForAssignment(array_filter($rules, static fn(array $r): bool =>
            (!empty($r['fornitore']) && ((bool)($r['is_custom'] ?? false) || (int)($r['transazioni_count'] ?? 0) >= 5)) ||
            (!empty($r['accorpato_a']) && !empty($r['fornitore']))
        ));

        foreach ($activeRules as $rule) {
            if ($ctx->shouldStop()) {
                return new JobResult($ok + $skipped, $ok, $skipped, $errors, $ok > 0, $details);
            }
            try {
                $n = $this->repo->bulkAssignL1($this->idDominio, (string)$rule['pattern_iuv'], (string)$rule['fornitore']);
                if ($n > 0) {
                    $ctx->debug("'{$rule['pattern_iuv']}' → '{$rule['fornitore']}': {$n} righe assegnate.");
                    $ok += $n;
                }
            } catch (\Throwable $e) {
                $errors++;
                $details[] = ['ref' => (string)$rule['pattern_iuv'], 'reason' => 'Eccezione ' . get_class($e)];
                $ctx->debug("ERRORE pattern '{$rule['pattern_iuv']}': " . $e->getMessage());
            }
        }

        if ($ctx->shouldStop()) {
            return new JobResult($ok + $skipped, $ok, $skipped, $errors, $ok > 0, $details);
        }

        try {
            $skipped = $this->repo->bulkSetL1NoMatch($this->idDominio);
            if ($skipped > 0) {
                $ctx->debug("{$skipped} righe senza pattern corrispondente → NO_MATCH.");
            }
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $errors++;
            $details[] = ['ref' => 'NO_MATCH', 'reason' => 'Eccezione ' . get_class($e)];
            $ctx->debug('ERRORE bulk NO_MATCH: ' . $e->getMessage());
        }

        return new JobResult($ok + $skipped, $ok, $skipped, $errors, $ok > 0, $details);
    }
}
