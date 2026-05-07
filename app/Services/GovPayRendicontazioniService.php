<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Servizio per il report ragioneria.
 *
 * Logica:
 *   1. GET /flussiRendicontazione?dataDa=...&dataA=... (paginato) → elenco flussi nel periodo
 *   2. Per ogni flusso: GET /flussiRendicontazione/{idDominio}/{idFlusso} → rendicontazioni
 *   3. Filtra per cod_entrata (tassonomia) se specificato
 *   4. Restituisce righe di dettaglio + aggregazione per tipologia
 */
class GovPayRendicontazioniService
{
    private const MAX_FLUSSI_PAGES  = 200;
    private const RESULTS_PER_PAGE  = 100;

    /**
     * @param array<string,mixed> $guzzleOptions  Opzioni extra Guzzle (TLS, cert, …)
     * @param array<string,mixed> $filters         dataDa, dataA, idDominio, tassonomie[], collectRaw
     * @return array<string,mixed>
     */
    public function findByTaxonomyFromFlussi(
        string $backofficeUrl,
        string $username,
        string $password,
        array  $guzzleOptions,
        array  $filters
    ): array {
        $idDominio   = (string)($filters['idDominio'] ?? '');
        $dataDa      = $filters['dataDa'] ?? null;
        $dataA       = $filters['dataA'] ?? null;
        $tassonomie  = $this->normalizeStringArray($filters['tassonomie'] ?? []);
        $collectRaw  = (bool)($filters['collectRaw'] ?? false);
        $excludeNd   = (bool)($filters['excludeNd'] ?? true);
        $taxonomyLabels = is_array($filters['taxonomyLabels'] ?? null) ? $filters['taxonomyLabels'] : [];
        $maxFlussiPages = (int)($filters['maxFlussiPages'] ?? self::MAX_FLUSSI_PAGES);
        if ($maxFlussiPages <= 0) {
            $maxFlussiPages = self::MAX_FLUSSI_PAGES;
        }
        $resultsPerPage = (int)($filters['resultsPerPage'] ?? self::RESULTS_PER_PAGE);
        if ($resultsPerPage <= 0) {
            $resultsPerPage = self::RESULTS_PER_PAGE;
        }
        $metadatiPaginazione = (bool)($filters['metadatiPaginazione'] ?? true);
        $maxRisultati = (bool)($filters['maxRisultati'] ?? true);
        $progressCallback = isset($filters['progressCallback']) && is_callable($filters['progressCallback'])
            ? $filters['progressCallback']
            : null;

        $baseUrl    = rtrim($backofficeUrl, '/');
        $clientOpts = array_merge($guzzleOptions, [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 30,
        ]);
        if ($username !== '' && $password !== '') {
            $clientOpts['auth'] = [$username, $password];
        }
        $client = new Client($clientOpts);

        // ── Step 1: elenca tutti i flussi nel periodo ────────────────────────────
        /** @var array<int,array<string,mixed>> $flussiIndex */
        $flussiIndex = [];
        $rawPayloads = [];
        $page        = 1;

        do {
            $query = [
                'pagina'               => $page,
                'risultatiPerPagina'   => $resultsPerPage,
                'metadatiPaginazione'  => $metadatiPaginazione ? 'true' : 'false',
                'maxRisultati'         => $maxRisultati ? 'true' : 'false',
            ];
            if ($idDominio !== '') {
                $query['idDominio'] = $idDominio;
            }
            if ($dataDa !== null) {
                $query['dataDa'] = $dataDa;
            }
            if ($dataA !== null) {
                $query['dataA'] = $dataA;
            }

            try {
                $resp    = $client->request('GET', $baseUrl . '/flussiRendicontazione', ['query' => $query]);
                $payload = json_decode((string)$resp->getBody(), true);
            } catch (RequestException $e) {
                throw new \RuntimeException('Errore lista flussi (p.' . $page . '): ' . $e->getMessage(), 0, $e);
            }

            if (!is_array($payload)) {
                break;
            }

            if ($collectRaw) {
                $rawPayloads[] = ['step' => 'flussi_list', 'page' => $page, 'response' => $payload];
            }

            $risultati = $payload['risultati'] ?? [];
            if (!is_array($risultati) || $risultati === []) {
                break;
            }

            foreach ($risultati as $item) {
                if (is_array($item)) {
                    $flussiIndex[] = $item;
                }
            }

            if ($progressCallback !== null) {
                $progressCallback([
                    'phase' => 'fetch_flussi',
                    'current_page' => $page,
                    'flussi_indexed' => count($flussiIndex),
                ]);
            }

            $prossimiRisultati = (string)($payload['prossimiRisultati'] ?? '');
            $nextPage          = $this->extractNextPage($prossimiRisultati);
            if ($nextPage === null || $nextPage <= $page) {
                break;
            }
            $page = $nextPage;
        } while ($page <= $maxFlussiPages);

        // ── Step 2: apri ogni flusso e spacchetta le rendicontazioni ────────────
        /** @var array<int,array<string,mixed>> $rows */
        $rows     = [];
        $seenKeys = [];

        $totalFlussi = count($flussiIndex);
        $processedFlussi = 0;
        foreach ($flussiIndex as $flussoItem) {
            $idFlusso       = (string)($flussoItem['idFlusso'] ?? '');
            $domainId       = (string)($flussoItem['dominio']['idDominio'] ?? $flussoItem['idDominio'] ?? $idDominio);
            $dataFlusso     = (string)($flussoItem['dataFlusso'] ?? '');
            $dataRegolamento = (string)($flussoItem['dataRegolamento'] ?? '');
            $trn            = (string)($flussoItem['trn'] ?? '');
            $idPsp          = (string)($flussoItem['idPsp'] ?? '');

            if ($idFlusso === '' || $domainId === '') {
                $processedFlussi++;
                continue;
            }

            if ($progressCallback !== null) {
                $progressCallback([
                    'phase' => 'fetch_dettagli',
                    'current_flow_id' => $idFlusso,
                    'processed_flussi' => $processedFlussi,
                    'total_flussi' => $totalFlussi,
                ]);
            }

            try {
                $detailPath = $baseUrl . '/flussiRendicontazione/'
                    . rawurlencode($domainId) . '/'
                    . rawurlencode($idFlusso);
                $detailResp = $client->request('GET', $detailPath);
                $detail     = json_decode((string)$detailResp->getBody(), true);
            } catch (\Throwable $e) {
                if ($collectRaw) {
                    $rawPayloads[] = [
                        'step'     => 'flusso_detail_error',
                        'idFlusso' => $idFlusso,
                        'error'    => $e->getMessage(),
                    ];
                }
                continue;
            }

            if (!is_array($detail)) {
                continue;
            }

            if ($collectRaw) {
                $rawPayloads[] = ['step' => 'flusso_detail', 'idFlusso' => $idFlusso, 'response' => $detail];
            }

            $rendicontazioni = $detail['rendicontazioni'] ?? [];
            if (!is_array($rendicontazioni)) {
                continue;
            }

            foreach ($rendicontazioni as $rend) {
                if (!is_array($rend)) {
                    continue;
                }

                // Tassonomia: cod_entrata nella voce pendenza della riscossione
                $risc         = is_array($rend['riscossione'] ?? null) ? $rend['riscossione'] : null;
                $vocePendenza = ($risc !== null && is_array($risc['vocePendenza'] ?? null))
                    ? $risc['vocePendenza']
                    : null;
                $codEntrata   = ($vocePendenza !== null) ? (string)($vocePendenza['codEntrata'] ?? '') : '';
                if ($excludeNd && $codEntrata === '') {
                    continue;
                }

                // Filtro tassonomia: se l'utente ha selezionato specifiche tipologie, escludi le altre
                if ($tassonomie !== [] && !in_array($codEntrata, $tassonomie, true)) {
                    continue;
                }

                $taxonomyDescription = '';
                if ($codEntrata !== '' && isset($taxonomyLabels[$codEntrata])) {
                    $taxonomyDescription = (string)$taxonomyLabels[$codEntrata];
                }
                $taxonomyLabel = $codEntrata !== ''
                    ? ($taxonomyDescription !== '' ? $taxonomyDescription : $codEntrata)
                    : 'N/D';

                $iuv    = (string)($rend['iuv'] ?? '');
                $iur    = (string)($rend['iur'] ?? '');
                $indice = (int)($rend['indice'] ?? 0);
                $idPendenza = '';
                if ($vocePendenza !== null) {
                    $idPendenza = (string)($vocePendenza['idPendenza'] ?? '');
                    if ($idPendenza === '' && is_array($vocePendenza['pendenza'] ?? null)) {
                        $idPendenza = (string)($vocePendenza['pendenza']['idPendenza'] ?? '');
                    }
                }
                if ($idPendenza === '' && is_array($rend['pendenza'] ?? null)) {
                    $idPendenza = (string)($rend['pendenza']['idPendenza'] ?? '');
                }

                $key = $domainId . '|' . $idFlusso . '|' . $iuv . '|' . $iur . '|' . $indice;
                if (isset($seenKeys[$key])) {
                    continue;
                }
                $seenKeys[$key] = true;

                $rows[] = [
                    'data_flusso'      => $dataFlusso,
                    'data_regolamento' => $dataRegolamento,
                    'id_flusso'        => $idFlusso,
                    'trn'              => $trn,
                    'id_psp'           => $idPsp,
                    'id_dominio'       => $domainId,
                    'tassonomia'       => $codEntrata !== '' ? $codEntrata : 'N/D',
                    'tassonomia_descrizione' => $taxonomyDescription,
                    'tassonomia_label' => $taxonomyLabel,
                    'id_pendenza'      => $idPendenza,
                    'iuv'              => $iuv,
                    'iur'              => $iur,
                    'indice'           => $indice,
                    'importo'          => (float)($rend['importo'] ?? 0),
                    'esito'            => (int)($rend['esito'] ?? -1),
                    'stato_rend'       => (string)($rend['stato'] ?? ''),
                    'data_pagamento'   => ($risc !== null) ? (string)($risc['data'] ?? '') : '',
                    'descrizione_voce' => ($vocePendenza !== null) ? (string)($vocePendenza['descrizione'] ?? '') : '',
                ];
            }
            $processedFlussi++;
        }

        // Ordina per data_flusso DESC
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($b['data_flusso'] ?? ''), (string)($a['data_flusso'] ?? ''));
        });

        // Totali globali
        $totals = ['amount' => 0.0, 'count' => 0];
        foreach ($rows as $row) {
            $totals['amount'] += (float)($row['importo'] ?? 0);
            $totals['count']++;
        }

        // Aggregazione per tipologia
        if ($progressCallback !== null) {
            $progressCallback([
                'phase' => 'aggregazione',
                'processed_flussi' => $processedFlussi,
                'total_flussi' => $totalFlussi,
            ]);
        }
        $byTipologia = [];
        foreach ($rows as $row) {
            $t = (string)($row['tassonomia'] ?? 'N/D');
            $td = (string)($row['tassonomia_descrizione'] ?? '');
            if (!isset($byTipologia[$t])) {
                $byTipologia[$t] = [
                    'tassonomia' => $t,
                    'tassonomia_descrizione' => $td,
                    'tassonomia_label' => $td !== '' ? $td : $t,
                    'count' => 0,
                    'amount' => 0.0,
                ];
            }
            $byTipologia[$t]['count']++;
            $byTipologia[$t]['amount'] += (float)($row['importo'] ?? 0);
        }

        return [
            'rows'        => $rows,
            'totals'      => $totals,
            'by_tipologia' => array_values($byTipologia),
            'meta'        => [
                'flussi_processati'      => count($flussiIndex),
                'rendicontazioni_totali' => count($rows),
                'tassonomie_filtrate'    => $tassonomie,
                'exclude_nd'             => $excludeNd,
                'max_flussi_pages'       => $maxFlussiPages,
                'results_per_page'       => $resultsPerPage,
            ],
            'raw' => $collectRaw ? $rawPayloads : null,
        ];
    }

    /**
     * @param mixed $values
     * @return array<int,string>
     */
    private function normalizeStringArray(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $v) {
            $s = trim((string)$v);
            if ($s !== '') {
                $result[] = $s;
            }
        }
        return array_values(array_unique($result));
    }

    private function extractNextPage(string $prossimiRisultati): ?int
    {
        if ($prossimiRisultati === '') {
            return null;
        }
        $query = parse_url($prossimiRisultati, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $params);
        $page = (int)($params['pagina'] ?? 0);
        return $page > 0 ? $page : null;
    }
}
