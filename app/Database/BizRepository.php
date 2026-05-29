<?php
declare(strict_types=1);

namespace App\Database;

use PDO;

class BizRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Connection::getPDO();
        $this->ensureTable();
    }

    /**
     * Bulk-insert rendicontazioni non-govpay come PENDING. Ignora duplicati (uq_iur_dominio).
     * @param array<int,array{id_dominio:string,anno:int,mese:int,id_flusso:string,iur:string,iuv:string,data_pagamento:string,importo:float}> $rows
     */
    public function upsertPending(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $sql = 'INSERT INTO biz_ricevute
                    (id_dominio, anno, mese, id_flusso, iur, iuv, data_pagamento, importo, stato, created_at, updated_at)
                VALUES
                    (:id_dominio, :anno, :mese, :id_flusso, :iur, :iuv, :data_pagamento, :importo, \'PENDING\', NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    id_flusso      = VALUES(id_flusso),
                    iuv            = VALUES(iuv),
                    data_pagamento = VALUES(data_pagamento),
                    importo        = VALUES(importo),
                    updated_at     = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $inserted = 0;
        foreach ($rows as $r) {
            $stmt->execute([
                ':id_dominio'     => $r['id_dominio'],
                ':anno'           => $r['anno'],
                ':mese'           => $r['mese'],
                ':id_flusso'      => $r['id_flusso'] ?? null,
                ':iur'            => $r['iur'],
                ':iuv'            => $r['iuv'] ?? null,
                ':data_pagamento' => ($r['data_pagamento'] ?? '') !== '' ? $r['data_pagamento'] : null,
                ':importo'        => $r['importo'] ?? null,
            ]);
            if ($stmt->rowCount() === 1) {
                $inserted++;
            }
        }
        return $inserted;
    }

    /** Restituisce l'id_flusso del prossimo flusso con record PENDING (ordine cronologico). */
    public function getNextPendingFlusso(string $idDominio, ?string $minDate = null): ?string
    {
        $sql = 'SELECT id_flusso, MIN(data_pagamento) AS min_data_riferimento FROM biz_ricevute
             WHERE id_dominio = :dom AND stato = \'PENDING\'';
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND data_pagamento >= :min_date';
        }
        $sql .= ' GROUP BY id_flusso ORDER BY min_data_riferimento ASC, id_flusso ASC LIMIT 1';

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
        $sql = 'SELECT * FROM biz_ricevute
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
        $sql = 'SELECT COUNT(*) FROM biz_ricevute
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

    /**
     * Segna un record come PROCESSED salvando tutti i dati della ricevuta Biz Events.
     * @param array{descrizione?:string,cf_debitore?:string,nominativo_debitore?:string,cf_pagante?:string,nominativo_pagante?:string,company_name?:string,trasferimenti?:string} $bizData
     */
    public function markProcessed(int $id, array $bizData): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE biz_ricevute SET
               stato               = \'PROCESSED\',
               descrizione         = :descrizione,
               cf_debitore         = :cf_debitore,
               nominativo_debitore = :nominativo_debitore,
               cf_pagante          = :cf_pagante,
               nominativo_pagante  = :nominativo_pagante,
               company_name        = :company_name,
               trasferimenti       = :trasferimenti,
               error_msg           = NULL,
               updated_at          = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':descrizione'         => $bizData['descrizione'] ?? null,
            ':cf_debitore'         => $bizData['cf_debitore'] ?? null,
            ':nominativo_debitore' => $bizData['nominativo_debitore'] ?? null,
            ':cf_pagante'          => $bizData['cf_pagante'] ?? null,
            ':nominativo_pagante'  => $bizData['nominativo_pagante'] ?? null,
            ':company_name'        => $bizData['company_name'] ?? null,
            ':trasferimenti'       => $bizData['trasferimenti'] ?? null,
            ':id'                  => $id,
        ]);
    }

    public function markError(int $id, string $msg): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE biz_ricevute SET stato = \'ERROR\', error_msg = :msg, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':msg' => mb_substr($msg, 0, 1000), ':id' => $id]);
    }

    public function markSkipped(int $id, string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE biz_ricevute SET stato = \'SKIPPED\', error_msg = :msg, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':msg' => mb_substr($reason, 0, 500), ':id' => $id]);
    }

    public function resetErrors(string $idDominio): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE biz_ricevute SET stato = \'PENDING\', error_msg = NULL, updated_at = NOW()
             WHERE stato = \'ERROR\' AND id_dominio = :id_dominio'
        );
        $stmt->execute([':id_dominio' => $idDominio]);
        return $stmt->rowCount();
    }

    public function resetSkipped(string $idDominio): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE biz_ricevute SET stato = \'PENDING\', error_msg = NULL, updated_at = NOW()
             WHERE stato = \'SKIPPED\' AND id_dominio = :id_dominio'
        );
        $stmt->execute([':id_dominio' => $idDominio]);
        return $stmt->rowCount();
    }

    public function getCounts(string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT stato, COUNT(*) AS n FROM biz_ricevute WHERE id_dominio = :id_dominio GROUP BY stato'
        );
        $stmt->execute([':id_dominio' => $idDominio]);
        $result = ['PENDING' => 0, 'PROCESSED' => 0, 'ERROR' => 0, 'SKIPPED' => 0, 'total' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(string)$row['stato']] = (int)$row['n'];
            $result['total'] += (int)$row['n'];
        }
        return $result;
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
            "SELECT * FROM biz_ricevute
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
     * Restituisce record PROCESSED in biz_ricevute non ancora presenti in tefa_ricevute.
     * Usato da TefaScannerService per accodare il lavoro del demone TEFA.
     * @return array<int,array<string,mixed>>
     */
    public function getProcessedForTefa(string $idDominio, int $limit, ?string $minDate = null): array
    {
        $limit = max(1, $limit);

        $sql = 'SELECT b.*
            FROM biz_ricevute b
            LEFT JOIN tefa_ricevute t
              ON t.id_dominio = b.id_dominio
             AND t.iur = b.iur
            WHERE b.id_dominio = :id_dominio
              AND b.stato = \'PROCESSED\'
              AND t.id IS NULL';

        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND b.data_pagamento >= :min_date';
        }

        $sql .= ' ORDER BY b.data_pagamento ASC, b.id ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id_dominio', $idDominio);
        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $stmt->bindValue(':min_date', $minDate);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countProcessedForTefa(string $idDominio, ?string $minDate = null): int
    {
        $sql = 'SELECT COUNT(*)
            FROM biz_ricevute b
            LEFT JOIN tefa_ricevute t
              ON t.id_dominio = b.id_dominio
             AND t.iur = b.iur
            WHERE b.id_dominio = :id_dominio
              AND b.stato = \'PROCESSED\'
              AND t.id IS NULL';

        if ($minDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDate)) {
            $sql .= ' AND b.data_pagamento >= :min_date';
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
            'DELETE FROM biz_ricevute
             WHERE id_dominio = :id_dominio
               AND data_pagamento >= :data_da
               AND data_pagamento <= :data_a'
        );
        $stmt->execute([
            ':id_dominio' => $idDominio,
            ':data_da'    => $dataDa,
            ':data_a'     => $dataA,
        ]);
        return $stmt->rowCount();
    }

    /**
     * Copertura mensile da biz_ricevute (usato quando TEFA è disabilitato).
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
             FROM biz_ricevute
             WHERE id_dominio = :id_dominio
               AND (
                 (data_pagamento IS NOT NULL AND data_pagamento >= :da AND data_pagamento <= :a)
                 OR (data_pagamento IS NULL AND MAKEDATE(anno, 1) >= :da2 AND MAKEDATE(anno, 1) <= :a2)
               )
             GROUP BY anno, mese
             ORDER BY anno ASC, mese ASC'
        );
        $stmt->execute([
            ':id_dominio' => $idDominio,
            ':da'         => $dataDa,
            ':a'          => $dataA,
            ':da2'        => $dataDa,
            ':a2'         => $dataA,
        ]);
        return array_map(static function (array $r): array {
            return [
                'anno'        => (int)$r['anno'],
                'mese'        => (int)$r['mese'],
                'n_total'     => (int)$r['n_total'],
                'n_processed' => (int)$r['n_processed'],
                'n_pending'   => (int)$r['n_pending'],
                'n_error'     => (int)$r['n_error'],
                'n_skipped'   => (int)$r['n_skipped'],
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
    }

    /** Righe ERROR per un dominio. */
    public function getErrors(string $idDominio, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, iur, iuv, data_pagamento, importo, error_msg, updated_at
             FROM biz_ricevute
             WHERE stato = \'ERROR\' AND id_dominio = :id_dominio
             ORDER BY updated_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':id_dominio', $idDominio);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function ensureTable(): void
    {
        try {
            $this->pdo->query('SELECT 1 FROM biz_ricevute LIMIT 1');
        } catch (\Throwable $e) {
            if (method_exists($e, 'getCode') && (string)$e->getCode() !== '42S02') {
                return;
            }
            $sql = "CREATE TABLE IF NOT EXISTS biz_ricevute (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  id_dominio           VARCHAR(20)     NOT NULL,
  id_flusso            VARCHAR(100),
  iur                  VARCHAR(35)     NOT NULL,
  iuv                  VARCHAR(35),
  anno                 YEAR            NOT NULL,
  mese                 TINYINT UNSIGNED NOT NULL,
  data_pagamento       DATE,
  importo              DECIMAL(10,2),
  descrizione          VARCHAR(512),
  cf_debitore          VARCHAR(35),
  nominativo_debitore  VARCHAR(255),
  cf_pagante           VARCHAR(35),
  nominativo_pagante   VARCHAR(255),
  company_name         VARCHAR(255),
  trasferimenti        JSON,
  stato                ENUM('PENDING','PROCESSED','ERROR','SKIPPED') NOT NULL DEFAULT 'PENDING',
  error_msg            TEXT,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_iur_dominio (iur, id_dominio),
  INDEX idx_stato_id (stato, id),
  INDEX idx_dominio_anno_mese (id_dominio, anno, mese)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            try {
                $this->pdo->exec($sql);
            } catch (\Throwable $_ignore) {}
        }
    }
}
