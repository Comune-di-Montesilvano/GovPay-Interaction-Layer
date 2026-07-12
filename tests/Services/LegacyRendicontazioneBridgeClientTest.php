<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\LegacyRendicontazioneBridgeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class LegacyRendicontazioneBridgeClientTest extends TestCase
{
    public function testInviaConEsitoPositivo(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['esito' => true, 'messaggio' => 'ok'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $bridge = new LegacyRendicontazioneBridgeClient($client);

        $result = $bridge->invia('GERI', '3012024000000001', 'ATTO123', '2026-07-10', 55.0, '1');

        $this->assertTrue($result['esito']);
        $this->assertSame('ok', $result['messaggio']);
    }

    public function testInviaConEsitoNegativoDalBridge(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['esito' => false, 'messaggio' => 'rata non trovata'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $bridge = new LegacyRendicontazioneBridgeClient($client);

        $result = $bridge->invia('DILAZIONE', '9012024000000001', 'ATTO999', '2026-07-10', 10.0);

        $this->assertFalse($result['esito']);
        $this->assertSame('rata non trovata', $result['messaggio']);
    }

    public function testInviaConErroreDiRete(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'http://bridge')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $bridge = new LegacyRendicontazioneBridgeClient($client);

        $result = $bridge->invia('GERI', '3012024000000001', 'ATTO123', '2026-07-10', 55.0);

        $this->assertFalse($result['esito']);
        $this->assertStringContainsString('Connection refused', $result['messaggio']);
    }
}
