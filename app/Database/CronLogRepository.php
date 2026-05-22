<?php
declare(strict_types=1);

namespace App\Database;

use PDO;

class CronLogRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Connection::getPDO();
        $this->ensureTable();
    }

    public function create(string $jobName, array $params): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO cron_log (job_name, params_json, status, started_at)
             VALUES (:job, :params, \'RUNNING\', NOW())'
        );
        $stmt->execute([
            ':job'    => $jobName,
            ':params' => $params !== [] ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateCompleted(int $id, string $status, string $output, int $exitCode): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE cron_log
             SET status = :status, output = :output, exit_code = :code, finished_at = NOW()
             WHERE id = :id AND status = \'RUNNING\''
        );
        $stmt->execute([
            ':status' => $status,
            ':output' => mb_substr($output, 0, 65535),
            ':code'   => $exitCode,
            ':id'     => $id,
        ]);
    }

    public function getRecent(int $limit = 50): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->pdo->prepare(
            'SELECT * FROM cron_log ORDER BY started_at DESC LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Verifica se c'è già un job con questo nome in stato RUNNING avviato nelle ultime 2 ore.
     * Oltre 2 ore → considera stale/morto e permette nuovo avvio.
     */
    public function isRunning(string $jobName): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cron_log
             WHERE job_name = :job
               AND status = \'RUNNING\'
               AND started_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)'
        );
        $stmt->execute([':job' => $jobName]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function markStaleRunning(string $jobName): void
    {
        $this->pdo->prepare(
            "UPDATE cron_log SET status = 'UNKNOWN', finished_at = NOW()
             WHERE job_name = :job AND status = 'RUNNING'"
        )->execute([':job' => $jobName]);
    }

    public function forceCancel(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE cron_log
             SET status = 'UNKNOWN', finished_at = NOW(),
                 output = COALESCE(NULLIF(output,''), 'Annullato manualmente')
             WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM cron_log WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function ensureTable(): void
    {
        try {
            $this->pdo->query('SELECT 1 FROM cron_log LIMIT 1');
        } catch (\Throwable $e) {
            if (method_exists($e, 'getCode') && (string)$e->getCode() !== '42S02') {
                return;
            }
            $sql = "CREATE TABLE IF NOT EXISTS cron_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  job_name     VARCHAR(60) NOT NULL,
  params_json  TEXT DEFAULT NULL,
  status       ENUM('RUNNING','COMPLETED','FAILED','UNKNOWN') NOT NULL DEFAULT 'RUNNING',
  output       TEXT,
  exit_code    TINYINT DEFAULT NULL,
  started_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at  DATETIME DEFAULT NULL,
  INDEX idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            try {
                $this->pdo->exec($sql);
            } catch (\Throwable $_ignore) {}
        }
    }
}
