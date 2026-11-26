<?php
declare(strict_types=1);

namespace App\Database;

/**
 * Migrazioni SQL di `migrations/` con registro dei file applicati (`gil_schema_migrations`).
 *
 * - Solo i file non registrati vengono eseguiti, in ordine naturale del nome.
 * - Prima di eseguirli, se il DB ha dati (tabella `users` non vuota), backup tramite `$backup`;
 *   backup fallito → nessuna migrazione, MigrationFailedException.
 * - Regola stretta: il primo file che fallisce ferma tutto (MigrationFailedException), non viene
 *   registrato e i successivi non partono.
 * - Bootstrap (registro assente) su un DB con dati: primo avvio dopo l'introduzione del registro.
 *   Tutti i file sono eseguiti tollerando gli errori come faceva il vecchio runner (alcuni file
 *   gia' applicati falliscono per scelta alla riesecuzione) e registrati tutti. Su un DB senza
 *   dati (installazione nuova) vale la regola stretta: nessun file e' "gia' applicato".
 * - Un file registrato con checksum diverso NON si riesegue: solo segnalato (migrazione gia'
 *   applicata modificata = errore di sviluppo).
 */
final class MigrationRunner
{
    public const REGISTRY_TABLE = 'gil_schema_migrations';

    /**
     * @param \Closure(string): string|null $backup crea il backup e ne ritorna il path; riceve la
     *        chiave dei file pendenti (stessa chiave = stesso backup, vedi PreMigrationBackup); null = disattivato
     * @param \Closure(string): void   $log
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $dir,
        private readonly ?\Closure $backup,
        private readonly \Closure $log,
        private readonly string $registryTable = self::REGISTRY_TABLE,
        private readonly string $dataTable = 'users',
    ) {
        foreach ([$registryTable, $dataTable] as $t) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                throw new \InvalidArgumentException("nome tabella non valido: {$t}");
            }
        }
    }

    /** Directory `migrations/` (repo: root; immagine: /var/www/html/migrations, accanto ad app/). */
    public static function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    /** @return array{pending: list<string>, mismatched: list<string>} */
    public function status(): array
    {
        $files = $this->files();
        $registered = $this->tableExists($this->registryTable) ? $this->registered() : [];
        $pending = [];
        $mismatched = [];
        foreach ($files as $name => $checksum) {
            if (!isset($registered[$name])) {
                $pending[] = $name;
            } elseif ($registered[$name] !== $checksum) {
                $mismatched[] = $name;
            }
        }
        return ['pending' => $pending, 'mismatched' => $mismatched];
    }

    public function run(): MigrationResult
    {
        $bootstrap = !$this->tableExists($this->registryTable);
        $status = $this->status();
        $pending = $status['pending'];

        foreach ($status['mismatched'] as $name) {
            ($this->log)("⚠️  {$name}: checksum diverso dal registro (file modificato dopo l'applicazione), non rieseguito");
        }
        ($this->log)('migrazioni: ' . count($pending) . ' da applicare');

        $hasData = $this->hasData();
        $tolerant = $bootstrap && $hasData;
        $backupFile = null;
        if ($pending !== [] && $hasData) {
            if ($this->backup === null) {
                ($this->log)('backup pre-migrazione DISATTIVATO (MIGRATION_BACKUP=skip)');
            } else {
                try {
                    $files = $this->files();
                    $key = hash('sha256', implode("
", array_map(static fn(string $n): string => $n . ':' . $files[$n], $pending)));
                    $backupFile = ($this->backup)($key);
                } catch (\Throwable $e) {
                    throw new MigrationFailedException('backup pre-migrazione fallito, nessuna migrazione eseguita: ' . $e->getMessage(), 0, $e);
                }
                $size = @filesize($backupFile);
                ($this->log)('backup pre-migrazione: ' . $backupFile . ($size !== false ? ' (' . (int)ceil($size / 1024) . ' KB)' : ''));
            }
        }

        if ($bootstrap) {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$this->registryTable}` (
                filename VARCHAR(255) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        $files = $this->files();
        $applied = 0;
        $tolerated = [];
        foreach ($pending as $name) {
            $sql = (string)file_get_contents($this->dir . '/' . $name);
            try {
                $this->pdo->exec($sql);
                ($this->log)("- {$name}: OK");
                $applied++;
            } catch (\Throwable $e) {
                if (!$tolerant) {
                    ($this->log)("- {$name}: ERRORE " . $e->getMessage());
                    throw new MigrationFailedException("migrazione {$name} fallita: " . $e->getMessage(), 0, $e);
                }
                ($this->log)("- {$name}: errore tollerato (primo avvio col registro): " . $e->getMessage());
                $tolerated[] = $name;
            }
            $this->register($name, $files[$name]);
        }

        if ($bootstrap) {
            ($this->log)('registro migrazioni creato: ' . count($pending) . ' file, ' . count($tolerated) . ' errori tollerati'
                . ($tolerated !== [] ? ': ' . implode(', ', $tolerated) : ''));
        }

        return new MigrationResult($applied, $bootstrap, $backupFile, $tolerated, $status['mismatched']);
    }

    /** @return array<string, string> nome file → sha256, in ordine naturale */
    private function files(): array
    {
        $paths = glob($this->dir . '/*.sql') ?: [];
        $names = array_map('basename', $paths);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $out = [];
        foreach ($names as $name) {
            $out[$name] = hash_file('sha256', $this->dir . '/' . $name) ?: '';
        }
        return $out;
    }

    /** @return array<string, string> */
    private function registered(): array
    {
        $rows = $this->pdo->query("SELECT filename, checksum FROM `{$this->registryTable}`")->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map('strval', $rows);
    }

    private function register(string $name, string $checksum): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO `{$this->registryTable}` (filename, checksum, applied_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), applied_at = VALUES(applied_at)");
        $stmt->execute([$name, $checksum]);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasData(): bool
    {
        if (!$this->tableExists($this->dataTable)) {
            return false;
        }
        return $this->pdo->query("SELECT 1 FROM `{$this->dataTable}` LIMIT 1")->fetchColumn() !== false;
    }
}
