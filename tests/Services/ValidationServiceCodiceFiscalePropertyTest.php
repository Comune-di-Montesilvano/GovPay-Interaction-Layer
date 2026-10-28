<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\ValidationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test property-based (generatore deterministico, seed fisso) sul controllo del codice
 * fiscale: CF strutturalmente validi con carattere di controllo calcolato qui in modo
 * indipendente (tabelle standard dispari/pari) devono risultare validi; alterando il solo
 * carattere di controllo la verifica deve fallire; maiuscole/minuscole e spazi attorno non
 * devono cambiare l'esito.
 */
final class ValidationServiceCodiceFiscalePropertyTest extends TestCase
{
    private const SEED = 20261027;
    private const CASES = 200;

    private const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const DIGITS = '0123456789';
    private const MONTHS = 'ABCDEHLMPRST';

    /** Valori per caratteri in posizione dispari (1-based), tabella standard Agenzia Entrate. */
    private const ODD = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    /** @return array<string, array{int, string}> */
    public static function codiciFiscaliProvider(): array
    {
        mt_srand(self::SEED);
        $cases = [];
        for ($i = 0; $i < self::CASES; $i++) {
            $cases['caso ' . $i] = [$i, self::generaCf()];
        }
        return $cases;
    }

    #[DataProvider('codiciFiscaliProvider')]
    public function testCfGeneratoValidoEControlloAlterato(int $i, string $cf): void
    {
        $msg = sprintf('seed=%d caso=%d cf=%s', self::SEED, $i, $cf);

        $ok = ValidationService::validateCodiceFiscale($cf);
        $this->assertTrue($ok['valid'], $msg);
        $this->assertTrue($ok['check_ok'], $msg);

        // Carattere di controllo diverso da quello calcolato → check_ok false.
        $altro = self::LETTERS[(strpos(self::LETTERS, $cf[15]) + 1 + ($i % 25)) % 26];
        $alterato = substr($cf, 0, 15) . $altro;
        $ko = ValidationService::validateCodiceFiscale($alterato);
        $this->assertTrue($ko['format_ok'], $msg . ' alterato=' . $alterato);
        $this->assertFalse($ko['check_ok'], $msg . ' alterato=' . $alterato);
        $this->assertFalse($ko['valid'], $msg . ' alterato=' . $alterato);
    }

    #[DataProvider('codiciFiscaliProvider')]
    public function testMinuscoleESpaziNonCambianoEsito(int $i, string $cf): void
    {
        $spazi = ["", " ", "  ", "\t", "\n", " \t "];
        $variante = $spazi[$i % count($spazi)] . strtolower($cf) . $spazi[($i * 7) % count($spazi)];
        $alterato = substr($cf, 0, 15) . ($cf[15] === 'A' ? 'B' : 'A');
        $varianteAlterata = ' ' . strtolower($alterato) . ' ';
        $msg = sprintf('seed=%d caso=%d cf=%s variante=%s', self::SEED, $i, $cf, json_encode($variante));

        $this->assertSame(
            ValidationService::validateCodiceFiscale($cf),
            ValidationService::validateCodiceFiscale($variante),
            $msg
        );
        $this->assertSame(
            ValidationService::validateCodiceFiscale($alterato),
            ValidationService::validateCodiceFiscale($varianteAlterata),
            $msg
        );
    }

    /** 6 lettere, 2 cifre, lettera mese, 2 cifre (giorno, +40 per F), lettera + 3 cifre (comune), controllo. */
    private static function generaCf(): string
    {
        $cf = '';
        for ($k = 0; $k < 6; $k++) {
            $cf .= self::LETTERS[mt_rand(0, 25)];
        }
        $cf .= self::DIGITS[mt_rand(0, 9)] . self::DIGITS[mt_rand(0, 9)];
        $cf .= self::MONTHS[mt_rand(0, 11)];
        $giorno = mt_rand(1, 31) + (mt_rand(0, 1) === 1 ? 40 : 0);
        $cf .= sprintf('%02d', $giorno);
        $cf .= self::LETTERS[mt_rand(0, 25)];
        $cf .= sprintf('%03d', mt_rand(0, 999));
        return $cf . self::carattereControllo($cf);
    }

    private static function carattereControllo(string $primi15): string
    {
        $somma = 0;
        for ($k = 0; $k < 15; $k++) {
            $ch = $primi15[$k];
            if ($k % 2 === 0) {
                $somma += self::ODD[$ch];
            } else {
                // Posizioni pari: cifre valgono il proprio valore, lettere A=0..Z=25.
                $somma += ctype_digit($ch) ? (int)$ch : ord($ch) - ord('A');
            }
        }
        return self::LETTERS[$somma % 26];
    }
}
