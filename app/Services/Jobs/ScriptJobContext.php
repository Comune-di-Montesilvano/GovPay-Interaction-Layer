<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Contesto usato dagli script cron legacy: shouldStop() verifica l'esistenza dello
 * stop file (senza rimuoverlo: lo fa il checkStop dello script, che poi esce);
 * debug() stampa su stdout come il closure $log degli script.
 */
final class ScriptJobContext implements JobContext
{
    public function __construct(private readonly string $stopFile) {}

    public function shouldStop(): bool
    {
        return file_exists($this->stopFile);
    }

    public function debug(string $msg): void
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
        flush();
    }
}
