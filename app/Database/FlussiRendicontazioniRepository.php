<?php
declare(strict_types=1);

namespace App\Database;

use PDO;

class FlussiRendicontazioniRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Connection::getPDO();
        $this->ensureTable();
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function upsertBatch(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $sql = 'INSERT INTO flussi_rendicontazioni
            (id_dominio, id_flusso, data_flusso, data_regolamento, trn, id_psp, ragione_psp,
             anno, mese, iur, iuv, importo, esito, stato_rend, indice, data_pagamento,
             cod_entrata, descrizione_entrata, id_pendenza, is_govpay, is_multibeneficiario)
            VALUES
            (:id_dominio, :id_flusso, :data_flusso, :data_regolamento, :trn, :id_psp, :ragione_psp,
             :anno, :mese, :iur, :iuv, :importo, :esito, :stato_rend, :indice, :data_pagamento,
             :cod_entrata, :descrizione_entrata, :id_pendenza, :is_govpay, :is_multibeneficiario)
            ON DUPLICATE KEY UPDATE
             id_flusso = VALUES(id_flusso),
             data_flusso = VALUES(data_flusso),
             data_regolamento = VALUES(data_regolamento),
             trn = VALUES(trn),
             id_psp = VALUES(id_psp),
             ragione_psp = VALUES(ragione_psp),
             anno = VALUES(anno),
             mese = VALUES(mese),
             iuv = VALUES(iuv),
             importo = VALUES(importo),
             esito = VALUES(esito),
             stato_rend = VALUES(stato_rend),
             indice = VALUES(indice),
             data_pagamento = VALUES(data_pagamento),
             cod_entrata = VALUES(cod_entrata),
             descrizione_entrata = VALUES(descrizione_entrata),
             id_pendenza = VALUES(id_pendenza),
             is_govpay = VALUES(is_govpay),
             is_multibeneficiario = VALUES(is_multibeneficiario),
             synced_at = CURRENT_TIMESTAMP';

        $stmt = $this->pdo->prepare($sql);
        $inserted = 0;

        foreach ($rows as $row) {
            $stmt->execute([
                ':id_dominio' => (string)($row['id_dominio'] ?? ''),
                ':id_flusso' => (string)($row['id_flusso'] ?? ''),
                ':data_flusso' => $this->normalizeDate($row['data_flusso'] ?? null),
                ':data_regolamento' => $this->normalizeDate($row['data_regolamento'] ?? null),
                ':trn' => $this->normalizeString($row['trn'] ?? null),
                ':id_psp' => $this->normalizeString($row['id_psp'] ?? null),
                ':ragione_psp' => $this->normalizeString($row['ragione_psp'] ?? null),
                ':anno' => (int)($row['anno'] ?? 0),
                ':mese' => (int)($row['mese'] ?? 0),
                ':iur' => (string)($row['iur'] ?? ''),
                ':iuv' => $this->normalizeString($row['iuv'] ?? null),
                ':importo' => $this->normalizeDecimal($row['importo'] ?? null),
                ':esito' => $this->normalizeInt($row['esito'] ?? null),
                ':stato_rend' => $this->normalizeString($row['stato_rend'] ?? null),
                ':indice' => $this->normalizeInt($row['indice'] ?? null),
                ':data_pagamento' => $this->normalizeDate($row['data_pagamento'] ?? null),
                ':cod_entrata' => $this->normalizeString($row['cod_entrata'] ?? null),
                ':descrizione_entrata' => $this->normalizeString($row['descrizione_entrata'] ?? null),
                ':id_pendenza' => $this->normalizeString($row['id_pendenza'] ?? null),
                ':is_govpay' => $this->normalizeBoolInt($row['is_govpay'] ?? null),
                ':is_multibeneficiario' => $this->normalizeBoolInt($row['is_multibeneficiario'] ?? null),
            ]);

            // MySQL rowCount for INSERT ... ON DUPLICATE KEY UPDATE:
            // 1 = inserted row, 2 = duplicate row updated.
            if ($stmt->rowCount() === 1) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * @param array<int,string> $codEntrate
     * @return array<int,array<string,mixed>>
     */
    public function getForReport(
        string $idDominio,
        string $dataDa,
        string $dataA,
        array $codEntrate,
        int $offset,
        int $limit
    ): array {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        [$whereSql, $params] = $this->buildReportWhereF($idDominio, $dataDa, $dataA, $codEntrate);

        $sql = "SELECT
                f.data_flusso,
                f.data_regolamento,
                f.id_flusso,
                f.trn,
                f.id_psp,
                f.id_dominio,
                CASE WHEN f.is_govpay = 0 AND t.id IS NOT NULL THEN 'TEFA'
                     WHEN f.is_govpay = 0 THEN 'ESTERNA'
                     ELSE COALESCE(f.cod_entrata, 'N/D')
                END AS tassonomia,
                 CASE WHEN f.is_govpay = 0 AND t.id IS NOT NULL THEN 'TEFA'
                     WHEN f.is_govpay = 0 THEN 'Tipologia esterna'
                     ELSE COALESCE(f.cod_entrata, 'N/D')
                 END AS tassonomia_label,
                f.iuv,
                f.iur,
                f.indice,
                f.importo,
                f.esito,
                f.stato_rend,
                f.data_pagamento,
                f.descrizione_entrata AS descrizione_voce,
                f.id_pendenza,
                f.is_govpay
            FROM flussi_rendicontazioni f
            LEFT JOIN tefa_ricevute t
              ON t.iur = f.iur
             AND t.id_dominio = f.id_dominio
             AND t.stato = 'PROCESSED'
            $whereSql
            ORDER BY f.data_pagamento DESC, f.id DESC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int,string> $codEntrate
     */
    public function countForReport(
        string $idDominio,
        string $dataDa,
        string $dataA,
        array $codEntrate
    ): int {
        [$whereSql, $params] = $this->buildReportWhereF($idDominio, $dataDa, $dataA, $codEntrate);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM flussi_rendicontazioni f
             LEFT JOIN tefa_ricevute t
               ON t.iur = f.iur
              AND t.id_dominio = f.id_dominio
              AND t.stato = 'PROCESSED'
             $whereSql"
        );
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * Tutte le righe per il CSV, arricchite con dati Biz Events. Stessa logica di
     * getForReport ma senza paginazione e con LEFT JOIN su biz_ricevute.
     * @param array<int,string> $codEntrate
     * @return array<int,array<string,mixed>>
     */
    public function getForCsvWithBiz(
        string $idDominio,
        string $dataDa,
        string $dataA,
        array $codEntrate
    ): array {
        [$whereSql, $params] = $this->buildReportWhereF($idDominio, $dataDa, $dataA, $codEntrate);

        $sql = "SELECT
                f.data_flusso,
                f.data_regolamento,
                f.id_flusso,
                f.trn,
                f.id_psp,
                f.ragione_psp,
                f.id_dominio,
                CASE WHEN f.is_govpay = 0 AND t.id IS NOT NULL THEN 'TEFA'
                     WHEN f.is_govpay = 0 THEN 'ESTERNA'
                     ELSE COALESCE(f.cod_entrata, 'N/D')
                END AS tassonomia,
                 CASE WHEN f.is_govpay = 0 AND t.id IS NOT NULL THEN 'TEFA'
                     WHEN f.is_govpay = 0 THEN 'Tipologia esterna'
                     ELSE COALESCE(f.cod_entrata, 'N/D')
                 END AS tassonomia_label,
                f.iuv,
                f.iur,
                f.indice,
                f.importo,
                f.esito,
                f.stato_rend,
                f.data_pagamento,
                f.descrizione_entrata AS descrizione_voce,
                f.id_pendenza,
                f.is_govpay,
                b.descrizione AS biz_descrizione,
                b.cf_debitore,
                b.nominativo_debitore,
                b.cf_pagante,
                b.nominativo_pagante,
                b.company_name AS biz_company_name
            FROM flussi_rendicontazioni f
            LEFT JOIN tefa_ricevute t
              ON t.iur = f.iur
             AND t.id_dominio = f.id_dominio
             AND t.stato = 'PROCESSED'
            LEFT JOIN biz_ricevute b
              ON b.iur = f.iur
             AND b.id_dominio = f.id_dominio
             AND b.stato = 'PROCESSED'
            $whereSql
            ORDER BY f.data_pagamento DESC, f.id DESC";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getUnprocessedForTefa(string $idDominio, int $limit, ?string $minDate = null): array
    {
        $limit = max(1, $limit);

        $sql = 'SELECT
                f.id_dominio,
                f.anno,
                f.mese,
                f.id_flusso,
                f.iur,
                f.iuv,
                f.data_pagamento,
                f.importo,
                f.is_govpay,
                f.is_multibeneficiario
            FROM flussi_rendicontazioni f
            LEFT JOIN tefa_ricevute t
              ON t.id_dominio = f.id_dominio
             AND t.iur = f.iur
            WHERE f.id_dominio = :id_dominio
                            AND t.id IS NULL';

        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND f.data_pagamento >= :min_date';
        }

        $sql .= '
                ORDER BY f.data_pagamento ASC, f.id ASC
            LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id_dominio', $idDominio);
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $stmt->bindValue(':min_date', $minDate);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMinSyncDate(string $idDominio): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MIN(data_pagamento) FROM flussi_rendicontazioni WHERE id_dominio = :id_dominio');
        $stmt->execute([':id_dominio' => $idDominio]);

        $value = $stmt->fetchColumn();
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    public function countUnprocessedForTefa(string $idDominio, ?string $minDate = null): int
    {
        $sql = 'SELECT COUNT(*)
            FROM flussi_rendicontazioni f
            LEFT JOIN tefa_ricevute t
              ON t.id_dominio = f.id_dominio
             AND t.iur = f.iur
            WHERE f.id_dominio = :id_dominio
                            AND t.id IS NULL';

        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND f.data_pagamento >= :min_date';
        }

        $stmt = $this->pdo->prepare($sql);
        $params = [':id_dominio' => $idDominio];
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $params[':min_date'] = $minDate;
        }
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getUnprocessedForBiz(string $idDominio, int $limit, ?string $minDate = null): array
    {
        $limit = max(1, $limit);

        $sql = 'SELECT
                f.id_dominio,
                f.anno,
                f.mese,
                f.id_flusso,
                f.iur,
                f.iuv,
                f.data_pagamento,
                f.importo
            FROM flussi_rendicontazioni f
            LEFT JOIN biz_ricevute b
              ON b.id_dominio = f.id_dominio
             AND b.iur = f.iur
            WHERE f.id_dominio = :id_dominio
              AND f.is_govpay = 0
              AND b.id IS NULL';

        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND f.data_pagamento >= :min_date';
        }

        $sql .= '
            ORDER BY f.data_pagamento ASC, f.id ASC
            LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id_dominio', $idDominio);
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $stmt->bindValue(':min_date', $minDate);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countUnprocessedForBiz(string $idDominio, ?string $minDate = null): int
    {
        $sql = 'SELECT COUNT(*)
            FROM flussi_rendicontazioni f
            LEFT JOIN biz_ricevute b
              ON b.id_dominio = f.id_dominio
             AND b.iur = f.iur
            WHERE f.id_dominio = :id_dominio
              AND f.is_govpay = 0
              AND b.id IS NULL';

        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND f.data_pagamento >= :min_date';
        }

        $stmt = $this->pdo->prepare($sql);
        $params = [':id_dominio' => $idDominio];
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $params[':min_date'] = $minDate;
        }
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function deleteByDateRange(string $idDominio, string $dataDa, string $dataA): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM flussi_rendicontazioni
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

    public function hasGovPayPendenza(string $idDominio, string $iur): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM flussi_rendicontazioni
             WHERE id_dominio = :id_dominio
               AND iur = :iur
               AND NULLIF(TRIM(id_pendenza), \'\') IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':id_dominio' => $idDominio,
            ':iur' => $iur,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array{is_govpay:bool,is_multibeneficiario:?bool}|null
     */
    public function getTefaHintsForIur(string $idDominio, string $iur): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_pendenza, is_govpay, is_multibeneficiario
             FROM flussi_rendicontazioni
             WHERE id_dominio = :id_dominio
               AND iur = :iur
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':id_dominio' => $idDominio,
            ':iur' => $iur,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $idPendenza = trim((string)($row['id_pendenza'] ?? ''));
        $isGovPayRaw = $row['is_govpay'] ?? null;
        $isMultiRaw = $row['is_multibeneficiario'] ?? null;

        return [
            'is_govpay' => $isGovPayRaw === null ? ($idPendenza !== '') : ((int)$isGovPayRaw === 1),
            'is_multibeneficiario' => $isMultiRaw === null ? null : ((int)$isMultiRaw === 1),
        ];
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
            "SELECT * FROM flussi_rendicontazioni
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

    public function ensureTable(): void
    {
        try {
            $this->pdo->query('SELECT 1 FROM flussi_rendicontazioni LIMIT 1');
        } catch (\Throwable $e) {
            if (method_exists($e, 'getCode') && (string)$e->getCode() !== '42S02') {
                return;
            }

            $sql = "CREATE TABLE IF NOT EXISTS flussi_rendicontazioni (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_dominio          VARCHAR(20) NOT NULL,
  id_flusso           VARCHAR(100) NOT NULL,
  data_flusso         DATE,
  data_regolamento    DATE,
  trn                 VARCHAR(50),
  id_psp              VARCHAR(50),
  ragione_psp         VARCHAR(255),
  anno                SMALLINT UNSIGNED NOT NULL,
  mese                TINYINT UNSIGNED NOT NULL,
  iur                 VARCHAR(35) NOT NULL,
  iuv                 VARCHAR(35),
  importo             DECIMAL(10,2),
  esito               TINYINT,
  stato_rend          VARCHAR(20),
  indice              SMALLINT,
  data_pagamento      DATE,
  cod_entrata         VARCHAR(100),
  descrizione_entrata VARCHAR(500),
  id_pendenza         VARCHAR(100),
    is_govpay           TINYINT(1) NULL,
    is_multibeneficiario TINYINT(1) NULL,
  synced_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_iur_dominio (iur, id_dominio),
  INDEX idx_dominio_flusso (id_dominio, id_flusso),
  INDEX idx_dominio_data (id_dominio, data_pagamento),
  INDEX idx_dominio_anno_mese (id_dominio, anno, mese),
  INDEX idx_dominio_entrata (id_dominio, cod_entrata)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            try {
                $this->pdo->exec($sql);
            } catch (\Throwable $_ignore) {
            }

            return;
        }

        try {
            $this->pdo->exec('ALTER TABLE flussi_rendicontazioni ADD COLUMN is_multibeneficiario TINYINT(1) NULL AFTER id_pendenza');
        } catch (\Throwable $_ignore) {
        }

        try {
            $this->pdo->exec('ALTER TABLE flussi_rendicontazioni ADD COLUMN is_govpay TINYINT(1) NULL AFTER id_pendenza');
        } catch (\Throwable $_ignore) {
        }
    }

    /**
     * WHERE builder con alias `f.` per query con JOIN. Se cod_entrata filter attivo,
     * include sempre righe non-GovPay (is_govpay = 0) indipendentemente dalla tassonomia.
     * @param array<int,string> $codEntrate
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildReportWhereF(string $idDominio, string $dataDa, string $dataA, array $codEntrate): array
    {
        $conditions = ['f.id_dominio = :id_dominio'];
        $params = [':id_dominio' => $idDominio];

        if ($dataDa !== '') {
            $conditions[] = 'f.data_pagamento >= :data_da';
            $params[':data_da'] = $dataDa;
        }
        if ($dataA !== '') {
            $conditions[] = 'f.data_pagamento <= :data_a';
            $params[':data_a'] = $dataA;
        }

        $codEntrate = array_values(array_filter(array_map(static fn(mixed $v): string => trim((string)$v), $codEntrate)));
        if ($codEntrate !== []) {
            $placeholders = [];
            foreach ($codEntrate as $idx => $code) {
                $key = ':cod_' . $idx;
                $placeholders[] = $key;
                $params[$key] = $code;
            }
            $conditions[] = '(f.is_govpay = 0 OR f.cod_entrata IN (' . implode(', ', $placeholders) . '))';
        }

        return ['WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<int,string> $codEntrate
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildReportWhere(string $idDominio, string $dataDa, string $dataA, array $codEntrate): array
    {
        $where = ['id_dominio = :id_dominio'];
        $params = [':id_dominio' => $idDominio];

        if ($dataDa !== '') {
            $where[] = 'data_pagamento >= :data_da';
            $params[':data_da'] = $dataDa;
        }

        if ($dataA !== '') {
            $where[] = 'data_pagamento <= :data_a';
            $params[':data_a'] = $dataA;
        }

        $codEntrate = array_values(array_filter(array_map(static fn(mixed $v): string => trim((string)$v), $codEntrate)));
        if ($codEntrate !== []) {
            $placeholders = [];
            foreach ($codEntrate as $idx => $code) {
                $key = ':cod_' . $idx;
                $placeholders[] = $key;
                $params[$key] = $code;
            }
            $where[] = 'cod_entrata IN (' . implode(', ', $placeholders) . ')';
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function normalizeDate(mixed $value): ?string
    {
        $str = trim((string)$value);
        if ($str === '') {
            return null;
        }

        return strlen($str) >= 10 ? substr($str, 0, 10) : null;
    }

    private function normalizeString(mixed $value): ?string
    {
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int)$value;
    }

    private function normalizeDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float)$value;
    }

    private function normalizeBoolInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true ? 1 : 0;
    }
}
