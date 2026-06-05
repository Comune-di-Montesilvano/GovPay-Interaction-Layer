<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Database\MappingPendenzeRepository;
use GuzzleHttp\Client;
use GovPay\Pendenze\Api\PendenzeApi;
use GovPay\Pendenze\Configuration as PendenzeConfiguration;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use App\Database\Connection;
use App\Database\BizRepository;
use App\Database\TefaRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Config\SettingsRepository;
use App\Controllers\CronController;

class HomeController
{
    public function __construct(private readonly Twig $twig)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $debug = '';
        $apiClass = PendenzeApi::class;
        if (class_exists($apiClass)) {
            $debug .= "Classe trovata: {$apiClass}\n";
            try {
                $client = new Client();
                new PendenzeApi($client, new PendenzeConfiguration());
                $debug .= "Istanza API creata con successo.\n";
            } catch (\Throwable $e) {
                $debug .= 'Errore: ' . $e->getMessage() . "\n";
            }
        } else {
            $debug .= "Classe API non trovata.\n";
        }

        if (isset($_SESSION['user'])) {
            $this->twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }

        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        // Stato dei demoni GIL
        $daemons = [];
        foreach (['biz', 'tefa', 'ragioneria', 'pendenze-massive'] as $key) {
            try {
                $daemons[$key] = [
                    'running' => CronController::isDaemonRunning($key),
                    'label' => CronController::getJobs()[$key]['label'] ?? $key,
                    'icon' => CronController::getJobs()[$key]['icon'] ?? 'fa-cogs',
                ];
            } catch (\Throwable $_) {
                $daemons[$key] = [
                    'running' => false,
                    'label' => $key,
                    'icon' => 'fa-cogs',
                ];
            }
        }

        $scanDa = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
        $scanDaFormatted = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa)) {
            try {
                $scanDaFormatted = (new \DateTime($scanDa))->format('d/m/Y');
            } catch (\Throwable $_) {}
        }
        if ($scanDaFormatted === '') {
            $fallbackDate = date('Y-01-01', strtotime('-1 year'));
            try {
                $scanDaFormatted = (new \DateTime($fallbackDate))->format('d/m/Y') . ' (Default)';
            } catch (\Throwable $_) {}
        }

        return $this->twig->render($response, 'home.html.twig', [
            'id_dominio' => $idDominio,
            'daemons' => $daemons,
            'ragioneria_scan_da_formatted' => $scanDaFormatted,
            'debug' => nl2br(htmlspecialchars($debug)),
        ]);
    }

    public function apiStats(Request $request, Response $response): Response
    {
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $pdo = Connection::getPDO();
        $bizRepo = new BizRepository($pdo);
        $tefaRepo = new TefaRepository($pdo);
        $flussiRepo = new FlussiRendicontazioniRepository($pdo);

        // 1. Volumi e numero transazioni complessive
        $totals = ['amount' => 0.0, 'count' => 0];
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS c, SUM(importo) AS a FROM flussi_rendicontazioni WHERE id_dominio = :dom');
            $stmt->execute([':dom' => $idDominio]);
            $row = $stmt->fetch();
            if ($row) {
                $totals['count'] = (int)$row['c'];
                $totals['amount'] = (float)($row['a'] ?? 0.0);
            }
        } catch (\Throwable $_) {}

        // 2. Biz Events e TEFA Counts
        $bizCounts = ['PENDING' => 0, 'PROCESSED' => 0, 'ERROR' => 0, 'SKIPPED' => 0, 'total' => 0];
        $tefaCounts = ['PENDING' => 0, 'PROCESSED' => 0, 'ERROR' => 0, 'SKIPPED' => 0, 'total' => 0];
        try {
            $bizCounts = $bizRepo->getCounts($idDominio);
        } catch (\Throwable $_) {}
        try {
            $tefaCounts = $tefaRepo->getCounts($idDominio);
        } catch (\Throwable $_) {}

        // 3. Code rimanenti da processare
        $bizQueueRemaining = 0;
        $tefaQueueRemaining = 0;
        try {
            $bizQueueRemaining = $flussiRepo->countUnprocessedForBiz($idDominio);
        } catch (\Throwable $_) {}
        try {
            $tefaQueueRemaining = $bizRepo->countProcessedForTefa($idDominio);
        } catch (\Throwable $_) {}

        // 4. Ripartizione canali d'incasso (GovPay vs Esterno) per doughnut chart
        $channelsData = ['govpay' => 0, 'external' => 0];
        try {
            $stmt = $pdo->prepare('
                SELECT is_govpay, COUNT(*) as c
                FROM flussi_rendicontazioni
                WHERE id_dominio = :dom
                GROUP BY is_govpay
            ');
            $stmt->execute([':dom' => $idDominio]);
            foreach ($stmt->fetchAll() as $row) {
                $isGovpay = (int)($row['is_govpay'] ?? 0);
                if ($isGovpay === 1) {
                    $channelsData['govpay'] += (int)$row['c'];
                } else {
                    $channelsData['external'] += (int)$row['c'];
                }
            }
        } catch (\Throwable $_) {}

        // 5. Andamento mensile ultimi 6 mesi (importi e transazioni) per combo chart
        $monthlyTrend = [];
        try {
            $stmt = $pdo->prepare('
                SELECT 
                    anno, 
                    mese, 
                    COUNT(*) AS count, 
                    SUM(importo) AS amount,
                    SUM(CASE WHEN is_govpay = 1 THEN importo ELSE 0 END) AS amount_internal,
                    SUM(CASE WHEN is_govpay = 0 THEN importo ELSE 0 END) AS amount_external
                FROM flussi_rendicontazioni
                WHERE id_dominio = :dom
                GROUP BY anno, mese
            ');
            $stmt->execute([':dom' => $idDominio]);
            $dbRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $indexed = [];
            foreach ($dbRows as $row) {
                $key = $row['anno'] . '_' . $row['mese'];
                $indexed[$key] = $row;
            }

            $mesiNomi = [
                1 => 'Gen', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mag', 6 => 'Giu',
                7 => 'Lug', 8 => 'Ago', 9 => 'Set', 10 => 'Ott', 11 => 'Nov', 12 => 'Dic'
            ];

            $now = new \DateTimeImmutable('first day of this month');
            for ($i = 5; $i >= 0; $i--) {
                $date = $now->modify("-$i month");
                $y = (int)$date->format('Y');
                $m = (int)$date->format('n');
                $key = $y . '_' . $m;
                $meseNome = $mesiNomi[$m] ?? (string)$m;

                if (isset($indexed[$key])) {
                    $row = $indexed[$key];
                    $monthlyTrend[] = [
                        'label' => $meseNome . ' ' . $y,
                        'count' => (int)$row['count'],
                        'amount' => round((float)$row['amount'], 2),
                        'amount_internal' => round((float)($row['amount_internal'] ?? 0.0), 2),
                        'amount_external' => round((float)($row['amount_external'] ?? 0.0), 2),
                    ];
                } else {
                    $monthlyTrend[] = [
                        'label' => $meseNome . ' ' . $y,
                        'count' => 0,
                        'amount' => 0.0,
                        'amount_internal' => 0.0,
                        'amount_external' => 0.0,
                    ];
                }
            }
        } catch (\Throwable $_) {}

        // 6. Feed ultimi 5 flussi rendicontati
        $recentFlussi = [];
        try {
            $stmt = $pdo->prepare('
                SELECT id_flusso, data_regolamento, ragione_psp, SUM(importo) as importo_totale, COUNT(*) as transazioni, is_govpay
                FROM flussi_rendicontazioni
                WHERE id_dominio = :dom
                GROUP BY id_flusso, data_regolamento, ragione_psp, is_govpay
                ORDER BY data_regolamento DESC, id_flusso DESC
                LIMIT 5
            ');
            $stmt->execute([':dom' => $idDominio]);
            $recentFlussi = $stmt->fetchAll() ?: [];
        } catch (\Throwable $_) {}

        // 7. Ripartizione per tipologia di pendenza (Top 5 per importo) per ultimi 12 mesi (default)
        $tipologieStats = [];
        $tipologieTotal = 0.0;
        try {
            $now = new \DateTimeImmutable('now');
            $firstDay = $now->modify('-1 year')->setTime(0, 0, 0);
            $dateFrom = $firstDay->format('Y-m-d');

            $stmtTot = $pdo->prepare('
                SELECT SUM(importo) AS a 
                FROM flussi_rendicontazioni 
                WHERE id_dominio = :dom 
                  AND data_regolamento >= :date_from
            ');
            $stmtTot->execute([':dom' => $idDominio, ':date_from' => $dateFrom]);
            $rowTot = $stmtTot->fetch();
            $tipologieTotal = (float)($rowTot['a'] ?? 0.0);

            $stmt = $pdo->prepare('
                SELECT 
                    COALESCE(e.descrizione_locale, e.descrizione, f.cod_entrata, "Altre pendenze / Esterni") AS tipologia_desc,
                    f.cod_entrata,
                    COUNT(*) AS transazioni,
                    SUM(f.importo) AS importo_totale
                FROM flussi_rendicontazioni f
                LEFT JOIN entrate_tipologie e 
                    ON e.id_entrata = f.cod_entrata 
                   AND e.id_dominio = f.id_dominio
                WHERE f.id_dominio = :dom
                  AND f.data_regolamento >= :date_from
                GROUP BY f.cod_entrata, e.descrizione_locale, e.descrizione
                ORDER BY importo_totale DESC
            ');
            $stmt->execute([':dom' => $idDominio, ':date_from' => $dateFrom]);
            $tipologieStats = $stmt->fetchAll() ?: [];
        } catch (\Throwable $_) {}

        try {
            $customMap = [];
            foreach ((new MappingPendenzeRepository())->getCustomTipologie($idDominio) as $tc) {
                $customMap[(string)$tc['cod_entrata']] = (string)$tc['descrizione'];
            }
            foreach ($tipologieStats as &$ts) {
                $cod = (string)($ts['cod_entrata'] ?? '');
                if ($cod === 'TEFA') {
                    $ts['tipologia_desc'] = 'TEFA (Quota provinciale)';
                } elseif ($cod !== '' && isset($customMap[$cod])) {
                    $ts['tipologia_desc'] = $customMap[$cod];
                }
            }
            unset($ts);
        } catch (\Throwable $_) {}

        $data = [
            'totals' => $totals,
            'biz_counts' => $bizCounts,
            'tefa_counts' => $tefaCounts,
            'biz_queue_remaining' => $bizQueueRemaining,
            'tefa_queue_remaining' => $tefaQueueRemaining,
            'channels_data' => $channelsData,
            'monthly_trend' => $monthlyTrend,
            'recent_flussi' => $recentFlussi,
            'tipologie_stats' => $tipologieStats,
            'tipologie_total' => $tipologieTotal,
        ];

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function apiTipologie(Request $request, Response $response): Response
    {
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $pdo = Connection::getPDO();

        $periodo = (string)($request->getQueryParams()['periodo'] ?? '12m');

        // Build date constraint based on selected period
        $now = new \DateTimeImmutable('now');
        switch ($periodo) {
            case '30d':
                $firstDay = $now->modify('-30 days')->setTime(0, 0, 0);
                $dateCondition = "AND f.data_regolamento >= :date_from";
                $bindings = [':dom' => $idDominio, ':date_from' => $firstDay->format('Y-m-d')];
                break;
            case 'last_month':
                $firstDay = $now->modify('first day of last month')->setTime(0, 0, 0);
                $lastDay  = $now->modify('last day of last month')->setTime(23, 59, 59);
                $dateCondition = "AND f.data_regolamento BETWEEN :date_from AND :date_to";
                $bindings = [':dom' => $idDominio, ':date_from' => $firstDay->format('Y-m-d'), ':date_to' => $lastDay->format('Y-m-d')];
                break;
            case 'this_year':
                $firstDay = new \DateTimeImmutable($now->format('Y') . '-01-01 00:00:00');
                $dateCondition = "AND f.data_regolamento >= :date_from";
                $bindings = [':dom' => $idDominio, ':date_from' => $firstDay->format('Y-m-d')];
                break;
            case 'last_year':
                $lastYear = (int)$now->format('Y') - 1;
                $firstDay = new \DateTimeImmutable($lastYear . '-01-01 00:00:00');
                $lastDay  = new \DateTimeImmutable($lastYear . '-12-31 23:59:59');
                $dateCondition = "AND f.data_regolamento BETWEEN :date_from AND :date_to";
                $bindings = [':dom' => $idDominio, ':date_from' => $firstDay->format('Y-m-d'), ':date_to' => $lastDay->format('Y-m-d')];
                break;
            case '12m':
            case '1y': // Keep compatibility
                $firstDay = $now->modify('-1 year')->setTime(0, 0, 0);
                $dateCondition = "AND f.data_regolamento >= :date_from";
                $bindings = [':dom' => $idDominio, ':date_from' => $firstDay->format('Y-m-d')];
                break;
            default:
                $periodo = '12m';
                $firstDay = $now->modify('-1 year')->setTime(0, 0, 0);
                $dateCondition = "AND f.data_regolamento >= :date_from";
                $bindings = [':dom' => $idDominio, ':date_from' => $firstDay->format('Y-m-d')];
                break;
        }

        $tipologieStats = [];
        $totalAmount    = 0.0;
        try {
            // Total for this period
            $stmtTot = $pdo->prepare(
                "SELECT SUM(importo) AS a FROM flussi_rendicontazioni f WHERE f.id_dominio = :dom $dateCondition"
            );
            $stmtTot->execute($bindings);
            $rowTot = $stmtTot->fetch();
            $totalAmount = (float)($rowTot['a'] ?? 0.0);

            // Breakdown by tipologia
            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(e.descrizione_locale, e.descrizione, f.cod_entrata, 'Altre pendenze / Esterni') AS tipologia_desc,
                    f.cod_entrata,
                    COUNT(*) AS transazioni,
                    SUM(f.importo) AS importo_totale
                FROM flussi_rendicontazioni f
                LEFT JOIN entrate_tipologie e
                    ON e.id_entrata = f.cod_entrata
                   AND e.id_dominio = f.id_dominio
                WHERE f.id_dominio = :dom
                $dateCondition
                GROUP BY f.cod_entrata, e.descrizione_locale, e.descrizione
                ORDER BY importo_totale DESC
            ");
            $stmt->execute($bindings);
            $tipologieStats = $stmt->fetchAll() ?: [];
        } catch (\Throwable $_) {}

        // Sostituisce tipologia_desc con descrizione custom dove applicabile
        try {
            $customMap = [];
            foreach ((new MappingPendenzeRepository())->getCustomTipologie($idDominio) as $tc) {
                $customMap[(string)$tc['cod_entrata']] = (string)$tc['descrizione'];
            }
            foreach ($tipologieStats as &$ts) {
                $cod = (string)($ts['cod_entrata'] ?? '');
                if ($cod === 'TEFA') {
                    $ts['tipologia_desc'] = 'TEFA (Quota provinciale)';
                } elseif ($cod !== '' && isset($customMap[$cod])) {
                    $ts['tipologia_desc'] = $customMap[$cod];
                }
            }
            unset($ts);
        } catch (\Throwable $_) {}

        $payload = json_encode([
            'total'     => $totalAmount,
            'tipologie' => $tipologieStats,
            'periodo'   => $periodo,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function guida(Request $request, Response $response): Response
    {
        if (isset($_SESSION['user'])) {
            $this->twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }

        return $this->twig->render($response, 'guida.html.twig');
    }

}

