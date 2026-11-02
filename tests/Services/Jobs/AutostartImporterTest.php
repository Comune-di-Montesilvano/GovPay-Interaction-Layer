<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Database\JobStateRepository;
use App\Services\Jobs\AutostartImporter;
use App\Services\Jobs\JobCatalog;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;

#[Group('db')]
final class AutostartImporterTest extends DbTestCase
{
    protected static array $tables = ['gil_jobs'];

    private string $dir;
    private JobStateRepository $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/gil-autostart-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->state = new JobStateRepository(static fn(): \PDO => self::pdo());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function importer(): AutostartImporter
    {
        return new AutostartImporter($this->state, JobCatalog::NAMES);
    }

    /** @return list<string> */
    private static function enabled(): array
    {
        return self::pdo()->query("SELECT name FROM gil_jobs WHERE enabled = 1 ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testEnablesExactlyTheSlugsWithAutostartFilesOnce(): void
    {
        foreach (['biz', 'mapping', 'rendicontazione-govpay', 'sconosciuto'] as $slug) {
            touch($this->dir . '/daemon-' . $slug . '.autostart');
        }
        touch($this->dir . '/daemon-tefa.log');

        self::assertSame(4, $this->importer()->importOnce($this->dir));

        // mapping porta con se' mapping-discovery (stesso interruttore in UI); slug ignoti scartati
        self::assertSame(['biz', 'mapping', 'mapping-discovery', 'rendicontazione-govpay'], self::enabled());
        self::assertTrue($this->state->autostartImported());

        // seconda chiamata: no-op anche con file nuovi
        touch($this->dir . '/daemon-tefa.autostart');
        self::assertSame(0, $this->importer()->importOnce($this->dir));
        self::assertSame(['biz', 'mapping', 'mapping-discovery', 'rendicontazione-govpay'], self::enabled());
    }

    public function testJobDisabledAfterImportStaysDisabled(): void
    {
        touch($this->dir . '/daemon-biz.autostart');
        $this->importer()->importOnce($this->dir);
        $this->state->setEnabled('biz', false);

        self::assertSame(0, $this->importer()->importOnce($this->dir));
        self::assertFalse($this->state->isEnabled('biz'));
    }

    public function testNoFilesStillWritesMarker(): void
    {
        self::assertSame(0, $this->importer()->importOnce($this->dir));
        self::assertSame([], self::enabled());
        self::assertTrue($this->state->autostartImported());
        self::assertFalse($this->state->isEnabled(JobStateRepository::IMPORT_MARKER));
    }

    public function testMissingDirectoryImportsNothingAndKeepsImportPending(): void
    {
        self::assertSame(0, $this->importer()->importOnce($this->dir . '/non-montata'));
        self::assertFalse($this->state->autostartImported());
    }
}
