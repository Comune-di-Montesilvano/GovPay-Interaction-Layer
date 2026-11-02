<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\ThrowableSummary;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ThrowableSummaryTest extends TestCase
{
    public function testClasseBreveSenzaMessaggio(): void
    {
        self::assertSame('RuntimeException', ThrowableSummary::of(new \RuntimeException('CF RSSMRA80A01H501U')));
        self::assertSame('ConnectException', ThrowableSummary::of(new ConnectException('https://x.test/?q=segreto', new Request('GET', 'https://x.test'))));
    }

    /** Fix round 2: testo mostrato (stato GovPay, download operatore) senza URL ne' corpo. */
    public function testForDisplayHttpPiuTitleStatusMaiUrlNeCorpo(): void
    {
        $req = new Request('GET', 'https://x.test/pendenze?cf=RSSMRA80A01H501U');
        $problem = '{"title":"Bad Gateway","status":502,"detail":"RSSMRA80A01H501U","descrizione":"segreto"}';

        self::assertSame('HTTP 502 - Bad Gateway (status 502)', ThrowableSummary::forDisplay(new ServerException('https://x.test/?cf=RSSMRA80A01H501U', $req, new Response(502, [], $problem))));
        self::assertSame('HTTP 404', ThrowableSummary::forDisplay(new ClientException('segreto', $req, new Response(404, [], 'RSSMRA80A01H501U'))));
        self::assertSame('ConnectException', ThrowableSummary::forDisplay(new ConnectException('cURL error 7 https://x.test/?cf=RSSMRA80A01H501U', $req)));
        self::assertSame('RuntimeException', ThrowableSummary::forDisplay(new \RuntimeException('RSSMRA80A01H501U')));

        // ApiException dei client generati: codice = status, corpo da getResponseBody()
        $api = new \GovPay\Backoffice\ApiException('[503] https://x.test/info?cf=RSSMRA80A01H501U', 503, [], "{\"title\":\"Down\\u0007\\n\",\"status\":503}");
        self::assertSame('HTTP 503 - Down (status 503)', ThrowableSummary::forDisplay($api));
        self::assertSame('ApiException', ThrowableSummary::forDisplay(new \GovPay\Backoffice\ApiException('[0] cURL error 7 https://x.test/', 0)));

        $open = new \GovPay\Backoffice\ApiException('[0] ' . \App\Services\GovPayClientFactory::CIRCUIT_OPEN_MESSAGE, 0);
        self::assertSame(\App\Services\GovPayClientFactory::CIRCUIT_OPEN_MESSAGE, ThrowableSummary::forDisplay($open));
    }

    public function testStatusHttpQuandoCeUnaRisposta(): void
    {
        $e = new ClientException('segreto', new Request('GET', 'https://x.test/?cf=RSSMRA'), new Response(404, [], 'segreto'));
        self::assertSame('ClientException (HTTP 404)', ThrowableSummary::of($e));
    }
}
