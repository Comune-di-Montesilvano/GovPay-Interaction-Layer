<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Database\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Base per test che richiedono MariaDB reale (gruppo `db`).
 *
 * Connessione da env DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD (letti da App\Database\Connection).
 * Senza DB_HOST i test vengono saltati. Lo schema e' costruito una volta per processo con lo
 * stesso percorso del runtime: first_run_create_tables.php poi run-migrations.php.
 *
 * PHPUnit 13 NON eredita #[Group] dalla classe astratta: ogni sottoclasse concreta deve avere
 * #[Group('db')]. Le sottoclassi dichiarano in $tables le tabelle da svuotare prima di ogni test.
 */
abstract class DbTestCase extends TestCase
{
    /** @var list<string> Tabelle svuotate (TRUNCATE) in setUp, nell'ordine dato. */
    protected static array $tables = [];

    private static bool $schemaReady = false;

    protected static function pdo(): \PDO
    {
        return Connection::getPDO();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('DB_HOST');
        if ($host === false || $host === '') {
            $this->markTestSkipped('DB_HOST non impostato: test DB saltati (vedi tests/Support/DbTestCase.php).');
        }
        // Salvaguardia: mai sul DB dello stack applicativo (porta 3306 / host compose `db`).
        $port = getenv('DB_PORT') ?: '3306';
        if ($port === '3306' || $host === 'db') {
            self::fail("Rifiuto di eseguire test DB su {$host}:{$port}: usare un DB usa-e-getta (porta 3307).");
        }

        self::ensureSchema();

        $pdo = self::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (static::$tables as $table) {
                $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '', $table) . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * @param list<string> $phpArgs
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private static function runPhp(string $script, array $phpArgs): array
    {
        $cmd = array_merge([PHP_BINARY], $phpArgs, [$script]);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        if (!is_resource($proc)) {
            self::fail('Impossibile avviare ' . basename($script));
        }
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    private static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $root = dirname(__DIR__, 2);
        foreach (['first_run_create_tables.php', 'run-migrations.php'] as $script) {
            [$code, $out, $err] = self::runPhp($root . '/backoffice/bin/' . $script, []);
            // Host con pdo_mysql abilitato solo da `php -d extension=pdo_mysql` (non da php.ini):
            // il sottoprocesso non lo eredita, quindi riprova passando l'estensione esplicitamente.
            if ($code !== 0 && str_contains($err . $out, 'could not find driver') && extension_loaded('pdo_mysql')) {
                [$code, $out, $err] = self::runPhp($root . '/backoffice/bin/' . $script, ['-d', 'extension=pdo_mysql']);
            }
            if ($code !== 0) {
                self::fail("{$script} fallito (exit {$code}): {$err}{$out}");
            }
        }
        self::$schemaReady = true;
    }
}
