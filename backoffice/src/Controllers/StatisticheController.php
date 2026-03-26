<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use GovPay\Backoffice\Model\RaggruppamentoStatistica;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class StatisticheController
{
    public function __construct(private readonly Twig $twig) {}

    public function index(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();

        $params = (array)($request->getQueryParams() ?? []);
        $errors = [];
        $today = new \DateTimeImmutable('today');
        $defaultStart = $today->sub(new \DateInterval('P30D'));
        $filters = [
            'dataDa' => (string)($params['dataDa'] ?? $defaultStart->format('Y-m-d')),
            'dataA' => (string)($params['dataA'] ?? $today->format('Y-m-d')),
            'raggruppamento' => strtoupper((string)($params['raggruppamento'] ?? RaggruppamentoStatistica::TIPO_PENDENZA)),
            'idDominio' => (string)($params['idDominio'] ?? (SettingsRepository::get('entity', 'id_dominio', ''))),
        ];
        $groupOptions = $this->getGroupOptions();
        if (!array_key_exists($filters['raggruppamento'], $groupOptions)) {
            $filters['raggruppamento'] = RaggruppamentoStatistica::TIPO_PENDENZA;
        }

        $dateFrom = $this->parseDate($filters['dataDa']);
        $dateTo = $this->parseDate($filters['dataA']);
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            $errors[] = 'Intervallo date non valido: la data iniziale supera la data finale.';
        }

        $stats = [];
        $statisticsJson = null;
        $chartPayloadJson = null;
        $totals = ['amount' => 0.0, 'count' => 0];

        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($backofficeUrl === '') {
            $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
        }
        if (!class_exists('GovPay\\Backoffice\\Api\\ReportisticaApi')) {
            $errors[] = 'Client Backoffice Reportistica non disponibile';
        }

        if (!$errors) {
            try {
                [$httpClient, $config] = $this->buildBackofficeClient($backofficeUrl);
                $api = new \GovPay\Backoffice\Api\ReportisticaApi($httpClient, $config);

                $result = $api->findQuadratureRiscossioni(
                    [$filters['raggruppamento']],
                    1,
                    200,
                    $this->formatDateForQuery($dateFrom),
                    $this->formatDateForQuery($dateTo),
                    $filters['idDominio'] ?: null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null,
                    null
                );

                $data = $this->convertToArray(\GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($result));
                $statisticsJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $stats = $this->normalizeStats($data['risultati'] ?? [], $filters['raggruppamento']);
                foreach ($stats as $row) {
                    $totals['amount'] += $row['importo'];
                    $totals['count'] += $row['numero_pagamenti'];
                }
                if ($stats) {
                    $chartPayload = [
                        'group' => $filters['raggruppamento'],
                        'labels' => array_column($stats, 'label'),
                        'amounts' => array_map(static fn(array $row): float => round($row['importo'], 2), $stats),
                        'counts' => array_column($stats, 'numero_pagamenti'),
                    ];
                    $chartPayloadJson = json_encode($chartPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            } catch (\Throwable $e) {
                $errors[] = 'Errore chiamata statistiche: ' . $e->getMessage();
            }
        }

        return $this->twig->render($response, 'statistiche.html.twig', [
            'filters' => $filters,
            'group_options' => $groupOptions,
            'errors' => $errors,
            'stats' => $stats,
            'totals' => $totals,
            'statistics_json' => $statisticsJson,
            'chart_payload_json' => $chartPayloadJson,
        ]);
    }

    private function buildBackofficeClient(string $baseUrl): array
    {
        $config = new \GovPay\Backoffice\Configuration();
        $config->setHost(rtrim($baseUrl, '/'));
        $username = SettingsRepository::get('govpay', 'user', '');
        $password = SettingsRepository::get('govpay', 'password', '');
        if ($username !== '' && $password !== '') {
            $config->setUsername($username);
            $config->setPassword($password);
        }

        $guzzleOptions = [];
        $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
        if (in_array(strtolower($authMethod), ['ssl', 'sslheader'], true)) {
            $cert    = SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key     = SettingsRepository::get('govpay', 'tls_key_path', '');
            $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert'] = $cert;
                $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            }
        }

        return [new Client($guzzleOptions), $config];
    }

    private function normalizeStats($raw, string $group): array
    {
        if (!is_iterable($raw)) {
            return [];
        }
        $rows = [];
        foreach ($raw as $entry) {
            if ($entry instanceof \GovPay\Backoffice\Model\StatisticaQuadratura) {
                $entry = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($entry);
            } elseif (!is_array($entry)) {
                $entry = json_decode(json_encode($entry, JSON_UNESCAPED_SLASHES), true) ?: [];
            }
            $label = $this->resolveLabel(is_array($entry) ? $entry : [], $group);
            $rows[] = [
                'label' => $label,
                'importo' => (float)($entry['importo'] ?? 0),
                'numero_pagamenti' => (int)($entry['numeroPagamenti'] ?? ($entry['numero_pagamenti'] ?? 0)),
            ];
        }
        return $rows;
    }

    private function resolveLabel(array $entry, string $group): string
    {
        return match ($group) {
            'DOMINIO' => $this->labelFromInfo($entry['dominio'] ?? $entry['dominio_index'] ?? []),
            'UNITA_OPERATIVA' => $this->labelFromInfo($entry['unitaOperativa'] ?? $entry['unita_operativa'] ?? []),
            'TIPO_PENDENZA' => $this->labelFromInfo($entry['tipoPendenza'] ?? $entry['tipo_pendenza'] ?? []),
            'APPLICAZIONE' => $this->labelFromInfo($entry['applicazione'] ?? []),
            'DIREZIONE' => (string)($entry['direzione'] ?? 'Direzione N/D'),
            'DIVISIONE' => (string)($entry['divisione'] ?? 'Divisione N/D'),
            'TASSONOMIA' => (string)($entry['tassonomia'] ?? 'Tassonomia N/D'),
            default => (string)($entry['dettaglio'] ?? 'Non disponibile'),
        };
    }

    private function labelFromInfo($info): string
    {
        if (is_string($info) && $info !== '') {
            return $info;
        }
        if (!is_array($info)) {
            return 'Non disponibile';
        }
        foreach (['descrizione', 'ragioneSociale', 'denominazione', 'nome', 'id', 'idDominio', 'idUnitaOperativa', 'idTipoPendenza'] as $field) {
            if (isset($info[$field]) && $info[$field] !== '') {
                return (string)$info[$field];
            }
        }
        return 'Non disponibile';
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value) ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        return $dt ?: null;
    }

    private function getGroupOptions(): array
    {
        $labels = [
                RaggruppamentoStatistica::TIPO_PENDENZA => 'Tipologia pendenza',
                RaggruppamentoStatistica::DOMINIO => 'Dominio',
                RaggruppamentoStatistica::UNITA_OPERATIVA => 'Unità operativa',
                RaggruppamentoStatistica::APPLICAZIONE => 'Applicazione',
                RaggruppamentoStatistica::DIREZIONE => 'Direzione',
                RaggruppamentoStatistica::DIVISIONE => 'Divisione',
                RaggruppamentoStatistica::TASSONOMIA => 'Tassonomia',
        ];
        return $labels;
    }

    private function convertToArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \stdClass) {
            return json_decode(json_encode($value, JSON_UNESCAPED_SLASHES), true) ?: [];
        }

        if (is_object($value)) {
            $sanitized = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($value);
            return $this->convertToArray($sanitized);
        }

        return (array)$value;
    }

        private function formatDateForQuery(?\DateTimeImmutable $value): ?string
        {
            if ($value === null) {
                return null;
            }

            // GovPay client expects ISO-8601 strings, not DateTime instances.
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
}
