<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\EntrateRepository;
use App\Services\GovPayTaxonomyCollectionsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class IncassiTassonomiaController
{
    private const REPORT_TIMEZONE = 'Europe/Rome';

    public function __construct(private readonly Twig $twig) {}

    public function index(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();

        $params = (array)($request->getQueryParams() ?? []);
        $errors = [];
        $sessionUser = $_SESSION['user'] ?? [];

        $today = new \DateTimeImmutable('today');
        $defaultStart = $today->sub(new \DateInterval('P30D'));

        $filters = [
            'dataDa' => (string)($params['dataDa'] ?? $defaultStart->format('Y-m-d')),
            'dataA' => (string)($params['dataA'] ?? $today->format('Y-m-d')),
            'idDominio' => (string)($params['idDominio'] ?? SettingsRepository::get('entity', 'id_dominio', '')),
            'tassonomie' => $this->parseTaxonomySelection($params),
        ];

        $tipologieRepo = new EntrateRepository();
        $tipologieCensite = [];
        if ($filters['idDominio'] !== '') {
            $userId = (int)($sessionUser['id'] ?? 0);
            $userRole = (string)($sessionUser['role'] ?? '');
            if ($userId > 0 && $userRole !== '') {
                $tipologieCensite = $tipologieRepo->listAbilitateByDominioForUser($filters['idDominio'], $userId, $userRole);
            } else {
                $tipologieCensite = $tipologieRepo->listAbilitateByDominio($filters['idDominio']);
            }
        }

        $allowedTipologie = array_map(static fn(array $r): string => (string)($r['id_entrata'] ?? ''), $tipologieCensite);
        $allowedTipologie = array_values(array_filter($allowedTipologie, static fn(string $v): bool => $v !== ''));
        $filters['tassonomie'] = array_values(array_intersect($filters['tassonomie'], $allowedTipologie));

        $dataDa = $this->parseStartDate($filters['dataDa']);
        $dataA = $this->parseEndDate($filters['dataA']);

        if ($dataDa && $dataA && $dataDa > $dataA) {
            $errors[] = 'Intervallo date non valido: la data iniziale supera la data finale.';
        }

        if (!$filters['tassonomie']) {
            $errors[] = 'Selezionare almeno una tassonomia tra le tipologie censite.';
        }

        $rows = [];
        $totals = ['amount' => 0.0, 'count' => 0];
        $meta = null;
        $rawPayloadJson = null;
        $csvLink = null;

        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($backofficeUrl === '') {
            $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
        }

        if (!class_exists('GovPay\\Backoffice\\Api\\RiscossioniApi')) {
            $errors[] = 'Client Backoffice Riscossioni non disponibile';
        }

        if (!$errors) {
            try {
                $service = new GovPayTaxonomyCollectionsService();

                $result = $service->findByTaxonomy(
                    $backofficeUrl,
                    (string)SettingsRepository::get('govpay', 'user', ''),
                    (string)SettingsRepository::get('govpay', 'password', ''),
                    $this->buildTlsOptions(),
                    [
                        'idDominio' => $filters['idDominio'],
                        'dataDa' => $this->formatDateForQuery($dataDa),
                        'dataA' => $this->formatDateForQuery($dataA),
                        'tassonomie' => $filters['tassonomie'],
                        'collectRaw' => $this->isAppDebug(),
                    ]
                );

                $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
                $totals = is_array($result['totals'] ?? null) ? $result['totals'] : $totals;
                $meta = is_array($result['meta'] ?? null) ? $result['meta'] : null;
                if ($this->isAppDebug() && isset($result['raw'])) {
                    $rawPayloadJson = json_encode($result['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }

                $query = array_merge($filters, ['export' => 'csv']);
                $csvLink = '/pagamenti/incassi-tassonomia?' . http_build_query($query);

                if (($params['export'] ?? null) === 'csv') {
                    return $this->exportCsv($response, $rows, $filters);
                }
            } catch (\Throwable $e) {
                $errors[] = 'Errore chiamata riscossioni: ' . $e->getMessage();
            }
        }

        return $this->twig->render($response, 'pagamenti/incassi_tassonomia.html.twig', [
            'filters' => $filters,
            'errors' => $errors,
            'rows' => $rows,
            'totals' => $totals,
            'meta' => $meta,
            'tipologie_censite' => $tipologieCensite,
            'raw_payload_json' => $rawPayloadJson,
            'csv_link' => $csvLink,
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    private function exportCsv(Response $response, array $rows, array $filters): Response
    {
        $filename = sprintf(
            'incassi-tassonomia-%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($filters['dataDa'] ?? 'da')),
            preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($filters['dataA'] ?? 'a'))
        );

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return $response;
        }

        fputcsv($stream, ['tassonomia', 'data', 'id_dominio', 'iuv', 'iur', 'indice', 'stato', 'tipo', 'importo', 'id_voce_pendenza', 'incasso'], ';');

        foreach ($rows as $row) {
            fputcsv($stream, [
                (string)($row['tassonomia'] ?? ''),
                (string)($row['data'] ?? ''),
                (string)($row['id_dominio'] ?? ''),
                (string)($row['iuv'] ?? ''),
                (string)($row['iur'] ?? ''),
                (string)($row['indice'] ?? ''),
                (string)($row['stato'] ?? ''),
                (string)($row['tipo'] ?? ''),
                (string)number_format((float)($row['importo'] ?? 0), 2, '.', ''),
                (string)($row['id_voce_pendenza'] ?? ''),
                (string)($row['incasso'] ?? ''),
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
        $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
        if (in_array(strtolower($authMethod), ['ssl', 'sslheader'], true)) {
            $cert = SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key = SettingsRepository::get('govpay', 'tls_key_path', '');
            $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert'] = $cert;
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

        if (!$values) {
            return [];
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

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, $tz);

        return $dt ?: null;
    }

    private function parseEndDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $tz = new \DateTimeZone(self::REPORT_TIMEZONE);

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $value, $tz);

        if (!$dt) {
            return null;
        }

        return $dt->setTime(23, 59, 59);
    }

    private function formatDateForQuery(?\DateTimeImmutable $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->format(\DateTimeInterface::ATOM);
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

        $appDebugRaw = getenv('APP_DEBUG');
        if ($appDebugRaw === false) {
            return false;
        }

        return in_array(strtolower((string)$appDebugRaw), ['1', 'true', 'yes', 'on'], true);
    }
}
