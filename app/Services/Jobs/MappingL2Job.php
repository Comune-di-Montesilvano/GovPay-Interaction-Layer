<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Database\MappingPendenzeRepository;

/**
 * Mapping L2 (cod_entrata): corpo del loop di cron_vocab_mapping.php.
 *
 * Un ciclo: per ogni pattern con fornitore risolto, keyword in ordine di priorita' (bulk UPDATE),
 * poi fallback default; le righe L1-processate rimaste vocab PENDING passano a NO_MATCH; infine
 * refresh diagnostics. Loop, pause (15 min a vuoto), stop file, log e gestione errore di
 * caricamento vocabolario restano nello script.
 */
final class MappingL2Job
{
    public function __construct(
        private readonly MappingPendenzeRepository $repo,
        private readonly string $idDominio
    ) {}

    /**
     * Un'eccezione di getVocabRules() si propaga (lo script la logga e ritenta).
     *
     * processed = righe classificate + righe NO_MATCH; ok = classificate (keyword + default);
     * skipped = NO_MATCH; hadWork = classificate > 0.
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

        $vocabIndex = $this->repo->getVocabRules($this->idDominio);

        foreach ($vocabIndex as $patternIuv => $patternData) {
            if ($ctx->shouldStop()) {
                return new JobResult($ok + $skipped, $ok, $skipped, $errors, $ok > 0, $details);
            }
            $keywords   = $patternData['keywords'] ?? [];  // già ordinati per priorita DESC
            $defaultCod = $patternData['default_cod_entrata'] ?? null;

            // Una riga già PROCESSED non viene ritoccata: la priorità è data dall'ordine.
            foreach ($keywords as $kw) {
                $keyword    = trim((string)($kw['keyword'] ?? ''));
                $codEntrata = trim((string)($kw['cod_entrata'] ?? ''));
                if ($keyword === '' || $codEntrata === '') {
                    continue;
                }
                try {
                    $n = $this->repo->bulkAssignVocabKeyword($this->idDominio, (string)$patternIuv, $keyword, $codEntrata);
                    if ($n > 0) {
                        $ctx->debug("'{$patternIuv}' keyword '{$keyword}' → '{$codEntrata}': {$n} righe.");
                        $ok += $n;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $details[] = ['ref' => (string)$patternIuv, 'reason' => 'Eccezione ' . get_class($e)];
                    $ctx->debug("ERRORE vocab keyword '{$keyword}': " . $e->getMessage());
                }
            }

            if ($defaultCod !== null && $defaultCod !== '') {
                try {
                    $n = $this->repo->bulkAssignVocabDefault($this->idDominio, (string)$patternIuv, $defaultCod);
                    if ($n > 0) {
                        $ctx->debug("'{$patternIuv}' fallback → '{$defaultCod}': {$n} righe.");
                        $ok += $n;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $details[] = ['ref' => (string)$patternIuv, 'reason' => 'Eccezione ' . get_class($e)];
                    $ctx->debug("ERRORE vocab default '{$patternIuv}': " . $e->getMessage());
                }
            }
        }

        if ($ctx->shouldStop()) {
            return new JobResult($ok + $skipped, $ok, $skipped, $errors, $ok > 0, $details);
        }

        try {
            $skipped = $this->repo->bulkSetVocabNoMatch($this->idDominio);
            if ($skipped > 0) {
                $ctx->debug("{$skipped} righe L1-processate senza keyword match → vocab NO_MATCH.");
            }
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $errors++;
            $details[] = ['ref' => 'NO_MATCH', 'reason' => 'Eccezione ' . get_class($e)];
            $ctx->debug('ERRORE bulk vocab NO_MATCH: ' . $e->getMessage());
        }

        try {
            $this->repo->refreshPatternDiagnostics($this->idDominio);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $errors++;
            $details[] = ['ref' => 'DIAGNOSTICS', 'reason' => 'Eccezione ' . get_class($e)];
            $ctx->debug('ERRORE refresh diagnostics: ' . $e->getMessage());
        }

        return new JobResult($ok + $skipped, $ok, $skipped, $errors, $ok > 0, $details);
    }
}
