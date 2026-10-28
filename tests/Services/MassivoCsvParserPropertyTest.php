<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\MassivoCsvParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test property-based (generatore deterministico, seed fisso) sul parsing del CSV di
 * inserimento massivo: round-trip fputcsv → parse e normalizzazione degli importi.
 */
final class MassivoCsvParserPropertyTest extends TestCase
{
    private const SEED = 424242;
    private const CASES = 200;

    /** Niente backslash: con escape '\\' fputcsv/fgetcsv non fanno round-trip (comportamento PHP noto). */
    private const ALFABETO_CELLE = [
        'a', 'B', '7', ' ', ';', '"', "\n", 'à', 'é', 'ò', 'ù', '€', '-', ',', '.', '\'', 'Ç', 'ñ',
    ];

    /** @return array<string, array{int, list<list<string>>}> */
    public static function csvProvider(): array
    {
        mt_srand(self::SEED);
        $cases = [];
        for ($i = 0; $i < self::CASES; $i++) {
            $rows = [];
            $nRows = mt_rand(1, 5);
            for ($r = 0; $r < $nRows; $r++) {
                $row = [];
                $nCols = mt_rand(1, 6);
                for ($c = 0; $c < $nCols; $c++) {
                    $row[] = self::cellaCasuale();
                }
                // Una riga composta da una sola cella vuota viene scritta come riga vuota,
                // che fgetcsv restituisce come [null]: fuori dal dominio del round-trip.
                if ($row === ['']) {
                    $row = ['x'];
                }
                $rows[] = $row;
            }
            $cases['caso ' . $i] = [$i, $rows];
        }
        return $cases;
    }

    /** @param list<list<string>> $rows */
    #[DataProvider('csvProvider')]
    public function testRoundTripFputcsvParse(int $i, array $rows): void
    {
        $fh = fopen('php://temp', 'w+');
        $this->assertNotFalse($fh);
        foreach ($rows as $row) {
            fputcsv($fh, $row, ';', '"', '\\');
        }
        rewind($fh);
        $content = (string)stream_get_contents($fh);
        fclose($fh);

        $this->assertSame(
            $rows,
            MassivoCsvParser::parse($content),
            sprintf('seed=%d caso=%d csv=%s', self::SEED, $i, json_encode($content, JSON_UNESCAPED_UNICODE))
        );
    }

    /** @return array<string, array{int, int}> */
    public static function importiProvider(): array
    {
        mt_srand(self::SEED + 1);
        $cases = [];
        for ($i = 0; $i < self::CASES; $i++) {
            // Mix di importi piccoli (< 1000 euro) e grandi (con separatore delle migliaia).
            $cents = $i % 2 === 0 ? mt_rand(0, 99999) : mt_rand(100000, 9999999999);
            $cases['caso ' . $i] = [$i, $cents];
        }
        return $cases;
    }

    #[DataProvider('importiProvider')]
    public function testCleanImportoRawFormatiEquivalenti(int $i, int $cents): void
    {
        $euro = intdiv($cents, 100);
        $dec = sprintf('%02d', $cents % 100);
        $canonico = $euro . '.' . $dec;
        $msg = sprintf('seed=%d caso=%d cents=%d', self::SEED + 1, $i, $cents);

        $this->assertSame($canonico, MassivoCsvParser::cleanImportoRaw($euro . ',' . $dec), $msg . ' (1234,56)');
        $this->assertSame($canonico, MassivoCsvParser::cleanImportoRaw($euro . '.' . $dec), $msg . ' (1234.56)');
        $this->assertSame($canonico, MassivoCsvParser::cleanImportoRaw(' € ' . $euro . ',' . $dec . ' '), $msg . ' (€ e spazi)');
        $this->assertSame($canonico, MassivoCsvParser::cleanImportoRaw("\xC2\xA0" . $euro . ',' . $dec . "\xC2\xA0€"), $msg . ' (NBSP)');
        $this->assertSame('-' . $canonico, MassivoCsvParser::cleanImportoRaw('-' . $euro . ',' . $dec), $msg . ' (negativo)');

        // Formato italiano con punto delle migliaia (1.234,56): il comportamento ATTUALE
        // trasforma ogni separatore in punto ("1.234.56", che (float) legge come 1.234).
        // Difetto noto, fissato qui come caratterizzazione: correggerlo in un task dedicato
        // aggiornando questa asserzione (regressione voluta).
        $migliaia = number_format($euro, 0, '', '.') . ',' . $dec;
        $atteso = $euro < 1000 ? $canonico : number_format($euro, 0, '', '.') . '.' . $dec;
        $this->assertSame($atteso, MassivoCsvParser::cleanImportoRaw($migliaia), $msg . ' (1.234,56)');
    }

    public function testCleanImportoRawStringaVuotaENonNumerica(): void
    {
        $this->assertSame('', MassivoCsvParser::cleanImportoRaw(''));
        $this->assertSame('', MassivoCsvParser::cleanImportoRaw('   '));
        $this->assertSame('', MassivoCsvParser::cleanImportoRaw('abc'));
    }

    private static function cellaCasuale(): string
    {
        $cella = '';
        $len = mt_rand(0, 10);
        for ($k = 0; $k < $len; $k++) {
            $cella .= self::ALFABETO_CELLE[mt_rand(0, count(self::ALFABETO_CELLE) - 1)];
        }
        return $cella;
    }
}
