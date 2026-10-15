<?php
declare(strict_types=1);
namespace App\Services;

use DateTimeImmutable;
use DateTimeInterface;

class RateizzazioneService
{
    /**
     * Split an amount into $n parts: first part carries the rounding remainder so that
     * sum(parts) == total. Algorithm: base = floor((total / n) * 100) / 100;
     * first = round(total - base*(n-1), 2);
     * Returns array of float values.
     *
     * @param float $total
     * @param int $n
     * @return float[]
     */
    public static function splitAmount(float $total, int $n): array
    {
        if ($n <= 1) {
            return [round($total, 2)];
        }
        $base = floor(($total / $n) * 100) / 100.0;
        $sumOthers = $base * ($n - 1);
        $first = round($total - $sumOthers, 2);
        $parts = [$first];
        for ($i = 1; $i < $n; $i++) {
            $parts[] = $base;
        }
        return $parts;
    }

    /**
     * Build parts with default dates. Default: first validity = today +1 month, then +1 month each.
     * Returns array of arrays with keys: indice, importo, dataValidita, dataScadenza
     *
     * @param float $total
     * @param int $n
     * @param DateTimeInterface|null $startDate
     * @return array<int,array{indice:int,importo:float,dataValidita:string,dataScadenza:string}>
     */
    public static function buildPartsWithDates(float $total, int $n, ?DateTimeInterface $startDate = null): array
    {
        $parts = self::splitAmount($total, $n);
        $start = $startDate ? DateTimeImmutable::createFromMutable((new \DateTime())->setTimestamp($startDate->getTimestamp())) : new DateTimeImmutable();
        // Prima rata: +1 mese rispetto a oggi (comportamento precedente)
        $first = $start->modify('+1 month');
        $anchorMode = self::detectAnchorMode((int)$first->format('j'), (int)$first->format('n'), (int)$first->format('Y'));
        $result = [];
        for ($i = 0; $i < count($parts); $i++) {
            $target = self::addMonthsWithAnchor($first, $i, $anchorMode);
            $formatted = $target->format('Y-m-d');
            $result[] = [
                'indice' => $i + 1,
                'importo' => (float)round($parts[$i], 2),
                'dataValidita' => $formatted,
                'dataScadenza' => $formatted,
            ];
        }
        return $result;
    }

    /**
     * Come buildPartsWithDates(), ma la prima rata scade esattamente a $firstDueDate
     * (nessun +1 mese) e le successive a cadenza mensile con lo stesso ancoraggio di fine mese.
     *
     * @return array<int,array{indice:int,importo:float,dataValidita:string,dataScadenza:string}>
     */
    public static function buildPartsFromFirstDueDate(float $total, int $n, DateTimeImmutable $firstDueDate): array
    {
        $parts = self::splitAmount($total, $n);
        $anchorMode = self::detectAnchorMode((int)$firstDueDate->format('j'), (int)$firstDueDate->format('n'), (int)$firstDueDate->format('Y'));
        $result = [];
        foreach ($parts as $i => $importo) {
            $formatted = self::addMonthsWithAnchor($firstDueDate, $i, $anchorMode)->format('Y-m-d');
            $result[] = [
                'indice' => $i + 1,
                'importo' => (float)round($importo, 2),
                'dataValidita' => $formatted,
                'dataScadenza' => $formatted,
            ];
        }
        return $result;
    }

    /**
     * Rileva la modalità di ancoraggio in base al giorno della prima scadenza.
     * - 31 o ultimo giorno mese -> EOM
     * - 30 -> D30 (usa 30 per i mesi >=30 giorni, ultimo per febbraio)
     * - 28/29 se febbraio -> EOM
     */
    private static function detectAnchorMode(int $day, int $month, int $year): string
    {
        $last = (int) (new \DateTimeImmutable("$year-$month-01"))->modify('last day of this month')->format('j');
        $isFeb = ($month === 2);
        if ($day === 31 || $day === $last) return 'EOM';
        if ($day === 30) return 'D30';
        if ($isFeb && ($day === 28 || $day === 29)) return 'EOM';
        return 'NORMAL';
    }

    /**
     * Aggiunge mesi rispettando la modalità di ancoraggio.
     * $offset mesi da aggiungere (0 per la prima rata).
     */
    private static function addMonthsWithAnchor(DateTimeImmutable $baseFirst, int $offset, string $anchorMode): DateTimeImmutable
    {
        $targetBase = $baseFirst->modify('+' . $offset . ' month');
        $y = (int)$targetBase->format('Y');
        $m = (int)$targetBase->format('n');
        $last = (int)$targetBase->modify('last day of this month')->format('j');
        if ($anchorMode === 'EOM') {
            return $targetBase->setDate($y, $m, $last);
        }
        if ($anchorMode === 'D30') {
            $day = ($m === 2) ? $last : min(30, $last);
            return $targetBase->setDate($y, $m, $day);
        }
        // NORMAL: mantieni il giorno originale se possibile, altrimenti ultimo del mese
        $origDay = (int)$baseFirst->format('j');
        $day = min($origDay, $last);
        return $targetBase->setDate($y, $m, $day);
    }
}
