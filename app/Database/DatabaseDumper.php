<?php
declare(strict_types=1);

namespace App\Database;

/**
 * Dump SQL del database corrente via PDO, scritto in streaming in un file gzip (memoria costante,
 * nessun fetchAll). Formato usato dal backup manuale (Backoffice → Backup) e dal backup
 * automatico prima delle migrazioni: FK disattivate, per tabella DROP + CREATE + INSERT IGNORE
 * a batch, tabelle senza FK per prime (al ripristino le referenziate esistono gia').
 */
final class DatabaseDumper
{
    private const BATCH_SIZE = 500;

    /**
     * @throws \RuntimeException se il file non si apre o il dump fallisce (file parziale rimosso)
     */
    public static function dumpToGzip(\PDO $pdo, string $targetFile): void
    {
        $gz = @gzopen($targetFile, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException('dump DB: impossibile aprire ' . $targetFile);
        }

        try {
            /** @var list<string> $tables */
            $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);

            $ddls = [];
            foreach ($tables as $table) {
                $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                $ddls[$table] = (string)($row['Create Table'] ?? $row[array_keys($row)[1]] ?? '');
            }

            usort($tables, static function (string $a, string $b) use ($ddls): int {
                $aFk = str_contains($ddls[$a], 'FOREIGN KEY');
                $bFk = str_contains($ddls[$b], 'FOREIGN KEY');
                if ($aFk === $bFk) {
                    return 0;
                }
                return $aFk ? 1 : -1;
            });

            self::write($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                self::write($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
                self::write($gz, $ddls[$table] . ";\n\n");

                $stmt = $pdo->query("SELECT * FROM `{$table}`");
                $cols = '';
                $batch = [];
                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($cols === '') {
                        $cols = '`' . implode('`, `', array_keys($row)) . '`';
                    }
                    $vals = array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                    $batch[] = '(' . implode(', ', $vals) . ')';
                    if (count($batch) >= self::BATCH_SIZE) {
                        self::write($gz, "INSERT IGNORE INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    self::write($gz, "INSERT IGNORE INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batch) . ";\n");
                }
                if ($cols !== '') {
                    self::write($gz, "\n");
                }
            }

            self::write($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\Throwable $e) {
            @gzclose($gz);
            @unlink($targetFile);
            throw new \RuntimeException('dump DB fallito: ' . $e->getMessage(), 0, $e);
        }

        if (!gzclose($gz)) {
            @unlink($targetFile);
            throw new \RuntimeException('dump DB: chiusura di ' . $targetFile . ' fallita');
        }
    }

    /** @param resource $gz */
    private static function write($gz, string $data): void
    {
        if (gzwrite($gz, $data) === false) {
            throw new \RuntimeException('scrittura fallita (disco pieno?)');
        }
    }
}
