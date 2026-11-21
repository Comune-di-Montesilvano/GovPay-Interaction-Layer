<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Security;

/**
 * Destinazione di ritorno dopo un login (`return_to`): solo un percorso locale dello stesso
 * host, altrimenti "/". Rifiuta `//host` e `/\host` (i browser li trattano come URL
 * protocol-relative verso un altro host: open redirect), backslash, caratteri di controllo
 * (header injection) e tutto cio' che non inizia con un solo "/".
 */
final class SafeReturnPath
{
    public static function normalize(string $raw): string
    {
        if ($raw === '' || $raw[0] !== '/') {
            return '/';
        }
        if (str_contains($raw, '\\') || preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return '/';
        }
        if (strlen($raw) > 1 && $raw[1] === '/') {
            return '/';
        }
        return $raw;
    }
}
