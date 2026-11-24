<?php
declare(strict_types=1);

namespace Tests\Services\Deploy;

use App\Services\Deploy\FrontofficeDeployStatus;
use PHPUnit\Framework\TestCase;

final class FrontofficeDeployStatusTest extends TestCase
{
    private const TOKEN = 'token-sintetico-frontoffice-0123456789';
    private const INFO = ['version' => 'v1.12.0', 'commit' => 'abc123', 'build_date' => '2026-11-24'];

    public function testTokenCorrettoRitornaVersioneEBackoffice(): void
    {
        $probe = 0;
        $r = FrontofficeDeployStatus::respond('Bearer ' . self::TOKEN, self::TOKEN, self::INFO, function () use (&$probe): bool {
            $probe++;
            return true;
        });

        self::assertSame(200, $r['status']);
        self::assertSame(['version' => 'v1.12.0', 'commit' => 'abc123', 'backoffice' => true], $r['body']);
        self::assertSame(1, $probe);
    }

    public function testBackofficeNonRaggiungibile(): void
    {
        $r = FrontofficeDeployStatus::respond('Bearer ' . self::TOKEN, self::TOKEN, self::INFO, static fn(): bool => false);

        self::assertSame(200, $r['status']);
        self::assertFalse($r['body']['backoffice']);
    }

    public function testSenzaTokenOErrato401SenzaSondaNeVersione(): void
    {
        foreach ([null, '', 'Bearer sbagliato', self::TOKEN, 'Basic ' . self::TOKEN] as $header) {
            $probe = 0;
            $r = FrontofficeDeployStatus::respond($header, self::TOKEN, self::INFO, function () use (&$probe): bool {
                $probe++;
                return true;
            });
            self::assertSame(401, $r['status'], (string)$header);
            self::assertSame([], $r['body']);
            self::assertSame(0, $probe, 'nessuna chiamata al backoffice senza token valido');
        }
    }

    public function testMasterTokenNonConfigurato503(): void
    {
        $r = FrontofficeDeployStatus::respond('Bearer ', '', self::INFO, static fn(): bool => true);

        self::assertSame(503, $r['status']);
    }
}
