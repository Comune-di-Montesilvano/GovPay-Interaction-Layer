<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Export di un batch pendenze massive: tracciato CSV di output (input + numeri avviso)
 * e piano dei file PDF da mettere nello ZIP avvisi.
 *
 * Logica pura su righe `pendenze_massive` (array con riga/stato/payload_json/response_json).
 */
final class MassivoExportService
{
    public const BASE_HEADERS = [
        'TIPO', 'CODICE_FISCALE_PIVA', 'ANAGRAFICA', 'CAUSALE', 'ANNO_RIFERIMENTO', 'IMPORTO', 'EMAIL',
        'DATA_VALIDITA', 'DATA_SCADENZA', 'RATA', 'VOCE_1_IMPORTO', 'VOCE_1_CAUSALE', 'VOCE_2_IMPORTO', 'VOCE_2_CAUSALE',
        'NUMERO_AVVISO',
    ];

    /**
     * Raggruppa le righe DB per riga CSV di input. Esclude i gruppi senza alcuna riga SUCCESS
     * (righe scartate in validazione: hanno già il CSV errori dedicato).
     *
     * @param list<array<string,mixed>> $dbRows
     * @return list<array{riga:int,rate:list<array{payload:array<string,mixed>,numeroAvviso:string,stato:string}>}>
     */
    public static function groupByRiga(array $dbRows): array
    {
        $groups = [];
        foreach ($dbRows as $r) {
            $payload = json_decode((string)($r['payload_json'] ?? ''), true);
            if (!is_array($payload) || !isset($payload['soggettoPagatore'])) {
                continue;
            }
            $stato = (string)($r['stato'] ?? '');
            $resp = json_decode((string)($r['response_json'] ?? ''), true);
            $avviso = ($stato === 'SUCCESS' && is_array($resp)) ? (string)($resp['numeroAvviso'] ?? '') : '';
            $riga = (int)($r['riga'] ?? 0);
            $groups[$riga][] = ['payload' => $payload, 'numeroAvviso' => $avviso, 'stato' => $stato];
        }
        ksort($groups);
        $out = [];
        foreach ($groups as $riga => $rate) {
            if (!array_filter($rate, static fn($x) => $x['stato'] === 'SUCCESS')) {
                continue;
            }
            usort($rate, static fn($a, $b) => self::rataIndex($a['payload']) <=> self::rataIndex($b['payload']));
            $out[] = ['riga' => (int)$riga, 'rate' => $rate];
        }
        return $out;
    }

    /**
     * Tracciato di output: una riga per riga CSV di input. Pendenza unica → NUMERO_AVVISO;
     * rateizzata → RATA_i_IMPORTO/RATA_i_SCADENZA/RATA_i_NUMERO_AVVISO fino al max rate del batch.
     *
     * @param list<array<string,mixed>> $dbRows
     * @return array{0:list<string>,1:list<list<string>>} [header, righe]
     */
    public static function buildTracciato(array $dbRows): array
    {
        $groups = self::groupByRiga($dbRows);
        $maxRate = 0;
        foreach ($groups as $g) {
            if (self::isRateizzata($g)) {
                $maxRate = max($maxRate, count($g['rate']));
            }
        }
        $header = self::BASE_HEADERS;
        for ($i = 1; $i <= $maxRate; $i++) {
            array_push($header, "RATA_{$i}_IMPORTO", "RATA_{$i}_SCADENZA", "RATA_{$i}_NUMERO_AVVISO");
        }

        $rows = [];
        foreach ($groups as $g) {
            $first = $g['rate'][0]['payload'];
            $orig = self::originale($g);
            $sp = is_array($first['soggettoPagatore'] ?? null) ? $first['soggettoPagatore'] : [];
            $voci = array_values($orig['voci']);
            $rateizzata = self::isRateizzata($g);
            $line = [
                (string)($sp['tipo'] ?? ''),
                (string)($sp['identificativo'] ?? ''),
                (string)($sp['anagrafica'] ?? ''),
                $orig['causale'],
                (string)($first['annoRiferimento'] ?? ''),
                self::fmtImporto($orig['importo']),
                (string)($sp['email'] ?? ''),
                self::fmtData($orig['dataValidita']),
                self::fmtData($orig['dataScadenza']),
                $rateizzata ? (string)count($g['rate']) : '',
                isset($voci[0]) ? self::fmtImporto($voci[0]['importo'] ?? null) : '',
                isset($voci[0]) ? (string)($voci[0]['descrizione'] ?? '') : '',
                isset($voci[1]) ? self::fmtImporto($voci[1]['importo'] ?? null) : '',
                isset($voci[1]) ? (string)($voci[1]['descrizione'] ?? '') : '',
                $rateizzata ? '' : $g['rate'][0]['numeroAvviso'],
            ];
            for ($i = 0; $i < $maxRate; $i++) {
                $r = $rateizzata ? ($g['rate'][$i] ?? null) : null;
                array_push(
                    $line,
                    $r ? self::fmtImporto($r['payload']['importo'] ?? null) : '',
                    $r ? self::fmtData($r['payload']['dataScadenza'] ?? null) : '',
                    $r ? $r['numeroAvviso'] : ''
                );
            }
            $rows[] = $line;
        }
        return [$header, $rows];
    }

    /**
     * Piano PDF per lo ZIP: pendenza unica → avviso singolo; rateizzata → PDF documento multirata.
     *
     * @param list<array<string,mixed>> $dbRows
     * @return list<array{tipo:string,codice:string,filename:string,riga:int}>
     *         tipo 'avviso' (codice = numeroAvviso) | 'documento' (codice = identificativo documento)
     */
    public static function buildPdfPlan(array $dbRows): array
    {
        $plan = [];
        $used = [];
        foreach (self::groupByRiga($dbRows) as $g) {
            $first = $g['rate'][0]['payload'];
            $cf = (string)($first['soggettoPagatore']['identificativo'] ?? '');
            $causale = self::originale($g)['causale'];
            if (self::isRateizzata($g)) {
                $codice = (string)($first['documento']['identificativo'] ?? '');
                $tipo = 'documento';
            } else {
                $codice = $g['rate'][0]['numeroAvviso'];
                $tipo = 'avviso';
            }
            if ($codice === '') {
                continue;
            }
            $plan[] = ['tipo' => $tipo, 'codice' => $codice, 'filename' => self::pdfFileName($causale, $cf, $used), 'riga' => $g['riga']];
        }
        return $plan;
    }

    /**
     * Nome file "CAUSALE_CODICEFISCALE.pdf": maiuscolo, caratteri non alfanumerici → "_",
     * causale max 80 caratteri. Nomi duplicati ricevono suffisso "_2", "_3", ...
     *
     * @param array<string,int> $used nomi già usati (aggiornato per riferimento)
     */
    public static function pdfFileName(string $causale, string $cf, array &$used): string
    {
        $clean = static function (string $s): string {
            $s = strtoupper(trim($s));
            $s = (string)preg_replace('/[^A-Z0-9]+/', '_', $s);
            return trim($s, '_');
        };
        $c = substr($clean($causale), 0, 80);
        $c = rtrim($c, '_');
        $base = ($c !== '' ? $c : 'AVVISO') . '_' . ($clean($cf) !== '' ? $clean($cf) : 'SENZA_CF');
        $key = $base;
        if (isset($used[$base])) {
            $used[$base]++;
            $key = $base . '_' . $used[$base];
        } else {
            $used[$base] = 1;
        }
        return $key . '.pdf';
    }

    /** CSV con separatore ";" (stesso formato del template di input). */
    public static function toCsv(array $header, array $rows): string
    {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $header, ';', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fh, $r, ';', '"', '\\');
        }
        rewind($fh);
        $csv = (string)stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /** @param array{rate:list<array{payload:array<string,mixed>}>} $g */
    private static function isRateizzata(array $g): bool
    {
        return isset($g['rate'][0]['payload']['datiAllegati']['rateizzazione']);
    }

    /**
     * Valori della riga CSV originale: per le rateizzate vengono da datiAllegati.rateizzazione,
     * per le uniche coincidono col payload.
     *
     * @return array{causale:string,importo:mixed,dataValidita:mixed,dataScadenza:mixed,voci:array<int,array<string,mixed>>}
     */
    private static function originale(array $g): array
    {
        $p = $g['rate'][0]['payload'];
        $src = self::isRateizzata($g) ? $p['datiAllegati']['rateizzazione'] : $p;
        return [
            'causale' => (string)($src['causale'] ?? ''),
            'importo' => $src['importo'] ?? null,
            'dataValidita' => $src['dataValidita'] ?? null,
            'dataScadenza' => $src['dataScadenza'] ?? null,
            'voci' => is_array($src['voci'] ?? null) ? $src['voci'] : [],
        ];
    }

    private static function rataIndex(array $payload): int
    {
        return (int)($payload['documento']['rata'] ?? 0);
    }

    private static function fmtImporto(mixed $v): string
    {
        return is_numeric($v) ? number_format((float)$v, 2, ',', '') : '';
    }

    private static function fmtData(mixed $v): string
    {
        $s = trim((string)($v ?? ''));
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        return $s;
    }
}
