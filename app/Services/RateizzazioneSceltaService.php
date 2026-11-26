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
