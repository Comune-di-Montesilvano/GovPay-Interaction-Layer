<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Services;

/**
 * Parsing del CSV di inserimento massivo pendenze (separatore `;`) e normalizzazione
 * degli importi grezzi. Funzioni pure, estratte da MassivePendenzeController.
 */
final class MassivoCsvParser
{
    /**
     * Righe del CSV come restituite da fgetcsv(';', '"', '\\').
     *
     * @return list<array<int, string|null>>
     */
    public static function parse(string $content): array
    {
        $rows = [];
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $content); rewind($fh);
        // Specifica esplicitamente enclosure ed escape per evitare warning di deprecazione
        while (($r = fgetcsv($fh, 0, ';', '"', '\\')) !== false) { $rows[] = $r; }
        fclose($fh);
        return $rows;
    }

    /**
     * Ripulisce un valore importo grezzo da CSV (simboli valuta, spazi/NBSP, migliaia)
     * e normalizza la virgola decimale a punto, restituendo stringa pronta per (float) cast.
     */
    public static function cleanImportoRaw(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') return '';
        $s = str_replace("\xC2\xA0", ' ', $s); // NBSP -> spazio
        $s = str_replace(' ', '', $s);
        $s = preg_replace('/[^\d,.\-]/', '', $s) ?? ''; // via simboli valuta (€, $, ecc.)

        // Separatore decimale = l'ultimo tra ',' e '.'; gli altri sono migliaia (1.234,56 e
        // 1,234.56). Un solo separatore seguito da esattamente 3 cifre (1.234, 1,234) o
        // ripetuto (1.234.567) e' di migliaia: in euro non esistono 3 decimali.
        $last = max((int) strrpos($s, ','), (int) strrpos($s, '.'));
        $hasSep = strpbrk($s, ',.') !== false;
        if ($hasSep) {
            $sep = $s[$last];
            $other = $sep === ',' ? '.' : ',';
            $isThousands = !str_contains($s, $other)
                && (substr_count($s, $sep) > 1 || preg_match('/^-?\d{1,3}[.,]\d{3}$/', $s) === 1);
            if ($isThousands) {
                $s = str_replace($sep, '', $s);
            } else {
                $s = str_replace([',', '.'], '', substr($s, 0, $last)) . '.' . substr($s, $last + 1);
            }
        }
        return $s;
    }
}
