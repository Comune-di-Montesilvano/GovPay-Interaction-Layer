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
             cod_entrata, descrizione_entrata, id_pendenza)
            VALUES
            (:id_dominio, :id_flusso, :data_flusso, :data_regolamento, :trn, :id_psp, :ragione_psp,
             :anno, :mese, :iur, :iuv, :importo, :esito, :stato_rend, :indice, :data_pagamento,
             :cod_entrata, :descrizione_entrata, :id_pendenza)
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
             synced_at = CURRENT_TIMESTAMP';

        $stmt = $this->pdo->prepare($sql);
        $affected = 0;

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
            ]);

            $affected += $stmt->rowCount();
        }

        return $affected;
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

        [$whereSql, $params] = $this->buildReportWhere($idDominio, $dataDa, $dataA, $codEntrate);

        $sql = 'SELECT
                data_flusso,
                data_regolamento,
                id_flusso,
                trn,
                id_psp,
                id_dominio,
                cod_entrata AS tassonomia,
                COALESCE(descrizione_entrata, cod_entrata, "N/D") AS tassonomia_label,
                iuv,
                iur,
                indice,
                importo,
                esito,
                stato_rend,
                data_pagamento,
                descrizione_entrata AS descrizione_voce,
                id_pendenza
            FROM flussi_rendicontazioni
            ' . $whereSql . '
            ORDER BY data_flusso DESC, id DESC
            LIMIT :limit OFFSET :offset';

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
        [$whereSql, $params] = $this->buildReportWhere($idDominio, $dataDa, $dataA, $codEntrate);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM flussi_rendicontazioni ' . $whereSql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getUnprocessedForTefa(string $idDominio, int $limit): array
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
            LEFT JOIN tefa_ricevute t
              ON t.id_dominio = f.id_dominio
             AND t.iur = f.iur
            WHERE f.id_dominio = :id_dominio
              AND t.id IS NULL
            ORDER BY f.data_pagamento ASC, f.id ASC
            LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id_dominio', $idDominio);
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
        }
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
            $where[] = 'data_flusso >= :data_da';
            $params[':data_da'] = $dataDa;
        }

        if ($dataA !== '') {
            $where[] = 'data_flusso <= :data_a';
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
}
