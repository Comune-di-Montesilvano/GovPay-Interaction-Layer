<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Security;

/**
 * Link firmati HMAC stateless (usati dal frontoffice per i link pubblici avviso/ricevuta/checkout).
 * Payload = parametri + `expires` ordinati per chiave (ksort) serializzati con
 * http_build_query RFC3986; firma = HMAC-SHA256 esadecimale in `sig`.
 * Funzioni pure: chiave e istante corrente passati dal chiamante.
 */
final class SignedLink
{
    /**
     * Restituisce i parametri con `expires` (now + ttl, stringa) e `sig` aggiunti.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function sign(array $params, string $key, int $ttlSeconds, int $now): array
    {
        $expires = $now + $ttlSeconds;
        $params['expires'] = (string)$expires;
        ksort($params);
        $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $sig = hash_hmac('sha256', $payload, $key);
        return array_merge($params, ['sig' => $sig]);
    }

    /**
     * Verifica firma e scadenza dei parametri ricevuti (inclusi `expires` e `sig`).
     *
     * @param array<string, mixed> $params
     */
    public static function verify(array $params, string $key, int $now): bool
    {
        $sig = $params['sig'] ?? '';
        if ($sig === '') {
            return false;
        }
        $expires = (int)($params['expires'] ?? 0);
        if ($expires === 0 || $now > $expires) {
            return false;
        }
        $check = $params;
        unset($check['sig']);
        ksort($check);
        $payload = http_build_query($check, '', '&', PHP_QUERY_RFC3986);
        $expected = hash_hmac('sha256', $payload, $key);
        return hash_equals($expected, $sig);
    }
}
