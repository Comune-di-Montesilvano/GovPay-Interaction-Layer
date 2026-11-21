<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Support\IntegrationTestCase;

/**
 * Primo avvio su DB vuoto: first_run_create_tables.php + run-migrations.php senza errori.
 * run-migrations.php stampa "ERRORE" e prosegue con exit 0 (al riavvio le migrazioni gia'
 * applicate possono fallire per scelta): qui, su DB vuoto, qualsiasi ERRORE e' un bug.
 */
#[Group('db')]
#[Group('integration')]
final class MigrationsFromScratchTest extends IntegrationTestCase
{
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
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
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
            self::assertSame('', $err, "$script stderr:\n$err");
        }

        foreach (['settings', 'users', 'gil_jobs', 'gil_job_runs', 'messenger_messages', 'flussi_rendicontazioni'] as $t) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$t'")->fetchColumn();
            self::assertSame(1, $n, "tabella $t assente dopo il primo avvio");
        }
    }
}
