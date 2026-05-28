<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Database\EntrateRepository;
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
            'raggruppamento' => strtoupper((string)($params['raggruppamento'] ?? 'TIPO_PENDENZA')),
            'idDominio' => (string)($params['idDominio'] ?? SettingsRepository::get('entity', 'id_dominio', '')),
        ];

        $groupOptions = $this->getGroupOptions();
        if (!array_key_exists($filters['raggruppamento'], $groupOptions)) {
            $filters['raggruppamento'] = 'TIPO_PENDENZA';
        }

        $dateFrom = $this->parseDate($filters['dataDa']);
        $dateTo = $this->parseDate($filters['dataA']);
        if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
            $errors[] = 'Intervallo date non valido: la data iniziale supera la data finale.';
        }

        if ($filters['idDominio'] === '') {
            $errors[] = 'ID Dominio non configurato.';
        }

        $stats = [];
        $chartPayloadJson = null;
        $totals = ['amount' => 0.0, 'count' => 0];

        if (!$errors) {
            try {
                $repo = new FlussiRendicontazioniRepository();
                $stats = $repo->getLocalStatistics(
                    $filters['idDominio'],
                    $filters['dataDa'],
                    $filters['dataA'],
                    $filters['raggruppamento']
                );

                // Applica label della tassonomia locale se raggruppato per TIPO_PENDENZA
                if ($filters['raggruppamento'] === 'TIPO_PENDENZA') {
                    $taxonomyLabels = $this->loadTaxonomyLabels($filters['idDominio']);
                    $normalizedLabels = [];
                    foreach ($taxonomyLabels as $code => $label) {
                        $normalizedCode = trim((string)$code);
                        if ($normalizedCode !== '') {
                            $normalizedLabels[$normalizedCode] = (string)$label;
                            $normalizedLabels[strtoupper($normalizedCode)] = (string)$label;
                        }
                    }

                    foreach ($stats as &$row) {
                        $tax = trim((string)$row['label']);
                        if ($tax === 'ESTERNA') {
                            $row['label'] = 'Pendenze esterne';
                        } elseif ($tax !== '' && $tax !== 'N/D' && $tax !== 'TEFA') {
                            $row['label'] = $normalizedLabels[$tax] ?? $normalizedLabels[strtoupper($tax)] ?? $tax;
                        }
                    }
                    unset($row);
                }

                // Calcola i totali complessivi
                foreach ($stats as $row) {
                    $totals['amount'] += (float)$row['importo'];
                    $totals['count'] += (int)$row['numero_pagamenti'];
                }

                if ($stats !== []) {
                    $chartPayload = [
                        'group' => $filters['raggruppamento'],
                        'labels' => array_column($stats, 'label'),
                        'amounts' => array_map(static fn(array $row): float => round((float)$row['importo'], 2), $stats),
                        'counts' => array_column($stats, 'numero_pagamenti'),
                    ];
                    $chartPayloadJson = json_encode($chartPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            } catch (\Throwable $e) {
                $errors[] = 'Errore calcolo statistiche locali: ' . $e->getMessage();
            }
        }

        return $this->twig->render($response, 'statistiche.html.twig', [
            'filters' => $filters,
            'group_options' => $groupOptions,
            'errors' => $errors,
            'stats' => $stats,
            'totals' => $totals,
            'chart_payload_json' => $chartPayloadJson,
            'app_debug' => $this->isAppDebug(),
        ]);
    }

    private function getGroupOptions(): array
    {
        return [
            'TIPO_PENDENZA' => 'Tipologia pendenza (Tassonomia)',
            'FORNITORE'     => 'Partner / Fornitore Tecnologico',
            'CANALE'        => 'Canale d\'Incasso',
            'PSP'           => 'Prestatore Servizi (PSP)',
        ];
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value) ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
        return $dt ?: null;
    }

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

        return $labels;
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
