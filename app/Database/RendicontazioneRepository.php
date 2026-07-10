<?php
declare(strict_types=1);

namespace App\Database;

class RendicontazioneRepository
{
    private \PDO $pdo;

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::getPDO();
    }

    public function getPendingOrError(string $idDominio, int $limit, string $minDataPagamento): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND is_govpay = 1
               AND rendicontazione_stato IN (\'PENDING\', \'ERRORE\')
               AND data_pagamento >= :min_data
             ORDER BY data_pagamento ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':dom', $idDominio);
        $stmt->bindValue(':min_data', $minDataPagamento);
        $stmt->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function markInAttesaConferma(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni SET rendicontazione_stato = \'IN_ATTESA_CONFERMA\' WHERE id = :id'
        )->execute([':id' => $id]);
    }

    public function markGestito(int $id, string $handler, ?string $note = null): void
    {
        $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni
             SET rendicontazione_stato = \'GESTITO\', rendicontazione_handler = :handler, rendicontazione_note = :note
             WHERE id = :id'
        )->execute([':id' => $id, ':handler' => $handler, ':note' => $note]);
    }

    public function markErrore(int $id, string $note): void
    {
        $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni SET rendicontazione_stato = \'ERRORE\', rendicontazione_note = :note WHERE id = :id'
        )->execute([':id' => $id, ':note' => $note]);
    }

    public function markAppioEsito(int $id, string $stato): void
    {
        $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni SET rendicontazione_appio_stato = :stato WHERE id = :id'
        )->execute([':id' => $id, ':stato' => $stato]);
    }

    /** @return array<int,array{pattern_tipo:string,pattern_valore:string,handler:string}> */
    public function getRegoleEsterneAttive(string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pattern_tipo, pattern_valore, handler FROM rendicontazione_regole_esterne
             WHERE id_dominio = :dom AND attivo = 1
             ORDER BY LENGTH(pattern_valore) DESC'
        );
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function getGruppoTipologia(string $idDominio, string $idEntrata): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT modalita FROM rendicontazione_gruppo_tipologie
             WHERE id_dominio = :dom AND id_entrata = :ent
             ORDER BY (modalita = \'NOTIFICA_E_SMARCATURA\') DESC
             LIMIT 1'
        );
        $stmt->execute([':dom' => $idDominio, ':ent' => $idEntrata]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getNonNotificate(string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND is_govpay = 1
               AND rendicontazione_notificato = 0
               AND rendicontazione_stato != \'PENDING\'
             ORDER BY cod_entrata ASC, id_flusso ASC'
        );
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function marcaNotificate(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE flussi_rendicontazioni SET rendicontazione_notificato = 1 WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_map('intval', $ids));
    }

    public function countPendingIsGovpay(string $idDominio, string $minDataPagamento): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND is_govpay = 1 AND rendicontazione_stato = \'PENDING\'
               AND data_pagamento >= :min_data'
        );
        $stmt->execute([':dom' => $idDominio, ':min_data' => $minDataPagamento]);
        return (int)$stmt->fetchColumn();
    }

    /** @param string[] $idEntrate */
    public function getDaConfermarePerTipologie(string $idDominio, array $idEntrate, int $page, int $perPage): array
    {
        if (empty($idEntrate)) {
            return [];
        }

        // Build named placeholders for the IN clause
        $placeholders = [];
        $params = [':dom' => $idDominio];
        foreach ($idEntrate as $i => $entrata) {
            $key = ':ent' . $i;
            $placeholders[] = $key;
            $params[$key] = $entrata;
        }
        $inClause = implode(',', $placeholders);

        $offset = max(0, ($page - 1) * $perPage);
        $limit = max(1, $perPage);

        $stmt = $this->pdo->prepare(
            "SELECT * FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND is_govpay = 1 AND rendicontazione_stato = 'IN_ATTESA_CONFERMA'
               AND cod_entrata IN ($inClause)
             ORDER BY cod_entrata ASC, id_flusso ASC, iur ASC
             LIMIT :limit OFFSET :offset"
        );

        // Bind all parameters
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /** @param string[] $idEntrate */
    public function countDaConfermarePerTipologie(string $idDominio, array $idEntrate): int
    {
        if (empty($idEntrate)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($idEntrate), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM flussi_rendicontazioni
             WHERE id_dominio = ? AND is_govpay = 1 AND rendicontazione_stato = 'IN_ATTESA_CONFERMA'
               AND cod_entrata IN ($placeholders)"
        );
        $stmt->execute(array_merge([$idDominio], $idEntrate));
        return (int)$stmt->fetchColumn();
    }

    /** @param int[] $ids @return int righe aggiornate */
    public function confermaRighe(array $ids, int $userId): int
    {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE flussi_rendicontazioni
             SET rendicontazione_stato = 'GESTITO', rendicontazione_handler = 'GIL_MANUALE',
                 rendicontazione_confermato_da = ?, rendicontazione_confermato_at = NOW()
             WHERE id IN ($placeholders) AND rendicontazione_stato = 'IN_ATTESA_CONFERMA'"
        );
        $stmt->execute(array_merge([$userId], array_map('intval', $ids)));
        return $stmt->rowCount();
    }

    /** @param int[] $ids @param string[] $idEntrate @return int righe aggiornate */
    public function confermaRigheScoped(array $ids, array $idEntrate, int $userId): int
    {
        if (empty($ids) || empty($idEntrate)) {
            return 0;
        }

        $idPlaceholders = [];
        $params = [':user_id' => $userId];
        foreach ($ids as $i => $id) {
            $key = ':id' . $i;
            $idPlaceholders[] = $key;
            $params[$key] = (int)$id;
        }
        $idInClause = implode(',', $idPlaceholders);

        $entPlaceholders = [];
        foreach ($idEntrate as $i => $entrata) {
            $key = ':ent' . $i;
            $entPlaceholders[] = $key;
            $params[$key] = $entrata;
        }
        $entInClause = implode(',', $entPlaceholders);

        $stmt = $this->pdo->prepare(
            "UPDATE flussi_rendicontazioni
             SET rendicontazione_stato = 'GESTITO', rendicontazione_handler = 'GIL_MANUALE',
                 rendicontazione_confermato_da = :user_id, rendicontazione_confermato_at = NOW()
             WHERE id IN ($idInClause) AND rendicontazione_stato = 'IN_ATTESA_CONFERMA'
               AND cod_entrata IN ($entInClause)"
        );
        foreach ($params as $key => $value) {
            if ($key === ':user_id') {
                $stmt->bindValue($key, $value, \PDO::PARAM_INT);
            } elseif (str_starts_with($key, ':id')) {
                $stmt->bindValue($key, $value, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function isFlussoRendicontato(string $idDominio, string $idFlusso): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND id_flusso = :flusso AND is_govpay = 1
               AND rendicontazione_stato IN (\'PENDING\', \'IN_ATTESA_CONFERMA\', \'ERRORE\')'
        );
        $stmt->execute([':dom' => $idDominio, ':flusso' => $idFlusso]);
        return (int)$stmt->fetchColumn() === 0;
    }
}
