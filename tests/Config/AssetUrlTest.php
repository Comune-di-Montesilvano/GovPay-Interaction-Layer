<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace Tests\Config;

use App\Config\Config;
use PHPUnit\Framework\TestCase;

final class AssetUrlTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['GIT_COMMIT_SHA', 'GIL_BUILD_DATE', 'GIL_IMAGE_TAG'] as $k) {
            $this->savedEnv[$k] = getenv($k);
        }
        Config::resetAssetTokenForTests();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            putenv($v === false ? $k : "$k=$v");
        }
        Config::resetAssetTokenForTests();
    }

    public function testBuildMetadataProducesStableToken(): void
    {
        putenv('GIT_COMMIT_SHA=abc123');
        putenv('GIL_BUILD_DATE=2026-11-17');

        $url = Config::assetUrl('/assets/css/app.css');

        self::assertMatchesRegularExpression('#^/assets/css/app\.css\?v=[0-9a-f]{10}$#', $url);
        self::assertSame($url, Config::assetUrl('/assets/css/app.css'));
    }

    public function testDifferentCommitChangesToken(): void
    {
        putenv('GIT_COMMIT_SHA=abc123');
        $first = Config::assetUrl('/assets/js/app.js');

        Config::resetAssetTokenForTests();
        putenv('GIT_COMMIT_SHA=def456');
        $second = Config::assetUrl('/assets/js/app.js');

        self::assertNotSame($first, $second);
    }

    public function testExistingQueryStringIsPreserved(): void
    {
        putenv('GIT_COMMIT_SHA=abc123');

        self::assertMatchesRegularExpression('#^/assets/x\.css\?a=1&v=[0-9a-f]{10}$#', Config::assetUrl('/assets/x.css?a=1'));
    }

    public function testWithoutBuildInfoTraversalAndMissingFilesAreUntouched(): void
    {
        putenv('GIT_COMMIT_SHA');
        putenv('GIL_BUILD_DATE');
        putenv('GIL_IMAGE_TAG');

        self::assertSame('/../composer.json', Config::assetUrl('/../composer.json'));
        self::assertSame('/assets/missing-file.css', Config::assetUrl('/assets/missing-file.css'));
    }

    public function testExternalUrlsAreUntouched(): void
    {
        putenv('GIT_COMMIT_SHA=abc123');

        self::assertSame('https://cdn.example.test/a.js', Config::assetUrl('https://cdn.example.test/a.js'));
        self::assertSame('//cdn.example.test/a.js', Config::assetUrl('//cdn.example.test/a.js'));
    }
}
