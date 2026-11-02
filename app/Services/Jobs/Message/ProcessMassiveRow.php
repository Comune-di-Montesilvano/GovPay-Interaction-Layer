<?php
declare(strict_types=1);

namespace App\Services\Jobs\Message;

/**
 * Elabora la riga pendenze_massive $rowId presa con il claim $claimToken (MassiveEnqueueJob).
 * Coda Doctrine `massive`. Il token rende il messaggio idempotente: duplicati e messaggi
 * riconsegnati dopo un reset del claim non trovano piu' il loro token sulla riga (no-op).
 */
final class ProcessMassiveRow
{
    public function __construct(public readonly int $rowId, public readonly string $claimToken) {}
}
