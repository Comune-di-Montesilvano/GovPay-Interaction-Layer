<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Services;

use GovPay\Backoffice\Api\RiscossioniApi;
use GovPay\Backoffice\Configuration;
use GovPay\Backoffice\ObjectSerializer;
use GuzzleHttp\Client;

class GovPayTaxonomyCollectionsService
{
    private const MAX_PAGES_PER_COMBINATION = 500;

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function findByTaxonomy(string $baseUrl, string $username, string $password, array $guzzleOptions, array $filters): array
    {
        $config = new Configuration();
        $config->setHost(rtrim($baseUrl, '/'));

        if ($username !== '' && $password !== '') {
            $config->setUsername($username);
            $config->setPassword($password);
        }

        $api = new RiscossioniApi(GovPayClientFactory::makeBackofficeClient($guzzleOptions), $config);

        $idDominio = (string)($filters['idDominio'] ?? '');
        $dataDa = $filters['dataDa'] ?? null;
        $dataA = $filters['dataA'] ?? null;
        $tipologiePendenza = $this->normalizeStringArray($filters['tassonomie'] ?? []);
        $tipi = $this->normalizeStringArray($filters['tipi'] ?? []);
        $tipiFilter = $tipi !== [] ? $tipi : null;
        $stati = $this->normalizeStringArray($filters['stati'] ?? []);
        $statiFilter = $stati !== [] ? $stati : [null];
        $collectRaw = (bool)($filters['collectRaw'] ?? false);

        if (!$tipologiePendenza) {
            $tipologiePendenza = [''];
        }

        $rowsByKey = [];
        $pagesFetched = 0;
        $rawPayloads = [];

        foreach ($tipologiePendenza as $taxonomyToken) {
            $queryVariants = $this->buildQueryVariants($taxonomyToken);

            foreach ($queryVariants as $queryVariant) {
                foreach ($statiFilter as $stato) {
                    $page = 1;
                    $seenPages = [];
                    $pagesForCombination = 0;
                    do {
                    if (isset($seenPages[$page])) {
                        if ($collectRaw) {
                            $rawPayloads[] = [
                                'request' => [
                                    'page' => $page,
                                    'idTipoPendenza' => $queryVariant['id_tipo_pendenza'],
                                    'tassonomia' => $queryVariant['tassonomia'],
                                    'queryMode' => $queryVariant['mode'],
                                    'stato' => $stato,
                                    'idDominio' => $idDominio,
                                    'dataDa' => $dataDa,
                                    'dataA' => $dataA,
                                ],
                                'response' => [
                                    'guard' => 'duplicate_page_detected',
                                ],
                            ];
                        }
                        break;
                    }
                    $seenPages[$page] = true;
                    $pagesForCombination++;
                    if ($pagesForCombination > self::MAX_PAGES_PER_COMBINATION) {
                        if ($collectRaw) {
                            $rawPayloads[] = [
                                'request' => [
                                    'page' => $page,
                                    'idTipoPendenza' => $queryVariant['id_tipo_pendenza'],
                                    'tassonomia' => $queryVariant['tassonomia'],
                                    'queryMode' => $queryVariant['mode'],
                                    'stato' => $stato,
                                    'idDominio' => $idDominio,
                                    'dataDa' => $dataDa,
                                    'dataA' => $dataA,
                                ],
                                'response' => [
                                    'guard' => 'max_pages_reached',
                                    'max_pages' => self::MAX_PAGES_PER_COMBINATION,
                                ],
                            ];
                        }
                        break;
                    }

                    $result = $api->findRiscossioni(
                        $page,
                        200,
                        '+data',
                        null,
                        $idDominio !== '' ? $idDominio : null,
                        null,
                        null,
                        null,
                        $queryVariant['id_tipo_pendenza'],
                        $stato,
                        $dataDa,
                        $dataA,
                        $tipiFilter,
                        null,
                        null,
                        null,
                        $queryVariant['tassonomia'],
                        true,
                        true,
                        null
                    );

                    $payload = $this->toArray(ObjectSerializer::sanitizeForSerialization($result));
                    if ($collectRaw) {
                        $rawPayloads[] = [
                            'request' => [
                                'page' => $page,
                                'idTipoPendenza' => $queryVariant['id_tipo_pendenza'],
                                'tassonomia' => $queryVariant['tassonomia'],
                                'queryMode' => $queryVariant['mode'],
                                'stato' => $stato,
                                'idDominio' => $idDominio,
                                'dataDa' => $dataDa,
                                'dataA' => $dataA,
                            ],
                            'response' => $payload,
                        ];
                    }
                    $risultati = is_array($payload['risultati'] ?? null) ? $payload['risultati'] : [];

                    if ($risultati === []) {
                        if ($collectRaw) {
                            $rawPayloads[] = [
                                'request' => [
                                    'page' => $page,
                                    'idTipoPendenza' => $queryVariant['id_tipo_pendenza'],
                                    'tassonomia' => $queryVariant['tassonomia'],
                                    'queryMode' => $queryVariant['mode'],
                                    'stato' => $stato,
                                    'idDominio' => $idDominio,
                                    'dataDa' => $dataDa,
                                    'dataA' => $dataA,
                                ],
                                'response' => [
                                    'guard' => 'empty_results_stop',
                                ],
                            ];
                        }
                        break;
                    }

                    foreach ($risultati as $item) {
                        if (!is_array($item)) {
                            continue;
                        }

                        $row = [
                            'id_dominio' => (string)($item['idDominio'] ?? ''),
                            'iuv' => (string)($item['iuv'] ?? ''),
                            'iur' => (string)($item['iur'] ?? ''),
                            'indice' => (int)($item['indice'] ?? 0),
                            'importo' => (float)($item['importo'] ?? 0),
                            'data' => (string)($item['data'] ?? ''),
                            'stato' => (string)($item['stato'] ?? ''),
                            'tipo' => (string)($item['tipo'] ?? ''),
                            'id_voce_pendenza' => (string)($item['idVocePendenza'] ?? ''),
                            'incasso' => (string)($item['incasso'] ?? ''),
                            'tassonomia' => $taxonomyToken,
                        ];

                        $key = $this->buildRowKey($row);

                        if (!isset($rowsByKey[$key])) {
                            $rowsByKey[$key] = $row;
                        }
                    }

                    $pagesFetched++;
                    $nextPage = $this->extractNextPage((string)($payload['prossimiRisultati'] ?? ''));
                    if ($nextPage === null || $nextPage <= $page) {
                        break;
                    }

                    $page = $nextPage;
                    } while (true);
                }
            }
        }

        $rows = array_values($rowsByKey);
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string)($b['data'] ?? ''), (string)($a['data'] ?? ''));
        });

        $totals = ['amount' => 0.0, 'count' => 0];
        foreach ($rows as $row) {
            $totals['amount'] += (float)($row['importo'] ?? 0);
            $totals['count']++;
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'meta' => [
                'pages_fetched' => $pagesFetched,
                'raw_calls' => count($rawPayloads),
                'states' => $stati,
                'types' => $tipi,
                'taxonomies' => $tipologiePendenza,
            ],
            'raw' => $collectRaw ? $rawPayloads : null,
        ];
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \stdClass) {
            return json_decode((string)json_encode($value, JSON_UNESCAPED_SLASHES), true) ?: [];
        }

        if (is_object($value)) {
            return $this->toArray(ObjectSerializer::sanitizeForSerialization($value));
        }

        return (array)$value;
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
        foreach ($values as $value) {
            $v = trim((string)$value);
            if ($v !== '') {
                $result[] = $v;
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

    /**
     * @param array<string,mixed> $row
     */
    private function buildRowKey(array $row): string
    {
        return implode('|', [
            (string)($row['id_dominio'] ?? ''),
            (string)($row['iuv'] ?? ''),
            (string)($row['iur'] ?? ''),
            (string)($row['indice'] ?? ''),
            (string)($row['stato'] ?? ''),
            (string)($row['tipo'] ?? ''),
            (string)($row['incasso'] ?? ''),
            (string)($row['data'] ?? ''),
            number_format((float)($row['importo'] ?? 0), 2, '.', ''),
        ]);
    }

    /**
     * @return array<int,array{id_tipo_pendenza:?string,tassonomia:?string,mode:string}>
     */
    private function buildQueryVariants(string $taxonomyToken): array
    {
        $token = trim($taxonomyToken);
        if ($token === '') {
            return [[
                'id_tipo_pendenza' => null,
                'tassonomia' => null,
                'mode' => 'no_taxonomy_filter',
            ]];
        }

        return [
            [
                'id_tipo_pendenza' => $token,
                'tassonomia' => null,
                'mode' => 'id_tipo_pendenza',
            ],
            [
                'id_tipo_pendenza' => null,
                'tassonomia' => $token,
                'mode' => 'tassonomia',
            ],
        ];
    }
}
