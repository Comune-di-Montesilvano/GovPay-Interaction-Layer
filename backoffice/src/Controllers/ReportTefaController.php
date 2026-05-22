<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\TefaRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Report TEFA per provincia.
 * Feature-gated: richiede backoffice.tefa_enabled = 'true'.
 */
class ReportTefaController
{
    public function __construct(private readonly Twig $twig) {}

    public function index(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);
        $this->exposeCurrentUser();

        $params   = (array)($request->getQueryParams() ?? []);
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $today     = new \DateTimeImmutable('today');

        $dataDa = (string)($params['dataDa'] ?? ($today->format('Y') - 1) . '-01-01');
        $dataA  = (string)($params['dataA'] ?? $today->format('Y-m-d'));

        $repo   = new TefaRepository();
        $counts = $repo->getCounts($idDominio);

        $reportRows   = [];
        $totaliTefa   = 0.0;
        $totaliComune = 0.0;
        $queryMade    = array_key_exists('q', $params);

        $coverage = [];
        if ($queryMade) {
            $repo->fixNullDataPagamento($idDominio);
            $reportRows = $repo->getReport($dataDa, $dataA, $idDominio);
            foreach ($reportRows as $r) {
                $totaliTefa   += (float)$r['totale_tefa'];
                $totaliComune += (float)$r['totale_comune'];
            }
            $coverage = $repo->getCoverage($dataDa, $dataA, $idDominio);

            if (($params['export'] ?? '') === 'csv') {
                return $this->exportCsv($response, $reportRows, $dataDa, $dataA);
            }
        }

        $errors = $repo->getErrors($idDominio);

        return $this->twig->render($response, 'report-tefa/index.html.twig', [
            'filters'       => ['dataDa' => $dataDa, 'dataA' => $dataA],
            'counts'        => $counts,
            'report_rows'   => $reportRows,
            'totali_tefa'   => $totaliTefa,
            'totali_comune' => $totaliComune,
            'error_rows'    => $errors,
            'query_made'    => $queryMade,
            'coverage'      => $coverage,
            'id_dominio'    => $idDominio,
        ]);
    }

    private const SCANNER_PID_FILE  = '/tmp/cron-tefa-scanner.pid';
    private const SCANNER_STOP_FILE = '/tmp/cron-stop-tefa';

    public function scan(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);

        $body   = (array)($request->getParsedBody() ?? []);
        $dataDa = trim((string)($body['dataDa'] ?? ''));
        $dataA  = trim((string)($body['dataA'] ?? ''));

        $rangeMode = ($dataDa !== '' && $dataA !== '');

        if ($rangeMode) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDa) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataA)) {
                return $this->jsonResponse($response, ['ok' => false, 'error' => 'Formato date non valido (YYYY-MM-DD)'], 400);
            }
            // Ferma scanner eventualmente attivo (loop quotidiano)
            file_put_contents(self::SCANNER_STOP_FILE, '1');
            usleep(600_000); // 0.6s
        }

        $script = '/var/www/html/scripts/cron_tefa_scanner.php';
        $args   = $rangeMode
            ? '--da=' . escapeshellarg($dataDa) . ' --a=' . escapeshellarg($dataA)
            : '';

        $cmd = sprintf(
            'php %s %s < /dev/null > /dev/null 2>&1 &',
            escapeshellarg($script),
            $args
        );
        exec($cmd);

        $mode = $rangeMode ? 'range' : 'loop';
        return $this->jsonResponse($response, [
            'ok'   => true,
            'mode' => $mode,
            'result' => [
                'message' => $mode === 'loop'
                    ? 'Scansione quotidiana avviata (loop).'
                    : "Scansione range {$dataDa}÷{$dataA} avviata.",
            ],
        ]);
    }

    public function stop(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);

        file_put_contents(self::SCANNER_STOP_FILE, '1');
        return $this->jsonResponse($response, ['ok' => true]);
    }

    public function status(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $repo      = new TefaRepository();
        $counts    = $repo->getCounts($idDominio);

        return $this->jsonResponse($response, [
            'ok'             => true,
            'counts'         => $counts,
            'scanner_running' => $this->isScannerRunning(),
        ]);
    }

    private function isScannerRunning(): bool
    {
        if (!file_exists(self::SCANNER_PID_FILE)) {
            return false;
        }
        $pid = (int)file_get_contents(self::SCANNER_PID_FILE);
        return $pid > 0 && file_exists('/proc/' . $pid);
    }

    public function retryErrors(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $repo      = new TefaRepository();
        $reset     = $repo->resetErrors($idDominio);

        return $this->jsonResponse($response, ['ok' => true, 'reset' => $reset]);
    }

    public function fixDates(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $repo      = new TefaRepository();
        $fixed     = $repo->fixNullDataPagamento($idDominio);

        return $this->jsonResponse($response, ['ok' => true, 'fixed' => $fixed]);
    }

    public function retrySkipped(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $repo      = new TefaRepository();
        $reset     = $repo->resetSkipped($idDominio);

        return $this->jsonResponse($response, ['ok' => true, 'reset' => $reset]);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function requireAuth(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
    }

    private function requireTefaEnabled(Response $response): void
    {
        if (SettingsRepository::get('backoffice', 'tefa_enabled', 'false') !== 'true') {
            // Non interrompe con exit — lascia al controller chiamante gestire il redirect
            // (qui usato solo prima di render, quindi header+exit è ok)
            $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Funzione TEFA non abilitata nelle impostazioni.'];
            header('Location: /');
            exit;
        }
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

    /** @param array<int,array<string,mixed>> $rows */
    private function exportCsv(Response $response, array $rows, string $dataDa, string $dataA): Response
    {
        $filename = sprintf(
            'report-tefa-%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_-]/', '_', $dataDa),
            preg_replace('/[^A-Za-z0-9_-]/', '_', $dataA)
        );

        $response = $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return $response;
        }

        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 per Excel
        fputcsv($out, ['CF Comune', 'Denominazione Comune', 'N. Pagamenti TEFA', 'Totale TEFA (€)', 'Totale Importo Comune (€)'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['cf_comune'] ?? '',
                $r['denominazione_comune'] ?? '',
                (int)($r['n_pagamenti'] ?? 0),
                number_format((float)($r['totale_tefa'] ?? 0), 2, ',', '.'),
                number_format((float)($r['totale_comune'] ?? 0), 2, ',', '.'),
            ], ';');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $response->getBody()->write((string)$csv);
        return $response;
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response = $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
