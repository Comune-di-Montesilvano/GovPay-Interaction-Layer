<?php
declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Funzioni pure (nessun I/O) di mapping dei flussi di rendicontazione GovPay in righe per
 * flussi_rendicontazioni. Estratte invariate da scripts/cron_ragioneria.php.
 */
final class RagioneriaFlussoMapper
{
    public static function extractNextPage(string $prossimiRisultati): ?int
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

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function mapFlussoRows(array $detail, string $idDominio, string $idFlusso, string $idA2A = ''): array
    {
        $dataFlussoRaw = (string)($detail['dataFlusso'] ?? '');
        $dataRegolamentoRaw = (string)($detail['dataRegolamento'] ?? '');

        $dataFlusso = self::normalizeDate($dataFlussoRaw);
        $dataRegolamento = self::normalizeDate($dataRegolamentoRaw);
        $dataIncasso = $dataRegolamento;

        $anno = (int)substr(($dataIncasso ?? date('Y-m-d')), 0, 4);
        $mese = (int)substr(($dataIncasso ?? date('Y-m-d')), 5, 2);
        if ($anno < 2020 || $anno > 2100) {
            $anno = (int)date('Y');
            $mese = (int)date('n');
        }

        $rendicontazioni = is_array($detail['rendicontazioni'] ?? null) ? $detail['rendicontazioni'] : [];
        $rows = [];

        foreach ($rendicontazioni as $rend) {
            if (!is_array($rend)) {
                continue;
            }

            $iur = trim((string)($rend['iur'] ?? ''));
            if ($iur === '') {
                continue;
            }

            $risc = is_array($rend['riscossione'] ?? null) ? $rend['riscossione'] : [];
            $voce = is_array($risc['vocePendenza'] ?? null) ? $risc['vocePendenza'] : [];
            $isMultiBeneficiario = self::deriveIsMultiBeneficiario($detail, $rend, $risc);
            $idPendenza = self::extractIdPendenza($rend, $risc, $voce);
            // GovPay only for pendenze registered by THIS application (matching id_a2a).
            // If idA2A not present in payload (older GovPay), fall back to idPendenza presence.
            $payloadA2A = self::extractIdA2A($rend, $risc, $voce);
            $isGovPay   = $idPendenza !== '' && ($payloadA2A === '' || $idA2A === '' || $payloadA2A === $idA2A);

            $rows[] = [
                'id_dominio' => $idDominio,
                'id_flusso' => $idFlusso,
                'data_flusso' => $dataFlusso,
                'data_regolamento' => $dataRegolamento,
                'trn' => (string)($detail['trn'] ?? ''),
                'id_psp' => (string)($detail['psp']['idPsp'] ?? $detail['idPsp'] ?? ''),
                'ragione_psp' => (string)($detail['psp']['ragioneSociale'] ?? $detail['ragionePsp'] ?? ''),
                'anno' => $anno,
                'mese' => $mese,
                'iur' => $iur,
                'iuv' => (string)($rend['iuv'] ?? ''),
                'importo' => (float)($rend['importo'] ?? 0),
                'esito' => isset($rend['esito']) ? (int)$rend['esito'] : null,
                'stato_rend' => (string)($rend['stato'] ?? $rend['statoRendicontazione'] ?? ''),
                'indice' => isset($rend['indice']) ? (int)$rend['indice'] : 1,
                'data_pagamento' => $dataIncasso,
                'cod_entrata' => (function() use ($voce): string {
                    $cod = (string)($voce['codEntrata'] ?? '');
                    if ($cod === '') {
                        $pend = is_array($voce['pendenza'] ?? null) ? $voce['pendenza'] : [];
                        $tipo = is_array($pend['tipoPendenza'] ?? null) ? $pend['tipoPendenza'] : [];
                        $cod = (string)($tipo['idTipoPendenza'] ?? '');
                    }
                    return $cod;
                })(),
                'descrizione_entrata' => (string)($voce['descrizione'] ?? ''),
                'id_pendenza' => $idPendenza,
                'is_govpay' => $isGovPay,
                'is_multibeneficiario' => $isMultiBeneficiario,
            ];
        }

        return $rows;
    }

    public static function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (strlen($value) >= 10) {
            return substr($value, 0, 10);
        }

        if (strlen($value) === 7) {
            return $value . '-01';
        }

        return null;
    }

    public static function deriveIsMultiBeneficiario(array $detail, array $rend, array $risc): ?bool
    {
        $candidates = [
            $rend['isMultibeneficiario'] ?? null,
            $rend['is_multi_beneficiario'] ?? null,
            $rend['multiBeneficiario'] ?? null,
            $rend['multibeneficiario'] ?? null,
            $risc['isMultibeneficiario'] ?? null,
            $risc['is_multi_beneficiario'] ?? null,
            $risc['multiBeneficiario'] ?? null,
            $risc['multibeneficiario'] ?? null,
            $detail['isMultibeneficiario'] ?? null,
            $detail['is_multi_beneficiario'] ?? null,
            $detail['multiBeneficiario'] ?? null,
            $detail['multibeneficiario'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $datiVersamento = $detail['rpt']['json']['datiVersamento'] ?? null;
        if (is_array($datiVersamento)) {
            $tipoVersamento = strtoupper(trim((string)($datiVersamento['tipoVersamento'] ?? '')));
            if ($tipoVersamento === 'MPO') {
                return true;
            }

            $singoli = $datiVersamento['datiSingoloVersamento'] ?? null;
            if (is_array($singoli)) {
                return count($singoli) > 1;
            }
        }

        return null;
    }

    public static function extractIdPendenza(array $rend, array $risc, array $voce): string
    {
        $candidates = [
            $voce['idPendenza'] ?? null,
            is_array($voce['pendenza'] ?? null) ? ($voce['pendenza']['idPendenza'] ?? null) : null,
            $risc['idPendenza'] ?? null,
            is_array($risc['pendenza'] ?? null) ? ($risc['pendenza']['idPendenza'] ?? null) : null,
            is_array($rend['pendenza'] ?? null) ? ($rend['pendenza']['idPendenza'] ?? null) : null,
        ];

        foreach ($candidates as $value) {
            $id = trim((string)$value);
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }

    public static function extractIdA2A(array $rend, array $risc, array $voce): string
    {
        $candidates = [
            is_array($voce['pendenza'] ?? null) ? ($voce['pendenza']['idA2A'] ?? null) : null,
            is_array($risc['pendenza'] ?? null) ? ($risc['pendenza']['idA2A'] ?? null) : null,
            is_array($rend['pendenza'] ?? null) ? ($rend['pendenza']['idA2A'] ?? null) : null,
        ];

        foreach ($candidates as $value) {
            $id = trim((string)$value);
            if ($id !== '') {
                return $id;
            }
        }

        return '';
    }
}
