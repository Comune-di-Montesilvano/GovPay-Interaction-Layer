<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Config\SettingsRepository;
use App\Services\TracciatoService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\Support\DbTestCase;

/**
 * @group db
 */
final class TracciatoServiceTest extends DbTestCase
{
    protected static array $tables = ['settings', 'entrate_tipologie'];

    private const DOMINIO = '01234567890';

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::set('govpay', 'backoffice_url', 'https://govpay.invalid/backoffice/rs/basic/v1');
        SettingsRepository::set('entity', 'id_a2a', 'A2A-TEST');
        SettingsRepository::set('entity', 'id_dominio', self::DOMINIO);
        SettingsRepository::flush();
    }

    /** 4xx di GovPay sull'invio: errore leggibile dal ProblemJson (`dettaglio`), risposta parsata, nessuna eccezione. */
    public function testClientExceptionRestituisceIlDettaglioDelProblemJson(): void
    {
        $problem = ['categoria' => 'RICHIESTA', 'codice' => 'SINTASSI', 'descrizione' => 'Richiesta non valida', 'dettaglio' => 'Campo causale troppo lungo'];
        $api = new class ($problem) {
            public function __construct(private array $problem) {}
            public function addTracciatoPendenze(mixed $body, bool $stampa): never
            {
                throw new ClientException(
                    'Client error: 400',
                    new Request('POST', 'https://govpay.invalid/pendenze/tracciati'),
                    new Response(400, ['Content-Type' => 'application/problem+json'], json_encode($this->problem))
                );
            }
        };

        $res = (new TracciatoService($api))->sendTracciato($this->merged(), [
            ['indice' => 1, 'importo' => '10.00', 'dataValidita' => '2026-12-31', 'dataScadenza' => '2026-12-31'],
        ]);

        self::assertFalse($res['success'], json_encode($res['errors']));
        self::assertSame(['Campo causale troppo lungo'], $res['errors']);
        self::assertSame($problem, $res['response']);
        self::assertStringStartsWith('TR-', (string)$res['idTracciato']);
    }

    /** @return array<string,mixed> */
    private function merged(): array
    {
        return [
            'idDominio'       => self::DOMINIO,
            'idTipoPendenza'  => 'TIPO_SINTETICO',
            'causale'         => 'Pendenza di test',
            'annoRiferimento' => 2026,
            'soggettoPagatore' => [
                'tipo' => 'F',
                'identificativo' => 'RSSMRA80A01H501U',
                'anagrafica' => 'Mario Rossi',
            ],
            'voci' => [[
                'idVocePendenza'    => 'V1',
                'importo'           => '10.00',
                'descrizione'       => 'Voce di test',
                'ibanAccredito'     => 'IT60X0542811101000000123456',
                'tipoContabilita'   => 'ALTRO',
                'codiceContabilita' => 'TEST',
            ]],
        ];
    }
}
