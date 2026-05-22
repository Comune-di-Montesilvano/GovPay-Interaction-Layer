<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Database\TefaRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Servizio TEFA:
 *   1. scanAndQueue  — itera flussiRendicontazione GovPay e inserisce record PENDING
 *   2. enrichBatch   — per ogni PENDING chiama Biz Events, identifica TEFA + comune
 *
 * Rate limit Biz Events: il cron usa max BIZ_RATE_FRACTION del limite,
 * lasciando il resto per le chiamate manuali degli utenti.
 */
class TefaScannerService
{
    private const MAX_FLUSSI_PAGES = 200;
    private const RESULTS_PER_PAGE = 100;

    public function __construct(
        private readonly TefaRepository $repo,
        private readonly FlussiRendicontazioniRepository $flussiRepo
    ) {}

    /**
     * @return array{queued:int,from_cache:int,sample_iur:string,sample_flusso:string,min_date:string}
     */
    public function queueFromCache(string $idDominio, int $limit = 500): array
    {
        $scanDa = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
        $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : '';

        $rows = $this->flussiRepo->getUnprocessedForTefa($idDominio, $limit, $minDate !== '' ? $minDate : null);
        if ($rows === []) {
            return ['queued' => 0, 'from_cache' => 0, 'sample_iur' => '', 'sample_flusso' => '', 'min_date' => $minDate];
        }

        $first = $rows[0] ?? [];

        $pendingRows = [];
        foreach ($rows as $row) {
            $pendingRows[] = [
                'id_dominio' => (string)($row['id_dominio'] ?? $idDominio),
                'anno' => (int)($row['anno'] ?? (int)date('Y')),
                'mese' => (int)($row['mese'] ?? (int)date('n')),
                'id_flusso' => (string)($row['id_flusso'] ?? ''),
                'iur' => (string)($row['iur'] ?? ''),
                'iuv' => (string)($row['iuv'] ?? ''),
                'data_pagamento' => (string)($row['data_pagamento'] ?? ''),
                'importo' => (float)($row['importo'] ?? 0.0),
            ];
        }

        $queued = $this->repo->upsertPending($pendingRows);

        return [
            'queued' => $queued,
            'from_cache' => count($rows),
            'sample_iur' => (string)($first['iur'] ?? ''),
            'sample_flusso' => (string)($first['id_flusso'] ?? ''),
            'min_date' => $minDate,
        ];
    }

    /**
     * Itera tutti i flussiRendicontazione GovPay nel range e inserisce PENDING.
     * Ritorna ['queued' => N, 'already_present' => M, 'flussi' => K].
     *
     * @return array{queued:int,already_present:int,flussi:int}
     */
    /**
     * @param callable(string):void|null $logger  Optional echo-style logger for progress
     */
    public function scanAndQueue(string $dataDa, string $dataA, string $idDominio, ?callable $logger = null): array
    {
        $log = $logger ?? static function (string $_): void {};

        $client  = $this->buildGovPayClient();
        $baseUrl = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');

        $flussiIndex = [];
        $page = 1;

        do {
            $log("  [scan] Fetching flussi pagina {$page}...");
            $query = [
                'pagina'              => $page,
                'risultatiPerPagina'  => self::RESULTS_PER_PAGE,
                'metadatiPaginazione' => 'true',
                'maxRisultati'        => 'true',
                'idDominio'           => $idDominio,
                'dataDa'              => $dataDa,
                'dataA'               => $dataA,
            ];

            try {
                $resp    = $client->request('GET', $baseUrl . '/flussiRendicontazione', ['query' => $query]);
                $payload = json_decode((string)$resp->getBody(), true);
            } catch (RequestException $e) {
                throw new \RuntimeException('Errore lista flussi TEFA (p.' . $page . '): ' . $e->getMessage(), 0, $e);
            }

            if (!is_array($payload)) {
                break;
            }

            $risultati = $payload['risultati'] ?? [];
            if (!is_array($risultati) || $risultati === []) {
                break;
            }

            foreach ($risultati as $item) {
                if (is_array($item)) {
                    $flussiIndex[] = $item;
                }
            }

            $log("  [scan] Pagina {$page}: trovati " . count($risultati) . " flussi (totale: " . count($flussiIndex) . ")");

            $prossimiRisultati = (string)($payload['prossimiRisultati'] ?? '');
            $nextPage = $this->extractNextPage($prossimiRisultati);
            if ($nextPage === null || $nextPage <= $page) {
                break;
            }
            $page = $nextPage;
        } while ($page <= self::MAX_FLUSSI_PAGES);

        $log("  [scan] Lista flussi: " . count($flussiIndex) . " flussi. Fetching dettagli rendicontazioni...");

        $pendingRows = [];
        $flussoN     = 0;

        foreach ($flussiIndex as $flussoItem) {
            $flussoN++;
            $idFlusso   = (string)($flussoItem['idFlusso'] ?? '');
            $domainId   = (string)($flussoItem['dominio']['idDominio'] ?? $flussoItem['idDominio'] ?? $idDominio);
            $dataFlusso = (string)($flussoItem['dataFlusso'] ?? '');

            if ($idFlusso === '' || $domainId === '') {
                continue;
            }

            $log("  [scan] Flusso {$flussoN}/" . count($flussiIndex) . ": {$idFlusso}");

            try {
                $detailPath = $baseUrl . '/flussiRendicontazione/'
                    . rawurlencode($domainId) . '/'
                    . rawurlencode($idFlusso);
                $detailResp = $client->request('GET', $detailPath);
                $detail     = json_decode((string)$detailResp->getBody(), true);
            } catch (\Throwable $e) {
                $log("  [scan] Flusso {$idFlusso}: errore dettaglio — " . $e->getMessage());
                continue;
            }

            if (!is_array($detail)) {
                continue;
            }

            $rendicontazioni = $detail['rendicontazioni'] ?? [];
            if (!is_array($rendicontazioni)) {
                continue;
            }

            foreach ($rendicontazioni as $rend) {
                if (!is_array($rend)) {
                    continue;
                }

                $iur = (string)($rend['iur'] ?? '');
                $iuv = (string)($rend['iuv'] ?? '');
                if ($iur === '') {
                    continue;
                }

                $risc          = is_array($rend['riscossione'] ?? null) ? $rend['riscossione'] : null;
                $dataPagamento = ($risc !== null) ? (string)($risc['data'] ?? '') : '';

                // Estrai anno/mese dalla data flusso o data pagamento
                $dateRef = $dataPagamento !== '' ? $dataPagamento : $dataFlusso;
                $anno    = (int)substr($dateRef, 0, 4);
                $mese    = (int)substr($dateRef, 5, 2);
                if ($anno < 2020 || $anno > 2100) {
                    $anno = (int)date('Y');
                    $mese = (int)date('n');
                }

                // Usa data pagamento se disponibile, altrimenti fallback su data flusso.
                // Se dataFlusso è solo YYYY-MM (7 char), appende -01 per il primo del mese.
                if ($dataPagamento !== '') {
                    $dataPagamentoDb = substr($dataPagamento, 0, 10);
                } elseif (strlen($dataFlusso) >= 10) {
                    $dataPagamentoDb = substr($dataFlusso, 0, 10);
                } elseif (strlen($dataFlusso) === 7) {
                    $dataPagamentoDb = $dataFlusso . '-01';
                } else {
                    $dataPagamentoDb = '';
                }

                $pendingRows[] = [
                    'id_dominio'     => $domainId,
                    'anno'           => $anno,
                    'mese'           => $mese ?: (int)date('n'),
                    'id_flusso'      => $idFlusso,
                    'iur'            => $iur,
                    'iuv'            => $iuv,
                    'data_pagamento' => $dataPagamentoDb,
                    'importo'        => (float)($rend['importo'] ?? 0),
                ];
            }
        }

        $queued = $this->repo->upsertPending($pendingRows);
        $alreadyPresent = count($pendingRows) - $queued;

        return [
            'queued'          => $queued,
            'already_present' => max(0, $alreadyPresent),
            'flussi'          => count($flussiIndex),
        ];
    }

    /**
     * Processa un singolo record PENDING chiamando Biz Events. Nessun delay interno —
     * il chiamante gestisce il timing tra le chiamate.
     *
     * @param array<string,mixed> $row         Riga da tefa_ricevute
     * @param string $idDominioProvincia        CF provincia
     * @return array{status:string,is_tefa:bool,importo_tefa:float,cf_comune:string,reason:string}
     *         status: PROCESSED | SKIPPED | ERROR | RATE_LIMITED
     */
    public function enrichOne(array $row, string $idDominioProvincia): array
    {
        $id  = (int)$row['id'];
        $iur = (string)$row['iur'];

        $biz = $this->buildBizEventsClient();
        if ($biz === null) {
            $msg = 'Biz Events non configurato (host/api_key mancanti)';
            $this->repo->markError($id, $msg);
            return ['status' => 'ERROR', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
        }

        try {
            $receipt = $biz->getOrganizationReceiptIur($idDominioProvincia, $iur);
        } catch (\PagoPA\BizEvents\ApiException $e) {
            $code = $e->getCode();
            if ($code === 429) {
                // Non tocca il DB — lascia PENDING, il chiamante decide se retry
                return ['status' => 'RATE_LIMITED', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => '429'];
            }
            if ($code === 404) {
                $msg = 'Ricevuta non trovata in Biz Events (404)';
                $this->repo->markSkipped($id, $msg);
                return ['status' => 'SKIPPED', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
            }
            $msg = "Errore API Biz Events HTTP $code: " . mb_substr($e->getMessage(), 0, 300);
            $this->repo->markError($id, $msg);
            return ['status' => 'ERROR', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
        } catch (\Throwable $e) {
            $msg = 'Eccezione Biz Events: ' . mb_substr($e->getMessage(), 0, 300);
            $this->repo->markError($id, $msg);
            return ['status' => 'ERROR', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
        }

        $transferList = $receipt->getTransferList() ?? [];

        if (count($transferList) < 2) {
            $msg = 'Transfer list con meno di 2 beneficiari — non è pagamento multi-transfer';
            $this->repo->markSkipped($id, $msg);
            return ['status' => 'SKIPPED', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
        }

        $transferProvincia = null;
        $transferComune    = null;

        foreach ($transferList as $tr) {
            /** @var \PagoPA\BizEvents\Model\TransferPA $tr */
            $fc = (string)($tr->getFiscalCodePa() ?? '');

            if ($fc === $idDominioProvincia) {
                $transferProvincia = $tr;
            } else {
                $transferComune = $tr;
            }
        }

        if ($transferProvincia === null || $transferComune === null) {
            $msg = 'Impossibile separare transfer provincia/comune dalla lista';
            $this->repo->markSkipped($id, $msg);
            return ['status' => 'SKIPPED', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
        }

        $cfComune      = (string)($transferComune->getFiscalCodePa() ?? '');
        $importoTefa   = (float)($transferProvincia->getTransferAmount() ?? 0.0);
        $importoComune = (float)($transferComune->getTransferAmount() ?? 0.0);

        // Verifica rapporto TEFA/comune ≈ 5% (range accettabile 1%–10%)
        if ($importoComune > 0.0) {
            $ratio = $importoTefa / $importoComune;
            if ($ratio < 0.01 || $ratio > 0.10) {
                $msg = sprintf(
                    'Rapporto importo TEFA/comune fuori range: %.2f%% (atteso ~5%%, TEFA=%.2f comune=%.2f)',
                    $ratio * 100,
                    $importoTefa,
                    $importoComune
                );
                $this->repo->markSkipped($id, $msg);
                return ['status' => 'SKIPPED', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
            }
        }

        $denomComune = (string)($receipt->getCompanyName() ?? '');
        if ($denomComune === '' || strtoupper($denomComune) === strtoupper($idDominioProvincia)) {
            $denomComune = $cfComune;
        }

        $this->repo->markProcessed($id, $cfComune, $denomComune, $importoTefa, $importoComune, 'biz_events');

        return [
            'status'       => 'PROCESSED',
            'is_tefa'      => true,
            'importo_tefa' => $importoTefa,
            'cf_comune'    => $cfComune,
            'reason'       => '',
        ];
    }

    private function buildGovPayClient(): Client
    {
        $opts = [
            'headers'         => ['Accept' => 'application/json'],
            'connect_timeout' => 10,
            'timeout'         => 30,
        ];

        $username = SettingsRepository::get('govpay', 'user', '');
        $password = SettingsRepository::get('govpay', 'password', '');
        if ($username !== '' && $password !== '') {
            $opts['auth'] = [$username, $password];
        }

        $authMethod = strtolower((string)SettingsRepository::get('govpay', 'authentication_method', ''));
        if (in_array($authMethod, ['ssl', 'sslheader'], true)) {
            $cert    = SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key     = SettingsRepository::get('govpay', 'tls_key_path', '');
            $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
            if ($cert !== '' && $key !== '') {
                $opts['cert']    = $cert;
                $opts['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            }
        }

        return new Client($opts);
    }

    private function buildBizEventsClient(): ?\PagoPA\BizEvents\Api\PaymentReceiptsRESTAPIsApi
    {
        $host   = rtrim((string)SettingsRepository::get('pagopa', 'biz_events_host', ''), '/');
        $apiKey = (string)SettingsRepository::get('pagopa', 'biz_events_api_key', '');

        if ($host === '' || $apiKey === '') {
            return null;
        }

        $config = \PagoPA\BizEvents\Configuration::getDefaultConfiguration()
            ->setHost($host)
            ->setApiKey('Ocp-Apim-Subscription-Key', $apiKey);

        return new \PagoPA\BizEvents\Api\PaymentReceiptsRESTAPIsApi(
            new \GuzzleHttp\Client(['connect_timeout' => 5, 'timeout' => 20]),
            $config
        );
    }

    private function extractNextPage(string $prossimiRisultati): ?int
    {
        if ($prossimiRisultati === '') {
            return null;
        }
        $query = parse_url($prossimiRisultati, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $params);
        $page = (int)($params['pagina'] ?? 0);
        return $page > 0 ? $page : null;
    }
}
