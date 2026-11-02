<?php
declare(strict_types=1);

namespace App\Services\Jobs\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Ricevitore che cede il turno: dopo $maxConsecutive messaggi consecutivi restituisce una volta
 * "nessun messaggio", cosi' il Worker passa ai ricevitori successivi (scheduler) anche con una
 * coda `massive` piena. Il Worker riparte dal primo ricevitore dopo ogni messaggio: senza questo
 * cap lo scheduler (tutti i lavori periodici) aspetterebbe lo svuotamento della coda massive
 * (fino a ~50 righe x 90 s di timeout PUT con GovPay lento).
 */
final class FairShareReceiver implements ReceiverInterface
{
    private int $streak = 0;

    public function __construct(private readonly ReceiverInterface $inner, private readonly int $maxConsecutive) {}

    public function get(): iterable
    {
        if ($this->streak >= $this->maxConsecutive) {
            $this->streak = 0;
            return [];
        }
        $envelopes = [];
        foreach ($this->inner->get() as $envelope) {
            $envelopes[] = $envelope;
        }
        $this->streak = $envelopes === [] ? 0 : $this->streak + count($envelopes);
        return $envelopes;
    }

    public function ack(Envelope $envelope): void
    {
        $this->inner->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->inner->reject($envelope);
    }
}
