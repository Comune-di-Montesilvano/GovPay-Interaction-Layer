<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Database;

use PDO;
use PDOException;
use App\Config\ConfigLoader;

class Connection
{
    private static ?PDO $pdo = null;

    public static function getPDO(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host   = getenv('DB_HOST') ?: ConfigLoader::get('db.host', 'db');
        $port   = getenv('DB_PORT') ?: ConfigLoader::get('db.port', '3306');
        $dbname = getenv('DB_NAME') ?: ConfigLoader::get('db.name', 'govpay');
        $user   = getenv('DB_USER') ?: ConfigLoader::get('db.user', 'govpay');
        $pass   = ConfigLoader::get('db.password') ?: getenv('DB_PASSWORD') ?: '';

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $dbname);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
            // Ensure utf8mb4
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            return self::$pdo;
        } catch (PDOException $e) {
            throw $e;
        }
    }

    /**
     * Esegue $fn ritentando su deadlock InnoDB (SQLSTATE 40001 / errore MySQL 1213)
     * e su lock wait timeout (1205). Transitori attesi quando piu' demoni fanno
     * bulk UPDATE concorrenti sulle stesse tabelle (es. mapping L1/L2 su
     * flussi_rendicontazioni). Backoff esponenziale con jitter, max $maxAttempts tentativi.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function retryOnDeadlock(callable $fn, int $maxAttempts = 5)
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $fn();
            } catch (PDOException $e) {
                $sqlState = $e->errorInfo[0] ?? $e->getCode();
                $driverCode = (int)($e->errorInfo[1] ?? 0);
                $isDeadlock = $sqlState === '40001' || in_array($driverCode, [1213, 1205], true);
                if (!$isDeadlock || $attempt >= $maxAttempts) {
                    throw $e;
                }
                $baseDelayMs = 50 * (1 << ($attempt - 1));
                $jitterMs    = random_int(0, 100);
                usleep((int)min(2000, $baseDelayMs + $jitterMs) * 1000);
            }
        }
    }
}
