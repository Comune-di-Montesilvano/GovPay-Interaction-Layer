<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\MassivoRateizzazioneService;
use App\Services\RateizzazioneService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class MassivoRateizzazioneServiceTest extends TestCase
{
    private function payload(): array
    {
        return [
            'idTipoPendenza' => 'TARI',
            'causale' => 'TARI 2026',
            'importo' => 100.0,
            'annoRiferimento' => 2026,
            'dataScadenza' => '2026-11-30',
            'soggettoPagatore' => ['tipo' => 'F', 'identificativo' => 'RSSMRA80A01F205X', 'anagrafica' => 'MARIO ROSSI'],
            'voci' => [
                ['idVocePendenza' => '1', 'descrizione' => 'Quota A', 'importo' => 70.0],
                ['idVocePendenza' => '2', 'descrizione' => 'Quota B', 'importo' => 30.0],
            ],
            'datiAllegati' => ['sorgente' => 'GIL-Massivo', 'batchId' => 'B1'],
        ];
    }

    public function testExpandCreaRateCollegateDaDocumento(): void
    {
        $rate = MassivoRateizzazioneService::expand($this->payload(), 3, new DateTimeImmutable('2026-11-30'), 'RATA-abc', 'GIL-xyz');

        $this->assertCount(3, $rate);
        $this->assertSame([33.34, 33.33, 33.33], array_map(fn($r) => $r['importo'], $rate));
        $this->assertSame(['2026-11-30', '2026-12-31', '2027-01-31'], array_column($rate, 'dataScadenza'));
        $this->assertSame(array_column($rate, 'dataScadenza'), array_column($rate, 'dataValidita'));
        foreach ($rate as $k => $r) {
            $i = $k + 1;
            $this->assertSame("GIL-xyz-R{$i}", $r['idPendenza']);
            $this->assertSame("TARI 2026 (Rata {$i} di 3)", $r['causale']);
            $this->assertSame(['identificativo' => 'RATA-abc', 'descrizione' => 'TARI 2026 (Rate 3)', 'rata' => $i], $r['documento']);
            $sommaVoci = array_sum(array_map(fn($v) => (int)round($v['importo'] * 100), $r['voci']));
            $this->assertSame((int)round($r['importo'] * 100), $sommaVoci);
            $this->assertSame('TARI 2026', $r['datiAllegati']['rateizzazione']['causale']);
            $this->assertSame(100.0, $r['datiAllegati']['rateizzazione']['importo']);
            $this->assertSame('2026-11-30', $r['datiAllegati']['rateizzazione']['dataScadenza']);
            $this->assertSame('GIL-Massivo', $r['datiAllegati']['sorgente']);
        }
    }

    public function testExpandConDateEsplicitePerRata(): void
    {
        $rate = MassivoRateizzazioneService::expand($this->payload(), 3, new DateTimeImmutable('2026-11-30'), 'RATA-abc', 'GIL-xyz', [
            1 => ['dataValidita' => '2026-11-01', 'dataScadenza' => '2026-11-15'],
            2 => ['dataScadenza' => '2027-02-28'],
            3 => ['dataValidita' => '2027-05-01', 'dataScadenza' => '2027-06-30'],
        ]);
        $this->assertSame(['2026-11-15', '2027-02-28', '2027-06-30'], array_column($rate, 'dataScadenza'));
        // validità non indicata = scadenza della rata
        $this->assertSame(['2026-11-01', '2027-02-28', '2027-05-01'], array_column($rate, 'dataValidita'));
        $this->assertSame([33.34, 33.33, 33.33], array_column($rate, 'importo'));
    }

    public function testScheduleSoloValiditaMantieneScadenzeMensili(): void
    {
        $date = MassivoRateizzazioneService::schedule(2, new DateTimeImmutable('2026-11-30'), [2 => ['dataValidita' => '2026-12-01']]);
        $this->assertSame([
            ['dataValidita' => '2026-11-30', 'dataScadenza' => '2026-11-30'],
            ['dataValidita' => '2026-12-01', 'dataScadenza' => '2026-12-31'],
        ], $date);
    }

    public function testExpandSoloValiditaSenzaScadenza(): void
    {
        $payload = $this->payload();
        unset($payload['dataScadenza']);
        $rate = MassivoRateizzazioneService::expand($payload, 2, null, 'RATA-abc', 'GIL-xyz', [
            1 => ['dataValidita' => '2026-11-30'],
            2 => ['dataValidita' => '2026-12-31'],
        ]);
        $this->assertSame(['2026-11-30', '2026-12-31'], array_column($rate, 'dataValidita'));
        foreach ($rate as $r) {
            $this->assertArrayNotHasKey('dataScadenza', $r);
        }
    }

    public function testScheduleSenzaFirstDueLasciaDateVuote(): void
    {
        $date = MassivoRateizzazioneService::schedule(2, null, [1 => ['dataValidita' => '2026-11-30']]);
        $this->assertSame([
            ['dataValidita' => '2026-11-30', 'dataScadenza' => ''],
            ['dataValidita' => '', 'dataScadenza' => ''],
        ], $date);
    }

    public function testAllocateVociProporzionaleConRestoSullUltima(): void
    {
        $voci = [['importo' => 70.0, 'descrizione' => 'A'], ['importo' => 30.0, 'descrizione' => 'B']];
        $out = MassivoRateizzazioneService::allocateVoci($voci, 33.33, ' (Rata 2 di 3)');
        $this->assertSame(23.33, $out[0]['importo']);
        $this->assertSame(10.0, $out[1]['importo']);
        $this->assertSame('A (Rata 2 di 3)', $out[0]['descrizione']);
    }

    public function testWithSuffixRestaEntro140Caratteri(): void
    {
        $lunga = str_repeat('X', 140);
        $res = MassivoRateizzazioneService::withSuffix($lunga, ' (Rata 10 di 12)');
        $this->assertSame(140, mb_strlen($res));
        $this->assertStringEndsWith(' (Rata 10 di 12)', $res);
    }

    public function testBuildPartsFromFirstDueDateNonAggiungeMese(): void
    {
        $parts = RateizzazioneService::buildPartsFromFirstDueDate(10.0, 2, new DateTimeImmutable('2026-03-15'));
        $this->assertSame('2026-03-15', $parts[0]['dataScadenza']);
        $this->assertSame('2026-04-15', $parts[1]['dataScadenza']);
    }
}
