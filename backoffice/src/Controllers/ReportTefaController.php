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

        if (($params['export'] ?? '') === 'csv') {
            return $this->exportCsv($response, $dataDa, $dataA, $idDominio);
        }

        $coverage = [];
        if ($queryMade) {
            $repo->fixNullDataPagamento($idDominio);
            $reportRows = $repo->getReport($dataDa, $dataA, $idDominio);
            foreach ($reportRows as $r) {
                $totaliTefa   += (float)$r['totale_tefa'];
                $totaliComune += (float)$r['totale_comune'];
            }
            $coverage = $repo->getCoverage($dataDa, $dataA, $idDominio);
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

    private const BIZ_SCANNER_PID_FILE  = '/tmp/cron-biz-scanner.pid';
    private const BIZ_SCANNER_STOP_FILE = '/tmp/cron-stop-biz';

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

    private function exportCsv(Response $response, string $dataDa, string $dataA, string $idDominio): Response
    {
        $filename = sprintf(
            'report-tefa-pendenze-%s-%s.csv',
            preg_replace('/[^A-Za-z0-9_-]/', '_', $dataDa),
            preg_replace('/[^A-Za-z0-9_-]/', '_', $dataA)
        );

        $response = $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        $repo = new TefaRepository();
        $rows = $repo->getDetailedRows($dataDa, $dataA, $idDominio);

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return $response;
        }

        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 per Excel
        fputcsv($out, [
            'IUR', 'IUV', 'Anno', 'Mese', 'Data Pagamento',
            'Stato TEFA', 'GovPay', 'Multibeneficiario',
            'Importo TEFA (€)', 'Importo Comune (€)',
            'CF Comune', 'Denominazione Comune', 'Sorgente', 'Errore',
            'ID Flusso', 'Data Flusso', 'Data Regolamento', 'TRN',
            'ID PSP', 'Ragione PSP', 'Importo Originale (€)',
            'Esito', 'Stato Rend.', 'Cod. Entrata', 'Descrizione Entrata', 'ID Pendenza',
        ], ';');

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['iur'] ?? '',
                $r['iuv'] ?? '',
                $r['anno'] ?? '',
                $r['mese'] ?? '',
                $r['data_pagamento'] ?? '',
                $r['stato'] ?? '',
                $r['is_govpay'] === null ? '' : ((int)$r['is_govpay'] === 1 ? 'Sì' : 'No'),
                $r['is_multibeneficiario'] === null ? '' : ((int)$r['is_multibeneficiario'] === 1 ? 'Sì' : 'No'),
                $r['importo_tefa'] !== null ? number_format((float)$r['importo_tefa'], 2, ',', '.') : '',
                $r['importo_comune'] !== null ? number_format((float)$r['importo_comune'], 2, ',', '.') : '',
                $r['cf_comune'] ?? '',
                $r['denominazione_comune'] ?? '',
                $r['sorgente'] ?? '',
                $r['error_msg'] ?? '',
                $r['id_flusso'] ?? '',
                $r['data_flusso'] ?? '',
                $r['data_regolamento'] ?? '',
                $r['trn'] ?? '',
                $r['id_psp'] ?? '',
                $r['ragione_psp'] ?? '',
                $r['importo_originale'] !== null ? number_format((float)$r['importo_originale'], 2, ',', '.') : '',
                $r['esito'] ?? '',
                $r['stato_rend'] ?? '',
                $r['cod_entrata'] ?? '',
                $r['descrizione_entrata'] ?? '',
                $r['id_pendenza'] ?? '',
            ], ';');
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        $response->getBody()->write((string)$csv);
        return $response;
    }

    // ── Biz daemon controls ───────────────────────────────────────────────────

    public function bizScan(Request $request, Response $response): Response
    {
        $this->requireAuth();

        $script = '/var/www/html/scripts/cron_biz_scanner.php';
        $cmd    = sprintf('php %s < /dev/null > /dev/null 2>&1 &', escapeshellarg($script));
        exec($cmd);

        return $this->jsonResponse($response, [
            'ok'     => true,
            'result' => ['message' => 'Demone Biz avviato.'],
        ]);
    }

    public function bizStop(Request $request, Response $response): Response
    {
        $this->requireAuth();

        file_put_contents(self::BIZ_SCANNER_STOP_FILE, '1');
        return $this->jsonResponse($response, ['ok' => true]);
    }

    public function bizStatus(Request $request, Response $response): Response
    {
        $this->requireAuth();

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $repo      = new \App\Database\BizRepository();
        $counts    = $repo->getCounts($idDominio);

        return $this->jsonResponse($response, [
            'ok'             => true,
            'counts'         => $counts,
            'scanner_running' => $this->isBizScannerRunning(),
        ]);
    }

    private function isBizScannerRunning(): bool
    {
        if (!file_exists(self::BIZ_SCANNER_PID_FILE)) {
            return false;
        }
        $pid = (int)file_get_contents(self::BIZ_SCANNER_PID_FILE);
        return $pid > 0 && file_exists('/proc/' . $pid);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response = $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
