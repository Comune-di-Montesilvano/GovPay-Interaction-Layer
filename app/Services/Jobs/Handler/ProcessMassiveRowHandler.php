<?php
declare(strict_types=1);

namespace App\Services\Jobs\Handler;

use App\Config\SettingsRepository;
use App\Services\Jobs\MassivePendenzeProcessor;
use App\Services\Jobs\Message\ProcessMassiveRow;
use App\Services\Jobs\TransientMassiveFailure;

/**
 * Gestore di ProcessMassiveRow: una riga per messaggio (MassivePendenzeProcessor::processClaimedRow).
 *
 * - Processore costruito per messaggio (connessione PDO fresca, impostazioni rilette).
 * - Errore transitorio: TransientMassiveFailure esce dal gestore -> nuovo tentativo della coda
 *   `massive` (vedi WorkerKernel). Ogni altro esito e' gia' scritto sulla riga: ack.
 * - $output: una riga per errore definitivo o tentativo fallito (id riga + motivo, mai dati
 *   personali); il worker antepone data e suite (SUITE).
 */
final class ProcessMassiveRowHandler
{
    public const SUITE = 'cron-pendenze-massive';

    /**
     * @param \Closure(): MassivePendenzeProcessor $processorFactory
     * @param \Closure(string): void $output
     */
    public function __construct(
        private readonly \Closure $processorFactory,
        private readonly \Closure $output
    ) {}

    public function __invoke(ProcessMassiveRow $message): void
    {
        SettingsRepository::flush();
        try {
            $result = ($this->processorFactory)()->processClaimedRow($message->rowId, $message->claimToken);
        } catch (TransientMassiveFailure $e) {
            ($this->output)("riga {$message->rowId}: errore transitorio ({$e->getMessage()})");
            throw $e;
        }
        foreach ($result->errorDetails as $d) {
            ($this->output)("riga {$d['ref']}: {$d['reason']}");
        }
    }
}
