<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Config\SettingsRepository;
use GovPay\Backoffice\Api\InfoApi;
use GovPay\Backoffice\ApiException;
use GovPay\Backoffice\Configuration;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\FakeGovPayPendenzeController;

/**
 * Stack HTTP della GovPayClientFactory (fix round 1, http_errors): solo l'handler finale e'
 * finto (MockHandler grezzo, mai HandlerStack::create()), tutti i middleware sono quelli reali.
 * DB per SettingsRepository (metodo di autenticazione letto dalla factory).
 */
#[Group('db')]
final class GovPayClientFactoryTest extends DbTestCase
{
    protected static array $tables = ['settings'];

    private const BASE = 'https://govpay.example.test/backoffice/v1';

    /** @var list<array<string,mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        $this->history = [];
    }

    protected function tearDown(): void
    {
        @unlink(FakeGovPayPendenzeController::circuitBreakerFile());
        SettingsRepository::flush();
        parent::tearDown();
    }

    public function testClientErrorThrowsClientExceptionWithResponseAndIsNotRetried(): void
    {
        $client = FakeGovPayPendenzeController::factoryClient(new MockHandler([new Response(404, [], '{"categoria":"OPERAZIONE"}')]), $this->history);

        try {
            $client->request('GET', self::BASE . '/pendenze/A2A/X');
            self::fail('un 4xx deve lanciare');
        } catch (ClientException $e) {
            self::assertSame(404, $e->getResponse()->getStatusCode());
            self::assertSame('{"categoria":"OPERAZIONE"}', (string)$e->getResponse()->getBody());
        }
        self::assertCount(1, $this->history);
    }

    /** Un 5xx lancia ma resta una RISPOSTA per il circuit breaker: nessun conteggio guasti. */
    public function testServerErrorThrowsAndDoesNotCountAsCircuitBreakerFailure(): void
    {
        $responses = array_fill(0, 4, new Response(503));
        $client = FakeGovPayPendenzeController::factoryClient(new MockHandler($responses), $this->history);

        for ($i = 0; $i < 4; $i++) {
            try {
                $client->request('GET', self::BASE . '/info');
                self::fail('un 5xx deve lanciare');
            } catch (ServerException $e) {
                self::assertSame(503, $e->getResponse()->getStatusCode());
            }
        }
        self::assertCount(4, $this->history, 'nessun retry, circuito mai aperto');
        self::assertFileDoesNotExist(FakeGovPayPendenzeController::circuitBreakerFile());
    }

    /** Guasto di trasporto (nessuna risposta): conteggiato dal circuit breaker come prima. */
    public function testTransportFailureStillCountsAndOpensCircuitAfterThree(): void
    {
        $fail = static fn() => new ConnectException('cURL error 7', new Request('GET', self::BASE));
        $client = FakeGovPayPendenzeController::factoryClient(new MockHandler([$fail(), $fail(), $fail(), new Response(200)]), $this->history);

        for ($i = 0; $i < 3; $i++) {
            try {
                $client->request('GET', self::BASE . '/info');
            } catch (ConnectException) {
            }
        }
        $cb = json_decode((string)file_get_contents(FakeGovPayPendenzeController::circuitBreakerFile()), true);
        self::assertSame(['OPEN', 3], [$cb['status'] ?? null, $cb['failures'] ?? null]);

        try {
            $client->request('GET', self::BASE . '/info');
            self::fail('circuito aperto: rifiuto locale');
        } catch (ConnectException $e) {
            self::assertSame(\App\Services\GovPayClientFactory::CIRCUIT_OPEN_MESSAGE, $e->getMessage());
        }
        self::assertCount(3, $this->history, 'il quarto invio non parte');
    }

    public function testNetworkRetryOnCurl56StillWorks(): void
    {
        $reset = new \GuzzleHttp\Exception\RequestException('cURL error 56: reset', new Request('GET', self::BASE), null, null, ['errno' => 56]);
        $client = FakeGovPayPendenzeController::factoryClient(new MockHandler([$reset, new Response(200, [], 'ok')]), $this->history);

        self::assertSame('ok', (string)$client->request('GET', self::BASE . '/info')->getBody());
        self::assertCount(2, $this->history);
        self::assertSame('close', $this->history[1]['request']->getHeaderLine('Connection'));
    }

    /** Client OpenAPI generato: RequestException dello stack -> ApiException con codice e corpo. */
    public function testGeneratedClientMapsHttpErrorToApiException(): void
    {
        $client = FakeGovPayPendenzeController::factoryClient(new MockHandler([new Response(503, [], '{"descrizione":"giu"}')]), $this->history);
        $api = new InfoApi($client, (new Configuration())->setHost(self::BASE));

        try {
            $api->getInfo();
            self::fail('503 deve diventare ApiException');
        } catch (ApiException $e) {
            self::assertSame(503, $e->getCode());
            self::assertSame('{"descrizione":"giu"}', $e->getResponseBody());
        }
    }

    public function testGeneratedClientParsesSuccess(): void
    {
        $client = FakeGovPayPendenzeController::factoryClient(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"appName":"GovPay","versione":"3.8"}')]), $this->history);
        $api = new InfoApi($client, (new Configuration())->setHost(self::BASE));

        [, $status] = $api->getInfoWithHttpInfo();

        self::assertSame(200, $status);
        self::assertCount(1, $this->history);
    }
}
