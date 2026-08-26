<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use DateTimeImmutable;

class MassivePendenzeRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Connection::getPDO();
        $this->ensureTable();
    }

    public function insertPending(string $fileBatchId, int $riga, array $payload, ?string $errore = null): int
    {
        $sql = 'INSERT INTO pendenze_massive (file_batch_id, riga, stato, errore, payload_json, created_at, updated_at)
                VALUES (:batch, :riga, :stato, :errore, :payload, :created, :updated)';
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':batch' => $fileBatchId,
            ':riga' => $riga,
            ':stato' => $errore ? 'ERROR' : 'PENDING',
            ':errore' => $errore,
            ':payload' => self::safeJsonEncode($payload),
            ':created' => $now,
            ':updated' => $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * json_encode() ritorna false su byte UTF-8 invalidi (es. CF corrotto da encoding CSV) senza
     * eccezione: il valore false passato a PDO viola il CHECK JSON_VALID sulla colonna. Sostituisce
     * i byte invalidi con U+FFFD invece di fallire, e garantisce comunque una stringa JSON valida.
     */
    private static function safeJsonEncode(array $data): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($data, $flags);
        if ($json === false) {
            // Fallback estremo: dump errore invece di un false non-JSON
            $json = json_encode(['_encode_error' => json_last_error_msg()], JSON_UNESCAPED_SLASHES);
        }
        return (string)$json;
    }

    public function setProcessing(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE pendenze_massive SET stato = "PROCESSING", updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function setResult(int $id, bool $ok, ?array $response = null, ?string $errore = null): void
    {
        $stmt = $this->pdo->prepare('UPDATE pendenze_massive SET stato = :stato, response_json = :resp, errore = :err, updated_at = NOW() WHERE id = :id');
        $stmt->execute([
            ':stato' => $ok ? 'SUCCESS' : 'ERROR',
            ':resp' => $response ? self::safeJsonEncode($response) : null,
            ':err' => $errore,
            ':id' => $id,
        ]);
    }

    /**
     * Coppie identificativo(CF/PIVA)+causale già presenti in batch odierni (qualsiasi file_batch_id),
     * per rilevare duplicati cross-batch nello stesso giorno. Considera righe non ancora fallite
     * (PENDING/PROCESSING/SUCCESS): una riga ERROR non rappresenta una pendenza realmente creata.
     *
     * @return array<string,bool> chiavi "IDENTIFICATIVO|CAUSALE" (uppercase/trim) già presenti oggi
     */
    public function findTodayIdentCausaleKeys(): array
    {
        $sql = "SELECT
                    UPPER(TRIM(COALESCE(
                        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.soggettoPagatore.identificativo')),
                        JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.identificativo'))
                    ))) AS ident,
                    UPPER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.causale')))) AS causale
                FROM pendenze_massive
                WHERE DATE(created_at) = CURDATE()
                  AND stato IN ('PENDING','PROCESSING','SUCCESS')";
        $stmt = $this->pdo->query($sql);
        $keys = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $ident = (string)($row['ident'] ?? '');
            $causale = (string)($row['causale'] ?? '');
            if ($ident === '' || $causale === '') continue;
            $keys[$ident . '|' . $causale] = true;
        }
        return $keys;
    }

    public function listByBatch(string $fileBatchId, ?string $stato = null, int $page = 1, int $perPage = 50): array
    {
        $offset = max(0, ($page - 1) * $perPage);
        $params = [':batch' => $fileBatchId, ':limit' => $perPage, ':offset' => $offset];
        $sql = 'SELECT * FROM pendenze_massive WHERE file_batch_id = :batch';
        if ($stato) { $sql .= ' AND stato = :stato'; $params[':stato'] = $stato; }
        $sql .= ' ORDER BY riga ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            if (in_array($k, [':limit', ':offset'], true)) {
                $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($k, $v);
            }
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    public function countByBatch(string $fileBatchId, ?string $stato = null): int
    {
        $sql = 'SELECT COUNT(*) FROM pendenze_massive WHERE file_batch_id = :batch';
        $params = [':batch' => $fileBatchId];
        if ($stato) { $sql .= ' AND stato = :stato'; $params[':stato'] = $stato; }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Fetch next pending records to process.
     * @return array<int, array{id:int,file_batch_id:string,riga:int,stato:string,payload_json:?string}>
     */
    public function fetchPending(int $limit = 50): array
    {
        $limit = max(1, $limit);
        $sql = 'SELECT id, file_batch_id, riga, stato, payload_json FROM pendenze_massive WHERE stato = "PENDING" ORDER BY id ASC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Aggiorna in blocco lo stato delle pendenze di un determinato batch.
     * Restituisce le righe modificate.
     */
    public function updateBatchStatus(string $fileBatchId, string $fromStatus, string $toStatus): int
    {
        $sql = 'UPDATE pendenze_massive SET stato = :toStato, updated_at = NOW() WHERE file_batch_id = :batch AND stato = :fromStato';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':batch' => $fileBatchId,
            ':fromStato' => $fromStatus,
            ':toStato' => $toStatus
        ]);
        return $stmt->rowCount();
    }

    /**
     * Aggiorna lo stato ed eventualmente l'errore di una singola riga.
     */
    public function updateRowStatus(int $id, string $status, ?string $errore = null, bool $updateError = false): void
    {
        $sql = 'UPDATE pendenze_massive SET stato = :stato, updated_at = NOW()';
        $params = [':stato' => $status, ':id' => $id];
        if ($updateError) {
            $sql .= ', errore = :err';
            $params[':err'] = $errore;
        }
        $sql .= ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Recupera le pendenze in coda per l'annullamento.
     */
    public function fetchCancelPending(int $limit = 50): array
    {
        $limit = max(1, $limit);
        $sql = 'SELECT id, file_batch_id, riga, stato, payload_json, response_json FROM pendenze_massive WHERE stato = "CANCEL_PENDING" ORDER BY id ASC LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Marca una riga in elaborazione per l'annullamento.
     */
    public function setCancelProcessing(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE pendenze_massive SET stato = "CANCEL_PROCESSING", updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Elimina tutte le pendenze di un lotto. 
     * Ritorna le righe eliminate.
     */
    public function deleteBatch(string $fileBatchId): int
    {
        $sql = 'DELETE FROM pendenze_massive WHERE file_batch_id = :batch';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':batch' => $fileBatchId]);
        return $stmt->rowCount();
    }

    /**
     * Crea la tabella se assente (idempotente). Evita errori 42S02 se le migrazioni non sono ancora state eseguite.
     */
    private function ensureTable(): void
    {
        try {
            $this->pdo->query('SELECT 1 FROM pendenze_massive LIMIT 1');
            // Try to alter table safely (adds PAUSED, CANCELLED, CANCEL_PENDING, CANCEL_PROCESSING to enum if missing)
            try {
                $this->pdo->exec("ALTER TABLE pendenze_massive MODIFY COLUMN stato ENUM('PENDING','PROCESSING','SUCCESS','ERROR','PAUSED','CANCELLED','CANCEL_PENDING','CANCEL_PROCESSING') NOT NULL DEFAULT 'PENDING'");
            } catch (\Throwable $e) {}
        } catch (\Throwable $e) {
            // Solo se table not found (42S02)
            if (method_exists($e, 'getCode') && (string)$e->getCode() !== '42S02') {
                return; // altro errore, non forziamo
            }
            $sql = "CREATE TABLE IF NOT EXISTS pendenze_massive (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_batch_id VARCHAR(64) NOT NULL,
  riga INT UNSIGNED NOT NULL,
  stato ENUM('PENDING','PROCESSING','SUCCESS','ERROR','PAUSED','CANCELLED','CANCEL_PENDING','CANCEL_PROCESSING') NOT NULL DEFAULT 'PENDING',
  errore TEXT NULL,
  payload_json JSON NULL,
  response_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_batch (file_batch_id),
  INDEX idx_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            try { $this->pdo->exec($sql); } catch (\Throwable $_ignore) {}
        }
    }
}
