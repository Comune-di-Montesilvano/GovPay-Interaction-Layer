<?php
declare(strict_types=1);

namespace App\Services\Deploy;

/**
 * Verifica dello stack appena avviato (servizio compose `post-deploy-check`), solo rete interna:
 * backoffice (versione, DB, migrazioni registrate) via GET /api/internal/deploy-check e
 * frontoffice (versione, raggiungibilita' del backoffice col MASTER_TOKEN) via GET /health/deploy.
 * Le versioni di backoffice e frontoffice devono coincidere tra loro e, se APP_VERSION e' un
 * semver, con APP_VERSION.
 */
final class DeployCheck
{
    private const SEMVER = '/^v?\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/';

    /**
     * @param \Closure(string, string): array{status:int, body:string} $httpGet url, bearer token;
     *        status 0 = errore di rete
     */
    public function __construct(
        private readonly string $backofficeUrl,
        private readonly string $frontofficeUrl,
        private readonly string $token,
        private readonly string $appVersion,
        private readonly \Closure $httpGet,
    ) {
    }

    /** URL interno di un servizio dello stack: con SSL=on Apache parla TLS sulla porta 80. */
    public static function internalUrl(string $service, string $ssl): string
    {
        return strtolower(trim($ssl)) === 'on' ? "https://{$service}:80" : "http://{$service}";
    }

    /** @return list<CheckResult> */
    public function run(): array
    {
        $results = [];

        [$bo, $boErr] = $this->fetch($this->backofficeUrl . '/api/internal/deploy-check');
        $results[] = new CheckResult('backoffice', $bo !== null, $bo !== null ? 'versione ' . $this->version($bo) : $boErr);

        if ($bo === null) {
            foreach (['db', 'migrazioni'] as $name) {
                $results[] = new CheckResult($name, false, 'non verificato (backoffice irraggiungibile)');
            }
        } else {
            $dbOk = ($bo['db'] ?? false) === true;
            $results[] = new CheckResult('db', $dbOk, $dbOk ? 'raggiungibile' : 'non raggiungibile dal backoffice');
            $results[] = $this->migrations($bo, $dbOk);
        }

        [$fo, $foErr] = $this->fetch($this->frontofficeUrl . '/health/deploy');
        $results[] = new CheckResult('frontoffice', $fo !== null, $fo !== null ? 'versione ' . $this->version($fo) : $foErr);
        if ($fo === null) {
            $results[] = new CheckResult('frontoffice→backoffice', false, 'non verificato (frontoffice irraggiungibile)');
        } else {
            $linkOk = ($fo['backoffice'] ?? false) === true;
            $results[] = new CheckResult('frontoffice→backoffice', $linkOk, $linkOk
                ? 'configurazione letta col MASTER_TOKEN'
                : 'il frontoffice non legge la configurazione dal backoffice (MASTER_TOKEN o BACKOFFICE_INTERNAL_URL)');
        }

        $results[] = $this->versions($bo, $fo);

        return $results;
    }

    /** @param array<string, mixed> $bo */
    private function migrations(array $bo, bool $dbOk): CheckResult
    {
        $m = $bo['migrations'] ?? null;
        if (!$dbOk || !is_array($m)) {
            return new CheckResult('migrazioni', false, 'non verificato (DB non raggiungibile)');
        }
        $pending = array_values(array_map('strval', (array)($m['pending'] ?? [])));
        $mismatched = array_values(array_map('strval', (array)($m['mismatched'] ?? [])));
        if ($pending !== []) {
            return new CheckResult('migrazioni', false, 'non applicate: ' . implode(', ', $pending));
        }
        $detail = (int)($m['registered'] ?? 0) . ' registrate';
        if ($mismatched !== []) {
            $detail .= '; attenzione, modificate dopo l\'applicazione: ' . implode(', ', $mismatched);
        }
        return new CheckResult('migrazioni', true, $detail);
    }

    /**
     * @param array<string, mixed>|null $bo
     * @param array<string, mixed>|null $fo
     */
    private function versions(?array $bo, ?array $fo): CheckResult
    {
        if ($bo === null || $fo === null) {
            return new CheckResult('versioni', false, 'non verificato (servizio irraggiungibile)');
        }
        $boV = self::normalize($this->version($bo));
        $foV = self::normalize($this->version($fo));
        if ($boV !== $foV) {
            return new CheckResult('versioni', false, "backoffice {$boV} diverso da frontoffice {$foV}");
        }
        if (preg_match(self::SEMVER, trim($this->appVersion)) !== 1) {
            return new CheckResult('versioni', true, "{$boV} (APP_VERSION={$this->appVersion}: non confrontata)");
        }
        $want = self::normalize($this->appVersion);
        if ($want !== $boV) {
            return new CheckResult('versioni', false, "immagini {$boV} diverse da APP_VERSION={$want}");
        }
        return new CheckResult('versioni', true, "{$boV} = APP_VERSION");
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string} corpo JSON o null + motivo
     */
    private function fetch(string $url): array
    {
        $res = ($this->httpGet)($url, $this->token);
        $status = (int)($res['status'] ?? 0);
        if ($status === 0) {
            return [null, 'irraggiungibile (errore di rete)'];
        }
        if ($status !== 200) {
            return [null, "HTTP {$status}"];
        }
        $data = json_decode((string)($res['body'] ?? ''), true);
        if (!is_array($data)) {
            return [null, 'risposta non JSON'];
        }
        return [$data, ''];
    }

    /** @param array<string, mixed> $data */
    private function version(array $data): string
    {
        $v = (string)($data['version'] ?? '');
        // Mai riportare valori arbitrari del corpo: solo forme di versione attese.
        return preg_match('/^[0-9A-Za-z._-]{1,64}$/', $v) === 1 ? $v : 'sconosciuta';
    }

    private static function normalize(string $v): string
    {
        return ltrim(trim($v), 'v');
    }
}
