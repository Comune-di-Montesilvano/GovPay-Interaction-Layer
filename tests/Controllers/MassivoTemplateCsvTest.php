<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\MassivePendenzeController;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Template CSV pendenze massive: ogni riga di esempio deve superare la validazione d'import
 * (altrimenti l'operatore parte da un file che viene scartato) e le regole delle voci.
 */
final class MassivoTemplateCsvTest extends TestCase
{
    /** @return list<array<string,string>> righe del template come mappa intestazione => valore */
    private function templateRows(): array
    {
        $ctrl = (new \ReflectionClass(MassivePendenzeController::class))->newInstanceWithoutConstructor();
        $res = $ctrl->templateCsv((new ServerRequestFactory())->createServerRequest('GET', '/'), new Response());
        $lines = array_values(array_filter(preg_split('/\r?\n/', (string)$res->getBody()) ?: []));
        $header = str_getcsv(array_shift($lines), ';', '"', '\\');
        return array_map(static fn($l) => array_combine($header, str_getcsv($l, ';', '"', '\\')), $lines);
    }

    /** @param array<string,string> $row */
    private function validate(array $row): ?string
    {
        $norm = $this->normalize($row);
        $m = new \ReflectionMethod(MassivePendenzeController::class, 'validateRow');
        return $m->invoke(null, $norm);
    }

    /** @param array<string,string> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        $m = new \ReflectionMethod(MassivePendenzeController::class, 'normalizeRow');
        return $m->invoke(null, $row);
    }

    public function testTemplateHasFourExamplesAllValid(): void
    {
        $rows = $this->templateRows();
        $this->assertCount(4, $rows);
        $this->assertArrayHasKey('NUMERO_RATE', $rows[0]);
        $this->assertArrayNotHasKey('RATA', $rows[0]);
        foreach ($rows as $i => $row) {
            $this->assertNull($this->validate($row), 'riga di esempio ' . ($i + 1) . ' non valida');
        }
        $this->assertSame(['', '', '3', '3'], array_column($rows, 'NUMERO_RATE'));
        $this->assertNotSame('', $rows[1]['VOCE_2_IMPORTO']);
        $this->assertNotSame('', $rows[3]['RATA_3_DATA_SCADENZA']);
    }

    /** @return array<string,string> */
    private function base(): array
    {
        $row = $this->templateRows()[0];
        foreach (['VOCE_1_IMPORTO', 'VOCE_1_CAUSALE', 'VOCE_2_IMPORTO', 'VOCE_2_CAUSALE'] as $k) {
            $row[$k] = '';
        }
        $row['IMPORTO'] = '50.00';
        return $row;
    }

    public function testOnlyVoce2GivesRemainderToVoce1(): void
    {
        $row = ['VOCE_2_IMPORTO' => '10,00', 'VOCE_2_CAUSALE' => 'Diritti'] + $this->base();
        $this->assertNull($this->validate($row));
        $voci = $this->normalize($row)['voci'];
        $this->assertSame(40.0, $voci[0]['importo']);
        $this->assertSame(10.0, $voci[1]['importo']);
    }

    public function testVoce2RequiresCausale(): void
    {
        $row = ['VOCE_2_IMPORTO' => '10.00'] + $this->base();
        $this->assertStringContainsString('VOCE_2_CAUSALE', (string)$this->validate($row));
    }

    public function testVoce2NotBelowImporto(): void
    {
        $row = ['VOCE_2_IMPORTO' => '50.00', 'VOCE_2_CAUSALE' => 'Diritti'] + $this->base();
        $this->assertStringContainsString('VOCE_2_IMPORTO', (string)$this->validate($row));
    }

    public function testExplicitVociMustSumToImporto(): void
    {
        $row = ['VOCE_1_IMPORTO' => '30.00', 'VOCE_2_IMPORTO' => '10.00', 'VOCE_2_CAUSALE' => 'Diritti'] + $this->base();
        $this->assertSame('Somma voci diversa da importo', $this->validate($row));
    }
}
