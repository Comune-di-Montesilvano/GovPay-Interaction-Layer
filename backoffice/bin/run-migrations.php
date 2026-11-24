<?php
declare(strict_types=1);

/**
 * Migrazioni SQL all'avvio del backoffice (docker-setup.sh): App\Database\MigrationRunner.
 * Exit 0 = schema aggiornato; exit 1 = migrazione o backup fallito, il backoffice non deve partire.
 *
 * Env: MIGRATION_BACKUP=skip (solo emergenza: nessun backup pre-migrazione),
 *      MIGRATION_BACKUP_DIR (default /backups/pre-migrazione), MIGRATION_BACKUP_KEEP (default 3).
 */

use App\Config\Config;
use App\Database\Connection;
use App\Database\MigrationFailedException;
use App\Database\MigrationRunner;
use App\Database\PreMigrationBackup;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
	require __DIR__ . '/../vendor/autoload.php';
} else {
	require __DIR__ . '/../../vendor/autoload.php';
}

echo "Esecuzione migrazioni SQL...\n";

$dir = realpath(__DIR__ . '/../migrations') ?: realpath(__DIR__ . '/../../migrations');
if (!$dir || !is_dir($dir)) {
	echo "Nessuna directory migrazioni trovata.\n";
	exit(0);
}

try {
	$pdo = Connection::getPDO();
} catch (Throwable $e) {
	fwrite(STDERR, "Connessione DB fallita: " . $e->getMessage() . "\n");
	exit(1);
}

$backupDir = (string)(getenv('MIGRATION_BACKUP_DIR') ?: '/backups/pre-migrazione');
$keep = max(1, (int)(getenv('MIGRATION_BACKUP_KEEP') ?: 3));
$backup = getenv('MIGRATION_BACKUP') === 'skip'
	? null
	: static fn(): string => PreMigrationBackup::create($pdo, $backupDir, Config::getVersion(), $keep);

$runner = new MigrationRunner($pdo, $dir, $backup, static function (string $line): void {
	echo $line, "\n";
});

try {
	$runner->run();
} catch (MigrationFailedException $e) {
	fwrite(STDERR, "❌ " . $e->getMessage() . "\n");
	fwrite(STDERR, "Per tornare indietro: rimettere APP_VERSION precedente; dump pre-migrazione in {$backupDir}.\n");
	fwrite(STDERR, "Disco pieno o /backups non scrivibile: MIGRATION_BACKUP=skip solo per emergenza.\n");
	exit(1);
}

echo "Migrazioni completate.\n";
