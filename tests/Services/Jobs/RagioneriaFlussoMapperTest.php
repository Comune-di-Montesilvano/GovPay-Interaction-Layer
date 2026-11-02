<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Services\Jobs\RagioneriaFlussoMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Caratterizzazione delle funzioni pure di mapping flusso GovPay -> righe (ex cron_ragioneria.php). */
final class RagioneriaFlussoMapperTest extends TestCase
{
    private const DOM = '80000000001';
    private const A2A = 'GIL-TEST';

    /**
     * @param array<string,mixed> $rend
     * @param array<string,mixed> $o
     * @return array<string,mixed>
     */
    private static function detail(array $rend, array $o = []): array
    {
        return $o + ['dataFlusso' => '2026-03-01T10:00:00Z', 'dataRegolamento' => '2026-03-02', 'trn' => 'TRN1', 'rendicontazioni' => [$rend]];
    }

    /**
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    private static function one(array $detail, string $a2a = self::A2A): array
    {
        $rows = RagioneriaFlussoMapper::mapFlussoRows($detail, self::DOM, 'FL1', $a2a);
        self::assertCount(1, $rows);
        return $rows[0];
    }

    public function testMapsAllFieldsFromDetail(): void
    {
        $row = self::one([
            'dataFlusso' => '2026-03-01T10:00:00Z',
            'dataRegolamento' => '2026-03-02',
            'trn' => 'TRN1',
            'psp' => ['idPsp' => 'PSP1', 'ragioneSociale' => 'Banca Test'],
            'rendicontazioni' => [[
                'iur' => ' IUR1 ', 'iuv' => 'IUV1', 'importo' => '12.5', 'esito' => 0, 'indice' => 2, 'stato' => 'OK',
                'riscossione' => ['vocePendenza' => [
                    'codEntrata' => 'ENT1', 'descrizione' => 'Desc', 'idPendenza' => 'P1',
                    'pendenza' => ['idA2A' => self::A2A],
                ]],
            ]],
        ]);
        self::assertSame([
            'id_dominio' => self::DOM, 'id_flusso' => 'FL1', 'data_flusso' => '2026-03-01', 'data_regolamento' => '2026-03-02',
            'trn' => 'TRN1', 'id_psp' => 'PSP1', 'ragione_psp' => 'Banca Test', 'anno' => 2026, 'mese' => 3,
            'iur' => 'IUR1', 'iuv' => 'IUV1', 'importo' => 12.5, 'esito' => 0, 'stato_rend' => 'OK', 'indice' => 2,
            'data_pagamento' => '2026-03-02', 'cod_entrata' => 'ENT1', 'descrizione_entrata' => 'Desc',
            'id_pendenza' => 'P1', 'is_govpay' => true, 'is_multibeneficiario' => null,
        ], $row);
    }

    public function testPspFallbackKeysAndDefaults(): void
    {
        $row = self::one(self::detail(['iur' => 'I'], ['idPsp' => 'P2', 'ragionePsp' => 'R2']));
        self::assertSame('P2', $row['id_psp']);
        self::assertSame('R2', $row['ragione_psp']);
        self::assertNull($row['esito']);
        self::assertSame(1, $row['indice']);
        self::assertSame(0.0, $row['importo']);
        self::assertSame('', $row['stato_rend']);
        self::assertSame('', $row['cod_entrata']);
        self::assertSame('', $row['id_pendenza']);
        self::assertFalse($row['is_govpay']);
    }

    public function testStatoRendFallsBackToStatoRendicontazione(): void
    {
        $row = self::one(self::detail(['iur' => 'I', 'statoRendicontazione' => 'X']));
        self::assertSame('X', $row['stato_rend']);
    }

    public function testRendWithEmptyIurOrNonArrayIsSkipped(): void
    {
        $d = self::detail(['iur' => '  '], ['rendicontazioni' => [['iur' => '  '], 'x', ['iur' => 'OK1']]]);
        $rows = RagioneriaFlussoMapper::mapFlussoRows($d, self::DOM, 'FL1');
        self::assertCount(1, $rows);
        self::assertSame('OK1', $rows[0]['iur']);
    }

    public function testNoRendicontazioniKeyGivesNoRows(): void
    {
        self::assertSame([], RagioneriaFlussoMapper::mapFlussoRows(['dataFlusso' => '2026-03-01'], self::DOM, 'FL1'));
    }

    /** @return array<string,array{string,?string}> */
    public static function dates(): array
    {
        return [
            'vuota' => ['', null],
            'solo spazi' => ['   ', null],
            'iso datetime' => ['2026-03-01T10:00:00Z', '2026-03-01'],
            'data piena' => ['2026-03-01', '2026-03-01'],
            'anno-mese' => ['2026-03', '2026-03-01'],
            'lunghezza 8 invalida' => ['2026-03-', null],
            'corta' => ['2026', null],
        ];
    }

    #[DataProvider('dates')]
    public function testNormalizeDate(string $in, ?string $out): void
    {
        self::assertSame($out, RagioneriaFlussoMapper::normalizeDate($in));
    }

    public function testAnnoMeseFromDataRegolamento(): void
    {
        $row = self::one(self::detail(['iur' => 'I'], ['dataRegolamento' => '2027-11-30']));
        self::assertSame([2027, 11], [$row['anno'], $row['mese']]);
    }

    public function testAnnoOutOfRangeFallsBackToToday(): void
    {
        foreach (['2019-12-31', '2101-01-01'] as $d) {
            $row = self::one(self::detail(['iur' => 'I'], ['dataRegolamento' => $d]));
            self::assertSame([(int)date('Y'), (int)date('n')], [$row['anno'], $row['mese']], $d);
            self::assertSame($d, $row['data_pagamento']);
        }
    }

    public function testMissingDataRegolamentoUsesTodayAndNullDataPagamento(): void
    {
        $row = self::one(self::detail(['iur' => 'I'], ['dataRegolamento' => '']));
        self::assertNull($row['data_regolamento']);
        self::assertNull($row['data_pagamento']);
        self::assertSame([(int)date('Y'), (int)date('n')], [$row['anno'], $row['mese']]);
    }

    /** @return array<string,array{array<string,mixed>,string,bool}> */
    public static function isGovPayCases(): array
    {
        $v = static fn(array $voce): array => ['iur' => 'I', 'riscossione' => ['vocePendenza' => $voce]];
        return [
            'a2a uguale' => [$v(['idPendenza' => 'P', 'pendenza' => ['idA2A' => self::A2A]]), self::A2A, true],
            'a2a diverso' => [$v(['idPendenza' => 'P', 'pendenza' => ['idA2A' => 'ALTRA']]), self::A2A, false],
            'a2a assente nel payload' => [$v(['idPendenza' => 'P']), self::A2A, true],
            'a2a configurato vuoto' => [$v(['idPendenza' => 'P', 'pendenza' => ['idA2A' => 'ALTRA']]), '', true],
            'senza idPendenza' => [$v(['pendenza' => ['idA2A' => self::A2A]]), self::A2A, false],
            'nessuna riscossione' => [['iur' => 'I'], self::A2A, false],
        ];
    }

    /** @param array<string,mixed> $rend */
    #[DataProvider('isGovPayCases')]
    public function testIsGovPayClassification(array $rend, string $a2a, bool $expected): void
    {
        $row = self::one(self::detail($rend), $a2a);
        self::assertSame($expected, $row['is_govpay']);
    }

    public function testIdPendenzaCandidateOrder(): void
    {
        $rend = ['pendenza' => ['idPendenza' => 'E']];
        self::assertSame('A', RagioneriaFlussoMapper::extractIdPendenza(
            $rend,
            ['idPendenza' => 'C', 'pendenza' => ['idPendenza' => 'D']],
            ['idPendenza' => 'A', 'pendenza' => ['idPendenza' => 'B']]
        ));
        self::assertSame('B', RagioneriaFlussoMapper::extractIdPendenza($rend, ['idPendenza' => 'C'], ['pendenza' => ['idPendenza' => 'B']]));
        self::assertSame('C', RagioneriaFlussoMapper::extractIdPendenza($rend, ['idPendenza' => 'C', 'pendenza' => ['idPendenza' => 'D']], []));
        self::assertSame('D', RagioneriaFlussoMapper::extractIdPendenza($rend, ['pendenza' => ['idPendenza' => 'D']], []));
        self::assertSame('E', RagioneriaFlussoMapper::extractIdPendenza($rend, [], []));
        self::assertSame('', RagioneriaFlussoMapper::extractIdPendenza([], [], ['idPendenza' => '  ']));
    }

    public function testIdA2ACandidateOrder(): void
    {
        $rend = ['pendenza' => ['idA2A' => 'X']];
        self::assertSame('V', RagioneriaFlussoMapper::extractIdA2A($rend, ['pendenza' => ['idA2A' => 'R']], ['pendenza' => ['idA2A' => 'V']]));
        self::assertSame('R', RagioneriaFlussoMapper::extractIdA2A($rend, ['pendenza' => ['idA2A' => 'R']], []));
        self::assertSame('X', RagioneriaFlussoMapper::extractIdA2A($rend, [], []));
        self::assertSame('', RagioneriaFlussoMapper::extractIdA2A([], [], []));
    }

    public function testCodEntrataFallsBackToTipoPendenza(): void
    {
        $row = self::one(self::detail(['iur' => 'I', 'riscossione' => ['vocePendenza' => ['pendenza' => ['tipoPendenza' => ['idTipoPendenza' => 'TIPO']]]]]));
        self::assertSame('TIPO', $row['cod_entrata']);
    }

    /** @return array<string,array{array<string,mixed>,array<string,mixed>,array<string,mixed>,?bool}> */
    public static function multi(): array
    {
        return [
            'rend bool true' => [[], ['isMultibeneficiario' => true], [], true],
            'rend stringa false' => [[], ['multiBeneficiario' => 'false'], [], false],
            'rend snake' => [[], ['is_multi_beneficiario' => '1'], [], true],
            'rend lower' => [[], ['multibeneficiario' => 'yes'], [], true],
            'risc' => [[], [], ['isMultibeneficiario' => 'no'], false],
            'risc snake' => [[], [], ['is_multi_beneficiario' => 'true'], true],
            'detail' => [['isMultibeneficiario' => true], [], [], true],
            'detail lower' => [['multibeneficiario' => 0], [], [], false],
            'rend ha precedenza' => [['isMultibeneficiario' => true], ['isMultibeneficiario' => false], [], false],
            'valore non booleano => null' => [[], ['isMultibeneficiario' => 'boh'], [], null],
            'stringa vuota ignorata' => [[], ['isMultibeneficiario' => ''], [], null],
            'MPO' => [['rpt' => ['json' => ['datiVersamento' => ['tipoVersamento' => ' mpo ']]]], [], [], true],
            'piu singoli' => [['rpt' => ['json' => ['datiVersamento' => ['datiSingoloVersamento' => [[], []]]]]], [], [], true],
            'un singolo' => [['rpt' => ['json' => ['datiVersamento' => ['datiSingoloVersamento' => [[]]]]]], [], [], false],
            'datiVersamento senza singoli' => [['rpt' => ['json' => ['datiVersamento' => ['tipoVersamento' => 'ST']]]], [], [], null],
            'nessun dato' => [[], [], [], null],
        ];
    }

    /**
     * @param array<string,mixed> $detail
     * @param array<string,mixed> $rend
     * @param array<string,mixed> $risc
     */
    #[DataProvider('multi')]
    public function testDeriveIsMultiBeneficiario(array $detail, array $rend, array $risc, ?bool $expected): void
    {
        self::assertSame($expected, RagioneriaFlussoMapper::deriveIsMultiBeneficiario($detail, $rend, $risc));
    }

    /** @return array<string,array{string,?int}> */
    public static function nextPages(): array
    {
        return [
            'vuoto' => ['', null],
            'senza query' => ['/flussiRendicontazione', null],
            'query senza pagina' => ['/flussiRendicontazione?x=1', null],
            'pagina zero' => ['/flussiRendicontazione?pagina=0', null],
            'pagina non numerica' => ['/flussiRendicontazione?pagina=abc', null],
            'pagina 2' => ['/flussiRendicontazione?risultatiPerPagina=100&pagina=2', 2],
            'url assoluto' => ['https://x.test/b/flussiRendicontazione?pagina=7', 7],
        ];
    }

    #[DataProvider('nextPages')]
    public function testExtractNextPage(string $in, ?int $out): void
    {
        self::assertSame($out, RagioneriaFlussoMapper::extractNextPage($in));
    }
}
