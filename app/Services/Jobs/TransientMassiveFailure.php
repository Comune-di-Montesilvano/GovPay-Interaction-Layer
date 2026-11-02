<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

/**
 * Errore transitorio della creazione di una riga massiva (rete / esito incerto, GovPay 5xx):
 * il messaggio ProcessMassiveRow va ritentato. forceRetry=false: il numero di tentativi e' quello
 * della strategia della coda `massive` (TransientOnlyRetryStrategy, 3 retry), non illimitato.
 *
 * Il messaggio contiene solo il motivo sintetico (status HTTP / errno cURL), mai corpi upstream.
 */
final class TransientMassiveFailure extends RecoverableMessageHandlingException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason, 0, null, null, false);
    }
}
