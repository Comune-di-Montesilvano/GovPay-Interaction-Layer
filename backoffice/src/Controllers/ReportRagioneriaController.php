<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\EntrateRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Database\MappingPendenzeRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Report Ragioneria: incassi reali per data di regolamento/incasso (dataRegolamento), filtrati per tassonomia.
 *
 * Flusso dati:
 *   1. GET /flussiRendicontazione  (periodo → tutti i flussi)
 *   2. GET /flussiRendicontazione/{idDominio}/{idFlusso}  (dettaglio + rendicontazioni)
 *   3. Filtra per cod_entrata (tassonomia)
 */
class ReportRagioneriaController
{
    private const REPORT_TIMEZONE = 'Europe/Rome';
    private const PAGE_SIZE = 50;

    public function __construct(private readonly Twig $twig) {}

    public function index(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();

        $params      = (array)($request->getQueryParams() ?? []);
        $queryMade   = array_key_exists('q', $params);
        $errors      = [];
        $sessionUser = $_SESSION['user'] ?? [];

        $today        = new \DateTimeImmutable('today');
        $defaultStart = $today->sub(new \DateInterval('P30D'));

        $filters = [
            'q'          => $queryMade ? '1' : null,
            'dataDa'     => (string)($params['dataDa'] ?? $defaultStart->format('Y-m-d')),
            'dataA'      => (string)($params['dataA'] ?? $today->format('Y-m-d')),
            'idDominio'  => (string)($params['idDominio'] ?? SettingsRepository::get('entity', 'id_dominio', '')),
            'tassonomie' => $this->parseTaxonomySelection($params),
            'page'       => max(1, (int)($params['page'] ?? 1)),
            'cf'         => trim((string)($params['cf'] ?? '')),
            'anagrafica' => trim((string)($params['anagrafica'] ?? '')),
            'origine'    => in_array($params['origine'] ?? '', ['interne', 'esterne'], true) ? $params['origine'] : '',
            'iuv'        => trim((string)($params['iuv'] ?? '')),
        ];

        $bizCounts = ['PENDING' => 0, 'PROCESSED' => 0, 'ERROR' => 0, 'SKIPPED' => 0, 'total' => 0, 'not_queued' => 0];
        if ($filters['idDominio'] !== '') {
            try {
                $bizCounts = (new BizRepository())->getCounts($filters['idDominio']);
                $bizCounts['not_queued'] = 0;
            } catch (\Throwable $_) {}
            try {
                $flussiRepo = new FlussiRendicontazioniRepository();
                $scanDa = trim((string)\App\Config\SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
                $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : null;
                $bizCounts['not_queued'] = $flussiRepo->countUnprocessedForBiz($filters['idDominio'], $minDate);
            } catch (\Throwable $_) {}
        }

        // Carica tipologie censite dal DB per il filtro
        $tipologieRepo    = new EntrateRepository();
        $tipologieCensite = [];
        if ($filters['idDominio'] !== '') {
            $userId   = (int)($sessionUser['id'] ?? 0);
            $userRole = (string)($sessionUser['role'] ?? '');
            if ($userId > 0 && $userRole !== '') {
                $tipologieCensite = $tipologieRepo->listAbilitateByDominioForUser($filters['idDominio'], $userId, $userRole);
            } else {
                $tipologieCensite = $tipologieRepo->listAbilitateByDominio($filters['idDominio']);
            }
        }

        // Appende tipologie custom (mapping_tipologie_custom) e N/D
        $customCodes = [];
        if ($filters['idDominio'] !== '') {
            $customRepo = new MappingPendenzeRepository();
            foreach ($customRepo->getCustomTipologie($filters['idDominio']) as $tc) {
                $customCodes[] = $tc['cod_entrata'];
                $tipologieCensite[] = [
                    'id_entrata'  => $tc['cod_entrata'],
                    'descrizione' => $tc['descrizione'],
                ];
            }
            $tipologieCensite[] = ['id_entrata' => 'N/D', 'descrizione' => 'N/D (senza tipologia)'];
        }

        // Interseca selezione con tipologie ammesse
        $allowedTipologie = array_values(array_filter(
            array_map(static fn(array $r): string => (string)($r['id_entrata'] ?? ''), $tipologieCensite),
            static fn(string $v): bool => $v !== ''
        ));
        if ($filters['tassonomie'] !== []) {
            $filters['tassonomie'] = array_values(array_intersect($filters['tassonomie'], $allowedTipologie));
        }

        // Separa standard, custom e N/D per WHERE builder
        $ndSelected         = in_array('N/D', $filters['tassonomie'], true);
        $tassonomieNoNd     = array_values(array_filter($filters['tassonomie'], static fn(string $v): bool => $v !== 'N/D'));
        $standardTassonomie = array_values(array_diff($tassonomieNoNd, $customCodes));
        $customTassonomie   = array_values(array_intersect($tassonomieNoNd, $customCodes));

        $taxonomyLabels = $this->loadTaxonomyLabels($filters['idDominio']);

        $dataDa = $this->parseStartDate($filters['dataDa']);
        $dataA  = $this->parseEndDate($filters['dataA']);

        if ($queryMade && $dataDa && $dataA && $dataDa > $dataA) {
            $errors[] = 'Intervallo date non valido: la data iniziale supera la data finale.';
        }

        if ($queryMade && $filters['tassonomie'] === [] && $allowedTipologie === []) {
            $errors[] = 'Nessuna tipologia censita disponibile per il dominio selezionato.';
        }

        $extra = [
            'cf'         => $filters['cf'],
            'anagrafica' => $filters['anagrafica'],
            'origine'    => $filters['origine'],
            'iuv'        => $filters['iuv'],
        ];

        $rows        = [];
        $allRows     = [];
        $totals      = ['amount' => 0.0, 'count' => 0, 'count_interne' => 0, 'count_esterne' => 0];
        $byTipologia = [];
        $meta        = null;
        $csvLink     = null;
        $numPagine   = 1;
        $prevUrl     = null;
        $nextUrl     = null;

        $coverage = [];
        $mancantiPeriodi = [];

        if ($queryMade) {
            if (!$errors) {
                try {
                    $repo = new FlussiRendicontazioniRepository();

                    $totalRows = $repo->countForReport(
                        $filters['idDominio'],
                        $filters['dataDa'],
                        $filters['dataA'],
                        $standardTassonomie,
                        $extra,
                        $customTassonomie,
                        $ndSelected
                    );

                    $numPagine = max(1, (int)ceil($totalRows / self::PAGE_SIZE));
                    $currentPage = min($filters['page'], $numPagine);
                    $filters['page'] = $currentPage;
                    $offset = ($currentPage - 1) * self::PAGE_SIZE;

                    $rows = $repo->getForReport(
                        $filters['idDominio'],
                        $filters['dataDa'],
                        $filters['dataA'],
                        $standardTassonomie,
                        $offset,
                        self::PAGE_SIZE,
                        $extra,
                        $customTassonomie,
                        $ndSelected
                    );

                    $rows = $this->applyTaxonomyLabels($rows, $taxonomyLabels);

                    $aggregations = $repo->getReportAggregations(
                        $filters['idDominio'],
                        $filters['dataDa'],
                        $filters['dataA'],
                        $standardTassonomie,
                        $extra,
                        $customTassonomie,
                        $ndSelected
                    );

                    $byTipologia = $this->applyTaxonomyLabels($aggregations['by_tipologia'], $taxonomyLabels);

                    $totals = ['amount' => 0.0, 'count' => 0, 'count_interne' => 0, 'count_esterne' => 0];
                    foreach ($byTipologia as $item) {
                        $totals['amount']        += (float)$item['amount'];
                        $totals['count']         += (int)$item['count'];
                        $totals['count_interne'] += (int)($item['count_interne'] ?? 0);
                        $totals['count_esterne'] += (int)($item['count_esterne'] ?? 0);
                    }

                    $meta = [
                        'query_made' => true,
                        'flussi_processati' => $aggregations['flussi_processati'],
                        'rendicontazioni_totali' => $totalRows,
                        'tassonomie_filtrate' => $filters['tassonomie'],
                        'total_rows' => $totalRows,
                        'page_size' => self::PAGE_SIZE,
                        'page' => $currentPage,
                        'num_pagine' => $numPagine,
                    ];

                    if (($params['export'] ?? null) === 'csv') {
                        return $this->exportCsv($response, $filters);
                    }

                    $baseParams = $filters;
                    $baseParams['q'] = '1';
                    unset($baseParams['export']);

                    if ($currentPage > 1) {
                        $prevParams = $baseParams;
                        $prevParams['page'] = $currentPage - 1;
                        $prevUrl = '/pagamenti/report-ragioneria?' . http_build_query($prevParams);
                    }
                    if ($currentPage < $numPagine) {
                        $nextParams = $baseParams;
                        $nextParams['page'] = $currentPage + 1;
                        $nextUrl = '/pagamenti/report-ragioneria?' . http_build_query($nextParams);
                    }

                    $csvQuery = $baseParams;
                    $csvQuery['export'] = 'csv';
                    unset($csvQuery['page']);
                    $csvLink  = '/pagamenti/report-ragioneria?' . http_build_query($csvQuery);

                    // Calcolo scansione mensile
                    if ($filters['idDominio'] !== '') {
                        try {
                            $tefaRepo = new \App\Database\TefaRepository();
                            $queryDa = $filters['dataDa'] !== '' ? $filters['dataDa'] : '1970-01-01';
                            $queryA  = $filters['dataA'] !== '' ? $filters['dataA'] : '2099-12-31';
                            $coverage = $tefaRepo->getCoverage($queryDa, $queryA, $filters['idDominio']);

                            $daDate = new \DateTime($filters['dataDa'] !== '' ? $filters['dataDa'] : ($today->format('Y') - 1) . '-01-01');
                            $aDate  = new \DateTime($filters['dataA'] !== '' ? $filters['dataA'] : $today->format('Y-m-d'));

                            $mesiConDati = [];
                            foreach ($coverage as $c) {
                                $mesiConDati[$c['anno'] . '-' . $c['mese']] = true;
                            }

                            $current = clone $daDate;
                            $current->modify('first day of this month');
                            $end = clone $aDate;
                            $end->modify('first day of this month');

                            $missingMonths = [];
                            while ($current <= $end) {
                                $key = $current->format('Y-n');
                                if (!isset($mesiConDati[$key])) {
                                    $missingMonths[] = clone $current;
                                }
                                $current->modify('+1 month');
                            }

                            $ranges = [];
                            $tempRange = [];
                            foreach ($missingMonths as $m) {
                                if ($tempRange === []) {
                                    $tempRange[] = $m;
                                } else {
                                    $last = end($tempRange);
                                    $diff = $last->diff($m);
                                    $monthsDiff = ($diff->y * 12) + $diff->m;
                                    if ($monthsDiff === 1) {
                                        $tempRange[] = $m;
                                    } else {
                                        $ranges[] = $tempRange;
                                        $tempRange = [$m];
                                    }
                                }
                            }
                            if ($tempRange !== []) {
                                $ranges[] = $tempRange;
                            }

                            $mesiNomi = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
                            foreach ($ranges as $r) {
                                if (count($r) === 1) {
                                    $mancantiPeriodi[] = $mesiNomi[(int)$r[0]->format('n') - 1] . ' ' . $r[0]->format('Y');
                                } else {
                                    $first = $r[0];
                                    $last  = end($r);
                                    $mancantiPeriodi[] = $mesiNomi[(int)$first->format('n') - 1] . ' ' . $first->format('Y') . ' – ' . $mesiNomi[(int)$last->format('n') - 1] . ' ' . $last->format('Y');
                                }
                            }
                        } catch (\Throwable $_) {}
                    }

                } catch (\Throwable $e) {
                    $errors[] = 'Errore report ragioneria: ' . $e->getMessage();
                }
            }
        }

        return $this->twig->render($response, 'pagamenti/report_ragioneria.html.twig', [
            'filters'          => $filters,
            'query_made'       => $queryMade,
            'errors'           => $errors,
            'rows'             => $rows,
            'totals'           => $totals,
            'by_tipologia'     => $byTipologia,
            'meta'             => $meta,
            'cache_info'       => null,
            'num_pagine'       => $numPagine,
            'prev_url'         => $prevUrl,
            'next_url'         => $nextUrl,
            'refresh_cache_url' => null,
            'page_size'        => self::PAGE_SIZE,
            'tipologie_censite' => $tipologieCensite,
            'biz_counts'       => $bizCounts,
            'raw_payload_json' => null,
            'csv_link'         => $csvLink,
            'app_debug'        => $this->isAppDebug(),
            'coverage'         => $coverage,
            'mancanti_periodi' => $mancantiPeriodi,
        ]);
    }

    public function resetBizErrors(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $body = (array)($request->getParsedBody() ?? []);
        $idDominio = trim((string)($body['idDominio'] ?? SettingsRepository::get('entity', 'id_dominio', '')));
        $reset = 0;

        if ($idDominio !== '') {
            $reset = (new BizRepository())->resetErrors($idDominio);
        }

        $_SESSION['flash'][] = [
            'type' => 'success',
            'text' => sprintf('Reset errori Biz completato: %d pendenze riportate in PENDING.', $reset),
        ];

        $returnUrl = trim((string)($body['return_url'] ?? ''));
        if ($returnUrl === '' || str_starts_with($returnUrl, '/pagamenti/report-ragioneria') === false) {
            $returnUrl = '/pagamenti/report-ragioneria?q=1&idDominio=' . rawurlencode($idDominio);
        }

        return $response
            ->withHeader('Location', $returnUrl)
            ->withStatus(302);
    }

    private function exportCsv(Response $response, array $filters): Response
    {
        $filename = sprintf(
            'report-ragioneria-%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($filters['dataDa'] ?? 'da')),
            preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($filters['dataA'] ?? 'a'))
        );

        $taxonomyLabels = $this->loadTaxonomyLabels((string)($filters['idDominio'] ?? ''));

        $normalizedLabels = [];
        foreach ($taxonomyLabels as $code => $label) {
            $code = trim((string)$code);
            if ($code !== '') {
                $normalizedLabels[$code] = (string)$label;
                $normalizedLabels[strtoupper($code)] = (string)$label;
            }
        }

        $repo = new FlussiRendicontazioniRepository();
        $rows = $repo->getForCsvWithBiz(
            (string)($filters['idDominio'] ?? ''),
            (string)($filters['dataDa'] ?? ''),
            (string)($filters['dataA'] ?? ''),
            (array)($filters['tassonomie'] ?? [])
        );

        $stream = fopen('php://temp/maxmemory:2097152', 'r+');
        if ($stream === false) {
            return $response;
        }

        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'data_flusso', 'data_regolamento', 'id_flusso', 'trn', 'id_psp', 'ragione_psp',
            'id_dominio', 'tassonomia', 'tassonomia_label', 'iuv', 'iur', 'indice',
            'importo', 'esito', 'stato_rend', 'data_pagamento', 'descrizione_voce', 'id_pendenza',
            'govpay', 'fornitore',
            'biz_descrizione', 'cf_debitore', 'nominativo_debitore', 'cf_pagante', 'nominativo_pagante', 'biz_company_name',
        ], ';', '"', '');

        foreach ($rows as $row) {
            $tax = trim((string)($row['tassonomia'] ?? ''));
            $existingLabel = trim((string)($row['tassonomia_label'] ?? ''));
            if ($tax !== '' && !in_array($tax, ['TEFA', 'ESTERNA', 'N/D'], true)) {
                $resolved = $normalizedLabels[$tax] ?? $normalizedLabels[strtoupper($tax)] ?? $tax;
                $row['tassonomia_label'] = $resolved;
            } elseif ($existingLabel === '') {
                $row['tassonomia_label'] = $tax !== '' ? $tax : 'N/D';
            }

            fputcsv($stream, [
                (string)($row['data_flusso'] ?? ''),
                (string)($row['data_regolamento'] ?? ''),
                (string)($row['id_flusso'] ?? ''),
                (string)($row['trn'] ?? ''),
                (string)($row['id_psp'] ?? ''),
                (string)($row['ragione_psp'] ?? ''),
                (string)($row['id_dominio'] ?? ''),
                (string)($row['tassonomia'] ?? ''),
                (string)($row['tassonomia_label'] ?? ''),
                (string)($row['iuv'] ?? ''),
                (string)($row['iur'] ?? ''),
                (string)($row['indice'] ?? ''),
                number_format((float)($row['importo'] ?? 0), 2, '.', ''),
                (string)($row['esito'] ?? ''),
                (string)($row['stato_rend'] ?? ''),
                (string)($row['data_pagamento'] ?? ''),
                (string)($row['descrizione_voce'] ?? ''),
                (string)($row['id_pendenza'] ?? ''),
                $row['is_govpay'] === null ? '' : ((int)$row['is_govpay'] === 1 ? 'Si' : 'No'),
                (string)($row['fornitore'] ?? ''),
                (string)($row['biz_descrizione'] ?? ''),
                (string)($row['cf_debitore'] ?? ''),
                (string)($row['nominativo_debitore'] ?? ''),
                (string)($row['cf_pagante'] ?? ''),
                (string)($row['nominativo_pagante'] ?? ''),
                (string)($row['biz_company_name'] ?? ''),
            ], ';', '"', '');
        }

        rewind($stream);
        $body = $response->getBody();
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk !== false && $chunk !== '') {
                $body->write($chunk);
            }
        }
        fclose($stream);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**
     * @return array<int,string>
     */
    private function parseTaxonomySelection(array $params): array
    {
        $values = [];
        if (isset($params['tassonomie']) && is_array($params['tassonomie'])) {
            $values = $params['tassonomie'];
        } elseif (isset($params['tassonomia'])) {
            $legacy = trim((string)$params['tassonomia']);
            if ($legacy !== '') {
                $values = [$legacy];
            }
        }
        $result = [];
        foreach ($values as $part) {
            $p = trim((string)$part);
            if ($p !== '') {
                $result[] = $p;
            }
        }
        return array_values(array_unique($result));
    }

    private function parseStartDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $tz = new \DateTimeZone(self::REPORT_TIMEZONE);
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, $tz)
            ?: null;
    }

    private function parseEndDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $tz = new \DateTimeZone(self::REPORT_TIMEZONE);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, $tz)
            ?: null;
        return $dt?->setTime(23, 59, 59);
    }

    private function exposeCurrentUser(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (isset($_SESSION['user'])) {
            $this->twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }
    }

    private function isAppDebug(): bool
    {
        if (SettingsRepository::get('app', 'debug', 'false') === 'true') {
            return true;
        }
        $raw = getenv('APP_DEBUG');
        if ($raw === false) {
            return false;
        }
        return in_array(strtolower((string)$raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array<string,string>
     */
    private function loadTaxonomyLabels(string $idDominio): array
    {
        if ($idDominio === '') {
            return [];
        }

        $labels = [];
        $tipologieRepo = new EntrateRepository();
        foreach ($tipologieRepo->listByDominio($idDominio) as $tipologia) {
            $idEntrata = trim((string)($tipologia['id_entrata'] ?? ''));
            if ($idEntrata === '') {
                continue;
            }

            $label = trim((string)($tipologia['descrizione_effettiva'] ?? $tipologia['descrizione'] ?? $idEntrata));
            $labels[$idEntrata] = $label !== '' ? $label : $idEntrata;
        }

        // Aggiunge tipologie custom (mapping_tipologie_custom)
        try {
            foreach ((new MappingPendenzeRepository())->getCustomTipologie($idDominio) as $tc) {
                $cod  = trim((string)$tc['cod_entrata']);
                $desc = trim((string)$tc['descrizione']);
                if ($cod !== '') {
                    $labels[$cod] = $desc !== '' ? $desc : $cod;
                }
            }
        } catch (\Throwable $_) {}

        return $labels;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,string> $taxonomyLabels
     * @return array<int,array<string,mixed>>
     */
    private function applyTaxonomyLabels(array $rows, array $taxonomyLabels): array
    {
        $normalizedLabels = [];
        foreach ($taxonomyLabels as $code => $label) {
            $normalizedCode = trim((string)$code);
            if ($normalizedCode === '') {
                continue;
            }

            $normalizedLabels[$normalizedCode] = (string)$label;
            $normalizedLabels[strtoupper($normalizedCode)] = (string)$label;
        }

        foreach ($rows as &$row) {
            $tax = trim((string)($row['tassonomia'] ?? ''));
            $existingLabel = trim((string)($row['tassonomia_label'] ?? ''));

            if ($tax === '' || $tax === 'N/D') {
                $row['tassonomia_label'] = $existingLabel !== '' ? $existingLabel : 'N/D';
                $row['tassonomia_descrizione'] = $row['tassonomia_label'];
                continue;
            }

            if (in_array($tax, ['TEFA', 'ESTERNA'], true)) {
                $row['tassonomia_label'] = $existingLabel !== '' ? $existingLabel : $tax;
                $row['tassonomia_descrizione'] = $row['tassonomia_label'];
                continue;
            }

            $resolvedLabel = $normalizedLabels[$tax] ?? $normalizedLabels[strtoupper($tax)] ?? $tax;
            $row['tassonomia_label'] = $resolvedLabel;
            $row['tassonomia_descrizione'] = $resolvedLabel;
        }
        unset($row);

        return $rows;
    }


    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>}
     */
    private function buildAggregations(array $rows): array
    {
        $totals = ['amount' => 0.0, 'count' => 0];
        $byTipologia = [];

        foreach ($rows as $row) {
            $totals['amount'] += (float)($row['importo'] ?? 0);
            $totals['count']++;

            $tax = (string)($row['tassonomia'] ?? 'N/D');
            $label = (string)($row['tassonomia_label'] ?? $tax);
            if (!isset($byTipologia[$tax])) {
                $byTipologia[$tax] = [
                    'tassonomia' => $tax,
                    'tassonomia_label' => $label,
                    'count' => 0,
                    'amount' => 0.0,
                ];
            }
            $byTipologia[$tax]['count']++;
            $byTipologia[$tax]['amount'] += (float)($row['importo'] ?? 0);
        }

        return [$totals, array_values($byTipologia)];
    }
}
