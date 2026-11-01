<?php
declare(strict_types=1);

namespace Tests\Database;

use App\Database\MassivePendenzeRepository;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

/**
 * P7 (fix 8b): la tabella pendenze_massive e' creata dalla migrazione 035 (non solo da
 * MassivePendenzeRepository::ensureTable()); con le colonne di claim della 037 (Task 11) il
 * percorso delle migrazioni (035 + 037) produce la STESSA definizione di ensureTable().
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

    private static function migration(string $file): string
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/migrations/' . $file);
        self::assertIsString($sql);
        return $sql;
    }

    private static function migrate(): void
    {
        self::pdo()->exec(self::migration('035_fix_difetti_demoni.sql'));
        self::pdo()->exec(self::migration('037_pendenze_massive_claim.sql'));
    }

    public function testMigrationCreatesTableIdenticalToEnsureTableAndIsIdempotent(): void
    {
        $pdo = self::pdo();

        $pdo->exec('DROP TABLE IF EXISTS pendenze_massive');
        new MassivePendenzeRepository($pdo); // ensureTable()
        $fromEnsure = self::showCreate();

        $pdo->exec('DROP TABLE IF EXISTS pendenze_massive');
        self::migrate();
        $fromMigration = self::showCreate();
        self::assertSame($fromEnsure, $fromMigration);

        // idempotente: rieseguite su tabella esistente con dati non perdono nulla
        $pdo->exec("INSERT INTO pendenze_massive (file_batch_id, riga, stato, claim_token) VALUES ('B', 1, 'PENDING', 'tok')");
        self::migrate();
        self::assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM pendenze_massive')->fetchColumn());
        self::assertSame('tok', $pdo->query('SELECT claim_token FROM pendenze_massive')->fetchColumn());
        self::assertSame($fromEnsure, self::showCreate());
        $pdo->exec('TRUNCATE TABLE pendenze_massive');
    }
}
