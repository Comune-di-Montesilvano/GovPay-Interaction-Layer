<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Services\Jobs\Handler\ProcessMassiveRowHandler;
use App\Services\Jobs\Message\ProcessMassiveRow;
use PHPUnit\Framework\TestCase;

final class ProcessMassiveRowHandlerTest extends TestCase
{
    private static function suiteTag(): ?string
    {
        $tag = null;
        \Sentry\configureScope(static function (\Sentry\State\Scope $scope) use (&$tag): void {
            $tag = $scope->applyToEvent(\Sentry\Event::createEvent())?->getTags()['suite'] ?? null;
        });
        return $tag;
    }

    public function testHandlerBodyRunsInScopeTaggedWithMassiveSuite(): void
    {
        $seen = null;
        $handler = new ProcessMassiveRowHandler(
            static function () use (&$seen): never {
                $seen = self::suiteTag();
                throw new \RuntimeException('stop');
            },
            static function (string $line): void {}
        );
        $before = self::suiteTag();

        try {
            $handler(new ProcessMassiveRow(1, str_repeat('a', 32)));
            self::fail('attesa eccezione del factory');
        } catch (\RuntimeException $e) {
            self::assertSame('stop', $e->getMessage());
        }

        self::assertSame('cron-pendenze-massive', $seen);
        self::assertSame($before, self::suiteTag(), 'scope ripristinato anche con eccezione');
    }
}
