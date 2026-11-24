<?php
declare(strict_types=1);

namespace App\Database;

/**
 * Backup automatico prima delle migrazioni: dump (formato DatabaseDumper) in
 * `<dir>/<Ymd-His>-<versione>.sql.gz`, poi rotazione: restano solo i `$keep` dump piu' recenti
 * (ordine per nome = ordine temporale, prefisso data).
 */
final class PreMigrationBackup
{
    /**
     * @return string path del dump creato
     * @throws \RuntimeException directory non creabile/scrivibile o dump fallito
     */
    public static function create(\PDO $pdo, string $dir, string $version, int $keep): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("directory backup {$dir} non creabile");
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("directory backup {$dir} non scrivibile");
        }

        $safe = (string)preg_replace('/[^A-Za-z0-9._-]/', '_', $version);
        $safe = trim($safe, '.') !== '' ? $safe : 'sconosciuta';
        $base = date('Ymd-His') . '-' . $safe;
        $file = "{$dir}/{$base}.sql.gz";
        for ($i = 2; file_exists($file); $i++) {
            $file = "{$dir}/{$base}-{$i}.sql.gz";
        }

        DatabaseDumper::dumpToGzip($pdo, $file);

        $all = glob($dir . '/*.sql.gz') ?: [];
        rsort($all, SORT_STRING);
        foreach (array_slice($all, max(1, $keep)) as $old) {
            @unlink($old);
        }

        return $file;
    }
}
