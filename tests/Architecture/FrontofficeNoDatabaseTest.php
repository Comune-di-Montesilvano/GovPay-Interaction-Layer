<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Vincolo architetturale: il frontoffice e' un sidecar e parla solo con il backoffice
 * (API /api/frontoffice/*, Bearer MASTER_TOKEN). Il container non riceve credenziali DB:
 * qualsiasi accesso a MariaDB (diretto o tramite classi App\ che leggono il DB) fallirebbe
 * a runtime. Configurazione e rate limit passano da GET /api/frontoffice/config e
 * POST /api/frontoffice/rate-limit/check.
 */
final class FrontofficeNoDatabaseTest extends TestCase
{
    /** @var array<string, string> regex => motivo */
    private const FORBIDDEN = [
        '/App\\\\Database\\\\/' => 'classi App\\Database (repository/Connection)',
        '/\bConnection::/' => 'App\\Database\\Connection',
        '/\bSettingsRepository\b/' => 'SettingsRepository legge la tabella settings',
        '/\bRateLimiter\b/' => 'RateLimiter scrive rate_limit_buckets (usare /api/frontoffice/rate-limit/check)',
        '/\bConfig::get\s*\(/' => 'Config::get legge la tabella settings',
        '/\bPDO\b/' => 'connessione PDO diretta',
        '/\bmysqli\b/' => 'connessione mysqli diretta',
        '/(getenv\s*\(|\$_ENV\[|\$_SERVER\[|\$env\s*\()\s*[\'"]DB_/' => 'variabili DB_* (il container non le riceve)',
    ];

    public function testFrontofficeDoesNotTouchTheDatabase(): void
    {
        $root = dirname(__DIR__, 2) . '/frontoffice';
        $this->assertDirectoryExists($root);

        $violations = [];
        $files = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_contains($path, '/vendor/')) {
                continue;
            }
            $files++;
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $n => $line) {
                foreach (self::FORBIDDEN as $regex => $reason) {
                    if (preg_match($regex, $line) === 1) {
                        $violations[] = sprintf('%s:%d — %s: %s', substr($path, strlen($root) - 11), $n + 1, $reason, trim($line));
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $files, 'nessun file PHP trovato in frontoffice/');
        $this->assertSame([], $violations, "Il frontoffice non deve accedere al DB: passare dalle API /api/frontoffice/* del backoffice.\n" . implode("\n", $violations));
    }
}
