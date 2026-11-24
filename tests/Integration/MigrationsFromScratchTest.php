<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Support\IntegrationTestCase;

/**
 * Primo avvio su DB vuoto: first_run_create_tables.php + run-migrations.php senza errori.
 * Su DB vuoto run-migrations.php applica la regola stretta (un errore = exit 1) e registra
 * tutti i file in gil_schema_migrations; un secondo giro non applica nulla.
 */
#[Group('db')]
#[Group('integration')]
final class MigrationsFromScratchTest extends IntegrationTestCase
{
    /** @return array{int, string} exit code, stdout+stderr */
    private static function runScript(string $root, string $script): array
    {
        $cmd = [PHP_BINARY];
        if (extension_loaded('pdo_mysql')) {
            $cmd[] = '-d';
            $cmd[] = 'extension=pdo_mysql';
        }
        $cmd[] = $root . '/backoffice/bin/' . $script;
        $env = getenv();
        $env['MIGRATION_BACKUP'] = 'skip';
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
        self::assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out];
    }

    public function testPrimoAvvioSenzaErrori(): void
    {
        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec('DROP TABLE `' . str_replace('`', '', (string)$t) . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        self::assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn());

        $root = dirname(__DIR__, 2);
        foreach (['first_run_create_tables.php', 'run-migrations.php'] as $script) {
            $cmd = [PHP_BINARY];
            if (extension_loaded('pdo_mysql')) {
                $cmd[] = '-d';
                $cmd[] = 'extension=pdo_mysql';
            }
            $cmd[] = $root . '/backoffice/bin/' . $script;
            $env = getenv();
            $env['MIGRATION_BACKUP'] = 'skip';
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
            self::assertIsResource($proc);
            $out = (string)stream_get_contents($pipes[1]);
            $err = (string)stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            // L'estensione gia' caricata da php.ini produce solo un warning "already loaded".
            $err = trim((string)preg_replace('/^.*Module "pdo_mysql" is already loaded.*$/m', '', $err));
            self::assertSame(0, $code, "$script: exit $code\n$out\n$err");
            self::assertStringNotContainsString('ERRORE', $out, "$script:\n$out\n$err");
            self::assertStringNotContainsString('errore tollerato', $out, "$script:\n$out\n$err");
            self::assertSame('', $err, "$script stderr:\n$err");
        }

        $registrati = (int)$pdo->query('SELECT COUNT(*) FROM gil_schema_migrations')->fetchColumn();
        self::assertSame(count(glob($root . '/migrations/*.sql') ?: []), $registrati, 'registro migrazioni incompleto');

        // Riavvio: nulla da applicare, exit 0.
        [$code, $out] = self::runScript($root, 'run-migrations.php');
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('migrazioni: 0 da applicare', $out);

        foreach (['settings', 'users', 'gil_jobs', 'gil_job_runs', 'messenger_messages', 'flussi_rendicontazioni'] as $t) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$t'")->fetchColumn();
            self::assertSame(1, $n, "tabella $t assente dopo il primo avvio");
        }
    }
}
