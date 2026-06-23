<?php
declare(strict_types=1);

namespace App\Services;

class CacheService
{
    private const CACHE_DIR = '/var/www/cache';

    /**
     * Recupera un valore dalla cache, oppure esegue il callback e lo salva se mancante o scaduto.
     */
    public static function get(string $key, int $ttl, callable $callback): mixed
    {
        $filePath = self::getFilePath($key);
        if (file_exists($filePath)) {
            $mtime = filemtime($filePath);
            if ($mtime !== false && (time() - $mtime) < $ttl) {
                $content = file_get_contents($filePath);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if ($data !== null && isset($data['value'])) {
                        return $data['value'];
                    }
                }
            }
        }

        $value = $callback();
        self::set($key, $value);
        return $value;
    }

    /**
     * Scrive un valore in cache.
     */
    public static function set(string $key, mixed $value): void
    {
        $filePath = self::getFilePath($key);
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $data = [
            'value' => $value,
            'cached_at' => time()
        ];
        @file_put_contents($filePath, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Rimuove un elemento dalla cache.
     */
    public static function delete(string $key): void
    {
        $filePath = self::getFilePath($key);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Svuota la cache associata ad un dominio (dashboard e report counts).
     */
    public static function clearDomainCache(string $idDominio): void
    {
        $dir = self::CACHE_DIR;
        if (!is_dir($dir)) {
            return;
        }
        $prefix1 = 'cache_dashboard_stats_' . $idDominio;
        $prefix2 = 'cache_dashboard_tipologie_' . $idDominio;
        $prefix3 = 'cache_report_tefa_counts_' . $idDominio;
        $prefix4 = 'cache_report_ragioneria_counts_' . $idDominio;

        foreach (scandir($dir) ?: [] as $file) {
            if (
                str_starts_with($file, $prefix1) ||
                str_starts_with($file, $prefix2) ||
                str_starts_with($file, $prefix3) ||
                str_starts_with($file, $prefix4)
            ) {
                @unlink($dir . '/' . $file);
            }
        }
    }

    private static function getFilePath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return self::CACHE_DIR . '/cache_' . $safeKey . '.json';
    }
}
