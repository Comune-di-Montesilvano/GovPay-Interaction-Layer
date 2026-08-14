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
}
