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
 * - Pausa: con il lavoro `pendenze-massive` disabilitato (letto a ogni messaggio) nessuna chiamata
 *   GovPay; la riga torna PENDING / CANCEL_PENDING (id conservato) e il messaggio e' confermato,
 *   senza consumare tentativi. Un errore di lettura del flag non ferma l'elaborazione.
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
     * @param (\Closure(): bool)|null $isEnabled flag del lavoro `pendenze-massive` (null = sempre attivo)
     */
    public function __construct(
        private readonly \Closure $processorFactory,
        private readonly \Closure $output,
        private readonly ?\Closure $isEnabled = null
    ) {}

    public function __invoke(ProcessMassiveRow $message): void
    {
        SettingsRepository::flush();
        if (!$this->enabled()) {
            ($this->processorFactory)()->releaseClaimedRow($message->rowId, $message->claimToken);
            return;
        }
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

    private function enabled(): bool
    {
        if ($this->isEnabled === null) {
            return true;
        }
        try {
            return ($this->isEnabled)();
        } catch (\Throwable) {
            // DB non leggibile: l'elaborazione fallirebbe comunque sulla stessa connessione e
            // seguirebbe il percorso di errore normale; mai una pausa "per errore".
            return true;
        }
    }
}
