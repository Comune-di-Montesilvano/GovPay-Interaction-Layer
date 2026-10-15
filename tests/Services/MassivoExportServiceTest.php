<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\MassivoExportService;
use App\Services\MassivoRateizzazioneService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MassivoExportServiceTest extends TestCase
{
    private function unica(): array
    {
        return [
            'causale' => 'Tassa iscrizione',
            'importo' => 44.0,
            'annoRiferimento' => 2026,
            'dataValidita' => '2026-10-20',
            'dataScadenza' => '2026-12-31',
            'soggettoPagatore' => ['tipo' => 'F', 'identificativo' => 'RSSMRA80A01F205X', 'anagrafica' => 'MARIO ROSSI', 'email' => 'm@r.it'],
            'voci' => [['idVocePendenza' => '1', 'descrizione' => 'Quota', 'importo' => 44.0]],
            'datiAllegati' => ['sorgente' => 'GIL-Massivo'],
        ];
    }

    /** @return list<array<string,mixed>> righe DB simulate: riga 2 unica, riga 3 rateizzata in 2 */
    private function dbRows(): array
    {
        $rateBase = $this->unica();
        $rateBase['causale'] = 'TARI 2026';
        $rateBase['importo'] = 100.0;
        $rateBase['soggettoPagatore']['identificativo'] = 'VRDLGU70B02H501Y';
        $rateBase['voci'] = [['idVocePendenza' => '1', 'descrizione' => 'TARI', 'importo' => 100.0]];
        $rate = MassivoRateizzazioneService::expand($rateBase, 2, new DateTimeImmutable('2026-12-31'), 'RATA-doc1', 'GIL-r');

        return [
            ['riga' => 2, 'stato' => 'SUCCESS', 'payload_json' => json_encode($this->unica()), 'response_json' => json_encode(['numeroAvviso' => '300000000000000001'])],
            ['riga' => 3, 'stato' => 'SUCCESS', 'payload_json' => json_encode($rate[1]), 'response_json' => json_encode(['numeroAvviso' => '300000000000000003'])],
            ['riga' => 3, 'stato' => 'SUCCESS', 'payload_json' => json_encode($rate[0]), 'response_json' => json_encode(['numeroAvviso' => '300000000000000002'])],
            // scartata in validazione: payload "normalizzato", nessun soggettoPagatore
            ['riga' => 4, 'stato' => 'ERROR', 'payload_json' => json_encode(['identificativo' => 'X']), 'response_json' => null],
            // creazione fallita su GovPay
            ['riga' => 5, 'stato' => 'ERROR', 'payload_json' => json_encode($this->unica()), 'response_json' => null],
        ];
    }

    public function testTracciatoUnaRigaPerInputConColonneRate(): void
    {
        [$header, $rows] = MassivoExportService::buildTracciato($this->dbRows());

        $this->assertSame(array_merge(MassivoExportService::BASE_HEADERS, [
            'RATA_1_IMPORTO', 'RATA_1_SCADENZA', 'RATA_1_NUMERO_AVVISO',
            'RATA_2_IMPORTO', 'RATA_2_SCADENZA', 'RATA_2_NUMERO_AVVISO',
        ]), $header);
        $this->assertNotContains('NOME', $header);
        $this->assertCount(2, $rows);

        $h = array_flip($header);
        [$unica, $rateizzata] = $rows;
        $this->assertSame('MARIO ROSSI', $unica[$h['ANAGRAFICA']]);
        $this->assertSame('44,00', $unica[$h['IMPORTO']]);
        $this->assertSame('31/12/2026', $unica[$h['DATA_SCADENZA']]);
        $this->assertSame('', $unica[$h['RATA']]);
        $this->assertSame('300000000000000001', $unica[$h['NUMERO_AVVISO']]);
        $this->assertSame('', $unica[$h['RATA_1_NUMERO_AVVISO']]);

        $this->assertSame('TARI 2026', $rateizzata[$h['CAUSALE']]);
        $this->assertSame('100,00', $rateizzata[$h['IMPORTO']]);
        $this->assertSame('2', $rateizzata[$h['RATA']]);
        $this->assertSame('100,00', $rateizzata[$h['VOCE_1_IMPORTO']]);
        $this->assertSame('', $rateizzata[$h['NUMERO_AVVISO']]);
        $this->assertSame('50,00', $rateizzata[$h['RATA_1_IMPORTO']]);
        $this->assertSame('31/12/2026', $rateizzata[$h['RATA_1_SCADENZA']]);
        $this->assertSame('300000000000000002', $rateizzata[$h['RATA_1_NUMERO_AVVISO']]);
        $this->assertSame('31/01/2027', $rateizzata[$h['RATA_2_SCADENZA']]);
        $this->assertSame('300000000000000003', $rateizzata[$h['RATA_2_NUMERO_AVVISO']]);
    }

    public function testPdfPlanAvvisoSingoloEDocumentoMultirata(): void
    {
        $plan = MassivoExportService::buildPdfPlan($this->dbRows());
        $this->assertSame([
            ['tipo' => 'avviso', 'codice' => '300000000000000001', 'filename' => 'TASSA_ISCRIZIONE_RSSMRA80A01F205X.pdf', 'riga' => 2],
            ['tipo' => 'documento', 'codice' => 'RATA-doc1', 'filename' => 'TARI_2026_VRDLGU70B02H501Y.pdf', 'riga' => 3],
        ], $plan);
    }

    public function testPdfFileNameSanificaETroncaEDeduplica(): void
    {
        $used = [];
        $this->assertSame('CANONE_D_USO_LOC_3_RSSMRA80A01F205X.pdf', MassivoExportService::pdfFileName("Canone d'uso / loc. 3", 'rssmra80a01f205x', $used));
        $this->assertSame('CANONE_D_USO_LOC_3_RSSMRA80A01F205X_2.pdf', MassivoExportService::pdfFileName("Canone d'uso / loc. 3", 'RSSMRA80A01F205X', $used));
        $long = MassivoExportService::pdfFileName(str_repeat('A', 200), 'CF', $used);
        $this->assertSame(str_repeat('A', 80) . '_CF.pdf', $long);
    }
}
