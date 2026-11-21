<?php
declare(strict_types=1);

namespace Tests\Fakes;

use PHPUnit\Framework\TestCase;
use Tests\Support\FakeGovPayServer;

/** GovPay/pagoPA finto (tests/Fakes/GovPay/router.php) usato da integrazione ed e2e. */
final class FakeGovPayServerTest extends TestCase
{
    private const CF = 'RSSMRA80A01H501U';
    private const DOMINIO = '00000000000';

    private static FakeGovPayServer $fake;

    public static function setUpBeforeClass(): void
    {
        self::$fake = FakeGovPayServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$fake->stop();
    }

    protected function setUp(): void
    {
        self::$fake->reset();
    }

    /**
     * @param array<string,mixed>|null $json
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    private function http(string $method, string $path, ?array $json = null): array
    {
        $ch = curl_init(self::$fake->baseUrl() . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $json === null ? null : json_encode($json),
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    private function putPendenza(string $id = 'PND-1', string $cf = self::CF): array
    {
        $r = $this->http('PUT', '/backoffice/pendenze/A2A-TEST/' . $id, [
            'idDominio' => self::DOMINIO,
            'causale' => 'Pendenza di test',
            'importo' => 10.0,
            'soggettoPagatore' => ['tipo' => 'F', 'identificativo' => $cf, 'anagrafica' => 'Mario Rossi'],
            'voci' => [['idVocePendenza' => '1', 'descrizione' => 'Voce di test', 'importo' => 10.0]],
        ]);
        self::assertSame(201, $r['status'], $r['body']);
        return json_decode($r['body'], true);
    }

    public function testPutThenGetPendenza(): void
    {
        $created = $this->putPendenza();
        self::assertMatchesRegularExpression('/^3\d{17}$/', $created['numeroAvviso']);

        $r = $this->http('GET', '/backoffice/pendenze/A2A-TEST/PND-1');
        self::assertSame(200, $r['status']);
        $p = json_decode($r['body'], true);
        self::assertSame('NON_ESEGUITA', $p['stato']);
        self::assertSame($created['numeroAvviso'], $p['numeroAvviso']);
        self::assertSame('00000000000000001', $p['iuvAvviso']);
    }

    public function testByAvvisoAndListByDebitore(): void
    {
        $created = $this->putPendenza();

        $r = $this->http('GET', '/backoffice/pendenze/byAvviso/' . self::DOMINIO . '/' . $created['numeroAvviso']);
        self::assertSame(200, $r['status']);
        self::assertSame('PND-1', json_decode($r['body'], true)['idPendenza']);

        $list = json_decode($this->http('GET', '/backoffice/pendenze?idDebitore=' . self::CF)['body'], true);
        self::assertSame(1, $list['numRisultati']);
        $other = json_decode($this->http('GET', '/backoffice/pendenze?idDebitore=BNCLGU80A01H501X')['body'], true);
        self::assertSame(0, $other['numRisultati']);
    }

    public function testRicevutaOnlyAfterPaid(): void
    {
        $this->putPendenza();
        $path = '/backoffice/rpp/' . self::DOMINIO . '/00000000000000001/n_a/rt';
        self::assertSame(404, $this->http('GET', $path)['status']);

        self::$fake->setPaid(self::DOMINIO, '00000000000000001');
        $r = $this->http('GET', $path);
        self::assertSame(200, $r['status']);
        self::assertStringStartsWith('application/pdf', $r['headers']['content-type'] ?? '');
        self::assertStringStartsWith('%PDF', $r['body']);
        $p = json_decode($this->http('GET', '/backoffice/pendenze/A2A-TEST/PND-1')['body'], true);
        self::assertSame('ESEGUITA', $p['stato']);
        $rpp = $p['voci'][0]['riscossioni'][0]['rpp'] ?? '';
        self::assertSame('/rpp/' . self::DOMINIO . '/00000000000000001/CCP-FAKE', $rpp);
        self::assertSame(200, $this->http('GET', '/backoffice' . $rpp . '/rt')['status']);
    }

    public function testInfo(): void
    {
        $r = $this->http('GET', '/backoffice/info');
        self::assertSame(200, $r['status']);
        self::assertArrayHasKey('versione', json_decode($r['body'], true));
        self::assertSame([], self::$fake->unmatched());
    }

    public function testAvvisoPdf(): void
    {
        $r = $this->http('GET', '/backoffice/avvisi/' . self::DOMINIO . '/300000000000000001');
        self::assertSame(200, $r['status']);
        self::assertStringStartsWith('%PDF', $r['body']);
    }

    public function testCartsLocationPointsToFake(): void
    {
        $r = $this->http('POST', '/checkout/carts', [
            'paymentNotices' => [['noticeNumber' => '300000000000000001', 'fiscalCode' => self::DOMINIO, 'amount' => 1000]],
            'returnUrls' => ['returnOkUrl' => 'http://frontoffice/esito?ok=1', 'returnCancelUrl' => 'http://frontoffice/esito?ko=1', 'returnErrorUrl' => 'http://frontoffice/esito?err=1'],
        ]);
        self::assertSame(302, $r['status']);
        $location = $r['headers']['location'] ?? '';
        self::assertStringStartsWith(self::$fake->baseUrl() . '/__checkout/', $location);

        $page = $this->http('GET', substr($location, strlen(self::$fake->baseUrl())));
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('http://frontoffice/esito?ok=1', $page['body']);
    }

    public function testFailNextReturnsConfiguredError(): void
    {
        self::$fake->failNext('POST', '#/carts$#', 422, ['title' => 'Invalid payment notice data']);
        $first = $this->http('POST', '/checkout/carts', ['returnUrls' => []]);
        self::assertSame(422, $first['status']);
        self::assertSame('Invalid payment notice data', json_decode($first['body'], true)['title']);
        self::assertSame(302, $this->http('POST', '/checkout/carts', ['returnUrls' => []])['status']);
    }

    public function testUnmatchedIsRecorded(): void
    {
        self::assertSame(404, $this->http('GET', '/backoffice/inesistente')['status']);
        self::assertSame(['GET /backoffice/inesistente'], self::$fake->unmatched());
    }

    public function testRequestsAreRecorded(): void
    {
        $this->putPendenza();
        $req = self::$fake->requests();
        self::assertCount(1, $req);
        self::assertSame('PUT', $req[0]['method']);
        self::assertSame('/backoffice/pendenze/A2A-TEST/PND-1', $req[0]['path']);
        self::assertSame(self::CF, $req[0]['body']['soggettoPagatore']['identificativo']);
    }

    public function testStateIsolatedAfterReset(): void
    {
        $this->putPendenza();
        self::$fake->reset();
        self::assertSame([], self::$fake->requests());
        self::assertSame(404, $this->http('GET', '/backoffice/pendenze/A2A-TEST/PND-1')['status']);
    }
}
