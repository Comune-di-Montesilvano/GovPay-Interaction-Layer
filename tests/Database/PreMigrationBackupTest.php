<?php
declare(strict_types=1);

namespace Tests\Database;

use App\Database\PreMigrationBackup;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

#[Group('db')]
final class PreMigrationBackupTest extends DbTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/gil-premig-' . bin2hex(random_bytes(4)) . '/pre-migrazione';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
            rmdir(dirname($this->dir));
        }
        parent::tearDown();
    }

    public function testRotazioneTieneGliUltimiN(): void
    {
        mkdir($this->dir, 0775, true);
        foreach (['20200101-000000-1.0.0', '20200102-000000-1.0.1', '20200103-000000-1.0.2', '20200104-000000-1.0.3'] as $n) {
            file_put_contents("{$this->dir}/{$n}.sql.gz", 'x');
        }

        $file = PreMigrationBackup::create(self::pdo(), $this->dir, '1.12.0', 3);

        self::assertFileExists($file);
        $rest = array_map('basename', glob($this->dir . '/*.sql.gz') ?: []);
        sort($rest);
        self::assertSame(['20200103-000000-1.0.2.sql.gz', '20200104-000000-1.0.3.sql.gz', basename($file)], $rest);
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-1\.12\.0\.sql\.gz$/', basename($file));
    }

    public function testStessaChiaveRiusaIlBackup(): void
    {
        // Crash loop su una migrazione rotta: ogni riavvio non deve ruotare via il dump buono.
        $primo = PreMigrationBackup::create(self::pdo(), $this->dir, '1.12.0', 3, 'chiave-a');
        $secondo = PreMigrationBackup::create(self::pdo(), $this->dir, '1.12.0', 3, 'chiave-a');

        self::assertSame($primo, $secondo);
        self::assertCount(1, glob($this->dir . '/*.sql.gz') ?: []);
    }

    public function testChiaveDiversaNuovoBackup(): void
    {
        $primo = PreMigrationBackup::create(self::pdo(), $this->dir, '1.12.0', 3, 'chiave-a');
        $secondo = PreMigrationBackup::create(self::pdo(), $this->dir, '1.12.0', 3, 'chiave-b');

        self::assertNotSame($primo, $secondo);
        self::assertCount(2, glob($this->dir . '/*.sql.gz') ?: []);
    }

    public function testVersioneSanificata(): void
    {
        $file = PreMigrationBackup::create(self::pdo(), $this->dir, '1.12.0/../x', 3);

        self::assertSame(realpath($this->dir), realpath(dirname($file)));
        self::assertStringNotContainsString('/', basename($file));
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[A-Za-z0-9._-]+\.sql\.gz$/', basename($file));
    }
}
