<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Services\BizScannerService;

/**
 * Una iterazione del demone Biz scanner (corpo del loop di cron_biz_scanner.php):
 * accoda IUR non-GovPay da flussi_rendicontazioni, poi arricchisce da Biz Events
 * tutti i PENDING in-scope, flusso per flusso.
 *
 * Pause di idle (15 minuti) e stop file restano nello script: se hadWork e' false
 * lo script attende prima di richiamare runOnce().
 */
final class BizScanJob
{
    /** Le righe ERROR tornano PENDING solo dopo questo intervallo dall'errore (backoff). */
    public const ERROR_RETRY_MINUTES = 60;

    /** @var callable(int):mixed */
    private $sleeper;

    /**
     * @param (callable(int):mixed)|null $sleeper pausa tra chiamate Biz Events (default: sleep); iniettabile nei test
     */
    public function __construct(
        private readonly BizRepository $repo,
        private readonly FlussiRendicontazioniRepository $flussiRepo,
        private readonly BizScannerService $service,
        private readonly string $idDominio,
        ?callable $sleeper = null
    ) {
        $this->sleeper = $sleeper ?? 'sleep';
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $idDominio = $this->idDominio;
        $processed = 0;
        $ok = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];
        $sleep = $this->sleeper;

        $scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
        $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;

        try {
            $backlogBefore = $this->flussiRepo->countUnprocessedForBiz($idDominio, $minDate);
            $queueResult   = $this->service->queueFromCache($idDominio);
            $ctx->debug(sprintf(
                'queueFromCache: min_date=%s backlog_before=%d from_cache=%d queued=%d sample_iur=%s sample_flusso=%s',
                $queueResult['min_date'] !== '' ? $queueResult['min_date'] : '-',
                $backlogBefore,
                $queueResult['from_cache'],
                $queueResult['queued'],
                $queueResult['sample_iur'] !== '' ? $queueResult['sample_iur'] : '-',
                $queueResult['sample_flusso'] !== '' ? $queueResult['sample_flusso'] : '-'
            ));
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $queueResult = ['queued' => 0, 'from_cache' => 0, 'sample_iur' => '', 'sample_flusso' => '', 'min_date' => ''];
            $ctx->debug('ERRORE queueFromCache: ' . $e->getMessage());
        }

        if ($ctx->shouldStop()) {
            return new JobResult($processed, $ok, $skipped, $errors, $processed > 0, $details);
        }
        $counts         = $this->repo->getCounts($idDominio);
        $pendingInScope = $this->repo->countPendingForProcessing($idDominio, $minDate);
        $ctx->debug(sprintf(
            'Coda: PENDING=%d (in-scope=%d, min_date=%s) PROCESSED=%d ERROR=%d SKIPPED=%d',
            $counts['PENDING'],
            $pendingInScope,
            $minDate ?? '-',
            $counts['PROCESSED'],
            $counts['ERROR'],
            $counts['SKIPPED']
        ));

        // Enrich tutti i PENDING flusso per flusso
        while (true) {
            if ($ctx->shouldStop()) {
                return new JobResult($processed, $ok, $skipped, $errors, $processed > 0, $details);
            }
            $idFlusso = $this->repo->getNextPendingFlusso($idDominio, $minDate);
            if ($idFlusso === null) {
                $ctx->debug('Tutti i flussi in-scope elaborati.');
                break;
            }

            $rows  = $this->repo->fetchPendingByFlusso($idFlusso, $idDominio, $minDate);
            $total = count($rows);
            $ctx->debug("Flusso {$idFlusso}: {$total} pendenze");

            foreach ($rows as $i => $row) {
                if ($ctx->shouldStop()) {
                    return new JobResult($processed, $ok, $skipped, $errors, $processed > 0, $details);
                }
                $n   = $i + 1;
                $iur = (string)$row['iur'];
                $ctx->debug("  Pendenza {$n}/{$total} IUR={$iur}: interrogo Biz Events...");

                try {
                    $enriched = $this->service->enrichOne($row, $idDominio);
                } catch (\Throwable $e) {
                    $ctx->debug("  IUR={$iur}: eccezione - " . $e->getMessage());
                    // Riga in ERROR: se restasse PENDING il loop interno la riselezionerebbe all'infinito.
                    $this->repo->markError((int)$row['id'], 'Eccezione ' . get_class($e));
                    $processed++;
                    $errors++;
                    $details[] = ['ref' => $iur, 'reason' => 'Eccezione ' . get_class($e)];
                    continue;
                }

                if ($enriched['status'] === 'RATE_LIMITED') {
                    for ($attempt = 1; $attempt < 6 && $enriched['status'] === 'RATE_LIMITED'; $attempt++) {
                        $ctx->debug("  IUR={$iur}: rate limit 429 (tentativo {$attempt}/6) - pausa " . ($attempt + 1) . "s...");
                        $sleep($attempt + 1); // 2s, 3s, 4s, 5s, 6s
                        try {
                            $enriched = $this->service->enrichOne($row, $idDominio);
                        } catch (\Throwable $e) {
                            $ctx->debug("  IUR={$iur}: eccezione - " . $e->getMessage());
                            $enriched = ['status' => 'ERROR', 'reason' => 'Eccezione ' . get_class($e)];
                            $this->repo->markError((int)$row['id'], $enriched['reason']);
                            break;
                        }
                    }
                    if ($enriched['status'] === 'RATE_LIMITED') {
                        $ctx->debug("  IUR={$iur}: rate limit persistente - errore");
                        // 6 chiamate in tutto: la prima + 5 ritentativi.
                        $msg = 'Rate limit Biz Events (429) dopo 6 tentativi';
                        $this->repo->markError((int)$row['id'], $msg);
                        $sleep(10);
                        $processed++;
                        $errors++;
                        $details[] = ['ref' => $iur, 'reason' => $msg];
                        continue;
                    }
                }

                $processed++;
                switch ($enriched['status']) {
                    case 'PROCESSED':
                        $ok++;
                        $ctx->debug("  IUR={$iur}: OK — ricevuta salvata");
                        break;
                    case 'SKIPPED':
                        $skipped++;
                        $ctx->debug("  IUR={$iur}: skip - {$enriched['reason']}");
                        break;
                    case 'ERROR':
                        $errors++;
                        $details[] = ['ref' => $iur, 'reason' => mb_substr($enriched['reason'], 0, 200)];
                        $ctx->debug("  IUR={$iur}: errore - {$enriched['reason']}");
                        break;
                }

                $sleep(5);
            }

            $ctx->debug("Flusso {$idFlusso} completato. Proseguo al flusso successivo...");
        }

        if ((int)$queueResult['queued'] === 0 && (int)$pendingInScope === 0) {
            $resetErrors = $this->repo->resetErrors($idDominio, self::ERROR_RETRY_MINUTES);
            if ($resetErrors > 0) {
                $ctx->debug("Reset ERROR Biz -> PENDING: {$resetErrors} record. Riavvio ciclo immediato...");
                // Nessuna pausa: lo script richiama subito runOnce() (hadWork = true).
                return new JobResult($processed, $ok, $skipped, $errors, true, $details);
            }

            if ((int)$counts['PENDING'] > 0) {
                $ctx->debug('Nessun elemento nuovo e nessun PENDING in-scope. Restano PENDING fuori filtro data. Prossimo tra 15 minuti...');
            } else {
                $ctx->debug('Nessun elemento nuovo e nessun PENDING. Prossimo tra 15 minuti...');
            }
            return new JobResult($processed, $ok, $skipped, $errors, false, $details);
        }

        $ctx->debug('Nuovi elementi processati. Avvio ciclo successivo immediato...');
        return new JobResult($processed, $ok, $skipped, $errors, true, $details);
    }
}
