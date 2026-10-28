<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Dati sintetici deterministici per i test (nessun dato reale/PII).
 */
final class Synthetic
{
    private const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const MONTHS = 'ABCDEHLMPRST';

    /** Valori per caratteri in posizione dispari (1-based), tabella standard Agenzia Entrate. */
    private const ODD = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    private const COGNOMI = ['Rossi', 'Bianchi', 'Verdi', 'Neri', 'Ferrari', 'Esposito', 'Romano', 'Colombo', 'Ricci', 'Marino'];
    private const NOMI = ['Mario', 'Luca', 'Giulia', 'Anna', 'Marco', 'Sara', 'Paolo', 'Elena', 'Franco', 'Chiara'];

    private function __construct()
    {
    }

    /** Codice fiscale strutturalmente valido (carattere di controllo corretto), deterministico per seed. */
    public static function cf(int $seed): string
    {
        $s = abs($seed);
        $cf = '';
        for ($k = 0; $k < 6; $k++) {
            $cf .= self::LETTERS[($s * 7 + $k * 11 + intdiv($s, 26 + $k)) % 26];
        }
        $cf .= sprintf('%02d', ($s * 3) % 100);
        $cf .= self::MONTHS[$s % 12];
        $cf .= sprintf('%02d', 1 + ($s % 28));
        $cf .= self::LETTERS[($s * 5 + 3) % 26];
        $cf .= sprintf('%03d', ($s * 13) % 1000);

        $somma = 0;
        for ($k = 0; $k < 15; $k++) {
            $ch = $cf[$k];
            $somma += $k % 2 === 0
                ? self::ODD[$ch]
                : (ctype_digit($ch) ? (int)$ch : ord($ch) - ord('A'));
        }
        return $cf . self::LETTERS[$somma % 26];
    }

    /** IUV di $len caratteri: $prefix + numero $n con zero-padding a sinistra. */
    public static function iuv(string $prefix, int $n, int $len = 17): string
    {
        $pad = $len - strlen($prefix);
        if ($pad < 1) {
            throw new \InvalidArgumentException('Prefisso troppo lungo per la lunghezza IUV richiesta');
        }
        $num = (string)$n;
        if (strlen($num) > $pad) {
            throw new \InvalidArgumentException('Numero troppo grande per la lunghezza IUV richiesta');
        }
        return $prefix . str_pad($num, $pad, '0', STR_PAD_LEFT);
    }

    /** IUR sintetico (stile riferimento flusso di rendicontazione), univoco per $n. */
    public static function iur(int $n): string
    {
        return sprintf('IUR%014d', $n);
    }

    /** "Cognome Nome" da liste fisse, deterministico per seed. */
    public static function nome(int $seed): string
    {
        $s = abs($seed);
        return self::COGNOMI[$s % count(self::COGNOMI)] . ' ' . self::NOMI[intdiv($s, count(self::COGNOMI)) % count(self::NOMI)];
    }
}
