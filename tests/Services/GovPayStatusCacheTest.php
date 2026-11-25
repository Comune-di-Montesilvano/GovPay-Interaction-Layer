<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\GovPayClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * Cache del controllo "GovPay raggiungibile" (badge backoffice, /api/govpay/status).
 * Bug: un esito "URL non configurato" calcolato prima del wizard restava in cache 30 s anche dopo
 * il salvataggio dell'URL (badge "GovPay: Offline" su un GovPay funzionante; flake e2e in CI).
 */
final class GovPayStatusCacheTest extends TestCase
{
    private string $file;
    private int $probes = 0;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/gil-status-cache-test-' . bin2hex(random_bytes(4)) . '.json';
        $this->probes = 0;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    /** @return callable(): array{online: bool, error: ?string} */
    private function probe(bool $online = true): callable
    {
        return function () use ($online): array {
            $this->probes++;
            return ['online' => $online, 'error' => $online ? null : 'giu'];
        };
    }

    public function testUrlVuotoNonConfiguratoSenzaProbeNeCache(): void
    {
        $res = GovPayClientFactory::cachedStatus('', 30, $this->file, $this->probe());
        $this->assertFalse($res['online']);
        $this->assertStringContainsString('non configurato', (string)$res['error']);
        $this->assertSame(0, $this->probes);
        $this->assertFileDoesNotExist($this->file);
    }

    public function testEsitoInCacheRiusatoPerLoStessoUrl(): void
    {
        GovPayClientFactory::cachedStatus('http://govpay.test/backoffice', 30, $this->file, $this->probe());
        $res = GovPayClientFactory::cachedStatus('http://govpay.test/backoffice', 30, $this->file, $this->probe(false));
        $this->assertTrue($res['online']);
        $this->assertSame(1, $this->probes);
    }

    public function testUrlCambiatoInvalidaLaCache(): void
    {
        GovPayClientFactory::cachedStatus('http://vecchio.test', 30, $this->file, $this->probe(false));
        $res = GovPayClientFactory::cachedStatus('http://nuovo.test', 30, $this->file, $this->probe());
        $this->assertTrue($res['online']);
        $this->assertSame(2, $this->probes);
    }

    public function testCacheVecchioFormatoSenzaUrlIgnorata(): void
    {
        // Formato scritto dalle versioni <= 1.13.0: nessun legame con l'URL.
        file_put_contents($this->file, json_encode([
            'status' => 'offline',
            'error' => 'GovPay Backoffice URL non configurato nel database.',
            'time' => time(),
        ]));
        $res = GovPayClientFactory::cachedStatus('http://govpay.test', 30, $this->file, $this->probe());
        $this->assertTrue($res['online']);
        $this->assertSame(1, $this->probes);
    }

    public function testCacheScadutaRicalcolata(): void
    {
        GovPayClientFactory::cachedStatus('http://govpay.test', 30, $this->file, $this->probe(false));
        $data = json_decode((string)file_get_contents($this->file), true);
        $data['time'] = time() - 31;
        file_put_contents($this->file, json_encode($data));
        $res = GovPayClientFactory::cachedStatus('http://govpay.test', 30, $this->file, $this->probe());
        $this->assertTrue($res['online']);
        $this->assertSame(2, $this->probes);
    }
}
