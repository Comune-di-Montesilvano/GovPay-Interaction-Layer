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

    public function __construct(private readonly Twig $twig) {}

    public function index(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();

        $params      = (array)($request->getQueryParams() ?? []);
        $errors      = [];
        $sessionUser = $_SESSION['user'] ?? [];

        $today        = new \DateTimeImmutable('today');
        $defaultStart = $today->sub(new \DateInterval('P30D'));

        $filters = [
            'dataDa'     => (string)($params['dataDa'] ?? $defaultStart->format('Y-m-d')),
            'dataA'      => (string)($params['dataA'] ?? $today->format('Y-m-d')),
            'idDominio'  => (string)($params['idDominio'] ?? SettingsRepository::get('entity', 'id_dominio', '')),
            'tassonomie' => $this->parseTaxonomySelection($params),
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

        if ($dataDa && $dataA && $dataDa > $dataA) {
            $errors[] = 'Intervallo date non valido: la data iniziale supera la data finale.';
        }

        if ($filters['tassonomie'] === [] && $allowedTipologie === []) {
            $errors[] = 'Nessuna tipologia censita disponibile per il dominio selezionato.';
        }

        $rows        = [];
        $totals      = ['amount' => 0.0, 'count' => 0];
        $byTipologia = [];
        $meta        = null;
        $rawJson     = null;
        $csvLink     = null;

        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($backofficeUrl === '') {
            $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
        }

        if (!$errors) {
            try {
                $service = new GovPayRendicontazioniService();
                $result  = $service->findByTaxonomyFromFlussi(
                    $backofficeUrl,
                    (string)SettingsRepository::get('govpay', 'user', ''),
                    (string)SettingsRepository::get('govpay', 'password', ''),
                    $this->buildTlsOptions(),
                    [
                        'idDominio'  => $filters['idDominio'],
                        'dataDa'     => $this->formatDateForQuery($dataDa),
                        'dataA'      => $this->formatDateForQuery($dataA),
                        'tassonomie' => $filters['tassonomie'],
                        'collectRaw' => $this->isAppDebug(),
                    ]
                );

                $rows        = is_array($result['rows'] ?? null) ? $result['rows'] : [];
                $totals      = is_array($result['totals'] ?? null) ? $result['totals'] : $totals;
                $byTipologia = is_array($result['by_tipologia'] ?? null) ? $result['by_tipologia'] : [];
                $meta        = is_array($result['meta'] ?? null) ? $result['meta'] : null;

                if ($this->isAppDebug() && isset($result['raw'])) {
                    $rawJson = json_encode($result['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }

                $csvQuery = array_merge($filters, ['export' => 'csv']);
                $csvLink  = '/pagamenti/report-ragioneria?' . http_build_query($csvQuery);

                if (($params['export'] ?? null) === 'csv') {
                    return $this->exportCsv($response, $rows, $filters);
                }
            } catch (\Throwable $e) {
                $errors[] = 'Errore report ragioneria: ' . $e->getMessage();
            }
        }

        return $this->twig->render($response, 'pagamenti/report_ragioneria.html.twig', [
            'filters'          => $filters,
            'errors'           => $errors,
            'rows'             => $rows,
            'totals'           => $totals,
            'by_tipologia'     => $byTipologia,
            'meta'             => $meta,
            'tipologie_censite' => $tipologieCensite,
            'raw_payload_json' => $rawJson,
            'csv_link'         => $csvLink,
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
            'importo', 'esito', 'stato_rend', 'data_pagamento', 'descrizione_voce',
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
}
