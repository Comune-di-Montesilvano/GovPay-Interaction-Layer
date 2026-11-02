<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Services\Jobs\JobCatalog;
use App\Services\Jobs\MassiveEnqueueJob;
use App\Services\Jobs\WorkerSupervisor;
use PHPUnit\Framework\TestCase;

/**
 * Supervisore con processi figli finti (`php -r ...`): riavvio con pausa crescente, inoltro di
 * SIGTERM, uscita quando tutti i figli sono usciti. Nessun DB.
 */
final class WorkerSupervisorTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $out = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gil-supervisor-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->out = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** @return list<string> */
    private static function php(string $code): array
    {
        return [PHP_BINARY, '-r', $code];
    }

    /** @return array<int, mixed> */
    private static function quiet(): array
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        return [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
    }

    /** @param array<string, list<string>> $commands */
    private function supervisor(array $commands, float $initial = 0.2, float $max = 0.4, float $stable = 60.0, ?\Closure $prepare = null): WorkerSupervisor
    {
        return new WorkerSupervisor(
            $commands,
            function (string $line): void { $this->out[] = $line; },
            $prepare,
            null,
            null,
            $initial,
            $max,
            $stable,
            self::quiet()
        );
    }

    public function testDefaultCommandsStartOneProcessPerGroupPlusMassive(): void
    {
        $commands = WorkerSupervisor::defaultCommands('/var/www/html/bin/worker', '/usr/local/bin/php');

        $groups = JobCatalog::processGroups();
        self::assertSame(array_merge(array_keys($groups), [MassiveEnqueueJob::QUEUE]), array_keys($commands));
        self::assertSame(['mapping', 'mapping-discovery'], $groups['mapping'], 'discovery nello stesso processo di mapping');
        self::assertArrayNotHasKey('mapping-discovery', $groups);
        self::assertSame(['/usr/local/bin/php', '/var/www/html/bin/worker', 'gil:worker:job', 'biz'], $commands['biz']);
        self::assertSame(['/usr/local/bin/php', '/var/www/html/bin/worker', 'gil:worker:massive'], $commands['massive']);
        // ogni lavoro del catalogo e' in esattamente un gruppo
        $all = array_merge(...array_values($groups));
        sort($all);
        $names = JobCatalog::NAMES;
        sort($names);
        self::assertSame($names, $all);
    }

    public function testDeadChildIsRestartedWithGrowingBackoff(): void
    {
        $log = $this->dir . '/starts';
        $sup = $this->supervisor([
            'crash' => self::php('file_put_contents(' . var_export($log, true) . ', "x", FILE_APPEND); exit(3);'),
        ]);

        self::assertSame(0, $sup->run(2.0));

        $starts = strlen((string)@file_get_contents($log));
        self::assertGreaterThanOrEqual(3, $starts, implode("\n", $this->out));
        self::assertSame($starts, $sup->startCounts()['crash']);
        $restartLines = array_values(array_filter($this->out, static fn(string $l): bool => str_contains($l, 'processo crash terminato (exit 3): riavvio tra')));
        self::assertStringContainsString('riavvio tra 0.2 s', $restartLines[0]);
        self::assertStringContainsString('riavvio tra 0.4 s', $restartLines[1], 'pausa raddoppiata');
        self::assertStringContainsString('riavvio tra 0.4 s', $restartLines[2], 'fino al massimo');
        self::assertStringContainsString('processo crash riavviato (pid ', implode("\n", $this->out));
        self::assertStringContainsString('worker arrestato.', end($this->out));
        // riga cercata da scripts/smoke-stack.sh
        self::assertStringContainsString('avvio worker (supervisore, 1 processi: crash)', $this->out[0]);
    }

    public function testBackoffResetsAfterAStableRun(): void
    {
        $log = $this->dir . '/starts';
        // vive 0.3 s (> stable 0.2 s) poi muore: ogni riavvio riparte dalla pausa iniziale
        $sup = $this->supervisor([
            'lento' => self::php('file_put_contents(' . var_export($log, true) . ', "x", FILE_APPEND); usleep(300000); exit(1);'),
        ], 0.1, 10.0, 0.2);

        $sup->run(2.0);

        $restartLines = array_values(array_filter($this->out, static fn(string $l): bool => str_contains($l, 'riavvio tra')));
        self::assertGreaterThanOrEqual(2, count($restartLines));
        foreach ($restartLines as $l) {
            self::assertStringContainsString('riavvio tra 0.1 s', $l);
        }
    }

    public function testStopForwardsSigtermToAllChildrenAndWaitsForThem(): void
    {
        $code = static function (string $marker): string {
            // figlio "worker": su SIGTERM finisce il "batch" (0.3 s) e scrive il marker
            return 'if (function_exists("pcntl_async_signals")) { pcntl_async_signals(true); $stop = false;'
                . ' pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });'
                . ' while (!$stop) { usleep(50000); } usleep(300000); file_put_contents(' . var_export($marker, true) . ', "term"); exit(0); }'
                . ' while (true) { usleep(50000); }';
        };
        $a = $this->dir . '/a';
        $b = $this->dir . '/b';
        $sup = $this->supervisor(['a' => self::php($code($a)), 'b' => self::php($code($b))]);

        $t = microtime(true);
        self::assertSame(0, $sup->run(1.0));
        $elapsed = microtime(true) - $t;

        self::assertLessThan(5.0, $elapsed, 'uscita appena i figli sono usciti');
        self::assertSame([], $sup->childPids(), 'nessun figlio vivo');
        self::assertSame(['a' => 1, 'b' => 1], $sup->startCounts(), 'nessun riavvio dopo lo stop');
        self::assertStringContainsString('SIGTERM inoltrato a 2 processi', implode("\n", $this->out));
        self::assertStringContainsString('worker arrestato.', end($this->out));
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_signal') && extension_loaded('pcntl')) {
            // figli con pcntl (Linux): hanno ricevuto SIGTERM e finito il "batch"
            self::assertSame('term', @file_get_contents($a));
            self::assertSame('term', @file_get_contents($b));
            self::assertGreaterThan(1.3, $elapsed, 'attesa della fine del batch dei figli');
        }
    }

    public function testPrepareFalseMeansStopBeforeStartingChildren(): void
    {
        $log = $this->dir . '/starts';
        $sup = $this->supervisor(
            ['x' => self::php('file_put_contents(' . var_export($log, true) . ', "x");')],
            prepare: static fn(): bool => false
        );

        self::assertSame(0, $sup->run(1.0));
        self::assertFileDoesNotExist($log);
        self::assertSame(['x' => 0], $sup->startCounts());
    }
}
