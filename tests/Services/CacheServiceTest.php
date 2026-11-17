<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace Tests\Services;

use App\Services\CacheService;
use PHPUnit\Framework\TestCase;

final class CacheServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gil-cache-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        CacheService::setCacheDirForTests($this->dir);
    }

    protected function tearDown(): void
    {
        CacheService::setCacheDirForTests(null);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testValueIsCachedByDefault(): void
    {
        $calls = 0;
        $cb = function () use (&$calls) { $calls++; return ['n' => $calls]; };

        CacheService::get('k', 300, $cb);
        $second = CacheService::get('k', 300, $cb);

        self::assertSame(1, $calls);
        self::assertSame(['n' => 1], $second);
    }

    public function testFailureRecordedInsideCallbackPreventsCaching(): void
    {
        // Stesso schema di HomeController: il callback registra le sezioni fallite,
        // shouldCache le legge DOPO l'esecuzione (cattura per riferimento).
        $calls = 0;
        $dbReady = false;
        $run = function () use (&$calls, &$dbReady) {
            $failed = [];
            return CacheService::get('stats', 300, function () use (&$calls, &$failed, $dbReady) {
                $calls++;
                if (!$dbReady) {
                    $failed[] = 'channels';
                }
                return ['ok' => $dbReady];
            }, static function () use (&$failed): bool { return $failed === []; });
        };

        self::assertSame(['ok' => false], $run());
        $dbReady = true;
        self::assertSame(['ok' => true], $run());
        self::assertSame(['ok' => true], $run());
        self::assertSame(2, $calls);
    }

    public function testPartialResultIsNotCached(): void
    {
        // Avvio stack: alcune query falliscono -> risposta parziale, non va servita per tutto il TTL.
        $calls = 0;
        $partial = true;
        $cb = function () use (&$calls) { $calls++; return ['n' => $calls]; };
        $shouldCache = function () use (&$partial) { return !$partial; };

        $first = CacheService::get('k', 300, $cb, $shouldCache);
        $partial = false;
        $second = CacheService::get('k', 300, $cb, $shouldCache);
        $third = CacheService::get('k', 300, $cb, $shouldCache);

        self::assertSame(['n' => 1], $first);
        self::assertSame(['n' => 2], $second);
        self::assertSame(['n' => 2], $third);
        self::assertSame(2, $calls);
    }
}
