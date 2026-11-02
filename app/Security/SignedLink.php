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
     * Non lancia mai: qualsiasi input non valido (chiave vuota, `sig`/`expires` assenti, non
     * stringa — es. `sig[]=x` nella query — o malformati) da' false.
     *
     * @param array<array-key, mixed> $params
     */
    public static function verify(array $params, string $key, int $now): bool
    {
        // Chiave vuota: chiunque potrebbe calcolare la firma, mai valida.
        if ($key === '') {
            return false;
        }
        $sig = $params['sig'] ?? null;
        if (!is_string($sig) || preg_match('/^[0-9a-f]{64}$/', $sig) !== 1) {
            return false;
        }
        // Solo intero decimale positivo (come lo scrive sign()): niente "1e9", " 123", array.
        $expiresRaw = $params['expires'] ?? null;
        if (!is_string($expiresRaw) || preg_match('/^[1-9][0-9]{0,17}$/', $expiresRaw) !== 1) {
            return false;
        }
        if ($now > (int)$expiresRaw) {
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
