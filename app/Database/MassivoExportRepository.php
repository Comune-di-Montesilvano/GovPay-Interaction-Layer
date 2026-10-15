<?php
declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * Job asincroni di export ZIP avvisi PDF per batch pendenze massive (tabella pendenze_massive_export).
 */
class MassivoExportRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Connection::getPDO();
        $this->ensureTable();
    }

    public function create(string $fileBatchId, string $createdBy): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO pendenze_massive_export (file_batch_id, stato, created_by) VALUES (:batch, "PENDING", :by)');
        $stmt->execute([':batch' => $fileBatchId, ':by' => $createdBy]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pendenze_massive_export WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Ultimo job del batch (qualsiasi stato). @return array<string,mixed>|null */
    public function findLatestByBatch(string $fileBatchId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pendenze_massive_export WHERE file_batch_id = :batch ORDER BY id DESC LIMIT 1');
        $stmt->execute([':batch' => $fileBatchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function markRunning(int $id, int $totale): void
    {
        $stmt = $this->pdo->prepare('UPDATE pendenze_massive_export SET stato = "RUNNING", totale = :tot, processati = 0, errori = 0 WHERE id = :id');
        $stmt->execute([':tot' => $totale, ':id' => $id]);
    }

    public function updateProgress(int $id, int $processati, int $errori): void
    {
        $stmt = $this->pdo->prepare('UPDATE pendenze_massive_export SET processati = :p, errori = :e WHERE id = :id');
        $stmt->execute([':p' => $processati, ':e' => $errori, ':id' => $id]);
    }

    public function markDone(int $id, string $filePath, int $processati, int $errori): void
    {
        $stmt = $this->pdo->prepare('UPDATE pendenze_massive_export SET stato = "DONE", file_path = :f, processati = :p, errori = :e WHERE id = :id');
        $stmt->execute([':f' => $filePath, ':p' => $processati, ':e' => $errori, ':id' => $id]);
    }

    public function markError(int $id, string $messaggio): void
    {
        $stmt = $this->pdo->prepare('UPDATE pendenze_massive_export SET stato = "ERROR", messaggio = :m WHERE id = :id');
        $stmt->execute([':m' => mb_substr($messaggio, 0, 2000), ':id' => $id]);
    }

    /**
     * Job DONE più vecchi di $giorni: restituisce i file da cancellare e azzera file_path.
     * @return list<string>
     */
    public function expireOlderThan(int $giorni): array
    {
        $stmt = $this->pdo->prepare('SELECT id, file_path FROM pendenze_massive_export WHERE stato = "DONE" AND file_path IS NOT NULL AND updated_at < (NOW() - INTERVAL :g DAY)');
        $stmt->bindValue(':g', $giorni, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $files = [];
        $upd = $this->pdo->prepare('UPDATE pendenze_massive_export SET file_path = NULL, messaggio = "File scaduto" WHERE id = :id');
        foreach ($rows as $r) {
            $files[] = (string)$r['file_path'];
            $upd->execute([':id' => $r['id']]);
        }
        return $files;
    }

    /** Crea la tabella se assente (idempotente), come MassivePendenzeRepository. */
    private function ensureTable(): void
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS pendenze_massive_export (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  file_batch_id VARCHAR(64) NOT NULL,
  stato ENUM('PENDING','RUNNING','DONE','ERROR') NOT NULL DEFAULT 'PENDING',
  totale INT UNSIGNED NOT NULL DEFAULT 0,
  processati INT UNSIGNED NOT NULL DEFAULT 0,
  errori INT UNSIGNED NOT NULL DEFAULT 0,
  file_path VARCHAR(255) NULL,
  messaggio TEXT NULL,
  created_by VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_export_batch (file_batch_id),
  INDEX idx_export_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $_ignore) {
        }
    }
}
