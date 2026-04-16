<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App;

class Logger
{
    private static ?Logger $instance = null;
    private string $filePath;

    private function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            $path = self::resolveLogPath();
            self::$instance = new Logger($path);
        }
        return self::$instance;
    }

    public static function getLogFilePath(): string
    {
        if (self::$instance === null) {
            self::getInstance();
        }
        return self::$instance->filePath;
    }

    private static function resolveLogPath(): string
    {
        $custom = getenv('APP_LOG_PATH');
        if (is_string($custom) && $custom !== '') {
            return $custom;
        }

        $suite = getenv('APP_SUITE') ?: ($_ENV['APP_SUITE'] ?? null);
        $root = dirname(__DIR__);
        if ($suite === 'frontoffice') {
            return $root . '/frontoffice/storage/logs/app.log';
        }

        return $root . '/backoffice/storage/logs/app.log';
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $ts = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        $entry = sprintf("%s [%s] %s", $ts, strtoupper($level), $message);
        if ($context) {
            $entry .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $entry .= PHP_EOL;

        // Write atomically to file
        $fp = @fopen($this->filePath, 'a');
        if ($fp) {
            @flock($fp, LOCK_EX);
            @fwrite($fp, $entry);
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }

        // Always write to stderr → visible in docker logs / Portainer
        @fwrite(\STDERR, $entry);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    /**
     * Sanitize an error string for user display: remove truncation markers,
     * ellipses and excessive whitespace. Keep the full error for logs.
     */
    public static function sanitizeErrorForDisplay(string $err): string
    {
        $clean = $err;
        // Remove common truncation markers and sequences of ellipsis
        $clean = preg_replace('/\(truncated[^)]*\)/i', '', $clean);
        $clean = preg_replace('/\[truncated[^\]]*\]/i', '', $clean);
        // Remove explicit 'truncated' token (case-insensitive) possibly left without brackets
        $clean = preg_replace('/\btruncated\b/i', '', $clean);
        // Rimuove sequenze di tre o più punti di sospensione (es. '...' o '....')
        $clean = preg_replace('/\.{3,}/u', '', $clean);
        $clean = preg_replace('/\xE2\x80\xA6/u', '', $clean);
        // Remove repeated newlines and excessive whitespace
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean);
        if (strlen($clean) > 1000) {
            return substr($clean, 0, 1000) . '...';
        }
        return $clean;
    }

    /**
     * Sanitize an error message for full display (remove truncation markers
     * and collapse whitespace) without shortening the message. Use this for
     * expanded views where the user requests the full text.
     */
    public static function sanitizeErrorForFullDisplay(string $err): string
    {
        $clean = $err;
        $clean = preg_replace('/\(truncated[^)]*\)/i', '', $clean);
        $clean = preg_replace('/\[truncated[^\]]*\]/i', '', $clean);
        $clean = preg_replace('/\btruncated\b/i', '', $clean);
        $clean = preg_replace('/\.{3,}/u', '', $clean);
        $clean = preg_replace('/\xE2\x80\xA6/u', '', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean);
        return trim($clean);
    }
}
