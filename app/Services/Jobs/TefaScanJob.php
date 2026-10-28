<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\TefaRepository;
use App\Services\TefaScannerService;

/**
 * Una iterazione del demone TEFA scanner (corpo del loop di cron_tefa_scanner.php):
 * accoda IUR da biz_ricevute (PROCESSED, non ancora in tefa_ricevute) e li classifica
 * TEFA/non-TEFA, flusso per flusso.
 *
 * Il gate `tefa_enabled`, l'allineamento retroattivo all'avvio, la pausa di idle (15 minuti)
 * e lo stop file restano nello script.
 */
final class TefaScanJob
{
    /** Le righe ERROR tornano PENDING solo dopo questo intervallo dall'errore (backoff). */
    public const ERROR_RETRY_MINUTES = 60;

    public function __construct(
        private readonly TefaRepository $repo,
        private readonly BizRepository $bizRepo,
        private readonly TefaScannerService $service,
        private readonly string $idDominio
    ) {}

    /** Gate del demone: attivo solo se `backoffice.tefa_enabled` vale esattamente 'true'. */
    public static function isEnabled(): bool
    {
        return SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $idDominio = $this->idDominio;
        $processed = 0;
        $ok = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        $scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
        $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;

        try {
            $backlogBefore = $this->bizRepo->countProcessedForTefa($idDominio, $minDate);
            $queueResult   = $this->service->queueFromCache($idDominio);
            $ctx->debug(sprintf(
                'queueFromCache: source=biz_ricevute min_date=%s backlog_before=%d from_cache=%d queued=%d sample_iur=%s sample_flusso=%s',
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

        // Classifica tutti i PENDING flusso per flusso
        while (true) {
            if ($ctx->shouldStop()) {
                return new JobResult($processed, $ok, $skipped, $errors, $processed > 0, $details);
            }
            $idFlusso = $this->repo->getNextPendingFlusso($idDominio, $minDate);
            if ($idFlusso === null) {
                $ctx->debug('Tutti i flussi in-scope classificati.');
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
                $ctx->debug("  Pendenza {$n}/{$total} IUR={$iur}: classifico TEFA...");

                try {
                    $result = $this->service->enrichOne($row, $idDominio);
                } catch (\Throwable $e) {
                    $ctx->debug("  IUR={$iur}: eccezione - " . $e->getMessage());
                    // Riga in ERROR: se restasse PENDING il loop interno la riselezionerebbe all'infinito.
                    $this->repo->markError((int)$row['id'], 'Eccezione ' . get_class($e));
                    $processed++;
                    $errors++;
                    $details[] = ['ref' => $iur, 'reason' => 'Eccezione ' . get_class($e)];
                    continue;
                }

                $processed++;
                switch ($result['status']) {
                    case 'PROCESSED':
                        $ok++;
                        $cf  = $result['cf_comune'];
                        $imp = number_format($result['importo_tefa'], 2, ',', '.');
                        $ctx->debug("  IUR={$iur}: TEFA OK  EUR {$imp} -> comune {$cf}");
                        break;
                    case 'SKIPPED':
                        $skipped++;
                        $ctx->debug("  IUR={$iur}: skip - {$result['reason']}");
                        break;
                    case 'ERROR':
                        $errors++;
                        $details[] = ['ref' => $iur, 'reason' => mb_substr($result['reason'], 0, 200)];
                        $ctx->debug("  IUR={$iur}: errore - {$result['reason']}");
                        break;
                }
            }

            $ctx->debug("Flusso {$idFlusso} completato. Proseguo al flusso successivo...");
        }

        if ((int)$queueResult['queued'] === 0 && (int)$pendingInScope === 0) {
            $resetErrors = $this->repo->resetErrors($idDominio, self::ERROR_RETRY_MINUTES);
            if ($resetErrors > 0) {
                $ctx->debug("Reset ERROR TEFA -> PENDING: {$resetErrors} record. Riavvio ciclo immediato...");
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

        $ctx->debug('Nuovi elementi classificati. Avvio ciclo successivo immediato...');
        return new JobResult($processed, $ok, $skipped, $errors, true, $details);
    }
}
