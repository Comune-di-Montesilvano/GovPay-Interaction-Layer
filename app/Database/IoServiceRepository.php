<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Database;

use App\Logger;
use PDO;
use App\Security\Crypto;

class IoServiceRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getPDO();
        $this->ensureTables();
    }

    /**
     * Crea le tabelle se non esistono in MariaDB.
     */
    public function ensureTables(): void
    {
        try {
            // Tabella io_services
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS io_services (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    nome VARCHAR(255) NOT NULL UNIQUE,
                    descrizione TEXT,
                    id_service VARCHAR(255) NOT NULL,
                    api_key_primaria VARCHAR(255) NOT NULL,
                    api_key_secondaria VARCHAR(255),
                    codice_catalogo VARCHAR(255),
                    is_default TINYINT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_is_default (is_default)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            // Tabella io_service_tipologie (M:1 link)
            $this->pdo->exec('
                CREATE TABLE IF NOT EXISTS io_service_tipologie (
                    id_entrata VARCHAR(255) PRIMARY KEY,
                    io_service_id INT NOT NULL,
                    FOREIGN KEY (io_service_id) REFERENCES io_services(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
        } catch (\PDOException $e) {
            Logger::getInstance()->error('Errore creazione tabelle IO services', [
                'exception' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Elenca tutti i servizi IO.
     * @return array<int, array{id: int, nome: string, descrizione: ?string, id_service: string, ...}>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->prepare('
            SELECT s.id, s.nome, s.descrizione, s.id_service, s.api_key_primaria,
                   s.api_key_secondaria, s.codice_catalogo, s.is_default, s.created_at, s.updated_at,
                   COUNT(t.id_entrata) AS tipologie_count
            FROM io_services s
            LEFT JOIN io_service_tipologie t ON s.id = t.io_service_id
            GROUP BY s.id
            ORDER BY s.nome ASC
        ');
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['api_key_primaria'] = Crypto::decrypt($row['api_key_primaria']);
            if (!empty($row['api_key_secondaria'])) {
                $row['api_key_secondaria'] = Crypto::decrypt($row['api_key_secondaria']);
            }
        }
        return $rows;
    }

    /**
     * Trova un servizio per ID.
     * @return array{id: int, nome: string, ...}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, nome, descrizione, id_service, api_key_primaria, 
                   api_key_secondaria, codice_catalogo, is_default, created_at, updated_at
            FROM io_services
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row) {
            $row['api_key_primaria'] = Crypto::decrypt($row['api_key_primaria']);
            if (!empty($row['api_key_secondaria'])) {
                $row['api_key_secondaria'] = Crypto::decrypt($row['api_key_secondaria']);
            }
        }
        return $row ?: null;
    }

    /**
     * Trova il servizio predefinito (is_default = 1).
     * @return array{id: int, nome: string, ...}|null
     */
    public function findDefault(): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, nome, descrizione, id_service, api_key_primaria, 
                   api_key_secondaria, codice_catalogo, is_default, created_at, updated_at
            FROM io_services
            WHERE is_default = 1
            LIMIT 1
        ');
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) {
            $row['api_key_primaria'] = Crypto::decrypt($row['api_key_primaria']);
            if (!empty($row['api_key_secondaria'])) {
                $row['api_key_secondaria'] = Crypto::decrypt($row['api_key_secondaria']);
            }
        }
        return $row ?: null;
    }

    /**
     * Crea un nuovo servizio IO.
     * Se is_default = 1, disattiva tutti gli altri.
     */
    public function create(
        string $nome,
        ?string $descrizione,
        string $id_service,
        string $api_key_primaria,
        ?string $api_key_secondaria,
        ?string $codice_catalogo,
        bool $is_default = false
    ): int {
        if ($is_default) {
            // Disattiva tutti gli altri prima di inserire
            $this->pdo->prepare('UPDATE io_services SET is_default = 0 WHERE is_default = 1')->execute();
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO io_services 
            (nome, descrizione, id_service, api_key_primaria, api_key_secondaria, 
             codice_catalogo, is_default)
            VALUES (:nome, :descrizione, :id_service, :api_key_primaria, :api_key_secondaria, 
                    :codice_catalogo, :is_default)
        ');
        
        $stmt->execute([
            ':nome' => $nome,
            ':descrizione' => $descrizione,
            ':id_service' => $id_service,
            ':api_key_primaria' => Crypto::encrypt($api_key_primaria),
            ':api_key_secondaria' => $api_key_secondaria ? Crypto::encrypt($api_key_secondaria) : null,
            ':codice_catalogo' => $codice_catalogo,
            ':is_default' => $is_default ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Aggiorna un servizio IO.
     * Se is_default = true, disattiva tutti gli altri.
     */
    public function update(
        int $id,
        string $nome,
        ?string $descrizione,
        string $id_service,
        string $api_key_primaria,
        ?string $api_key_secondaria,
        ?string $codice_catalogo,
        bool $is_default = false
    ): void {
        if ($is_default) {
            // Disattiva tutti gli altri se questo deve diventare default
            $this->pdo->prepare('UPDATE io_services SET is_default = 0 WHERE is_default = 1 AND id != :id')
                ->execute([':id' => $id]);
        }

        $stmt = $this->pdo->prepare('
            UPDATE io_services
            SET nome = :nome, descrizione = :descrizione, id_service = :id_service,
                api_key_primaria = :api_key_primaria, api_key_secondaria = :api_key_secondaria,
                codice_catalogo = :codice_catalogo, is_default = :is_default
            WHERE id = :id
        ');

        $stmt->execute([
            ':id' => $id,
            ':nome' => $nome,
            ':descrizione' => $descrizione,
            ':id_service' => $id_service,
            ':api_key_primaria' => Crypto::encrypt($api_key_primaria),
            ':api_key_secondaria' => $api_key_secondaria ? Crypto::encrypt($api_key_secondaria) : null,
            ':codice_catalogo' => $codice_catalogo,
            ':is_default' => $is_default ? 1 : 0,
        ]);
    }

    /**
     * Elimina un servizio IO e tutte le sue associazioni tipologie.
     */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM io_services WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Imposta un servizio come predefinito (disattiva tutti gli altri).
     */
    public function setDefault(int $id): void
    {
        $this->pdo->prepare('UPDATE io_services SET is_default = 0 WHERE is_default = 1')->execute();
        $this->pdo->prepare('UPDATE io_services SET is_default = 1 WHERE id = :id')
            ->execute([':id' => $id]);
    }

    /**
     * Ottiene il servizio IO associato a una tipologia (id_entrata).
     * @return array{id: int, nome: string, ...}|null
     */
    public function getTipologiaService(string $idEntrata): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT s.id, s.nome, s.descrizione, s.id_service, s.api_key_primaria, 
                   s.api_key_secondaria, s.codice_catalogo, s.is_default, s.created_at, s.updated_at
            FROM io_services s
            INNER JOIN io_service_tipologie t ON s.id = t.io_service_id
            WHERE t.id_entrata = :id_entrata
            LIMIT 1
        ');
        $stmt->execute([':id_entrata' => $idEntrata]);
        $row = $stmt->fetch();
        if ($row) {
            $row['api_key_primaria'] = Crypto::decrypt($row['api_key_primaria']);
            if (!empty($row['api_key_secondaria'])) {
                $row['api_key_secondaria'] = Crypto::decrypt($row['api_key_secondaria']);
            }
        }
        return $row ?: null;
    }

    /**
     * Associa o dissocia un servizio IO a una tipologia.
     * Se $ioServiceId è null, rimuove l'associazione.
     */
    public function setTipologiaService(string $idEntrata, ?int $ioServiceId): void
    {
        // Prima rimuovi l'associazione precedente se esiste
        $this->pdo->prepare('DELETE FROM io_service_tipologie WHERE id_entrata = :id_entrata')
            ->execute([':id_entrata' => $idEntrata]);

        // Se ioServiceId è fornito, crea la nuova associazione
        if ($ioServiceId !== null) {
            $stmt = $this->pdo->prepare('
                INSERT INTO io_service_tipologie (id_entrata, io_service_id)
                VALUES (:id_entrata, :io_service_id)
            ');
            $stmt->execute([
                ':id_entrata' => $idEntrata,
                ':io_service_id' => $ioServiceId,
            ]);
        }
    }

    /**
     * Ottiene la mappa {idEntrata => io_service} per tutte le tipologie associate.
     * @return array<string, array{id: int, nome: string, ...}>
     */
    public function getAllTipologiaServices(): array
    {
        $stmt = $this->pdo->prepare('
            SELECT t.id_entrata,
                   s.id, s.nome, s.descrizione, s.id_service, s.api_key_primaria, 
                   s.api_key_secondaria, s.codice_catalogo, s.is_default, s.created_at, s.updated_at
            FROM io_service_tipologie t
            INNER JOIN io_services s ON t.io_service_id = s.id
        ');
        $stmt->execute();
        
        $map = [];
        while ($row = $stmt->fetch()) {
            $row['api_key_primaria'] = Crypto::decrypt($row['api_key_primaria']);
            if (!empty($row['api_key_secondaria'])) {
                $row['api_key_secondaria'] = Crypto::decrypt($row['api_key_secondaria']);
            }
            $idEntrata = $row['id_entrata'];
            $map[$idEntrata] = $row;
        }
        return $map;
    }
}
