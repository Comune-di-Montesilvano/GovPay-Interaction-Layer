<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * Espansione di una riga CSV pendenze massive con RATA = N > 1 in N payload GovPay
 * collegati dallo stesso documento (stesso formato della rateizzazione backoffice:
 * documento.identificativo condiviso, causale "(Rata i di N)", descrizione "(Rate N)").
 *
 * Logica pura, nessun I/O: testabile in isolamento.
 */
final class MassivoRateizzazioneService
{
    public const MAX_RATE = 24;
    private const MAX_CAUSALE = 140;

    /**
     * @param array<string,mixed> $payload    payload pendenza unica (output di rowToPayload)
     * @param int                 $numRate    numero rate (>1)
     * @param DateTimeImmutable|null $firstDue scadenza della rata 1 (null = rate senza scadenza calcolata)
     * @param string              $documentoId identificativo documento condiviso tra le rate
     * @param string              $baseIdPendenza prefisso idPendenza (ogni rata = "<base>-R<i>")
     * @param array<int,array{dataValidita?:string,dataScadenza?:string}> $dateRate date esplicite per rata (vedi schedule())
     * @return list<array<string,mixed>> un payload per rata, in ordine di indice
     */
    public static function expand(array $payload, int $numRate, ?DateTimeImmutable $firstDue, string $documentoId, string $baseIdPendenza, array $dateRate = []): array
    {
        $causaleOrig = trim((string)($payload['causale'] ?? ''));
        $importoTot = (float)($payload['importo'] ?? 0);
        $date = self::schedule($numRate, $firstDue, $dateRate);
        $parts = [];
        foreach (RateizzazioneService::splitAmount($importoTot, $numRate) as $k => $importo) {
            $parts[] = ['indice' => $k + 1, 'importo' => (float)round($importo, 2)] + $date[$k];
        }
        $vociOrig = is_array($payload['voci'] ?? null) ? array_values($payload['voci']) : [];
        $descrDoc = self::withSuffix($causaleOrig, " (Rate {$numRate})");

        $out = [];
        foreach ($parts as $p) {
            $i = (int)$p['indice'];
            $suffix = " (Rata {$i} di {$numRate})";
            $single = $payload;
            $single['idPendenza'] = substr($baseIdPendenza . '-R' . $i, 0, 35);
            $single['importo'] = $p['importo'];
            $single['dataValidita'] = $p['dataValidita'];
            if ($p['dataScadenza'] !== '') {
                $single['dataScadenza'] = $p['dataScadenza'];
            } else {
                unset($single['dataScadenza']);
            }
            $single['causale'] = self::withSuffix($causaleOrig, $suffix);
            $single['voci'] = self::allocateVoci($vociOrig, $p['importo'], $suffix);
            $single['documento'] = [
                'identificativo' => $documentoId,
                'descrizione' => $descrDoc,
                'rata' => $i,
            ];
            $dati = is_array($single['datiAllegati'] ?? null) ? $single['datiAllegati'] : [];
            // Valori della riga CSV prima dell'espansione: servono a ricostruire il tracciato di output.
            $dati['rateizzazione'] = [
                'numeroRate' => $numRate,
                'causale' => $causaleOrig,
                'importo' => round($importoTot, 2),
                'dataValidita' => $payload['dataValidita'] ?? null,
                'dataScadenza' => $payload['dataScadenza'] ?? null,
                'voci' => $vociOrig,
            ];
            $single['datiAllegati'] = $dati;
            $out[] = $single;
        }
        return $out;
    }

    /**
     * Date validità/scadenza di ogni rata: scadenze mensili da $firstDue (nessuna se null),
     * sostituite da quelle esplicite in $dateRate (indice rata 1..N => date ISO, '' o assente = calcolata).
     * Validità non indicata = scadenza della rata. Date non determinabili restano ''.
     *
     * @param array<int,array{dataValidita?:string,dataScadenza?:string}> $dateRate
     * @return list<array{dataValidita:string,dataScadenza:string}> una voce per rata, in ordine di indice
     */
    public static function schedule(int $numRate, ?DateTimeImmutable $firstDue, array $dateRate = []): array
    {
        $calcolate = $firstDue !== null
            ? array_column(RateizzazioneService::buildPartsFromFirstDueDate(0.0, $numRate, $firstDue), 'dataScadenza')
            : [];
        $out = [];
        for ($i = 1; $i <= $numRate; $i++) {
            $scadenza = (string)($dateRate[$i]['dataScadenza'] ?? '') ?: (string)($calcolate[$i - 1] ?? '');
            $validita = (string)($dateRate[$i]['dataValidita'] ?? '') ?: $scadenza;
            $out[] = ['dataValidita' => $validita, 'dataScadenza' => $scadenza];
        }
        return $out;
    }

    /**
     * Ripartisce l'importo della rata sulle voci in proporzione agli importi originali
     * (in centesimi, resto sull'ultima voce) così che la somma voci == importo rata.
     *
     * @param list<array<string,mixed>> $voci
     * @return list<array<string,mixed>>
     */
    public static function allocateVoci(array $voci, float $importoRata, string $suffix = ''): array
    {
        $rataCents = (int)round($importoRata * 100);
        if ($voci === []) {
            return [];
        }
        $basis = array_map(static fn($v) => max(0, (int)round(((float)($v['importo'] ?? 0)) * 100)), $voci);
        $basisTot = array_sum($basis);
        $n = count($voci);
        $allocated = [];
        $sum = 0;
        foreach ($voci as $k => $_) {
            if ($k === $n - 1) {
                $allocated[$k] = $rataCents - $sum;
            } else {
                $allocated[$k] = $basisTot > 0 ? intdiv($rataCents * $basis[$k], $basisTot) : intdiv($rataCents, $n);
                $sum += $allocated[$k];
            }
        }
        $out = [];
        foreach ($voci as $k => $v) {
            if ($allocated[$k] <= 0) {
                continue;
            }
            $v['importo'] = $allocated[$k] / 100.0;
            if ($suffix !== '') {
                $v['descrizione'] = self::withSuffix(trim((string)($v['descrizione'] ?? '')), $suffix);
            }
            $out[] = $v;
        }
        return $out;
    }

    /** Appende $suffix troncando il testo base per restare entro 140 caratteri. */
    public static function withSuffix(string $base, string $suffix): string
    {
        $max = self::MAX_CAUSALE - mb_strlen($suffix);
        if (mb_strlen($base) > $max) {
            $base = rtrim(mb_substr($base, 0, $max));
        }
        return $base . $suffix;
    }
}
