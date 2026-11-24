<?php
declare(strict_types=1);

namespace App\Database;

/**
 * Backup automatico prima delle migrazioni: dump (formato DatabaseDumper) in
 * `<dir>/<Ymd-His>-<versione>.sql.gz`, poi rotazione: restano solo i `$keep` dump piu' recenti
 * (ordine per nome = ordine temporale, prefisso data).
 *
 * `$key` identifica l'insieme di migrazioni pendenti (MigrationRunner): se il dump piu' recente
 * e' stato fatto per la stessa chiave lo si riusa. In un crash loop su una migrazione rotta ogni
 * riavvio altrimenti creerebbe un dump nuovo, e dopo `$keep` riavvii la rotazione toglierebbe
 * quello fatto prima di qualsiasi modifica (i successivi possono contenere una migrazione a meta').
 */
final class PreMigrationBackup
{
    /**
     * @return string path del dump creato
     * @throws \RuntimeException directory non creabile/scrivibile o dump fallito
     */
    public static function create(\PDO $pdo, string $dir, string $version, int $keep, string $key = ''): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("directory backup {$dir} non creabile");
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("directory backup {$dir} non scrivibile");
        }

        $existing = glob($dir . '/*.sql.gz') ?: [];
        rsort($existing, SORT_STRING);
        if ($key !== '' && $existing !== [] && @file_get_contents($existing[0] . '.key') === $key) {
            return $existing[0];
        }

        $safe = (string)preg_replace('/[^A-Za-z0-9._-]/', '_', $version);
        $safe = trim($safe, '.') !== '' ? $safe : 'sconosciuta';
        $base = date('Ymd-His') . '-' . $safe;
        $file = "{$dir}/{$base}.sql.gz";
        for ($i = 2; file_exists($file); $i++) {
            $file = "{$dir}/{$base}-{$i}.sql.gz";
        }

        DatabaseDumper::dumpToGzip($pdo, $file);
        if ($key !== '' && @file_put_contents($file . '.key', $key) === false) {
            throw new \RuntimeException("scrittura di {$file}.key fallita");
        }

        $all = glob($dir . '/*.sql.gz') ?: [];
        rsort($all, SORT_STRING);
        foreach (array_slice($all, max(1, $keep)) as $old) {
            @unlink($old);
            @unlink($old . '.key');
        }

        return $file;
    }
}
