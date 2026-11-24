<?php
declare(strict_types=1);

namespace App\Database;

/** Esito di MigrationRunner::run(). */
final class MigrationResult
{
    /**
     * @param list<string> $tolerated  file falliti ma tollerati (solo al primo avvio col registro)
     * @param list<string> $mismatched file registrati il cui contenuto e' cambiato (non rieseguiti)
     */
    public function __construct(
        public readonly int $applied,
        public readonly bool $bootstrap,
        public readonly ?string $backupFile,
        public readonly array $tolerated,
        public readonly array $mismatched,
    ) {
    }
}
