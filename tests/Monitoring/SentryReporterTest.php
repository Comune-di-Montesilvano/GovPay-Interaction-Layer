<?php
declare(strict_types=1);

namespace Tests\Monitoring;

use App\Monitoring\SentryReporter;
use PHPUnit\Framework\TestCase;

final class SentryReporterTest extends TestCase
{
    public function testResolveEnvironmentDefaultsToProductionWhenEmpty(): void
    {
        $this->assertSame('production', SentryReporter::resolveEnvironment(null));
        $this->assertSame('production', SentryReporter::resolveEnvironment(''));
        $this->assertSame('production', SentryReporter::resolveEnvironment('   '));
    }

    public function testResolveEnvironmentReturnsConfiguredValue(): void
    {
        $this->assertSame('montesilvano-prod', SentryReporter::resolveEnvironment('montesilvano-prod'));
    }

    public function testResolveSuitePrefersOverride(): void
    {
        $this->assertSame('cron-ragioneria', SentryReporter::resolveSuite('cron-ragioneria', 'backoffice'));
    }

    public function testResolveSuiteFallsBackToAppSuiteEnv(): void
    {
        $this->assertSame('backoffice', SentryReporter::resolveSuite(null, 'backoffice'));
        $this->assertSame('frontoffice', SentryReporter::resolveSuite('', 'frontoffice'));
    }

    public function testResolveSuiteDefaultsToUnknown(): void
    {
        $this->assertSame('unknown', SentryReporter::resolveSuite(null, null));
        $this->assertSame('unknown', SentryReporter::resolveSuite('', ''));
    }

    public function testShouldInitFalseWhenDsnEmpty(): void
    {
        $this->assertFalse(SentryReporter::shouldInit(null));
        $this->assertFalse(SentryReporter::shouldInit(''));
        $this->assertFalse(SentryReporter::shouldInit('   '));
    }

    public function testShouldInitTrueWhenDsnSet(): void
    {
        $this->assertTrue(SentryReporter::shouldInit('https://key@glitchtip.example.com/1'));
    }

    public function testScrubSensitiveKeysRedactsMatchingKeys(): void
    {
        $input = [
            'codice_fiscale' => 'RSSMRA80A01H501U',
            'iban' => 'IT60X0542811101000000123456',
            'importo' => 100.50,
        ];
        $result = SentryReporter::scrubSensitiveKeys($input);
        $this->assertSame('[REDACTED]', $result['codice_fiscale']);
        $this->assertSame('[REDACTED]', $result['iban']);
        $this->assertSame(100.50, $result['importo']);
    }

    public function testScrubSensitiveKeysIsCaseInsensitiveAndMatchesCfShorthand(): void
    {
        $input = ['CF' => 'RSSMRA80A01H501U', 'PAN' => '1234567890123456'];
        $result = SentryReporter::scrubSensitiveKeys($input);
        $this->assertSame('[REDACTED]', $result['CF']);
        $this->assertSame('[REDACTED]', $result['PAN']);
    }

    public function testScrubSensitiveKeysRecursesIntoNestedArrays(): void
    {
        $input = ['debitore' => ['cf' => 'RSSMRA80A01H501U', 'nome' => 'Mario']];
        $result = SentryReporter::scrubSensitiveKeys($input);
        $this->assertSame('[REDACTED]', $result['debitore']['cf']);
        $this->assertSame('Mario', $result['debitore']['nome']);
    }

    public function testScrubSensitiveKeysRedactsSecretQueryParamsInStrings(): void
    {
        $input = ['context' => ['error' => '[422] Client error: `POST https://api.platform.pagopa.it/checkout/ec/v1/carts?subscription-key=9d73b7c4&x=1` resulted in a `422`']];
        $result = SentryReporter::scrubSensitiveKeys($input);
        $this->assertSame(
            '[422] Client error: `POST https://api.platform.pagopa.it/checkout/ec/v1/carts?subscription-key=[REDACTED]&x=1` resulted in a `422`',
            $result['context']['error']
        );
    }

    public function testScrubSecretsInStringRedactsFiscalCodeAnyCase(): void
    {
        $this->assertSame(
            'value "[REDACTED]" at root.fiscal_code is not valid',
            SentryReporter::scrubSecretsInString('value "dtdrst48e29d076a" at root.fiscal_code is not valid')
        );
        $this->assertSame('cf [REDACTED]', SentryReporter::scrubSecretsInString('cf RSSMRA80A01H501U'));
    }

    public function testScrubUrlRedactsSensitiveQueryParams(): void
    {
        $this->assertSame(
            'http://example.it/link/checkout?action=checkout&cf=%5BREDACTED%5D&iuv=300000000000561336',
            SentryReporter::scrubUrl('http://example.it/link/checkout?action=checkout&cf=BAXKDD85H58Z343X&iuv=300000000000561336')
        );
        $this->assertSame('http://example.it/path', SentryReporter::scrubUrl('http://example.it/path'));
    }

    public function testScrubSecretsInStringLeavesPlainTextUntouched(): void
    {
        $this->assertSame('Invalid payment notice data', SentryReporter::scrubSecretsInString('Invalid payment notice data'));
        $this->assertSame('token_received', SentryReporter::scrubSecretsInString('token_received'));
    }

    public function testResolveReleaseForTaggedBuild(): void
    {
        $info = ['version' => 'v1.6.6', 'version_type' => 'release', 'commit' => 'dbac2433d43f7fbf61065392b067cf8481a185ff', 'build_date' => '2026-10-21'];
        $this->assertSame('gil@1.6.6', SentryReporter::resolveRelease($info));
        $this->assertSame('gil@1.6.6', SentryReporter::resolveRelease(['version_type' => 'tag'] + $info));
    }

    public function testResolveReleaseForAutoBuildCarriesDateAndCommit(): void
    {
        $info = ['version' => 'v1.6.6', 'version_type' => 'auto', 'commit' => 'dbac2433d43f7fbf61065392b067cf8481a185ff', 'build_date' => '2026-10-26'];
        $this->assertSame('gil@1.6.6+auto.20261026.dbac243', SentryReporter::resolveRelease($info));
    }

    public function testResolveReleaseForAutoBuildWithoutMetadata(): void
    {
        $info = ['version' => 'v1.6.6', 'version_type' => 'auto', 'commit' => 'unknown', 'build_date' => ''];
        $this->assertSame('gil@1.6.6+auto', SentryReporter::resolveRelease($info));
    }

    public function testResolveReleaseForCommitBuilds(): void
    {
        $sha = 'dbac2433d43f7fbf61065392b067cf8481a185ff';
        $this->assertSame('gil@dbac243', SentryReporter::resolveRelease(['version' => 'dbac243', 'version_type' => 'commit', 'commit' => $sha]));
    }

    public function testResolveReleaseNullForRetiredDevBuilds(): void
    {
        // Branch dev dismesso: nessuna immagine nuova ha version_type "dev".
        $sha = 'dbac2433d43f7fbf61065392b067cf8481a185ff';
        $this->assertNull(SentryReporter::resolveRelease(['version' => 'dev', 'version_type' => 'dev', 'commit' => $sha]));
    }

    public function testResolveReleaseNullForLocalDevelopment(): void
    {
        $this->assertNull(SentryReporter::resolveRelease(['version' => 'development', 'version_type' => 'development', 'commit' => 'unknown']));
        $this->assertNull(SentryReporter::resolveRelease([]));
    }
}
