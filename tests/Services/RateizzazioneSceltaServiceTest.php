<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\RateizzazioneSceltaService as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateizzazioneSceltaServiceTest extends TestCase
{
    private const BASE = 'GIL-0000000000000001';

    /** @return array<string,mixed> */
    private static function dati(): array
    {
        return ['operatore' => 'x', 'rateizzazioneScelta' => S::buildMarker(
            self::BASE . '-RU',
            [self::BASE . '-R1', self::BASE . '-R2', self::BASE . '-R3']
        )];
    }

    public function testIdRataUnica(): void
    {
        $this->assertSame(self::BASE . '-RU', S::idRataUnica(self::BASE));
        $id = S::idRataUnica(str_repeat('A', 40));
        $this->assertSame(35, strlen($id));
        $this->assertStringEndsWith('-RU', $id);
        $this->assertSame('GIL-a-b-RU', S::idRataUnica('GIL-a b'));
    }

    public function testBuildMarker(): void
    {
        $this->assertSame(
            ['idRataUnica' => 'X-RU', 'idRate' => ['X-R1', 'X-R2']],
            S::buildMarker('X-RU', ['a' => 'X-R1', 'b' => 'X-R2'])
        );
    }

    public function testContropartiDaRataUnica(): void
    {
        $this->assertSame(
            [self::BASE . '-R1', self::BASE . '-R2', self::BASE . '-R3'],
            S::controparti(self::dati(), self::BASE . '-RU')
        );
    }

    public function testContropartiDaRata(): void
    {
        $this->assertSame([self::BASE . '-RU'], S::controparti(self::dati(), self::BASE . '-R2'));
    }

    /** @return array<string,array{mixed,string}> */
    public static function malformati(): array
    {
        return [
            'null' => [null, self::BASE . '-RU'],
            'vuoto' => [[], self::BASE . '-RU'],
            'marker stringa' => [['rateizzazioneScelta' => 'x'], self::BASE . '-RU'],
            'idRate stringa' => [['rateizzazioneScelta' => ['idRataUnica' => self::BASE . '-RU', 'idRate' => 'x']], self::BASE . '-RU'],
            'idRataUnica mancante' => [['rateizzazioneScelta' => ['idRate' => [self::BASE . '-R1']]], self::BASE . '-R1'],
            'id estraneo' => [self::dati(), 'ALTRO-R1'],
        ];
    }

    #[DataProvider('malformati')]
    public function testContropartiMarkerAssenteOMalformato(mixed $dati, string $idPagata): void
    {
        $this->assertSame([], S::controparti($dati, $idPagata));
    }

    public function testConFlagDalPostIgnoraFlagStantii(): void
    {
        $orig = ['causale' => 'x', 'crea_rata_unica' => '1', 'rata_unica_scadenza' => '2026-12-31'];
        // checkbox tolta nel POST corrente: i flag stantii di original_params spariscono
        $this->assertSame(['causale' => 'x'], S::conFlagDalPost($orig, ['causale' => 'y']));
        // checkbox presente nel POST: valgono i valori del POST
        $this->assertSame(
            ['causale' => 'x', 'crea_rata_unica' => '1', 'rata_unica_scadenza' => ''],
            S::conFlagDalPost(['causale' => 'x'], ['crea_rata_unica' => '1'])
        );
    }

    public function testAzione(): void
    {
        $this->assertSame('ANNULLA', S::azione('NON_ESEGUITA'));
        $this->assertSame('ANNULLA', S::azione('SCADUTA'));
        $this->assertSame('DOPPIO_PAGAMENTO', S::azione('ESEGUITA'));
        $this->assertSame('DOPPIO_PAGAMENTO', S::azione('ESEGUITA_PARZIALE'));
        $this->assertSame('DOPPIO_PAGAMENTO', S::azione('INCASSATA'));
        $this->assertSame('NESSUNA', S::azione('ANNULLATA'));
        $this->assertSame('NESSUNA', S::azione(''));
        $this->assertSame('NESSUNA', S::azione('ANOMALA'));
    }
}
