<?php
declare(strict_types=1);

namespace Tests\Database;

use App\Database\MassivePendenzeRepository;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

/**
 * P7 (fix 8b): la tabella pendenze_massive e' creata dalla migrazione 035 (non solo da
 * MassivePendenzeRepository::ensureTable()), con la STESSA definizione di ensureTable().
 */
#[Group('db')]
final class PendenzeMassiveMigrationTest extends DbTestCase
{
    private static function showCreate(): string
    {
        $row = self::pdo()->query('SHOW CREATE TABLE pendenze_massive')->fetch(\PDO::FETCH_NUM);
        self::assertIsArray($row);
        return preg_replace('/ AUTO_INCREMENT=\d+/', '', (string)$row[1]) ?? '';
    }

    private static function migration035(): string
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/migrations/035_fix_difetti_demoni.sql');
        self::assertIsString($sql);
        return $sql;
    }

    public function testMigrationCreatesTableIdenticalToEnsureTableAndIsIdempotent(): void
    {
        $pdo = self::pdo();

        $pdo->exec('DROP TABLE IF EXISTS pendenze_massive');
        new MassivePendenzeRepository($pdo); // ensureTable()
        $fromEnsure = self::showCreate();

        $pdo->exec('DROP TABLE IF EXISTS pendenze_massive');
        $pdo->exec(self::migration035());
        $fromMigration = self::showCreate();
        self::assertSame($fromEnsure, $fromMigration);

        // idempotente: rieseguita su tabella esistente con dati non perde nulla
        $pdo->exec("INSERT INTO pendenze_massive (file_batch_id, riga, stato) VALUES ('B', 1, 'PENDING')");
        $pdo->exec(self::migration035());
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM pendenze_massive')->fetchColumn());
        $pdo->exec('TRUNCATE TABLE pendenze_massive');
    }
}
