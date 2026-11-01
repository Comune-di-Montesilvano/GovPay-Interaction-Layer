<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Services\Jobs\Message\ProcessMassiveRow;
use Doctrine\DBAL\Connection as DbalConnection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Lavoro periodico `pendenze-massive` (ex cron_pendenze_massive.php): NON elabora piu' le righe,
 * le prende in carico e accoda un messaggio ProcessMassiveRow per riga sulla coda `massive`
 * (elaborazione: ProcessMassiveRowHandler nel worker).
 *
 * Ogni giro (runOnce):
 * 1. Claim abbandonati (> STALE_SECONDS senza esito, es. worker ucciso) rimessi in coda:
 *    PROCESSING -> PENDING (l'idPendenza, se assegnato, e' gia' salvato sulla riga prima della
 *    PUT: il nuovo tentativo riusa lo stesso id), CANCEL_PROCESSING -> CANCEL_PENDING (mai un
 *    annullamento trasformato in creazione). Righe lasciate in lavorazione dal vecchio demone
 *    (claimed_at NULL, updated_at piu' vecchio di STALE_SECONDS): annullamenti -> CANCEL_PENDING;
 *    creazioni con idPendenza salvato -> PENDING; creazioni SENZA id salvato -> ERROR "esito
 *    incerto" (il vecchio demone salvava l'id solo dopo la PUT: la pendenza puo' esistere su
 *    GovPay con un id sconosciuto, un nuovo invio ne creerebbe una seconda).
 * 2. Contropressione: al massimo BATCH_SIZE messaggi `massive` in coda (compresi quelli in
 *    attesa di retry). Coda piena con righe in attesa -> nessun claim, prossimo giro dopo
 *    BACKPRESSURE_SECONDS.
 * 3. Claim atomico, nella STESSA transazione DBAL dell'inserimento dei messaggi (il trasporto
 *    usa la stessa connessione): UPDATE ... WHERE id = ? AND stato = 'PENDING' (o
 *    CANCEL_PENDING), messaggio solo se la riga e' cambiata. Due giri concorrenti non prendono mai
 *    la stessa riga; un errore di invio annulla anche i claim (nessuna riga presa senza messaggio).
 *    Come oggi gli annullamenti hanno la precedenza: con righe CANCEL_PENDING il giro prende solo
 *    quelle.
 *
 * hadWork = almeno una riga presa (il runner ripete subito: il giro successivo trova la coda
 * piena e si ferma). Coda vuota: IDLE_SECONDS (15 min, come oggi).
 */
final class MassiveEnqueueJob implements PeriodicJob
{
    public const NAME = 'pendenze-massive';
    public const QUEUE = 'massive';
    public const BATCH_SIZE = MassivePendenzeProcessor::BATCH_SIZE;
    public const IDLE_SECONDS = MassivePendenzeProcessor::IDLE_SECONDS;
    public const BACKPRESSURE_SECONDS = 5;
    public const STALE_SECONDS = 3600;

    public const LEGACY_INTERRUPTED_ERRORE = "Esito incerto: elaborazione interrotta (vecchio demone) \u{2014} verificare su GovPay prima di ripetere";

    private bool $backpressure = false;

    public function __construct(
        private readonly DbalConnection $dbal,
        private readonly SenderInterface $sender
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function emptyQueueIntervalSeconds(): int
    {
        return $this->backpressure ? self::BACKPRESSURE_SECONDS : self::IDLE_SECONDS;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $reset = $this->resetStaleClaims();
        foreach ($reset as $what => $n) {
            if ($n > 0) {
                $ctx->debug("Claim abbandonati: {$n} {$what}");
            }
        }

        $capacity = self::BATCH_SIZE - $this->outstandingMessages();
        if ($capacity <= 0) {
            $this->backpressure = $this->hasQueuedRows();
            return new JobResult(0, 0, 0, 0, false);
        }

        [$kind, $ids] = $this->selectCandidates($capacity);
        if ($ids === []) {
            $this->backpressure = false;
            $ctx->debug('Nessuna pendenza PENDING o CANCEL_PENDING. Attendo ' . self::IDLE_SECONDS . 's...');
            return new JobResult(0, 0, 0, 0, false);
        }

        $claimed = $this->claimAndDispatch($ids, $kind);
        // Nessuna presa (righe prese da un altro giro nel frattempo): riprova a breve.
        $this->backpressure = $claimed === 0;
        $ctx->debug("Accodate {$claimed} " . ($kind === 'cancel' ? 'richieste di annullamento' : 'pendenze') . '.');
        return new JobResult($claimed, $claimed, count($ids) - $claimed, 0, $claimed > 0);
    }

    /**
     * Prende in carico le righe $ids (tutte CANCEL_PENDING per 'cancel', PENDING per 'create') e
     * accoda un ProcessMassiveRow per ogni riga presa, in una sola transazione. Ritorna il numero
     * di righe prese.
     *
     * @param list<int> $ids
     * @param 'cancel'|'create' $kind
     */
    public function claimAndDispatch(array $ids, string $kind): int
    {
        [$from, $to] = $kind === 'cancel' ? ['CANCEL_PENDING', 'CANCEL_PROCESSING'] : ['PENDING', 'PROCESSING'];
        $claimed = 0;
        $this->dbal->beginTransaction();
        try {
            foreach ($ids as $id) {
                $token = bin2hex(random_bytes(16));
                $n = $this->dbal->executeStatement(
                    'UPDATE pendenze_massive SET stato = ?, claimed_at = NOW(), claim_token = ?, updated_at = NOW() WHERE id = ? AND stato = ?',
                    [$to, $token, $id, $from]
                );
                if ($n !== 1) {
                    continue;
                }
                $this->sender->send(new Envelope(new ProcessMassiveRow($id, $token)));
                $claimed++;
            }
            $this->dbal->commit();
        } catch (\Throwable $e) {
            $this->dbal->rollBack();
            throw $e;
        }
        return $claimed;
    }

    /**
     * Rimette in coda i claim abbandonati (vedi docblock della classe).
     *
     * @return array<string,int> descrizione => righe
     */
    public function resetStaleClaims(): array
    {
        $stale = 'NOW() - INTERVAL ' . self::STALE_SECONDS . ' SECOND';
        $hasId = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(response_json, '$.idPendenza')), '') NOT IN ('', 'null')";
        return [
            'creazioni rimesse in coda' => $this->dbal->executeStatement(
                "UPDATE pendenze_massive SET stato = 'PENDING', claimed_at = NULL, claim_token = NULL, updated_at = NOW()
                 WHERE stato = 'PROCESSING' AND claimed_at IS NOT NULL AND claimed_at < {$stale}"
            ),
            'annullamenti rimessi in coda' => $this->dbal->executeStatement(
                "UPDATE pendenze_massive SET stato = 'CANCEL_PENDING', claimed_at = NULL, claim_token = NULL, updated_at = NOW()
                 WHERE stato = 'CANCEL_PROCESSING' AND claimed_at IS NOT NULL AND claimed_at < {$stale}"
            ),
            'creazioni del vecchio demone rimesse in coda (id salvato)' => $this->dbal->executeStatement(
                "UPDATE pendenze_massive SET stato = 'PENDING', updated_at = NOW()
                 WHERE stato = 'PROCESSING' AND claimed_at IS NULL AND updated_at < {$stale} AND {$hasId}"
            ),
            // claimed_at = updated_at (prima del suo aggiornamento: SET da sinistra a destra):
            // riga elaborata, quindi "riprova" possibile dopo la verifica su GovPay.
            'creazioni del vecchio demone in errore (esito incerto)' => $this->dbal->executeStatement(
                "UPDATE pendenze_massive SET stato = 'ERROR', errore = ?, claimed_at = updated_at, claim_token = NULL, updated_at = NOW()
                 WHERE stato = 'PROCESSING' AND claimed_at IS NULL AND updated_at < {$stale} AND NOT ({$hasId})",
                [self::LEGACY_INTERRUPTED_ERRORE]
            ),
            'annullamenti del vecchio demone rimessi in coda' => $this->dbal->executeStatement(
                "UPDATE pendenze_massive SET stato = 'CANCEL_PENDING', updated_at = NOW()
                 WHERE stato = 'CANCEL_PROCESSING' AND claimed_at IS NULL AND updated_at < {$stale}"
            ),
        ];
    }

    private function outstandingMessages(): int
    {
        return (int)$this->dbal->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', [self::QUEUE]);
    }

    private function hasQueuedRows(): bool
    {
        return $this->dbal->fetchOne("SELECT 1 FROM pendenze_massive WHERE stato IN ('PENDING','CANCEL_PENDING') LIMIT 1") !== false;
    }

    /**
     * Come oggi: prima gli annullamenti (id ASC), solo se non ce ne sono le creazioni (id ASC).
     *
     * @return array{0:'cancel'|'create',1:list<int>}
     */
    private function selectCandidates(int $limit): array
    {
        $limit = max(1, $limit);
        $cancels = $this->dbal->fetchFirstColumn("SELECT id FROM pendenze_massive WHERE stato = 'CANCEL_PENDING' ORDER BY id ASC LIMIT {$limit}");
        if ($cancels !== []) {
            return ['cancel', array_values(array_map('intval', $cancels))];
        }
        $pending = $this->dbal->fetchFirstColumn("SELECT id FROM pendenze_massive WHERE stato = 'PENDING' ORDER BY id ASC LIMIT {$limit}");
        return ['create', array_values(array_map('intval', $pending))];
    }
}
