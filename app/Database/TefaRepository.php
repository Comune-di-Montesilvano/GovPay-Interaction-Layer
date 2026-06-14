<?php
declare(strict_types=1);

namespace App\Database;

use PDO;

class TefaRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Connection::getPDO();
        $this->ensureTable();
    }

    /**
     * Bulk-insert rendicontazioni come PENDING. Ignora duplicati (uq_iur_dominio).
        * @param array<int,array{id_dominio:string,anno:int,mese:int,id_flusso:string,iur:string,iuv:string,data_pagamento:string,importo:float,is_govpay?:bool|null,is_multibeneficiario?:bool|null}> $rows
     */
    public function upsertPending(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
                $inserted = 0;
                $sql = 'INSERT INTO tefa_ricevute
                                    (id_dominio, anno, mese, id_flusso, iur, iuv, data_pagamento, importo_tefa, is_govpay, is_multibeneficiario, stato, created_at, updated_at)
                                VALUES
                                    (:id_dominio, :anno, :mese, :id_flusso, :iur, :iuv, :data_pagamento, :importo_tefa, :is_govpay, :is_multibeneficiario, \'PENDING\', NOW(), NOW())
                                ON DUPLICATE KEY UPDATE
                                    id_flusso = VALUES(id_flusso),
                                    iuv = VALUES(iuv),
                                    data_pagamento = VALUES(data_pagamento),
                                    importo_tefa = VALUES(importo_tefa),
                                    is_govpay = VALUES(is_govpay),
                                    is_multibeneficiario = VALUES(is_multibeneficiario),
                                    updated_at = NOW()';
        $stmt = $this->pdo->prepare($sql);
        foreach ($rows as $r) {
            $stmt->execute([
                ':id_dominio'     => $r['id_dominio'],
                ':anno'           => $r['anno'],
                ':mese'           => $r['mese'],
                ':id_flusso'      => $r['id_flusso'] ?? null,
                ':iur'            => $r['iur'],
                ':iuv'            => $r['iuv'] ?? null,
                ':data_pagamento' => $r['data_pagamento'] !== '' ? $r['data_pagamento'] : null,
                ':importo_tefa'   => $r['importo'] ?? null,
                ':is_govpay'      => array_key_exists('is_govpay', $r) && $r['is_govpay'] !== null ? (int)$r['is_govpay'] : null,
                ':is_multibeneficiario' => array_key_exists('is_multibeneficiario', $r) && $r['is_multibeneficiario'] !== null ? (int)$r['is_multibeneficiario'] : null,
            ]);
            if ($stmt->rowCount() === 1) {
                $inserted++;
            }
        }
        return $inserted;
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchPending(int $limit = 50): array
    {
        $limit = max(1, $limit);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tefa_ricevute WHERE stato = \'PENDING\' ORDER BY id ASC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Restituisce l'id_flusso del prossimo flusso con record PENDING (ordine cronologico). */
    public function getNextPendingFlusso(string $idDominio, ?string $minDate = null): ?string
    {
           $sql = 'SELECT id_flusso, MIN(data_pagamento) AS min_data_riferimento FROM tefa_ricevute
               WHERE id_dominio = :dom AND stato = \'PENDING\'';
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND data_pagamento >= :min_date';
        }
           $sql .= ' GROUP BY id_flusso
               ORDER BY min_data_riferimento ASC, id_flusso ASC
               LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $params = [':dom' => $idDominio];
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $params[':min_date'] = $minDate;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (string)$row['id_flusso'] : null;
    }

    /** Tutti i record PENDING per uno specifico flusso. */
    public function fetchPendingByFlusso(string $idFlusso, string $idDominio, ?string $minDate = null): array
    {
        $sql = 'SELECT * FROM tefa_ricevute
             WHERE id_dominio = :dom AND id_flusso = :flusso AND stato = \'PENDING\'';
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND data_pagamento >= :min_date';
        }
        $sql .= ' ORDER BY id ASC';

        $stmt = $this->pdo->prepare($sql);
        $params = [':dom' => $idDominio, ':flusso' => $idFlusso];
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $params[':min_date'] = $minDate;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countPendingForProcessing(string $idDominio, ?string $minDate = null): int
    {
        $sql = 'SELECT COUNT(*) FROM tefa_ricevute
             WHERE id_dominio = :dom AND stato = \'PENDING\'';
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND data_pagamento >= :min_date';
        }

        $stmt = $this->pdo->prepare($sql);
        $params = [':dom' => $idDominio];
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $params[':min_date'] = $minDate;
        }
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function deleteByDateRange(string $idDominio, string $dataDa, string $dataA): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM tefa_ricevute
             WHERE id_dominio = :id_dominio
               AND data_pagamento >= :data_da
               AND data_pagamento <= :data_a'
        );
        $stmt->execute([
            ':id_dominio' => $idDominio,
            ':data_da' => $dataDa,
            ':data_a' => $dataA,
        ]);

        return $stmt->rowCount();
    }

    public function markProcessed(
        int $id,
        string $cfComune,
        string $denominazioneComune,
        float $importoTefa,
        float $importoComune,
        string $sorgente
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE tefa_ricevute SET
               stato = \'PROCESSED\',
               cf_comune = :cf_comune,
               denominazione_comune = :denom,
               importo_tefa = :imp_tefa,
               importo_comune = :imp_comune,
               sorgente = :sorgente,
               error_msg = NULL,
               updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':cf_comune'  => $cfComune,
            ':denom'      => $denominazioneComune,
            ':imp_tefa'   => $importoTefa,
            ':imp_comune' => $importoComune,
            ':sorgente'   => $sorgente,
            ':id'         => $id,
        ]);

        // Mark corresponding row in flussi_rendicontazioni as PROCESSED under TEFA category
        $stmt2 = $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni f
             INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
             SET f.mapping_stato = \'PROCESSED\',
                 f.vocab_stato = \'PROCESSED\',
                 f.cod_entrata = \'TEFA\'
             WHERE t.id = :id'
        );
        $stmt2->execute([':id' => $id]);
    }

    public function markError(int $id, string $msg): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tefa_ricevute SET stato = \'ERROR\', error_msg = :msg, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':msg' => mb_substr($msg, 0, 1000), ':id' => $id]);
    }

    public function markSkipped(int $id, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tefa_ricevute SET stato = \'SKIPPED\', error_msg = :msg, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':msg' => mb_substr($reason, 0, 500), ':id' => $id]);
    }

    /** Resetta ERROR → PENDING per retry manuale. Ritorna n. righe modificate. */
    public function resetErrors(string $idDominio): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tefa_ricevute SET stato = \'PENDING\', error_msg = NULL, updated_at = NOW()
             WHERE stato = \'ERROR\' AND id_dominio = :id_dominio'
        );
        $stmt->execute([':id_dominio' => $idDominio]);
        return $stmt->rowCount();
    }

    /**
     * Per i record con data_pagamento NULL, prova a estrarre la data dall'id_flusso
     * (formato tipico: "2025-01-XXXXXXX" → "2025-01-01").
     * Ritorna n. righe aggiornate.
     */
    public function fixNullDataPagamento(string $idDominio): int
    {
        $rowsUpdated = 0;
        try {
            // Check if there are any records that need fixing first
            $check = $this->pdo->prepare(
                "SELECT 1 FROM tefa_ricevute
                 WHERE id_dominio = :dom
                   AND (data_pagamento IS NULL OR DAY(data_pagamento) = 0)
                   AND id_flusso IS NOT NULL
                   AND id_flusso REGEXP '^[0-9]{4}-[0-9]{2}'
                 LIMIT 1"
            );
            $check->execute([':dom' => $idDominio]);
            if ($check->fetchColumn() !== false) {
                $stmt = $this->pdo->prepare(
                    "UPDATE tefa_ricevute
                     SET data_pagamento = DATE(CONCAT(SUBSTRING(id_flusso, 1, 7), '-01')),
                         updated_at = NOW()
                     WHERE id_dominio = :dom
                       AND (data_pagamento IS NULL OR DAY(data_pagamento) = 0)
                       AND id_flusso IS NOT NULL
                       AND id_flusso REGEXP '^[0-9]{4}-[0-9]{2}'"
                );
                $stmt->execute([':dom' => $idDominio]);
                $rowsUpdated = $stmt->rowCount();
            }
        } catch (\Throwable $e) {
            error_log("Errore/Deadlock in fixNullDataPagamento: " . $e->getMessage());
        }

        // Also retrospectively update mapping and vocab statuses in flussi_rendicontazioni for processed TEFA receipts
        $this->fixProcessedTefaMapping($idDominio);

        return $rowsUpdated;
    }

    /**
     * Sincronizza lo stato in flussi_rendicontazioni per i record già PROCESSED in tefa_ricevute.
     * Imposta mapping_stato='PROCESSED', vocab_stato='PROCESSED', cod_entrata='TEFA'.
     */
    public function fixProcessedTefaMapping(string $idDominio): int
    {
        try {
            // Fix NULL values for is_govpay in flussi_rendicontazioni for this domain
            $checkGovPay = $this->pdo->prepare(
                "SELECT 1 FROM flussi_rendicontazioni WHERE id_dominio = :dom AND is_govpay IS NULL LIMIT 1"
            );
            $checkGovPay->execute([':dom' => $idDominio]);
            if ($checkGovPay->fetchColumn() !== false) {
                $stmtGovPay = $this->pdo->prepare(
                    "UPDATE flussi_rendicontazioni
                     SET is_govpay = CASE WHEN id_pendenza IS NOT NULL AND TRIM(id_pendenza) != '' THEN 1 ELSE 0 END
                     WHERE id_dominio = :dom AND is_govpay IS NULL"
                );
                $stmtGovPay->execute([':dom' => $idDominio]);
            }
        } catch (\Throwable $e) {
            error_log("Errore/Deadlock in fixProcessedTefaMapping (is_govpay): " . $e->getMessage());
        }

        try {
            // Fix NULL values for is_multibeneficiario in flussi_rendicontazioni for this domain
            $checkMulti = $this->pdo->prepare(
                "SELECT 1 FROM flussi_rendicontazioni WHERE id_dominio = :dom AND is_multibeneficiario IS NULL LIMIT 1"
            );
            $checkMulti->execute([':dom' => $idDominio]);
            if ($checkMulti->fetchColumn() !== false) {
                $stmtMulti = $this->pdo->prepare(
                    "UPDATE flussi_rendicontazioni
                     SET is_multibeneficiario = 0
                     WHERE id_dominio = :dom AND is_multibeneficiario IS NULL"
                );
                $stmtMulti->execute([':dom' => $idDominio]);
            }
        } catch (\Throwable $e) {
            error_log("Errore/Deadlock in fixProcessedTefaMapping (is_multibeneficiario): " . $e->getMessage());
        }

        try {
            // Fix NULL values for is_govpay and is_multibeneficiario in tefa_ricevute for this domain
            $checkTefaNulls = $this->pdo->prepare(
                "SELECT 1 FROM tefa_ricevute WHERE id_dominio = :dom AND (is_govpay IS NULL OR is_multibeneficiario IS NULL) LIMIT 1"
            );
            $checkTefaNulls->execute([':dom' => $idDominio]);
            if ($checkTefaNulls->fetchColumn() !== false) {
                $stmtTefaNulls = $this->pdo->prepare(
                    "UPDATE tefa_ricevute
                     SET is_govpay = CASE WHEN is_govpay IS NULL THEN 0 ELSE is_govpay END,
                         is_multibeneficiario = CASE WHEN is_multibeneficiario IS NULL THEN 0 ELSE is_multibeneficiario END
                     WHERE id_dominio = :dom AND (is_govpay IS NULL OR is_multibeneficiario IS NULL)"
                );
                $stmtTefaNulls->execute([':dom' => $idDominio]);
            }
        } catch (\Throwable $e) {
            error_log("Errore/Deadlock in fixProcessedTefaMapping (tefa nulls): " . $e->getMessage());
        }

        $affected = 0;
        try {
            // Check if there are any processed TEFA mappings to fix
            $checkMappings = $this->pdo->prepare(
                "SELECT 1 FROM flussi_rendicontazioni f
                 INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                 WHERE t.id_dominio = :dom
                   AND t.stato = 'PROCESSED'
                   AND (f.mapping_stato != 'PROCESSED' OR f.vocab_stato != 'PROCESSED' OR f.cod_entrata IS NULL OR f.cod_entrata != 'TEFA')
                 LIMIT 1"
            );
            $checkMappings->execute([':dom' => $idDominio]);
            if ($checkMappings->fetchColumn() !== false) {
                $stmt = $this->pdo->prepare(
                    "UPDATE flussi_rendicontazioni f
                     INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                     SET f.mapping_stato = 'PROCESSED',
                         f.vocab_stato = 'PROCESSED',
                         f.cod_entrata = 'TEFA'
                     WHERE t.id_dominio = :dom
                       AND t.stato = 'PROCESSED'
                       AND (f.mapping_stato != 'PROCESSED' OR f.vocab_stato != 'PROCESSED' OR f.cod_entrata IS NULL OR f.cod_entrata != 'TEFA')"
                );
                $stmt->execute([':dom' => $idDominio]);
                $affected = $stmt->rowCount();
            }
        } catch (\Throwable $e) {
            error_log("Errore/Deadlock in fixProcessedTefaMapping (mapping sync): " . $e->getMessage());
        }

        return $affected;
    }

    /** Resetta SKIPPED → PENDING per ri-elaborazione. Ritorna n. righe modificate. */
    public function resetSkipped(string $idDominio): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tefa_ricevute SET stato = \'PENDING\', error_msg = NULL, updated_at = NOW()
             WHERE stato = \'SKIPPED\' AND id_dominio = :id_dominio'
        );
        $stmt->execute([':id_dominio' => $idDominio]);
        return $stmt->rowCount();
    }

    public function getCounts(string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT stato, COUNT(*) AS n FROM tefa_ricevute WHERE id_dominio = :id_dominio GROUP BY stato'
        );
        $stmt->execute([':id_dominio' => $idDominio]);
        $result = ['PENDING' => 0, 'PROCESSED' => 0, 'ERROR' => 0, 'SKIPPED' => 0, 'total' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(string)$row['stato']] = (int)$row['n'];
            $result['total'] += (int)$row['n'];
        }

        // Include non-GovPay processed receipts from biz_ricevute not yet queued in tefa_ricevute
        try {
            $stmt2 = $this->pdo->prepare(
                'SELECT COUNT(*)
                  FROM biz_ricevute b
                  LEFT JOIN tefa_ricevute t
                    ON t.id_dominio = b.id_dominio
                   AND t.iur = b.iur
                  LEFT JOIN flussi_rendicontazioni f
                    ON f.id_dominio = b.id_dominio
                   AND f.iur = b.iur
                   AND f.is_govpay = 1
                  WHERE b.id_dominio = :id_dominio
                    AND b.stato = \'PROCESSED\'
                    AND t.id IS NULL
                    AND f.id IS NULL'
            );
            $stmt2->execute([':id_dominio' => $idDominio]);
            $unqueued = (int)$stmt2->fetchColumn();
            $result['PENDING'] += $unqueued;
            $result['total'] += $unqueued;
        } catch (\Throwable $_) {}

        return $result;
    }


    /**
     * Report aggregato per comune nel range date.
     * @return array<int,array{cf_comune:string,denominazione_comune:string,n_pagamenti:int,totale_tefa:float,totale_comune:float}>
     */
    public function getReport(string $dataDa, string $dataA, string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
               cf_comune,
               MAX(denominazione_comune) AS denominazione_comune,
               COUNT(*) AS n_pagamenti,
               SUM(importo_tefa) AS totale_tefa,
               SUM(importo_comune) AS totale_comune
             FROM tefa_ricevute
             WHERE stato = \'PROCESSED\'
               AND id_dominio = :id_dominio
               AND data_pagamento >= :da
               AND data_pagamento <= :a
             GROUP BY cf_comune
             ORDER BY totale_tefa DESC'
        );
        $stmt->execute([
            ':id_dominio' => $idDominio,
            ':da'         => $dataDa,
            ':a'          => $dataA,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Righe dettagliate per-IUR (per export CSV), JOIN con flussi_rendicontazioni.
     * @return array<int,array<string,mixed>>
     */
    public function getDetailedRows(string $dataDa, string $dataA, string $idDominio): \Generator
    {
        $stmt = $this->pdo->prepare(
            "SELECT
               t.iur, t.iuv, t.anno, t.mese, t.data_pagamento,
               t.stato, t.is_govpay, t.is_multibeneficiario,
               t.importo_tefa, t.importo_comune,
               t.cf_comune, t.denominazione_comune, t.sorgente, t.error_msg,
               COALESCE(f.id_flusso, t.id_flusso) AS id_flusso,
               f.data_flusso, f.data_regolamento, f.trn,
               f.id_psp, f.ragione_psp, f.importo AS importo_originale,
               f.esito, f.stato_rend, f.cod_entrata, f.descrizione_entrata, f.id_pendenza
             FROM tefa_ricevute t
             LEFT JOIN flussi_rendicontazioni f
               ON f.iur = t.iur AND f.id_dominio = t.id_dominio
             WHERE t.id_dominio = :id_dominio
               AND t.stato = 'PROCESSED'
               AND t.data_pagamento >= :da
               AND t.data_pagamento <= :a
             ORDER BY t.data_pagamento DESC, t.id ASC"
        );
        $stmt->execute([':id_dominio' => $idDominio, ':da' => $dataDa, ':a' => $dataA]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    /**
     * Fetch rows by IUR list for a given domain. Returns map [iur => row].
     * @param array<int,string> $iurs
     * @return array<string,array<string,mixed>>
     */
    public function getByIurs(array $iurs, string $idDominio): array
    {
        if ($iurs === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($iurs), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM tefa_ricevute
             WHERE id_dominio = ? AND iur IN ($placeholders)
             ORDER BY id DESC"
        );
        $stmt->execute([$idDominio, ...$iurs]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(string)$row['iur']] = $row;
        }
        return $result;
    }

    /**
     * Copertura mensile: per ogni mese nel range restituisce n. record per stato.
     * Usato per la vista "date scansionate / mancanti".
     * @return array<int,array{anno:int,mese:int,n_total:int,n_processed:int,n_pending:int,n_error:int,n_skipped:int}>
     */
    public function getCoverage(string $dataDa, string $dataA, string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
               anno,
               mese,
               COUNT(*) AS n_total,
               SUM(stato = \'PROCESSED\') AS n_processed,
               SUM(stato = \'PENDING\')   AS n_pending,
               SUM(stato = \'ERROR\')     AS n_error,
               SUM(stato = \'SKIPPED\')   AS n_skipped
             FROM (
               SELECT anno, mese, stato, data_pagamento, id_dominio
               FROM tefa_ricevute
               WHERE id_dominio = :id_dominio

               UNION ALL

               SELECT 
                 b.anno, 
                 b.mese,
                 CASE 
                   WHEN b.stato = \'PENDING\' THEN \'PENDING\'
                   WHEN b.stato = \'PROCESSED\' THEN \'PENDING\'
                   WHEN b.stato = \'ERROR\' THEN \'ERROR\'
                   ELSE \'SKIPPED\'
                 END AS stato,
                 b.data_pagamento,
                 b.id_dominio
                 FROM biz_ricevute b
                 LEFT JOIN tefa_ricevute t ON t.id_dominio = b.id_dominio AND t.iur = b.iur
                 LEFT JOIN flussi_rendicontazioni f ON f.id_dominio = b.id_dominio AND f.iur = b.iur AND f.is_govpay = 1
                 WHERE b.id_dominio = :id_dominio2 AND t.id IS NULL AND f.id IS NULL
             ) AS combined
             WHERE id_dominio = :id_dominio3
               AND (
                 (data_pagamento IS NOT NULL AND data_pagamento >= :da AND data_pagamento <= :a)
                 OR (data_pagamento IS NULL AND MAKEDATE(anno, 1) >= :da2 AND MAKEDATE(anno, 1) <= :a2)
               )
             GROUP BY anno, mese
             ORDER BY anno ASC, mese ASC'
        );
        $stmt->execute([
            ':id_dominio'  => $idDominio,
            ':id_dominio2' => $idDominio,
            ':id_dominio3' => $idDominio,
            ':da'          => $dataDa,
            ':a'           => $dataA,
            ':da2'         => $dataDa,
            ':a2'          => $dataA,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Righe ERROR per un dominio (per display nella UI).
     * @return array<int,array<string,mixed>>
     */
    public function getErrors(string $idDominio, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, iur, iuv, data_pagamento, importo_tefa, error_msg, updated_at
             FROM tefa_ricevute
             WHERE stato = \'ERROR\' AND id_dominio = :id_dominio
             ORDER BY updated_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':id_dominio', $idDominio);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Righe SKIPPED con ratio TEFA/Comune fuori range (anomalie multibeneficiario).
     * JOIN biz_ricevute per descrizione (causale). Fallback parsing importo_comune da error_msg.
     * @return array<int,array<string,mixed>>
     */
    public function getAnomalyRows(string $idDominio, int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.id, t.iur, t.iuv, t.id_flusso, t.data_pagamento, t.importo_tefa, t.importo_comune,
                    t.cf_comune, t.denominazione_comune, t.error_msg, t.is_multibeneficiario,
                    b.descrizione AS causale,
                    b.cf_debitore, b.nominativo_debitore,
                    b.cf_pagante, b.nominativo_pagante,
                    b.company_name AS biz_company,
                    b.trasferimenti AS trasferimenti_json
             FROM tefa_ricevute t
             LEFT JOIN biz_ricevute b ON b.iur = t.iur AND b.id_dominio = t.id_dominio AND b.stato = 'PROCESSED'
             WHERE t.id_dominio = :id_dominio
               AND t.stato = 'SKIPPED'
               AND t.error_msg LIKE 'Rapporto importo TEFA/comune fuori range%'
             ORDER BY t.data_pagamento DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':id_dominio', $idDominio);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Fallback: parse importi e cf_comune da error_msg / trasferimenti per righe vecchie
        foreach ($rows as &$row) {
            if ($row['importo_comune'] === null && preg_match('/comune=([\d.]+)/', (string)$row['error_msg'], $m)) {
                $row['importo_comune'] = (float)$m[1];
            }
            if ($row['importo_tefa'] === null && preg_match('/TEFA=([\d.]+)/', (string)$row['error_msg'], $m)) {
                $row['importo_tefa'] = (float)$m[1];
            }
            // Parse cf_comune dal JSON trasferimenti se ancora null
            if (empty($row['cf_comune']) && !empty($row['trasferimenti_json'])) {
                $transfers = json_decode((string)$row['trasferimenti_json'], true);
                if (is_array($transfers)) {
                    foreach ($transfers as $tr) {
                        $fc = (string)($tr['fiscal_code_pa'] ?? '');
                        if ($fc !== '' && $fc !== $idDominio) {
                            $row['cf_comune'] = $fc;
                            break;
                        }
                    }
                }
            }
            unset($row['trasferimenti_json']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Variante di markSkipped che salva anche i dati comune (per anomalie ratio).
     */
    public function markSkippedWithData(
        int $id,
        string $reason,
        string $cfComune,
        string $denominazioneComune,
        float $importoTefa,
        float $importoComune
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE tefa_ricevute SET
                stato = 'SKIPPED',
                error_msg = :msg,
                cf_comune = :cf,
                denominazione_comune = :denom,
                importo_tefa = :imp_tefa,
                importo_comune = :imp_comune,
                is_multibeneficiario = 1,
                updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute([
            ':msg'       => mb_substr($reason, 0, 500),
            ':id'        => $id,
            ':cf'        => $cfComune,
            ':denom'     => $denominazioneComune,
            ':imp_tefa'  => $importoTefa,
            ':imp_comune'=> $importoComune,
        ]);
    }

    /**
     * Override manuale di un'anomalia ratio.
     * $acceptAsTefa=true → PROCESSED; false → SKIPPED con msg "confermato non TEFA".
     * Cambiare error_msg esclude la riga da getAnomalyRows (LIKE non matcha più).
     */
    public function overrideAnomalyRow(int $id, string $idDominio, bool $acceptAsTefa): void
    {
        if ($acceptAsTefa) {
            $stmt = $this->pdo->prepare(
                "UPDATE tefa_ricevute
                 SET stato = 'PROCESSED',
                     error_msg = 'Override manuale: accettato come TEFA (ratio anomalo)',
                     updated_at = NOW()
                 WHERE id = :id AND id_dominio = :dom AND stato = 'SKIPPED'"
            );
            $stmt->execute([':id' => $id, ':dom' => $idDominio]);

            // Also update flussi_rendicontazioni
            $stmt2 = $this->pdo->prepare(
                'UPDATE flussi_rendicontazioni f
                 INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                 SET f.mapping_stato = \'PROCESSED\',
                     f.vocab_stato = \'PROCESSED\',
                     f.cod_entrata = \'TEFA\'
                 WHERE t.id = :id'
            );
            $stmt2->execute([':id' => $id]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE tefa_ricevute
                 SET error_msg = 'Confermato non TEFA (override manuale)',
                     updated_at = NOW()
                 WHERE id = :id AND id_dominio = :dom AND stato = 'SKIPPED'"
            );
            $stmt->execute([':id' => $id, ':dom' => $idDominio]);
        }
    }

    private function ensureTable(): void
    {
        try {
            $this->pdo->query('SELECT 1 FROM tefa_ricevute LIMIT 1');
        } catch (\Throwable $e) {
            if (method_exists($e, 'getCode') && (string)$e->getCode() !== '42S02') {
                return;
            }
            $sql = "CREATE TABLE IF NOT EXISTS tefa_ricevute (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_dominio    VARCHAR(20)     NOT NULL,
  anno          YEAR            NOT NULL,
  mese          TINYINT UNSIGNED NOT NULL,
  id_flusso     VARCHAR(100),
  iur           VARCHAR(35)     NOT NULL,
  iuv           VARCHAR(35),
  data_pagamento DATE,
  importo_tefa  DECIMAL(10,2),
    is_govpay     TINYINT(1),
    is_multibeneficiario TINYINT(1),
  cf_comune     VARCHAR(20),
  denominazione_comune VARCHAR(255),
  importo_comune DECIMAL(10,2),
  sorgente      ENUM('govpay','biz_events') DEFAULT NULL,
  stato         ENUM('PENDING','PROCESSED','ERROR','SKIPPED') NOT NULL DEFAULT 'PENDING',
  error_msg     TEXT,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_iur_dominio (iur, id_dominio),
  INDEX idx_stato_id (stato, id),
  INDEX idx_dominio_anno_mese (id_dominio, anno, mese)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            try {
                $this->pdo->exec($sql);
            } catch (\Throwable $_ignore) {}

            return;
        }

        try {
            $this->pdo->exec('ALTER TABLE tefa_ricevute ADD COLUMN is_govpay TINYINT(1) NULL AFTER importo_tefa');
        } catch (\Throwable $_ignore) {
        }

        try {
            $this->pdo->exec('ALTER TABLE tefa_ricevute ADD COLUMN is_multibeneficiario TINYINT(1) NULL AFTER is_govpay');
        } catch (\Throwable $_ignore) {
        }
    }
}
