<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\EntrateRepository;
use App\Database\FlussiRendicontazioniRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Report Ragioneria: incassi reali per data flusso di rendicontazione, filtrati per tassonomia.
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
        ];

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

        // Interseca selezione con tipologie ammesse
        $allowedTipologie = array_values(array_filter(
            array_map(static fn(array $r): string => (string)($r['id_entrata'] ?? ''), $tipologieCensite),
            static fn(string $v): bool => $v !== ''
        ));
        if ($filters['tassonomie'] !== []) {
            $filters['tassonomie'] = array_values(array_intersect($filters['tassonomie'], $allowedTipologie));
        }

        $taxonomyLabels = [];
        foreach ($tipologieCensite as $tipologia) {
            $idEntrata = (string)($tipologia['id_entrata'] ?? '');
            if ($idEntrata !== '') {
                $taxonomyLabels[$idEntrata] = (string)($tipologia['descrizione'] ?? $idEntrata);
            }
        }

        $dataDa = $this->parseStartDate($filters['dataDa']);
        $dataA  = $this->parseEndDate($filters['dataA']);

        if ($queryMade && $dataDa && $dataA && $dataDa > $dataA) {
            $errors[] = 'Intervallo date non valido: la data iniziale supera la data finale.';
        }

        if ($queryMade && $filters['tassonomie'] === [] && $allowedTipologie === []) {
            $errors[] = 'Nessuna tipologia censita disponibile per il dominio selezionato.';
        }

        $rows        = [];
        $allRows     = [];
        $totals      = ['amount' => 0.0, 'count' => 0];
        $byTipologia = [];
        $meta        = null;
        $csvLink     = null;
        $numPagine   = 1;
        $prevUrl     = null;
        $nextUrl     = null;

        if ($queryMade) {
            if (!$errors) {
                try {
                    $repo = new FlussiRendicontazioniRepository();

                    $totalRows = $repo->countForReport(
                        $filters['idDominio'],
                        $filters['dataDa'],
                        $filters['dataA'],
                        $filters['tassonomie']
                    );

                    $numPagine = max(1, (int)ceil($totalRows / self::PAGE_SIZE));
                    $currentPage = min($filters['page'], $numPagine);
                    $filters['page'] = $currentPage;
                    $offset = ($currentPage - 1) * self::PAGE_SIZE;

                    $rows = $repo->getForReport(
                        $filters['idDominio'],
                        $filters['dataDa'],
                        $filters['dataA'],
                        $filters['tassonomie'],
                        $offset,
                        self::PAGE_SIZE
                    );

                    $allRows = $totalRows > 0
                        ? $repo->getForReport(
                            $filters['idDominio'],
                            $filters['dataDa'],
                            $filters['dataA'],
                            $filters['tassonomie'],
                            0,
                            $totalRows
                        )
                        : [];

                    foreach ($allRows as &$row) {
                        $tax = (string)($row['tassonomia'] ?? '');
                        if ($tax !== '') {
                            $row['tassonomia_label'] = $taxonomyLabels[$tax] ?? (string)($row['tassonomia_label'] ?? $tax);
                        }
                        $row['tassonomia_descrizione'] = (string)($row['tassonomia_label'] ?? '');
                    }
                    unset($row);

                    foreach ($rows as &$row) {
                        $tax = (string)($row['tassonomia'] ?? '');
                        if ($tax !== '') {
                            $row['tassonomia_label'] = $taxonomyLabels[$tax] ?? (string)($row['tassonomia_label'] ?? $tax);
                        }
                    }
                    unset($row);

                    [$totals, $byTipologia] = $this->buildAggregations($allRows);
                    $meta = [
                        'query_made' => true,
                        'flussi_processati' => count(array_unique(array_filter(array_map(static fn(array $r): string => (string)($r['id_flusso'] ?? ''), $allRows)))),
                        'rendicontazioni_totali' => count($allRows),
                        'tassonomie_filtrate' => $filters['tassonomie'],
                        'total_rows' => $totalRows,
                        'page_size' => self::PAGE_SIZE,
                        'page' => $currentPage,
                        'num_pagine' => $numPagine,
                    ];

                    if (($params['export'] ?? null) === 'csv') {
                        return $this->exportCsv($response, $allRows, $filters);
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
            'raw_payload_json' => null,
            'csv_link'         => $csvLink,
            'app_debug'        => $this->isAppDebug(),
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function exportCsv(Response $response, array $rows, array $filters): Response
    {
        $filename = sprintf(
            'report-ragioneria-%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($filters['dataDa'] ?? 'da')),
            preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($filters['dataA'] ?? 'a'))
        );

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return $response;
        }

        fputcsv($stream, [
            'data_flusso', 'data_regolamento', 'id_flusso', 'trn', 'id_psp',
            'id_dominio', 'tassonomia', 'iuv', 'iur', 'indice',
            'tassonomia_descrizione', 'importo', 'esito', 'stato_rend', 'data_pagamento', 'descrizione_voce',
        ], ';');

        foreach ($rows as $row) {
            fputcsv($stream, [
                (string)($row['data_flusso'] ?? ''),
                (string)($row['data_regolamento'] ?? ''),
                (string)($row['id_flusso'] ?? ''),
                (string)($row['trn'] ?? ''),
                (string)($row['id_psp'] ?? ''),
                (string)($row['id_dominio'] ?? ''),
                (string)($row['tassonomia'] ?? ''),
                (string)($row['iuv'] ?? ''),
                (string)($row['iur'] ?? ''),
                (string)($row['indice'] ?? ''),
                (string)($row['tassonomia_descrizione'] ?? ''),
                number_format((float)($row['importo'] ?? 0), 2, '.', ''),
                (string)($row['esito'] ?? ''),
                (string)($row['stato_rend'] ?? ''),
                (string)($row['data_pagamento'] ?? ''),
                (string)($row['descrizione_voce'] ?? ''),
            ], ';');
        }

        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        $response->getBody()->write($csv);

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
