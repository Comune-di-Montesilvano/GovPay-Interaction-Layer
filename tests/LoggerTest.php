<?php
declare(strict_types=1);

namespace Tests;

use App\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase
{
    private static string $logFile;

    public static function setUpBeforeClass(): void
    {
        self::$logFile = sys_get_temp_dir() . '/gil-logger-test-' . uniqid('', true) . '.log';
        putenv('APP_LOG_PATH=' . self::$logFile);
    }

    public static function tearDownAfterClass(): void
    {
        putenv('APP_LOG_PATH');
        if (is_file(self::$logFile)) {
            @unlink(self::$logFile);
        }
    }

    public function testErrorWithoutExceptionWritesLogFile(): void
    {
        Logger::getInstance()->error('errore di test', ['foo' => 'bar']);
        $this->assertStringContainsString('errore di test', (string)file_get_contents(self::$logFile));
    }

    public function testErrorWithExceptionDoesNotThrowAndWritesLogFile(): void
    {
        Logger::getInstance()->error('errore con eccezione', [], new \RuntimeException('boom'));
        $this->assertStringContainsString('errore con eccezione', (string)file_get_contents(self::$logFile));
    }

    public function testWarningBackwardCompatibleTwoArgCall(): void
    {
        Logger::getInstance()->warning('warning di test');
        $this->assertStringContainsString('warning di test', (string)file_get_contents(self::$logFile));
    }

    public function testErrorWithReportToSentryFalseStillWritesLogFile(): void
    {
        Logger::getInstance()->error('deprecation soppressa da sentry', [], null, false);
        $this->assertStringContainsString('deprecation soppressa da sentry', (string)file_get_contents(self::$logFile));
    }
}
