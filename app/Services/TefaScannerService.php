<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\TefaRepository;

/**
 * Servizio TEFA:
 *   1. queueFromCache  — accoda IUR da biz_ricevute PROCESSED non ancora in tefa_ricevute
 *   2. enrichOne       — classifica l'IUR come TEFA/non-TEFA leggendo i trasferimenti salvati
 *
 * Non chiama Biz Events direttamente: i dati vengono dal demone Biz (BizScannerService).
 */
class TefaScannerService
{
    public function __construct(
        private readonly TefaRepository $repo,
        private readonly BizRepository $bizRepo
    ) {}

    /**
     * @return array{queued:int,from_cache:int,sample_iur:string,sample_flusso:string,min_date:string}
     */
    public function queueFromCache(string $idDominio, int $limit = 500): array
    {
        $scanDa  = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
        $minDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa) ? $scanDa : '';

        $rows = $this->bizRepo->getProcessedForTefa($idDominio, $limit, $minDate !== '' ? $minDate : null);
        if ($rows === []) {
            return ['queued' => 0, 'from_cache' => 0, 'sample_iur' => '', 'sample_flusso' => '', 'min_date' => $minDate];
        }

        $first = $rows[0] ?? [];

        $pendingRows = [];
        foreach ($rows as $row) {
            $pendingRows[] = [
                'id_dominio'          => (string)($row['id_dominio'] ?? $idDominio),
                'anno'                => (int)($row['anno'] ?? (int)date('Y')),
                'mese'                => (int)($row['mese'] ?? (int)date('n')),
                'id_flusso'           => (string)($row['id_flusso'] ?? ''),
                'iur'                 => (string)($row['iur'] ?? ''),
                'iuv'                 => (string)($row['iuv'] ?? ''),
                'data_pagamento'      => (string)($row['data_pagamento'] ?? ''),
                'importo'             => (float)($row['importo'] ?? 0.0),
                'is_govpay'           => 0,
                'is_multibeneficiario' => null,
            ];
        }

        $queued = $this->repo->upsertPending($pendingRows);

        return [
            'queued'        => $queued,
            'from_cache'    => count($rows),
            'sample_iur'    => (string)($first['iur'] ?? ''),
            'sample_flusso' => (string)($first['id_flusso'] ?? ''),
            'min_date'      => $minDate,
        ];
    }

    /**
     * Classifica un singolo record PENDING leggendo i trasferimenti da biz_ricevute.
     * Non chiama Biz Events — i dati devono già essere presenti.
     *
     * @param array<string,mixed> $row             Riga da tefa_ricevute
     * @param string $idDominioProvincia            CF provincia (usato per separare il transfer provincia dal comune)
     * @return array{status:string,is_tefa:bool,importo_tefa:float,cf_comune:string,reason:string}
     *         status: PROCESSED | SKIPPED | ERROR
     */
    public function enrichOne(array $row, string $idDominioProvincia): array
    {
        $id  = (int)$row['id'];
        $iur = (string)$row['iur'];

        $bizMap = $this->bizRepo->getByIurs([$iur], $idDominioProvincia);
        $bizRow = $bizMap[$iur] ?? null;

        if ($bizRow === null) {
            $msg = 'Dati Biz Events non disponibili: IUR non trovato in biz_ricevute';
            $this->repo->markSkipped($id, $msg);
            return ['status' => 'SKIPPED', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
        }

        if ((string)$bizRow['stato'] !== 'PROCESSED') {
            $msg = 'Biz Events non ancora elaborato (stato: ' . $bizRow['stato'] . ')';
            $this->repo->markSkipped($id, $msg);
            return ['status' => 'SKIPPED', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
        }

        $trasferimenti = json_decode((string)($bizRow['trasferimenti'] ?? '[]'), true);
        if (!is_array($trasferimenti)) {
            $trasferimenti = [];
        }

        if (count($trasferimenti) < 2) {
            $msg = 'Transfer list con meno di 2 beneficiari — non è pagamento multi-transfer';
            $this->repo->markSkipped($id, $msg);
            return ['status' => 'SKIPPED', 'is_tefa' => false, 'importo_tefa' => 0.0, 'cf_comune' => '', 'reason' => $msg];
        }

        $transferProvincia = null;
        $transferComune    = null;

        foreach ($trasferimenti as $tr) {
            $fc = (string)($tr['fiscal_code_pa'] ?? '');
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

        $cfComune      = (string)($transferComune['fiscal_code_pa'] ?? '');
        $importoTefa   = (float)($transferProvincia['transfer_amount'] ?? 0.0);
        $importoComune = (float)($transferComune['transfer_amount'] ?? 0.0);

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

        $denomComune = (string)($bizRow['company_name'] ?? '');
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
}
