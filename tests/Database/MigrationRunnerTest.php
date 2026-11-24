<?php
declare(strict_types=1);

namespace Tests\Database;

use App\Database\MigrationFailedException;
use App\Database\MigrationRunner;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

/**
 * Registro e regole di MigrationRunner su file sintetici. Tabelle proprie (zz_mig_*) per non
 * toccare il registro reale gil_schema_migrations creato dallo schema dei test.
 */
#[Group('db')]
final class MigrationRunnerTest extends DbTestCase
{
    private const REGISTRY = 'zz_mig_registry';
    private const DATA = 'zz_mig_users';

    private string $dir = '';
    /** @var list<string> */
    private array $log = [];
    private int $backups = 0;
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAll();
        $this->dir = sys_get_temp_dir() . '/gil-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        self::pdo()->exec('CREATE TABLE ' . self::DATA . ' (id INT PRIMARY KEY)');
        $this->log = [];
        $this->backups = 0;
    }

    protected function tearDown(): void
    {
        $this->dropAll();
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function dropAll(): void
    {
        foreach ([self::REGISTRY, self::DATA, 'zz_mig_t1', 'zz_mig_t2', 'zz_mig_t3'] as $t) {
            self::pdo()->exec("DROP TABLE IF EXISTS {$t}");
        }
    }

    private function file(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }

    private function runner(bool $backupFails = false): MigrationRunner
    {
        $backup = function (string $key) use ($backupFails): string {
            $this->backups++;
            $this->keys[] = $key;
            if ($backupFails) {
                throw new \RuntimeException('disco pieno');
            }
            return '/backups/pre-migrazione/finto.sql.gz';
        };
        return new MigrationRunner(
            self::pdo(),
            $this->dir,
            $backup,
            function (string $line): void {
                $this->log[] = $line;
            },
            self::REGISTRY,
            self::DATA,
        );
    }

    private function withData(): void
    {
        self::pdo()->exec('INSERT INTO ' . self::DATA . ' VALUES (1)');
    }

    /** Registro gia' esistente con i file presenti (stato dopo il primo avvio col registro). */
    private function registerExisting(): void
    {
        $this->runner()->run();
        $this->backups = 0;
        $this->log = [];
    }

    private function tableExists(string $t): bool
    {
        $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$t}'";
        return (int)self::pdo()->query($sql)->fetchColumn() === 1;
    }

    private function logContains(string ...$needles): bool
    {
        foreach ($this->log as $line) {
            $all = true;
            foreach ($needles as $n) {
                $all = $all && str_contains($line, $n);
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }

    public function testDbNuovoApplicaTuttoSenzaBackup(): void
    {
        $this->file('001_a.sql', 'CREATE TABLE IF NOT EXISTS zz_mig_t1 (id INT)');
        $this->file('002_b.sql', 'CREATE TABLE IF NOT EXISTS zz_mig_t2 (id INT)');

        $r = $this->runner()->run();
        self::assertSame(2, $r->applied);
        self::assertTrue($r->bootstrap);
        self::assertNull($r->backupFile);
        self::assertSame(0, $this->backups);
        self::assertTrue($this->tableExists('zz_mig_t2'));

        $r2 = $this->runner()->run();
        self::assertSame(0, $r2->applied);
        self::assertFalse($r2->bootstrap);
        self::assertSame(['pending' => [], 'mismatched' => []], $this->runner()->status());
    }

    public function testSoloFileNuoviConBackup(): void
    {
        $this->file('001_a.sql', 'CREATE TABLE zz_mig_t1 (id INT)');
        $this->registerExisting();
        $this->withData();
        $this->file('002_b.sql', 'CREATE TABLE zz_mig_t2 (id INT)');

        self::assertSame(['pending' => ['002_b.sql'], 'mismatched' => []], $this->runner()->status());
        $r = $this->runner()->run();
        self::assertSame(1, $r->applied);
        self::assertSame(1, $this->backups);
        self::assertSame('/backups/pre-migrazione/finto.sql.gz', $r->backupFile);
    }

    public function testChiaveBackupStabileFinchePendentiUguali(): void
    {
        $this->file('001_a.sql', 'CREATE TABLE zz_mig_t1 (id INT)');
        $this->registerExisting();
        $this->withData();
        $this->file('002_b.sql', 'QUESTO NON E SQL');

        foreach ([1, 2] as $_) {
            try {
                $this->runner()->run();
            } catch (MigrationFailedException) {
            }
        }
        $this->file('003_c.sql', 'CREATE TABLE zz_mig_t3 (id INT)');
        try {
            $this->runner()->run();
        } catch (MigrationFailedException) {
        }

        self::assertCount(3, $this->keys);
        self::assertSame($this->keys[0], $this->keys[1]);
        self::assertNotSame($this->keys[1], $this->keys[2]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->keys[0]);
    }

    public function testFileFallitoBloccaENonRegistra(): void
    {
        $this->file('001_a.sql', 'CREATE TABLE zz_mig_t1 (id INT)');
        $this->registerExisting();
        $this->file('002_b.sql', 'QUESTO NON E SQL');
        $this->file('003_c.sql', 'CREATE TABLE zz_mig_t3 (id INT)');

        try {
            $this->runner()->run();
            self::fail('attesa MigrationFailedException');
        } catch (MigrationFailedException $e) {
            self::assertStringContainsString('002_b.sql', $e->getMessage());
        }
        self::assertFalse($this->tableExists('zz_mig_t3'));
        self::assertSame(['002_b.sql', '003_c.sql'], $this->runner()->status()['pending']);
    }

    public function testErroreNelSecondoStatementDelFileBlocca(): void
    {
        $this->file('001_a.sql', 'CREATE TABLE zz_mig_t1 (id INT)');
        $this->registerExisting();
        $this->file('002_b.sql', "CREATE TABLE zz_mig_t2 (id INT);\nINSERT INTO tabella_inesistente_zz VALUES (1);\n");

        $this->expectException(MigrationFailedException::class);
        $this->expectExceptionMessage('002_b.sql');
        $this->runner()->run();
    }

    public function testBackupFallitoBloccaMigrazioni(): void
    {
        $this->file('001_a.sql', 'CREATE TABLE zz_mig_t1 (id INT)');
        $this->registerExisting();
        $this->withData();
        $this->file('002_b.sql', 'CREATE TABLE zz_mig_t2 (id INT)');

        try {
            $this->runner(backupFails: true)->run();
            self::fail('attesa MigrationFailedException');
        } catch (MigrationFailedException $e) {
            self::assertStringContainsString('backup', $e->getMessage());
            self::assertStringContainsString('disco pieno', $e->getMessage());
        }
        self::assertFalse($this->tableExists('zz_mig_t2'));
    }

    public function testBootstrapTolleraErroriERegistraTutto(): void
    {
        $this->withData();
        // Gia' applicata prima del registro: rieseguita fallisce (tabella esistente), come in prod.
        self::pdo()->exec('CREATE TABLE zz_mig_t1 (id INT)');
        $this->file('001_a.sql', 'CREATE TABLE zz_mig_t1 (id INT)');
        $this->file('002_b.sql', 'CREATE TABLE IF NOT EXISTS zz_mig_t2 (id INT)');

        $r = $this->runner()->run();
        self::assertTrue($r->bootstrap);
        self::assertSame(['001_a.sql'], $r->tolerated);
        self::assertSame(1, $this->backups);
        self::assertSame(['pending' => [], 'mismatched' => []], $this->runner()->status());
        self::assertTrue($this->tableExists('zz_mig_t2'));
        self::assertTrue($this->logContains('registro migrazioni creato'));
    }

    public function testBootstrapSuDbSenzaDatiERegolaStretta(): void
    {
        // Installazione nuova: nessun file e' "gia' applicato", un errore e' un bug vero.
        $this->file('001_a.sql', 'QUESTO NON E SQL');

        $this->expectException(MigrationFailedException::class);
        $this->expectExceptionMessage('001_a.sql');
        $this->runner()->run();
    }

    public function testChecksumDiversoNonRiesegue(): void
    {
        $this->file('001_a.sql', 'CREATE TABLE IF NOT EXISTS zz_mig_t1 (id INT)');
        $this->registerExisting();
        self::pdo()->exec('DROP TABLE zz_mig_t1');
        $this->file('001_a.sql', 'CREATE TABLE IF NOT EXISTS zz_mig_t1 (id INT, extra INT)');

        $r = $this->runner()->run();
        self::assertSame(0, $r->applied);
        self::assertSame(['001_a.sql'], $r->mismatched);
        self::assertFalse($this->tableExists('zz_mig_t1'));
        self::assertTrue($this->logContains('001_a.sql', 'checksum'));
    }
}
