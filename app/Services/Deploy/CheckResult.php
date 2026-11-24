<?php
declare(strict_types=1);

namespace App\Services\Deploy;

/** Esito di un controllo di post-deploy-check. `detail` mai con token, URL completi o corpi di risposta. */
final class CheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly bool $ok,
        public readonly string $detail,
    ) {
    }
}
