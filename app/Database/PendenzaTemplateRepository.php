<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Database;

use PDO;

class PendenzaTemplateRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getPDO();
    }

    /**
     * @param array{id_dominio: string, titolo: string, id_tipo_pendenza: string, causale: string, importo: float} $data
     * @return int
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO pendenza_template (id_dominio, titolo, id_tipo_pendenza, causale, importo, created_at, updated_at)
                VALUES (:id_dominio, :titolo, :id_tipo_pendenza, :causale, :importo, NOW(), NOW())";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id_dominio' => $data['id_dominio'],
            ':titolo' => $data['titolo'],
            ':id_tipo_pendenza' => $data['id_tipo_pendenza'],
            ':causale' => $data['causale'],
            ':importo' => $data['importo']
        ]);

        return (int)$this->pdo->lastInsertId();

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param int $id
     * @param array{titolo: string, id_tipo_pendenza: string, causale: string, importo: float} $data
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE pendenza_template 
                SET titolo = :titolo, id_tipo_pendenza = :id_tipo_pendenza, causale = :causale, 
                    importo = :importo, updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':id' => $id,
            ':titolo' => $data['titolo'],
            ':id_tipo_pendenza' => $data['id_tipo_pendenza'],
            ':causale' => $data['causale'],
            ':importo' => $data['importo']
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM pendenza_template WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM pendenza_template WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAllByDominio(string $idDominio): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM pendenza_template WHERE id_dominio = :id_dominio ORDER BY titolo ASC");
        $stmt->execute([':id_dominio' => $idDominio]);
        return $stmt->fetchAll();
    }

    // --- Users Association ---

    public function assignUsers(int $templateId, array $userIds): void
    {
        // Prima eliminiamo quelli esistenti
        $stmtDelete = $this->pdo->prepare("DELETE FROM pendenza_template_users WHERE template_id = :template_id");
        $stmtDelete->execute([':template_id' => $templateId]);

        if (empty($userIds)) {
            return;
        }

        // Inseriamo i nuovi
        $sql = "INSERT INTO pendenza_template_users (template_id, user_id) VALUES (:template_id, :user_id)";
        $stmtInsert = $this->pdo->prepare($sql);
        
        foreach ($userIds as $userId) {
            $stmtInsert->execute([
                ':template_id' => $templateId,
                ':user_id' => $userId
            ]);
        }
    }

    public function getAssignedUserIds(int $templateId): array
    {
        $stmt = $this->pdo->prepare("SELECT user_id FROM pendenza_template_users WHERE template_id = :template_id");
        $stmt->execute([':template_id' => $templateId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function findAllByDominioForUser(string $idDominio, int $userId): array
    {
        // Seleziona i template associati all'utente per quel dominio
        $sql = "SELECT pt.* 
                FROM pendenza_template pt
                INNER JOIN pendenza_template_users ptu ON pt.id = ptu.template_id
                WHERE pt.id_dominio = :id_dominio AND ptu.user_id = :user_id
                ORDER BY pt.titolo ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id_dominio' => $idDominio,
            ':user_id' => $userId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Restituisce tutti i template assegnati a un utente, indipendentemente dal dominio.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTemplatesForUser(int $userId): array
    {
        $sql = "SELECT pt.*
                FROM pendenza_template pt
                INNER JOIN pendenza_template_users ptu ON pt.id = ptu.template_id
                WHERE ptu.user_id = :user_id
                ORDER BY pt.titolo ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }
}
