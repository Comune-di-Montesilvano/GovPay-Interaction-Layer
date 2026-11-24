<?php
declare(strict_types=1);

namespace Tests\Database;

use App\Database\DatabaseDumper;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

#[Group('db')]
final class DatabaseDumperTest extends DbTestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS zz_dump_child');
        $pdo->exec('DROP TABLE IF EXISTS zz_dump_parent');
        $pdo->exec('CREATE TABLE zz_dump_parent (id INT PRIMARY KEY, nome VARCHAR(50) NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE zz_dump_child (id INT PRIMARY KEY, parent_id INT NOT NULL,
            CONSTRAINT fk_zz_dump FOREIGN KEY (parent_id) REFERENCES zz_dump_parent(id)) ENGINE=InnoDB');
        $pdo->exec("INSERT INTO zz_dump_parent VALUES (1, 'l''apice'), (2, NULL)");
        $pdo->exec('INSERT INTO zz_dump_child VALUES (10, 1), (11, 2)');
        $this->file = sys_get_temp_dir() . '/gil-dumper-test-' . bin2hex(random_bytes(4)) . '.sql.gz';
    }

    protected function tearDown(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS zz_dump_child');
        $pdo->exec('DROP TABLE IF EXISTS zz_dump_parent');
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testRoundTripRipristinaTabelleERighe(): void
    {
        $pdo = self::pdo();
        DatabaseDumper::dumpToGzip($pdo, $this->file);
        self::assertFileExists($this->file);

        $pdo->exec('DROP TABLE zz_dump_child');
        $pdo->exec('DROP TABLE zz_dump_parent');

        $sql = gzdecode((string)file_get_contents($this->file));
        self::assertIsString($sql);
        $pdo->exec($sql);

        $parent = $pdo->query('SELECT id, nome FROM zz_dump_parent ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['id' => 1, 'nome' => "l'apice"], ['id' => 2, 'nome' => null]], array_map(
            static fn(array $r): array => ['id' => (int)$r['id'], 'nome' => $r['nome']],
            $parent
        ));
        self::assertSame(2, (int)$pdo->query('SELECT COUNT(*) FROM zz_dump_child')->fetchColumn());
    }

    public function testFileNonScrivibileLanciaEccezione(): void
    {
        $target = sys_get_temp_dir() . '/gil-dir-inesistente-' . bin2hex(random_bytes(4)) . '/dump.sql.gz';
        try {
            DatabaseDumper::dumpToGzip(self::pdo(), $target);
            self::fail('attesa RuntimeException');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($target);
        }
    }
}
