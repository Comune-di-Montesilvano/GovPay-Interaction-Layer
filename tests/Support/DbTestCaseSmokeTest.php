<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Services\ValidationService;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
final class DbTestCaseSmokeTest extends DbTestCase
{
    protected static array $tables = ['settings'];

    public function testSettingsRoundTrip(): void
    {
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO settings (section, key_name, value) VALUES ('test', 'k', 'v')");
        $v = $pdo->query("SELECT value FROM settings WHERE section='test' AND key_name='k'")->fetchColumn();
        $this->assertSame('v', $v);
    }

    #[Depends('testSettingsRoundTrip')]
    public function testTruncateEmptiesTable(): void
    {
        $this->assertSame(0, (int)self::pdo()->query('SELECT COUNT(*) FROM settings')->fetchColumn());
    }

    public function testSyntheticCfIsValid(): void
    {
        foreach ([0, 1, 2, 99, 12345] as $seed) {
            $r = ValidationService::validateCodiceFiscale(Synthetic::cf($seed));
            $this->assertTrue($r['valid'], 'seed ' . $seed . ' ' . Synthetic::cf($seed));
        }
    }

    public function testSyntheticIuvAndIur(): void
    {
        $iuv = Synthetic::iuv('GIL', 7);
        $this->assertSame(17, strlen($iuv));
        $this->assertStringStartsWith('GIL', $iuv);
        $this->assertNotSame(Synthetic::iur(1), Synthetic::iur(2));
        $this->assertMatchesRegularExpression('/^\S+ \S+$/', Synthetic::nome(3));
    }
}
