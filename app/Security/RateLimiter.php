<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Security;

use App\Database\Connection;
use App\Logger;

class RateLimiter
{
    /**
     * Check if a request is allowed. If allowed, increments the counter.
     * 
     * @param string $key Unique identifier (e.g. "ip:1.2.3.4" or "email:user@test.com")
     * @param int $limit Max number of requests allowed in the window
     * @param int $windowSec Window size in seconds
     * @return bool True if allowed, false if rate limit exceeded
     */
    public static function check(string $key, int $limit, int $windowSec): bool
    {
        try {
            $pdo = Connection::getPDO();
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('RateLimiter: DB non disponibile, fail-open', [
                'error' => $e->getMessage()
            ]);
            return true;
        }

        $now = time();
        $threshold = $now - $windowSec;

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO rate_limit_buckets (bucket_key, window_start, count) VALUES (:k, :w, 1) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'count = IF(window_start < :t, 1, count + 1), '
                . 'window_start = IF(window_start < :t2, :w2, window_start)'
            );
            $stmt->execute([':k' => $key, ':w' => $now, ':w2' => $now, ':t' => $threshold, ':t2' => $threshold]);

            $sel = $pdo->prepare('SELECT count FROM rate_limit_buckets WHERE bucket_key = :k LIMIT 1');
            $sel->execute([':k' => $key]);
            $row = $sel->fetch(\PDO::FETCH_ASSOC);
            $count = is_array($row) ? (int)($row['count'] ?? 0) : 0;

            // Garbage collection
            if (mt_rand(1, 100) === 1) {
                $pdo->prepare('DELETE FROM rate_limit_buckets WHERE window_start < :t')
                    ->execute([':t' => $now - max(600, $windowSec * 10)]);
            }

            return $count <= $limit;
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('RateLimiter: errore query rate limit, fail-open', [
                'error' => $e->getMessage(),
                'key' => $key
            ]);
            return true;
        }
    }

    /**
     * Helper to get client IP taking TRUSTED_PROXIES into account.
     */
    public static function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        
        $trustedProxiesStr = $_ENV['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: '';
        if (empty($trustedProxiesStr)) {
            return $remoteAddr;
        }

        $trustedProxies = array_map('trim', explode(',', $trustedProxiesStr));
        
        // If REMOTE_ADDR is in TRUSTED_PROXIES, check forwarding headers
        if (in_array($remoteAddr, $trustedProxies, true) || in_array('*', $trustedProxies, true)) {
            $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'];
            foreach ($headers as $header) {
                if (!empty($_SERVER[$header])) {
                    $ips = array_map('trim', explode(',', $_SERVER[$header]));
                    $ip = $ips[0];
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return $remoteAddr;
    }
}
