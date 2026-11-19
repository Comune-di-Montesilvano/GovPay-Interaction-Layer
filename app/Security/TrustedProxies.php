<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Security;

/**
 * Riconoscimento dei reverse proxy fidati (TRUSTED_PROXIES): solo da questi si accetta
 * X-Forwarded-For per ricavare l'IP del client.
 *
 * Voci separate da virgola: IP esatti, CIDR IPv4/IPv6 (es. 172.19.0.0/16, fd00::/8) o "*".
 * Un CIDR serve quando il proxy non ha un IP fisso, es. Traefik su una rete Docker condivisa:
 * l'IP del container cambia a ogni ricreazione, la subnet della rete no.
 */
final class TrustedProxies
{
    public static function isTrusted(string $ip, string $setting): bool
    {
        $ipBin = self::toBinary($ip);
        if ($ipBin === null) {
            return false;
        }

        foreach (explode(',', $setting) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === '*') {
                return true;
            }
            if (str_contains($entry, '/')) {
                if (self::inCidr($ipBin, $entry)) {
                    return true;
                }
                continue;
            }
            // Confronto sulla forma binaria: "::1" e "0:0::1" sono lo stesso indirizzo.
            if (self::toBinary($entry) === $ipBin) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $ipBin, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $networkBin = self::toBinary(trim($network));
        $bits = trim($bits);
        if ($networkBin === null || !ctype_digit($bits) || strlen($networkBin) !== strlen($ipBin)) {
            return false;
        }

        $prefix = (int) $bits;
        if ($prefix > strlen($ipBin) * 8) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        if (substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefix % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
    }

    private static function toBinary(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($ip);
        return $bin === false ? null : $bin;
    }
}
