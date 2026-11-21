<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Support\IntegrationTestCase;

/** POST /api/frontoffice/carrello/checkout: redirect al checkout, 422 pagoPA → 409 dedicato. */
#[Group('db')]
#[Group('integration')]
final class CheckoutConflictTest extends IntegrationTestCase
{
    /** @return array<string,mixed> */
    private function carrello(): array
    {
        return [
            'idDominio' => self::DOMINIO,
            'notices' => [['numeroAvviso' => '300000000000000001', 'importo' => 10.0, 'causale' => 'Pagamento di test']],
            'returnUrls' => ['ok' => 'http://frontoffice/esito/ok', 'cancel' => 'http://frontoffice/esito/annullato', 'error' => 'http://frontoffice/esito/errore'],
            'emailNotice' => 'cittadino@example.test',
        ];
    }

    public function testCheckoutRestituisceLocationDelFinto(): void
    {
        $resp = $this->request('POST', '/api/frontoffice/carrello/checkout', $this->carrello(), self::bearer());
        $data = self::json($resp);
        self::assertTrue($data['success'], (string)$resp->getBody());
        $location = $data['data']['location'] ?? $data['location'] ?? '';
        self::assertStringStartsWith(self::$fake->baseUrl() . '/__checkout/', $location);

        $carts = array_values(array_filter(self::$fake->requests(), static fn(array $r): bool => $r['path'] === '/checkout/carts'));
        self::assertCount(1, $carts);
        self::assertSame('300000000000000001', $carts[0]['body']['paymentNotices'][0]['noticeNumber']);
        self::assertSame(1000, $carts[0]['body']['paymentNotices'][0]['amount']);
        $this->assertNoUnmatched();
    }

    public function testPagoPa422Diventa409(): void
    {
        self::$fake->failNext('POST', '#/carts$#', 422, ['title' => 'Invalid payment notice data', 'status' => 422]);
        $resp = $this->request('POST', '/api/frontoffice/carrello/checkout', $this->carrello(), self::bearer());
        self::assertSame(200, $resp->getStatusCode());
        $data = self::json($resp);
        self::assertFalse($data['success']);
        self::assertSame(409, $data['error_status']);
        self::assertSame('409', $resp->getHeaderLine('X-App-Error-Status'));
    }
}
