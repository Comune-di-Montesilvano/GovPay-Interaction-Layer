<?php
declare(strict_types=1);

namespace App\Services\Deploy;

/**
 * GET /health/deploy del frontoffice, chiamato solo da post-deploy-check sulla rete interna.
 * Bearer MASTER_TOKEN obbligatorio: senza, 401 senza corpo e nessuna chiamata al backoffice
 * (niente versione esposta ne' amplificazione verso il backoffice da internet).
 */
final class FrontofficeDeployStatus
{
    /**
     * @param array<string, mixed>   $versionInfo App\Config\Config::getVersionInfo()
     * @param \Closure(): bool       $backofficeOk legge /api/frontoffice/config col MASTER_TOKEN
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function respond(?string $authorization, string $masterToken, array $versionInfo, \Closure $backofficeOk): array
    {
        if ($masterToken === '') {
            return ['status' => 503, 'body' => []];
        }
        $auth = (string)$authorization;
        if (!str_starts_with($auth, 'Bearer ') || !hash_equals($masterToken, substr($auth, 7))) {
            return ['status' => 401, 'body' => []];
        }
        return ['status' => 200, 'body' => [
            'version' => (string)($versionInfo['version'] ?? 'development'),
            'commit' => (string)($versionInfo['commit'] ?? 'unknown'),
            'backoffice' => $backofficeOk(),
        ]];
    }
}
