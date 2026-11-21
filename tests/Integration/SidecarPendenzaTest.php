<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Support\IntegrationTestCase;

/** POST /api/frontoffice/pendenze: voci risolte dal DB (IBAN, contabilita') e inviate a GovPay. */
#[Group('db')]
#[Group('integration')]
final class SidecarPendenzaTest extends IntegrationTestCase
{
    private const TIPO = 'TEST_SERVIZIO';
    private const IBAN = 'IT60X0542811101000000123456';

    protected function setUp(): void
    {
        parent::setUp();
        $now = date('Y-m-d H:i:s');
        self::pdo()->prepare(
            'INSERT INTO entrate_tipologie (id_dominio, id_entrata, descrizione, iban_accredito, codice_contabilita, tipo_contabilita, abilitato_backoffice, effective_enabled, updated_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, ?)'
        )->execute([self::DOMINIO, self::TIPO, 'Servizio di test', self::IBAN, '9/0000000', '9', $now, $now]);
    }

    public function testCreaPendenzaConVociDalDb(): void
    {
        $resp = $this->request('POST', '/api/frontoffice/pendenze', [
            'idTipoPendenza' => self::TIPO,
            'idDominio' => self::DOMINIO,
            'causale' => 'Pagamento servizio di test',
            'importo' => 10.0,
            'soggettoPagatore' => ['tipo' => 'F', 'identificativo' => self::CF, 'anagrafica' => 'Mario Rossi'],
        ], self::bearer());
        $data = self::json($resp);
        self::assertTrue($data['success'], (string)$resp->getBody());
        self::assertNotSame('', $data['data']['idPendenza'] ?? $data['idPendenza'] ?? '');

        $puts = array_values(array_filter(self::$fake->requests(), static fn(array $r): bool => $r['method'] === 'PUT'));
        self::assertCount(1, $puts, 'una sola PUT di creazione verso GovPay');
        self::assertStringStartsWith('/backoffice/pendenze/' . self::ID_A2A . '/', $puts[0]['path']);
        $voce = $puts[0]['body']['voci'][0] ?? [];
        self::assertSame(self::IBAN, $voce['ibanAccredito'] ?? null, json_encode($puts[0]['body']));
        // Senza override dal form il codice contabilita' inviato e' l'id della tipologia, non
        // codice_contabilita del DB (PendenzeController::buildVociWithAccounting).
        self::assertSame(self::TIPO, $voce['codiceContabilita'] ?? null);
        self::assertSame('9', $voce['tipoContabilita'] ?? null);
        self::assertEquals(10.0, $voce['importo'] ?? null);
        self::assertSame(self::CF, $puts[0]['body']['soggettoPagatore']['identificativo']);
        $this->assertNoUnmatched();
    }

    public function testErroreGovPayRestituitoComeErroreSidecar(): void
    {
        self::$fake->failNext('PUT', '#/pendenze/#', 422, ['categoria' => 'RICHIESTA', 'codice' => 'SINTASSI', 'descrizione' => 'Richiesta non valida']);
        $resp = $this->request('POST', '/api/frontoffice/pendenze', [
            'idTipoPendenza' => self::TIPO,
            'idDominio' => self::DOMINIO,
            'causale' => 'Pagamento servizio di test',
            'importo' => 10.0,
            'soggettoPagatore' => ['tipo' => 'F', 'identificativo' => self::CF, 'anagrafica' => 'Mario Rossi'],
        ], self::bearer());
        self::assertSame(200, $resp->getStatusCode());
        $data = self::json($resp);
        self::assertFalse($data['success']);
        self::assertSame(422, $data['error_status']);
        self::assertSame('422', $resp->getHeaderLine('X-App-Error-Status'));
    }
}
