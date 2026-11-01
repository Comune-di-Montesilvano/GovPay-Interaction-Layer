<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\FlussiRendicontazioniRepository;

/**
 * Servizio Biz Events:
 *   1. queueFromCache  — accoda IUR non-GovPay da flussi_rendicontazioni
 *   2. enrichOne       — chiama Biz Events e salva tutti i dati ricevuta in biz_ricevute
 *
 * Funziona per qualsiasi ente con Biz Events configurato (non solo province).
 */
class BizScannerService
{
    public function __construct(
        private readonly BizRepository $repo,
        private readonly FlussiRendicontazioniRepository $flussiRepo,
        private readonly ?\GuzzleHttp\ClientInterface $httpClient = null
    ) {}

    /**
     * @return array{queued:int,from_cache:int,sample_iur:string,sample_flusso:string,min_date:string}
     */
    public function queueFromCache(string $idDominio, int $limit = 500): array
    {
        $scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
        $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : '';

        $rows = $this->flussiRepo->getUnprocessedForBiz($idDominio, $limit, $minDate !== '' ? $minDate : null);
        if ($rows === []) {
            return ['queued' => 0, 'from_cache' => 0, 'sample_iur' => '', 'sample_flusso' => '', 'min_date' => $minDate];
        }

        $first = $rows[0] ?? [];

        $pendingRows = [];
        foreach ($rows as $row) {
            $pendingRows[] = [
                'id_dominio'     => (string)($row['id_dominio'] ?? $idDominio),
                'anno'           => (int)($row['anno'] ?? (int)date('Y')),
                'mese'           => (int)($row['mese'] ?? (int)date('n')),
                'id_flusso'      => (string)($row['id_flusso'] ?? ''),
                'iur'            => (string)($row['iur'] ?? ''),
                'iuv'            => (string)($row['iuv'] ?? ''),
                'data_pagamento' => (string)($row['data_pagamento'] ?? ''),
                'importo'        => (float)($row['importo'] ?? 0.0),
            ];
        }

        $queued = $this->repo->upsertPending($pendingRows);

        return [
            'queued'       => $queued,
            'from_cache'   => count($rows),
            'sample_iur'   => (string)($first['iur'] ?? ''),
            'sample_flusso' => (string)($first['id_flusso'] ?? ''),
            'min_date'     => $minDate,
        ];
    }

    /**
     * Chiama Biz Events per un singolo record PENDING e salva tutti i dati ricevuta.
     * Nessun delay interno — il chiamante gestisce il timing tra le chiamate.
     *
     * @param array<string,mixed> $row         Riga da biz_ricevute
     * @param string $idDominio                CF ente
     * @return array{status:string,reason:string}
     *         status: PROCESSED | SKIPPED | ERROR | RATE_LIMITED
     */
    public function enrichOne(array $row, string $idDominio): array
    {
        $id  = (int)$row['id'];
        $iur = (string)$row['iur'];

        $biz = $this->buildBizEventsClient();
        if ($biz === null) {
            $msg = 'Biz Events non configurato (host/api_key mancanti)';
            $this->repo->markError($id, $msg);
            return ['status' => 'ERROR', 'reason' => $msg];
        }

        try {
            $receipt = $biz->getOrganizationReceiptIur($idDominio, $iur);
        } catch (\PagoPA\BizEvents\ApiException $e) {
            $code = $e->getCode();
            if ($code === 429) {
                return ['status' => 'RATE_LIMITED', 'reason' => '429'];
            }
            if ($code === 404) {
                $msg = 'Ricevuta non trovata in Biz Events (404)';
                $this->repo->markSkipped($id, $msg);
                // Senza ricevuta Biz la riga non potra' mai essere mappata da L1: non lasciarla PENDING.
                $this->flussiRepo->markMappingNoMatchByIur($idDominio, $iur);
                return ['status' => 'SKIPPED', 'reason' => $msg];
            }
            $msg = self::apiErrorReason($e);
            $this->repo->markError($id, $msg);
            return ['status' => 'ERROR', 'reason' => $msg];
        } catch (\Throwable $e) {
            // Mai il messaggio (URL con CF ente e IUR, estratto del corpo upstream).
            $msg = 'Eccezione Biz Events: ' . ThrowableSummary::of($e);
            $this->repo->markError($id, $msg);
            return ['status' => 'ERROR', 'reason' => $msg];
        }

        $debtor = $receipt->getDebtor();
        $payer  = $receipt->getPayer();

        $trasferimenti = [];
        foreach ($receipt->getTransferList() ?? [] as $tr) {
            /** @var \PagoPA\BizEvents\Model\TransferPA $tr */
            $trasferimenti[] = [
                'id_transfer'            => $tr->getIdTransfer(),
                'fiscal_code_pa'         => (string)($tr->getFiscalCodePa() ?? ''),
                'transfer_amount'        => (float)($tr->getTransferAmount() ?? 0.0),
                'remittance_information' => (string)($tr->getRemittanceInformation() ?? ''),
                'iban'                   => (string)($tr->getIban() ?? ''),
            ];
        }

        $this->repo->markProcessed($id, [
            'descrizione'         => mb_substr((string)($receipt->getDescription() ?? ''), 0, 512),
            'cf_debitore'         => $debtor !== null ? mb_substr((string)($debtor->getEntityUniqueIdentifierValue() ?? ''), 0, 35) : null,
            'nominativo_debitore' => $debtor !== null ? mb_substr((string)($debtor->getFullName() ?? ''), 0, 255) : null,
            'cf_pagante'          => $payer  !== null ? mb_substr((string)($payer->getEntityUniqueIdentifierValue() ?? ''), 0, 35) : null,
            'nominativo_pagante'  => $payer  !== null ? mb_substr((string)($payer->getFullName() ?? ''), 0, 255) : null,
            'company_name'        => mb_substr((string)($receipt->getCompanyName() ?? ''), 0, 255),
            'trasferimenti'       => json_encode($trasferimenti, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return ['status' => 'PROCESSED', 'reason' => ''];
    }

    /**
     * Motivo errore persistito (riga biz_ricevute + dettaglio run): solo 'HTTP <code>' e, se il
     * corpo e' un ProblemJson, title/status. Mai getMessage(): Guzzle vi mette l'URL
     * (/organizations/{CF ente}/receipts/{IUR}) e un estratto del corpo upstream.
     */
    private static function apiErrorReason(\PagoPA\BizEvents\ApiException $e): string
    {
        $code = (int)$e->getCode();
        if ($code <= 0) {
            return 'Errore API Biz Events: nessuna risposta HTTP (errore di rete)';
        }
        $msg = 'Errore API Biz Events HTTP ' . $code;
        $body = $e->getResponseBody();
        $problem = is_string($body) ? json_decode($body, true) : json_decode((string)json_encode($body), true);
        if (is_array($problem)) {
            $title = $problem['title'] ?? null;
            if (is_string($title) && trim($title) !== '') {
                $msg .= ' - ' . mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title)), 0, 100);
            }
            $status = $problem['status'] ?? null;
            if (is_int($status)) {
                $msg .= ' (status ' . $status . ')';
            }
        }
        return $msg;
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
            $this->httpClient ?? new \GuzzleHttp\Client(['connect_timeout' => 5, 'timeout' => 20]),
            $config
        );
    }
}
