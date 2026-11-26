<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Rateizzazione "a scelta": rata unica (<base>-RU, unica soluzione GovPay: documento senza rata)
 * + N rate (<base>-R<i>) nello stesso documento. Al pagamento di un'opzione il motore
 * rendicontazione annulla l'altra, leggendo il marker in datiAllegati.rateizzazioneScelta.
 * Logica pura, nessun I/O.
 */
final class RateizzazioneSceltaService
{
    public const MARKER_KEY = 'rateizzazioneScelta';
    private const SUFFIX = '-RU';
    private const MAX_ID = 35;

    public static function idRataUnica(string $baseId): string
    {
        $base = (string)preg_replace('/[^A-Za-z0-9\-_]/', '-', $baseId);
        return substr($base, 0, self::MAX_ID - strlen(self::SUFFIX)) . self::SUFFIX;
    }

    /**
     * @param array<array-key,string> $idRate
     * @return array{idRataUnica:string, idRate:list<string>}
     */
    public static function buildMarker(string $idRataUnica, array $idRate): array
    {
        return ['idRataUnica' => $idRataUnica, 'idRate' => array_values($idRate)];
    }

    /**
     * Id delle pendenze da annullare dato il pagamento di $idPagata.
     *
     * @param mixed $datiAllegati datiAllegati della pendenza pagata (GET GovPay)
     * @return list<string>
     */
    public static function controparti(mixed $datiAllegati, string $idPagata): array
    {
        if (!is_array($datiAllegati) || !is_array($datiAllegati[self::MARKER_KEY] ?? null)) {
            return [];
        }
        $m = $datiAllegati[self::MARKER_KEY];
        $ru = $m['idRataUnica'] ?? null;
        $rate = $m['idRate'] ?? null;
        if (!is_string($ru) || $ru === '' || !is_array($rate)) {
            return [];
        }
        $rate = array_values(array_filter($rate, static fn($r): bool => is_string($r) && $r !== ''));

        if ($idPagata === $ru) {
            return $rate;
        }
        return in_array($idPagata, $rate, true) ? [$ru] : [];
    }

    /**
     * I flag della rata unica valgono solo dal POST corrente: original_params puo' portarne
     * di stantii (checkbox tolta dopo un passaggio precedente). Rimuove quelli di $orig e,
     * se la checkbox e' nel POST, rimette i valori del POST.
     *
     * @param array<string,mixed> $orig
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public static function conFlagDalPost(array $orig, array $post): array
    {
        unset($orig['crea_rata_unica'], $orig['rata_unica_scadenza']);
        if (!empty($post['crea_rata_unica'])) {
            $orig['crea_rata_unica'] = '1';
            $orig['rata_unica_scadenza'] = is_scalar($post['rata_unica_scadenza'] ?? null) ? trim((string)$post['rata_unica_scadenza']) : '';
        }
        return $orig;
    }

    /**
     * Ripartisce $totaleCents in proporzione a $basisCents (stesso criterio delle rate):
     * arrotondamento per voce, poi la differenza a +-1 centesimo dalla prima voce in giro.
     * Base a somma zero = divisione uguale. Somma del risultato = $totaleCents.
     *
     * @param list<int> $basisCents
     * @return list<int>
     */
    public static function allocaCentesimi(array $basisCents, int $totaleCents): array
    {
        $n = count($basisCents);
        if ($n === 0) {
            return [];
        }
        $sum = array_sum($basisCents);
        $out = [];
        foreach ($basisCents as $i => $b) {
            $out[$i] = $sum > 0 ? (int)round($b / $sum * $totaleCents) : intdiv($totaleCents, $n);
        }
        $diff = $totaleCents - array_sum($out);
        $step = $diff > 0 ? 1 : -1;
        for ($k = 0; $k < abs($diff); $k++) {
            $out[$k % $n] += $step;
        }
        return array_values($out);
    }

    /** @return 'ANNULLA'|'DOPPIO_PAGAMENTO'|'NESSUNA' */
    public static function azione(string $statoControparte): string
    {
        return match (strtoupper($statoControparte)) {
            'NON_ESEGUITA', 'SCADUTA' => 'ANNULLA',
            'ESEGUITA', 'ESEGUITA_PARZIALE', 'INCASSATA' => 'DOPPIO_PAGAMENTO',
            default => 'NESSUNA',
        };
    }
}
