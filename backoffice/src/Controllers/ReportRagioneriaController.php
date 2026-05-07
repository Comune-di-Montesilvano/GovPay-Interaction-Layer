<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\EntrateRepository;
use App\Services\GovPayRendicontazioniService;
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
    private const CACHE_TTL_SECONDS = 31536000;
    private const PROGRESS_TTL_SECONDS = 7200;

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
            'gov_results_per_page' => min(200, max(1, (int)($params['gov_results_per_page'] ?? 100))),
            'gov_max_flussi_pages' => min(1000, max(1, (int)($params['gov_max_flussi_pages'] ?? 200))),
            'gov_metadati_paginazione' => $this->parseBooleanParam($params['gov_metadati_paginazione'] ?? 'true', true),
            'gov_max_risultati' => $this->parseBooleanParam($params['gov_max_risultati'] ?? 'true', true),
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
        $rawJson     = null;
        $csvLink     = null;
        $cacheInfo   = null;
        $numPagine   = 1;
        $prevUrl     = null;
        $nextUrl     = null;
        $refreshUrl  = null;

        if ($queryMade) {
            $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
            if ($backofficeUrl === '') {
                $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
            }

            $isAjaxRun = $this->parseBooleanParam($params['ajax_run'] ?? 'false', false);
            if ($isAjaxRun) {
                return $this->handleAjaxRun(
                    $response,
                    $params,
                    $filters,
                    $tipologieCensite,
                    $errors,
                    $backofficeUrl,
                    (int)($sessionUser['id'] ?? 0),
                    (string)($sessionUser['role'] ?? '')
                );
            }

            if (!$errors) {
                try {
                    $forceRefresh = $this->parseBooleanParam($params['refresh_cache'] ?? 'false', false);
                    $reportData = $this->executeReport(
                        $filters,
                        $tipologieCensite,
                        $backofficeUrl,
                        $forceRefresh,
                        null,
                        (int)($sessionUser['id'] ?? 0),
                        (string)($sessionUser['role'] ?? '')
                    );

                    $allRows = $reportData['rows'];
                    $totals = $reportData['totals'];
                    $byTipologia = $reportData['by_tipologia'];
                    $meta = $reportData['meta'];
                    $cacheInfo = $reportData['cache_info'];

                    if (($params['export'] ?? null) === 'csv') {
                        return $this->exportCsv($response, $allRows, $filters);
                    }

                    $totalRows = count($allRows);
                    $numPagine = max(1, (int)ceil($totalRows / self::PAGE_SIZE));
                    $currentPage = min($filters['page'], $numPagine);
                    $filters['page'] = $currentPage;
                    $offset = ($currentPage - 1) * self::PAGE_SIZE;
                    $rows = array_slice($allRows, $offset, self::PAGE_SIZE);

                    $baseParams = $filters;
                    $baseParams['q'] = '1';
                    unset($baseParams['export'], $baseParams['refresh_cache']);

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

                    $refreshQuery = $baseParams;
                    $refreshQuery['refresh_cache'] = '1';
                    unset($refreshQuery['export'], $refreshQuery['page']);
                    $refreshUrl = '/pagamenti/report-ragioneria?' . http_build_query($refreshQuery);

                    if (is_array($meta)) {
                        $meta['query_made'] = true;
                        $meta['total_rows'] = $totalRows;
                        $meta['page_size'] = self::PAGE_SIZE;
                        $meta['page'] = $currentPage;
                        $meta['num_pagine'] = $numPagine;
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
            'cache_info'       => $cacheInfo,
            'num_pagine'       => $numPagine,
            'prev_url'         => $prevUrl,
            'next_url'         => $nextUrl,
            'refresh_cache_url' => $refreshUrl,
            'page_size'        => self::PAGE_SIZE,
            'tipologie_censite' => $tipologieCensite,
            'raw_payload_json' => $rawJson,
            'csv_link'         => $csvLink,
        ]);
    }

    public function status(Request $request, Response $response): Response
    {
        $params = (array)($request->getQueryParams() ?? []);
        $token = trim((string)($params['token'] ?? ''));
        if ($token === '') {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Token mancante'], 400);
        }

        $status = $this->readProgress($token);
        if ($status === null) {
            return $this->jsonResponse($response, [
                'ok' => true,
                'status' => [
                    'state' => 'pending',
                    'phase' => 'pending',
                    'message' => 'Inizializzazione elaborazione...',
                    'percent' => 0,
                ],
            ]);
        }
        return $this->jsonResponse($response, ['ok' => true, 'status' => $status]);
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
     * @return array<string,mixed>
     */
    private function buildTlsOptions(): array
    {
        $guzzleOptions = [];
        $authMethod    = SettingsRepository::get('govpay', 'authentication_method', '');
        if (in_array(strtolower($authMethod), ['ssl', 'sslheader'], true)) {
            $cert    = SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key     = SettingsRepository::get('govpay', 'tls_key_path', '');
            $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert']    = $cert;
                $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            }
        }
        return $guzzleOptions;
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

    private function formatDateForQuery(?\DateTimeImmutable $value): ?string
    {
        return $value?->format(\DateTimeInterface::ATOM);
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

    private function parseBooleanParam(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        $raw = strtolower(trim((string)$value));
        if ($raw === '') {
            return $default;
        }
        if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return $default;
    }

    private function buildCacheKey(array $cacheFilters): string
    {
        ksort($cacheFilters);
        return hash('sha256', json_encode($cacheFilters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'report-ragioneria');
    }

    private function getCacheDir(): string
    {
        $cacheDir = '/var/www/cache';
        if ($this->ensureCacheDir($cacheDir)) {
            return $cacheDir;
        }
        throw new \RuntimeException('Directory cache non scrivibile: ' . $cacheDir);
    }

    private function getCacheFile(string $cacheKey): string
    {
        return $this->getCacheDir() . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    }

    private function loadCache(string $cacheKey): ?array
    {
        $file = $this->getCacheFile($cacheKey);
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $expiresAt = (int)($data['expires_at_ts'] ?? 0);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            unlink($file);
            return null;
        }
        return $data;
    }

    private function saveCache(string $cacheKey, array $payload): void
    {
        $dir = $this->getCacheDir();
        if (!is_dir($dir)) {
            error_log('[ReportRagioneriaController] Cache directory non disponibile: ' . $dir);
            return;
        }

        $now = time();
        $ttl = self::CACHE_TTL_SECONDS;
        $cachePayload = array_merge($payload, [
            'stored_at' => date(DATE_ATOM, $now),
            'stored_at_ts' => $now,
            'expires_at' => date(DATE_ATOM, $now + $ttl),
            'expires_at_ts' => $now + $ttl,
        ]);

        $encoded = json_encode($cachePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            error_log('[ReportRagioneriaController] Impossibile serializzare payload cache key ' . $cacheKey);
            return;
        }

        $bytes = @file_put_contents($this->getCacheFile($cacheKey), $encoded, LOCK_EX);
        if ($bytes === false) {
            error_log('[ReportRagioneriaController] Scrittura cache fallita per key ' . $cacheKey . ' in ' . $dir);
        }
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $filters
     * @param array<int,array<string,mixed>> $tipologieCensite
     * @param array<int,string> $errors
     */
    private function handleAjaxRun(
        Response $response,
        array $params,
        array $filters,
        array $tipologieCensite,
        array $errors,
        string $backofficeUrl,
        int $userId,
        string $userRole
    ): Response {
        if ($errors !== []) {
            return $this->jsonResponse($response, ['ok' => false, 'errors' => $errors], 422);
        }

        $token = trim((string)($params['progress_token'] ?? ''));
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
        }

        $this->writeProgress($token, [
            'state' => 'running',
            'phase' => 'avvio',
            'message' => 'Avvio elaborazione report',
            'percent' => 1,
            'updated_at' => date(DATE_ATOM),
        ]);

        // Rilascia il lock della sessione: permette al polling /status di rispondere
        // mentre questa richiesta resta occupata nell'elaborazione lunga.
        $this->releaseSessionLock();

        try {
            $reportData = $this->executeReport(
                $filters,
                $tipologieCensite,
                $backofficeUrl,
                $this->parseBooleanParam($params['refresh_cache'] ?? 'false', false),
                $token,
                $userId,
                $userRole
            );

            $this->writeProgress($token, [
                'state' => 'completed',
                'phase' => 'completed',
                'message' => 'Elaborazione completata',
                'percent' => 100,
                'current_flow_id' => null,
                'updated_at' => date(DATE_ATOM),
            ]);

            $redirectParams = $filters;
            $redirectParams['q'] = '1';
            unset($redirectParams['ajax_run'], $redirectParams['progress_token']);

            return $this->jsonResponse($response, [
                'ok' => true,
                'token' => $token,
                'cache_source' => (string)($reportData['cache_info']['source'] ?? ''),
                'redirect_url' => '/pagamenti/report-ragioneria?' . http_build_query($redirectParams),
            ]);
        } catch (\Throwable $e) {
            $this->writeProgress($token, [
                'state' => 'failed',
                'phase' => 'failed',
                'message' => $e->getMessage(),
                'updated_at' => date(DATE_ATOM),
            ]);
            return $this->jsonResponse($response, ['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<int,array<string,mixed>> $tipologieCensite
     * @return array{rows:array<int,array<string,mixed>>,totals:array<string,mixed>,by_tipologia:array<int,array<string,mixed>>,meta:array<string,mixed>,cache_info:array<string,mixed>}
     */
    private function executeReport(
        array $filters,
        array $tipologieCensite,
        string $backofficeUrl,
        bool $forceRefresh,
        ?string $progressToken,
        int $userId,
        string $userRole
    ): array {
        $taxonomyLabels = [];
        foreach ($tipologieCensite as $tipologia) {
            $idEntrata = (string)($tipologia['id_entrata'] ?? '');
            if ($idEntrata === '') {
                continue;
            }
            $taxonomyLabels[$idEntrata] = (string)($tipologia['descrizione'] ?? '');
        }

        $fullCacheFilters = [
            'kind' => 'full-v2',
            'idDominio' => $filters['idDominio'],
            'dataDa' => $filters['dataDa'],
            'dataA' => $filters['dataA'],
            'tassonomie' => $filters['tassonomie'],
            'gov_results_per_page' => $filters['gov_results_per_page'],
            'gov_max_flussi_pages' => $filters['gov_max_flussi_pages'],
            'gov_metadati_paginazione' => $filters['gov_metadati_paginazione'] ? 1 : 0,
            'gov_max_risultati' => $filters['gov_max_risultati'] ? 1 : 0,
            'user_id' => $userId,
            'user_role' => $userRole,
        ];
        $fullCacheKey = $this->buildCacheKey($fullCacheFilters);
        if (!$forceRefresh) {
            $cachedPayload = $this->loadCache($fullCacheKey);
            if (is_array($cachedPayload)) {
                return [
                    'rows' => is_array($cachedPayload['rows'] ?? null) ? $cachedPayload['rows'] : [],
                    'totals' => is_array($cachedPayload['totals'] ?? null) ? $cachedPayload['totals'] : ['amount' => 0.0, 'count' => 0],
                    'by_tipologia' => is_array($cachedPayload['by_tipologia'] ?? null) ? $cachedPayload['by_tipologia'] : [],
                    'meta' => is_array($cachedPayload['meta'] ?? null) ? $cachedPayload['meta'] : [],
                    'cache_info' => [
                        'source' => 'cache',
                        'stored_at' => (string)($cachedPayload['stored_at'] ?? ''),
                        'expires_at' => (string)($cachedPayload['expires_at'] ?? ''),
                    ],
                ];
            }
        }

        $start = $this->parseStartDate((string)$filters['dataDa']);
        $end = $this->parseEndDate((string)$filters['dataA']);
        if ($start === null || $end === null) {
            throw new \RuntimeException('Intervallo date non valido.');
        }

        $dailyBaseKey = $this->buildCacheKey([
            'kind' => 'daily-v1',
            'idDominio' => $filters['idDominio'],
            'gov_results_per_page' => $filters['gov_results_per_page'],
            'gov_max_flussi_pages' => $filters['gov_max_flussi_pages'],
            'gov_metadati_paginazione' => $filters['gov_metadati_paginazione'] ? 1 : 0,
            'gov_max_risultati' => $filters['gov_max_risultati'] ? 1 : 0,
            'user_id' => $userId,
            'user_role' => $userRole,
        ]);

        $dayList = $this->buildDayList($start, $end);
        $rowsByDay = [];
        $missingDays = [];
        foreach ($dayList as $day) {
            $dayRows = $forceRefresh ? null : $this->loadDailyCache($dailyBaseKey, $day);
            if ($dayRows === null) {
                $missingDays[] = $day;
                continue;
            }
            $rowsByDay[$day] = $dayRows;
        }

        if ($progressToken !== null) {
            $this->writeProgress($progressToken, [
                'state' => 'running',
                'phase' => 'cache_lookup',
                'message' => 'Verifica giorni in cache',
                'cached_days' => count($dayList) - count($missingDays),
                'missing_days' => count($missingDays),
                'percent' => 5,
                'updated_at' => date(DATE_ATOM),
            ]);
        }

        $meta = [
            'flussi_processati' => 0,
            'rendicontazioni_totali' => 0,
            'tassonomie_filtrate' => $filters['tassonomie'],
            'cache_days_total' => count($dayList),
            'cache_days_missing' => count($missingDays),
        ];

        if ($missingDays !== []) {
            $ranges = $this->buildContiguousDayRanges($missingDays);
            $service = new GovPayRendicontazioniService();
            foreach ($ranges as $range) {
                $rangeStart = $this->parseStartDate($range['from']);
                $rangeEnd = $this->parseEndDate($range['to']);
                if ($rangeStart === null || $rangeEnd === null) {
                    continue;
                }

                $result = $service->findByTaxonomyFromFlussi(
                    $backofficeUrl,
                    (string)SettingsRepository::get('govpay', 'user', ''),
                    (string)SettingsRepository::get('govpay', 'password', ''),
                    $this->buildTlsOptions(),
                    [
                        'idDominio'  => $filters['idDominio'],
                        'dataDa'     => $this->formatDateForQuery($rangeStart),
                        'dataA'      => $this->formatDateForQuery($rangeEnd),
                        'tassonomie' => [],
                        'collectRaw' => false,
                        'excludeNd'  => true,
                        'taxonomyLabels' => $taxonomyLabels,
                        'resultsPerPage' => $filters['gov_results_per_page'],
                        'maxFlussiPages' => $filters['gov_max_flussi_pages'],
                        'metadatiPaginazione' => $filters['gov_metadati_paginazione'],
                        'maxRisultati' => $filters['gov_max_risultati'],
                        'progressCallback' => function (array $status) use ($progressToken): void {
                            if ($progressToken === null) {
                                return;
                            }
                            $phase = (string)($status['phase'] ?? 'running');
                            $processed = (int)($status['processed_flussi'] ?? 0);
                            $total = (int)($status['total_flussi'] ?? 0);
                            $percent = 10;
                            if ($total > 0) {
                                $percent = min(95, max(10, (int)round(($processed / $total) * 90)));
                            }
                            $message = 'Elaborazione in corso';
                            if ($phase === 'fetch_flussi') {
                                $message = 'Recupero lista flussi';
                            } elseif ($phase === 'fetch_dettagli') {
                                $message = 'Scarico dettaglio flusso ' . (string)($status['current_flow_id'] ?? '');
                            } elseif ($phase === 'aggregazione') {
                                $message = 'Aggregazione risultati';
                            }
                            $this->writeProgress($progressToken, [
                                'state' => 'running',
                                'phase' => $phase,
                                'message' => $message,
                                'current_flow_id' => (string)($status['current_flow_id'] ?? ''),
                                'processed_flussi' => $processed,
                                'total_flussi' => $total,
                                'percent' => $percent,
                                'updated_at' => date(DATE_ATOM),
                            ]);
                        },
                    ]
                );

                $chunkRows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
                foreach ($chunkRows as $row) {
                    $day = substr((string)($row['data_flusso'] ?? ''), 0, 10);
                    if ($day === '') {
                        continue;
                    }
                    if (!isset($rowsByDay[$day])) {
                        $rowsByDay[$day] = [];
                    }
                    $rowsByDay[$day][] = $row;
                }

                $rangeDays = $this->buildDayList($rangeStart, $rangeEnd);
                foreach ($rangeDays as $day) {
                    $this->saveDailyCache($dailyBaseKey, $day, $rowsByDay[$day] ?? []);
                }

                $chunkMeta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
                $meta['flussi_processati'] += (int)($chunkMeta['flussi_processati'] ?? 0);
            }
        }

        $allRows = [];
        foreach ($dayList as $day) {
            $rowsForDay = $rowsByDay[$day] ?? [];
            foreach ($rowsForDay as $row) {
                $allRows[] = $row;
            }
        }

        if ($filters['tassonomie'] !== []) {
            $wanted = array_flip($filters['tassonomie']);
            $allRows = array_values(array_filter($allRows, static function (array $row) use ($wanted): bool {
                $tax = (string)($row['tassonomia'] ?? '');
                return isset($wanted[$tax]);
            }));
        }

        usort($allRows, static function (array $a, array $b): int {
            return strcmp((string)($b['data_flusso'] ?? ''), (string)($a['data_flusso'] ?? ''));
        });

        $totals = ['amount' => 0.0, 'count' => 0];
        $byTipologia = [];
        foreach ($allRows as $row) {
            $totals['amount'] += (float)($row['importo'] ?? 0);
            $totals['count']++;
            $t = (string)($row['tassonomia'] ?? '');
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

        $meta['rendicontazioni_totali'] = count($allRows);
        $payload = [
            'rows' => $allRows,
            'totals' => $totals,
            'by_tipologia' => array_values($byTipologia),
            'meta' => $meta,
        ];
        $this->saveCache($fullCacheKey, $payload);

        return [
            'rows' => $allRows,
            'totals' => $totals,
            'by_tipologia' => array_values($byTipologia),
            'meta' => $meta,
            'cache_info' => [
                'source' => $missingDays === [] ? 'cache-partial' : 'live-partial',
            ],
        ];
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }

    /** @return array<int,string> */
    private function buildDayList(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $days = [];
        $cursor = $start->setTime(0, 0, 0);
        $until = $end->setTime(0, 0, 0);
        while ($cursor <= $until) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->add(new \DateInterval('P1D'));
        }
        return $days;
    }

    /**
     * @param array<int,string> $days
     * @return array<int,array{from:string,to:string}>
     */
    private function buildContiguousDayRanges(array $days): array
    {
        if ($days === []) {
            return [];
        }
        sort($days);
        $ranges = [];
        $start = $days[0];
        $prev = $days[0];
        for ($i = 1; $i < count($days); $i++) {
            $current = $days[$i];
            $prevDate = new \DateTimeImmutable($prev);
            $expected = $prevDate->add(new \DateInterval('P1D'))->format('Y-m-d');
            if ($current !== $expected) {
                $ranges[] = ['from' => $start, 'to' => $prev];
                $start = $current;
            }
            $prev = $current;
        }
        $ranges[] = ['from' => $start, 'to' => $prev];
        return $ranges;
    }

    private function getDailyCacheFile(string $baseKey, string $day): string
    {
        return $this->getCacheDir() . DIRECTORY_SEPARATOR . 'daily-' . $baseKey . '-' . $day . '.json';
    }

    /** @return array<int,array<string,mixed>>|null */
    private function loadDailyCache(string $baseKey, string $day): ?array
    {
        $file = $this->getDailyCacheFile($baseKey, $day);
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $expiresAt = (int)($data['expires_at_ts'] ?? 0);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            unlink($file);
            return null;
        }
        $rows = $data['rows'] ?? null;
        return is_array($rows) ? $rows : [];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function saveDailyCache(string $baseKey, string $day, array $rows): void
    {
        $payload = [
            'rows' => $rows,
            'stored_at_ts' => time(),
            'expires_at_ts' => time() + self::CACHE_TTL_SECONDS,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return;
        }
        @file_put_contents($this->getDailyCacheFile($baseKey, $day), $encoded, LOCK_EX);
    }

    private function getProgressFile(string $token): string
    {
        return $this->getCacheDir() . DIRECTORY_SEPARATOR . 'progress-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) . '.json';
    }

    private function writeProgress(string $token, array $payload): void
    {
        $payload['expires_at_ts'] = time() + self::PROGRESS_TTL_SECONDS;
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return;
        }
        @file_put_contents($this->getProgressFile($token), $encoded, LOCK_EX);
    }

    private function readProgress(string $token): ?array
    {
        $file = $this->getProgressFile($token);
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $expiresAt = (int)($data['expires_at_ts'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < time()) {
            unlink($file);
            return null;
        }
        return $data;
    }

    private function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function ensureCacheDir(string $dir): bool
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        return is_writable($dir);
    }
}
