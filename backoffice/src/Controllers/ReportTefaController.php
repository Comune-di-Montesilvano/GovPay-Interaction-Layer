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

        $queryMade = array_key_exists('q', $params);
        if (!$queryMade) {
            $dataDa = ($today->format('Y') - 1) . '-01-01';
            $dataA  = $today->format('Y-m-d');
        } else {
            $dataDa = trim((string)($params['dataDa'] ?? ''));
            $dataA  = trim((string)($params['dataA'] ?? ''));
        }

        $queryDa = $dataDa !== '' ? $dataDa : '1970-01-01';
        $queryA  = $dataA !== '' ? $dataA : '2099-12-31';

        $repo   = new TefaRepository();
        $counts = $repo->getCounts($idDominio);

        $reportRows   = [];
        $totaliTefa   = 0.0;
        $totaliComune = 0.0;

        if (($params['export'] ?? '') === 'csv') {
            return $this->exportCsv($response, $queryDa, $queryA, $idDominio);
        }

        $coverage = [];
        $mancantiPeriodi = [];
        if ($queryMade) {
            $repo->fixNullDataPagamento($idDominio);
            $reportRows = $repo->getReport($queryDa, $queryA, $idDominio);
            foreach ($reportRows as $r) {
                $totaliTefa   += (float)$r['totale_tefa'];
                $totaliComune += (float)$r['totale_comune'];
            }
            $coverage = $repo->getCoverage($queryDa, $queryA, $idDominio);

            // Calcolo mesi mancanti come periodi per risparmio spazio
            try {
                $daDate = new \DateTime($dataDa !== '' ? $dataDa : ($today->format('Y') - 1) . '-01-01');
                $aDate  = new \DateTime($dataA !== '' ? $dataA : $today->format('Y-m-d'));

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

        $errors = $repo->getErrors($idDominio);
        $scannerRunning = $this->isScannerRunning();

        return $this->twig->render($response, 'report-tefa/index.html.twig', [
            'filters'         => ['dataDa' => $dataDa, 'dataA' => $dataA],
            'counts'          => $counts,
            'report_rows'     => $reportRows,
            'totali_tefa'     => $totaliTefa,
            'totali_comune'   => $totaliComune,
            'error_rows'      => $errors,
            'query_made'      => $queryMade,
            'coverage'        => $coverage,
            'id_dominio'      => $idDominio,
            'scanner_running' => $scannerRunning,
            'mancanti_periodi'=> $mancantiPeriodi,
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
        if ($errRes = $this->requireAdminOrSuperadminJson($response)) {
            return $errRes;
        }

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
        if ($errRes = $this->requireAdminOrSuperadminJson($response)) {
            return $errRes;
        }

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
        if ($errRes = $this->requireAdminOrSuperadminJson($response)) {
            return $errRes;
        }

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $repo      = new TefaRepository();
        $reset     = $repo->resetErrors($idDominio);

        return $this->jsonResponse($response, ['ok' => true, 'reset' => $reset]);
    }

    public function fixDates(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);
        if ($errRes = $this->requireAdminOrSuperadminJson($response)) {
            return $errRes;
        }

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $repo      = new TefaRepository();
        $fixed     = $repo->fixNullDataPagamento($idDominio);

        return $this->jsonResponse($response, ['ok' => true, 'fixed' => $fixed]);
    }

    public function retrySkipped(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->requireTefaEnabled($response);
        if ($errRes = $this->requireAdminOrSuperadminJson($response)) {
            return $errRes;
        }

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

    private function requireAdminOrSuperadminJson(Response $response): ?Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Richiesta autenticazione'], 401);
        }
        if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Accesso negato — richiesto ruolo amministrativo.'], 403);
        }
        return null;
    }

    private function requireTefaEnabled(Response $response): void
    {
        if (SettingsRepository::get('backoffice', 'tefa_enabled', 'false') !== 'true') {
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
        if ($errRes = $this->requireAdminOrSuperadminJson($response)) {
            return $errRes;
        }

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
        if ($errRes = $this->requireAdminOrSuperadminJson($response)) {
            return $errRes;
        }

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
