<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Security;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Token CSRF dei POST di controllo lavori (pagina Cron, Services Hub in home, "riprova" righe
 * massive, avvio/pausa servizi dal report TEFA). Stesso schema per-controller di
 * MappingPendenzeController (token in sessione, campo `csrf_token` nel corpo, hash_equals),
 * con una chiave di sessione condivisa cosi' ogni pagina che posta su queste route ha il token.
 */
final class JobControlCsrf
{
    public const SESSION_KEY = 'cron_csrf';
    public const FIELD = 'csrf_token';
    public const ERROR_MESSAGE = 'Token di sicurezza mancante o non valido: ricaricare la pagina e riprovare.';

    public static function token(): string
    {
        self::startSession();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function isValid(ServerRequestInterface $request): bool
    {
        self::startSession();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        $body = $request->getParsedBody();
        $provided = is_array($body) ? ($body[self::FIELD] ?? '') : '';
        return is_string($expected) && is_string($provided)
            && $expected !== '' && $provided !== ''
            && hash_equals($expected, $provided);
    }

    /** Corpo JSON del rifiuto (403): chiavi di entrambi gli stili usati dai chiamanti JS. */
    public static function errorPayload(): array
    {
        return ['success' => false, 'ok' => false, 'message' => self::ERROR_MESSAGE, 'error' => self::ERROR_MESSAGE];
    }

    private static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}
