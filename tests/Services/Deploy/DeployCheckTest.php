<?php
declare(strict_types=1);

namespace Tests\Services\Deploy;

use App\Services\Deploy\CheckResult;
use App\Services\Deploy\DeployCheck;
use PHPUnit\Framework\TestCase;

final class DeployCheckTest extends TestCase
{
    private const TOKEN = 'token-sintetico-di-test-0123456789';

    /** @var array<string, array{status:int, body:string}> */
    private array $responses = [];
    /** @var list<array{string, string}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->responses = [
            'http://backoffice/api/internal/deploy-check' => self::json(200, [
                'success' => true, 'version' => 'v1.12.0', 'commit' => 'abc', 'db' => true,
                'migrations' => ['registered' => 38, 'pending' => [], 'mismatched' => []],
            ]),
            'http://frontoffice/health/deploy' => self::json(200, ['version' => 'v1.12.0', 'commit' => 'abc', 'backoffice' => true]),
        ];
        $this->calls = [];
    }

    /** @param array<string, mixed> $body */
    private static function json(int $status, array $body): array
    {
        return ['status' => $status, 'body' => (string)json_encode($body)];
    }

    private function check(string $appVersion = '1.12.0', string $ssl = 'off'): DeployCheck
    {
        return new DeployCheck(
            DeployCheck::internalUrl('backoffice', $ssl),
            DeployCheck::internalUrl('frontoffice', $ssl),
            self::TOKEN,
            $appVersion,
            function (string $url, string $token): array {
                $this->calls[] = [$url, $token];
                return $this->responses[$url] ?? ['status' => 0, 'body' => ''];
            },
        );
    }

    /** @param list<CheckResult> $results */
    private static function byName(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[$r->name] = $r;
        }
        return $out;
    }

    /** @param list<CheckResult> $results */
    private static function failed(array $results): array
    {
        return array_values(array_map(static fn(CheckResult $r): string => $r->name, array_filter($results, static fn(CheckResult $r): bool => !$r->ok)));
    }

    public function testTuttoOk(): void
    {
        $results = $this->check()->run();

        self::assertSame([], self::failed($results));
        self::assertSame(['backoffice', 'db', 'migrazioni', 'frontoffice', 'frontoffice→backoffice', 'versioni'], array_keys(self::byName($results)));
        foreach ($this->calls as [, $token]) {
            self::assertSame(self::TOKEN, $token);
        }
    }

    public function testMigrazioniPendentiFalliscono(): void
    {
        $this->responses['http://backoffice/api/internal/deploy-check'] = self::json(200, [
            'success' => true, 'version' => 'v1.12.0', 'commit' => 'abc', 'db' => true,
            'migrations' => ['registered' => 37, 'pending' => ['039_nuova.sql'], 'mismatched' => []],
        ]);

        $results = self::byName($this->check()->run());

        self::assertFalse($results['migrazioni']->ok);
        self::assertStringContainsString('039_nuova.sql', $results['migrazioni']->detail);
    }

    public function testMigrazioniModificateSoloAvviso(): void
    {
        $this->responses['http://backoffice/api/internal/deploy-check'] = self::json(200, [
            'success' => true, 'version' => 'v1.12.0', 'commit' => 'abc', 'db' => true,
            'migrations' => ['registered' => 38, 'pending' => [], 'mismatched' => ['010_x.sql']],
        ]);

        $results = self::byName($this->check()->run());

        self::assertTrue($results['migrazioni']->ok);
        self::assertStringContainsString('010_x.sql', $results['migrazioni']->detail);
    }

    public function testDbGiuFallisce(): void
    {
        $this->responses['http://backoffice/api/internal/deploy-check'] = self::json(200, [
            'success' => true, 'version' => 'v1.12.0', 'commit' => 'abc', 'db' => false,
            'migrations' => null,
        ]);

        $results = self::byName($this->check()->run());

        self::assertFalse($results['db']->ok);
        self::assertFalse($results['migrazioni']->ok);
    }

    public function testVersioniDiverseFalliscono(): void
    {
        $this->responses['http://frontoffice/health/deploy'] = self::json(200, ['version' => 'v1.11.3', 'commit' => 'def', 'backoffice' => true]);

        $results = self::byName($this->check()->run());

        self::assertFalse($results['versioni']->ok);
        self::assertStringContainsString('1.11.3', $results['versioni']->detail);
    }

    public function testAppVersionDiversaDalleImmaginiFallisce(): void
    {
        $results = self::byName($this->check('1.13.0')->run());

        self::assertFalse($results['versioni']->ok);
    }

    public function testAppVersionLatestNonConfronta(): void
    {
        $results = self::byName($this->check('latest')->run());

        self::assertTrue($results['versioni']->ok);
        self::assertStringContainsString('APP_VERSION=latest', $results['versioni']->detail);
    }

    public function testBackofficeIrraggiungibile(): void
    {
        unset($this->responses['http://backoffice/api/internal/deploy-check']);

        $results = $this->check()->run();
        $byName = self::byName($results);

        self::assertFalse($byName['backoffice']->ok);
        foreach (['db', 'migrazioni', 'versioni'] as $dep) {
            self::assertFalse($byName[$dep]->ok);
            self::assertStringContainsString('non verificato', $byName[$dep]->detail);
        }
        self::assertTrue($byName['frontoffice']->ok);
    }

    public function testTokenRifiutatoDalFrontoffice(): void
    {
        $this->responses['http://frontoffice/health/deploy'] = ['status' => 401, 'body' => ''];

        $results = self::byName($this->check()->run());

        self::assertFalse($results['frontoffice']->ok);
        self::assertStringContainsString('401', $results['frontoffice']->detail);
        self::assertFalse($results['frontoffice→backoffice']->ok);
    }

    public function testFrontofficeNonRaggiungeBackoffice(): void
    {
        $this->responses['http://frontoffice/health/deploy'] = self::json(200, ['version' => 'v1.12.0', 'commit' => 'abc', 'backoffice' => false]);

        $results = self::byName($this->check()->run());

        self::assertTrue($results['frontoffice']->ok);
        self::assertFalse($results['frontoffice→backoffice']->ok);
    }

    public function testUrlInterniConSslOn(): void
    {
        self::assertSame('https://backoffice:80', DeployCheck::internalUrl('backoffice', 'on'));
        self::assertSame('http://frontoffice', DeployCheck::internalUrl('frontoffice', 'off'));
        self::assertSame('http://frontoffice', DeployCheck::internalUrl('frontoffice', ''));
    }

    public function testDetailSenzaToken(): void
    {
        $this->responses['http://backoffice/api/internal/deploy-check'] = ['status' => 500, 'body' => 'errore ' . self::TOKEN];

        foreach ($this->check()->run() as $r) {
            self::assertStringNotContainsString(self::TOKEN, $r->detail);
        }
    }
}
