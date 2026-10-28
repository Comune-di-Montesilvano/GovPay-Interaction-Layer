<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Config\SettingsRepository;
use App\Services\GovPayDebitoreService;

/**
 * Una iterazione del demone GovPay debitore (corpo del loop di
 * cron_govpay_debitore_scanner.php): prende un batch di IUR is_govpay=1 senza
 * biz_ricevute (o con un tentativo fallito ERROR piu' vecchio di 60 minuti), interroga
 * GovPay per ogni pendenza e salva il debitore. Ogni errore registra una riga ERROR.
 *
 * Pausa di idle (15 minuti), stop file e gestione dell'errore di getBatch (Sentry +
 * log + sleep 30s) restano nello script: un'eccezione di getBatch si propaga da
 * runOnce(). Se hadWork e' false lo script attende prima di richiamare runOnce().
 */
final class GovPayDebitoreJob
{
    public const BATCH_SIZE = 100;

    /** @var callable(int):mixed */
    private $sleeper;

    /**
     * @param (callable(int):mixed)|null $sleeper pausa tra chiamate (default: sleep); iniettabile nei test
     */
    public function __construct(
        private readonly GovPayDebitoreService $service,
        private readonly string $idDominio,
        ?callable $sleeper = null
    ) {
        $this->sleeper = $sleeper ?? 'sleep';
    }

    /** @throws \Throwable se il recupero del batch fallisce */
    public function runOnce(JobContext $ctx): JobResult
    {
        // Ogni iterazione rilegge i settings: in un processo longevo la cache statica
        // resterebbe ferma ai valori dell'avvio (modifiche da UI ignorate fino al riavvio).
        SettingsRepository::flush();

        $processed = 0;
        $ok = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];
        $sleep = $this->sleeper;

        $scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
        $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;

        $batch = $this->service->getBatch($this->idDominio, self::BATCH_SIZE, $minDate);

        if (count($batch) === 0) {
            $ctx->debug('Coda vuota. Prossimo controllo tra 15 minuti...');
            return new JobResult(0, 0, 0, 0, false);
        }

        $total = count($batch);
        $ctx->debug("Batch: {$total} pendenze GovPay da arricchire.");

        foreach ($batch as $i => $row) {
            if ($ctx->shouldStop()) {
                return new JobResult($processed, $ok, $skipped, $errors, $processed > 0, $details);
            }
            $n   = $i + 1;
            $iur = (string)$row['iur'];
            $idP = (string)($row['id_pendenza'] ?? '');
            $ctx->debug("  {$n}/{$total} IUR={$iur} id_pendenza={$idP}");

            try {
                $result = $this->service->enrichOne($row);
            } catch (\Throwable $e) {
                $ctx->debug("  IUR={$iur}: eccezione - " . $e->getMessage());
                // Registra il tentativo fallito (backoff): senza, la riga tornerebbe a ogni giro.
                try {
                    $this->service->recordError($row, 'Eccezione ' . get_class($e));
                } catch (\Throwable $recordErr) {
                    $ctx->debug("  IUR={$iur}: registrazione errore fallita - " . get_class($recordErr));
                }
                $processed++;
                $errors++;
                $details[] = ['ref' => $iur, 'reason' => 'Eccezione ' . get_class($e)];
                $sleep(2);
                continue;
            }

            $processed++;
            switch ($result['status']) {
                case 'PROCESSED':
                    $ok++;
                    $ctx->debug("  IUR={$iur}: OK — debitore salvato");
                    break;
                case 'SKIPPED':
                    $skipped++;
                    $ctx->debug("  IUR={$iur}: skip - {$result['reason']}");
                    break;
                case 'SKIP':
                    $skipped++;
                    $ctx->debug("  IUR={$iur}: configurazione mancante - {$result['reason']}");
                    break;
                case 'ERROR':
                    $errors++;
                    $details[] = ['ref' => $iur, 'reason' => GovPayDebitoreService::safeReason($result['reason'])];
                    $ctx->debug("  IUR={$iur}: errore - {$result['reason']}");
                    break;
            }

            if ($i < $total - 1) {
                $sleep(1);
            }
        }

        $ctx->debug("Batch completato ({$total} record). Avvio ciclo successivo...");
        return new JobResult($processed, $ok, $skipped, $errors, true, $details);
    }
}
