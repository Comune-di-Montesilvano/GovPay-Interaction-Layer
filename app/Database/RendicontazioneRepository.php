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

    /**
     * Come markErrore() ma incrementa anche rendicontazione_tentativi_geri.
     * Solo per fallimenti del bridge GERI (il connettore legacy non ha segnale
     * affidabile di successo/fallimento: si limita il retry per evitare doppie
     * registrazioni, cfr. migrations/029_rendicontazione_tentativi.sql).
     */
    public function markErroreGeri(int $id, string $note): void
    {
        $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni
             SET rendicontazione_stato = \'ERRORE\', rendicontazione_note = :note,
                 rendicontazione_tentativi_geri = rendicontazione_tentativi_geri + 1
             WHERE id = :id'
        )->execute([':id' => $id, ':note' => $note]);
    }

    public function markAppioEsito(int $id, string $stato): void
    {
        $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni SET rendicontazione_appio_stato = :stato WHERE id = :id'
        )->execute([':id' => $id, ':stato' => $stato]);
    }

    public function markAppioInviato(int $id, ?string $messageId): void
    {
        $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni
             SET rendicontazione_appio_stato = \'INVIATO\',
                 rendicontazione_appio_message_id = :msg,
                 rendicontazione_appio_inviato_at = NOW()
             WHERE id = :id'
        )->execute([':id' => $id, ':msg' => $messageId]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM flussi_rendicontazioni WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
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

    public function getRegoleEsterne(string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM rendicontazione_regole_esterne WHERE id_dominio = :dom ORDER BY id DESC'
        );
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function addRegolaEsterna(string $idDominio, string $patternTipo, string $patternValore, string $handler): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rendicontazione_regole_esterne (id_dominio, pattern_tipo, pattern_valore, handler)
             VALUES (:dom, :tipo, :val, :handler)'
        );
        $stmt->execute([':dom' => $idDominio, ':tipo' => $patternTipo, ':val' => $patternValore, ':handler' => $handler]);
        return (int)$this->pdo->lastInsertId();
    }

    public function toggleRegolaEsterna(int $id, bool $attivo): void
    {
        $this->pdo->prepare('UPDATE rendicontazione_regole_esterne SET attivo = :a WHERE id = :id')
                   ->execute([':a' => $attivo ? 1 : 0, ':id' => $id]);
    }

    public function deleteRegolaEsterna(int $id): void
    {
        $this->pdo->prepare('DELETE FROM rendicontazione_regole_esterne WHERE id = :id')->execute([':id' => $id]);
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
            'SELECT f.*, b.descrizione AS causale, b.cf_debitore, b.nominativo_debitore
             FROM flussi_rendicontazioni f
             LEFT JOIN biz_ricevute b ON f.iur = b.iur AND f.id_dominio = b.id_dominio
             WHERE f.id_dominio = :dom AND f.is_govpay = 1
               AND f.rendicontazione_notificato = 0
               AND f.rendicontazione_stato != \'PENDING\'
             ORDER BY f.cod_entrata ASC, f.id_flusso ASC'
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

    /** @param int[] $ids @param string[] $idEntrate @return int[] id delle righe effettivamente confermate */
    public function confermaRigheScoped(array $ids, array $idEntrate, int $userId): array
    {
        if (empty($ids) || empty($idEntrate)) {
            return [];
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

        // La UPDATE resta la fonte di verita' atomica (WHERE ri-verifica lo stato al momento della
        // scrittura, cosi' due operatori che confermano righe sovrapposte restano serializzati
        // correttamente dal DB). La SELECT successiva serve solo a "leggere" quali id sono stati
        // davvero toccati da QUESTA richiesta (confermato_da = questo utente), per poter innescare
        // la notifica App IO per riga (cfr. RendicontazioneController::conferma()) — non e' usata
        // come input di un'altra mutazione, quindi non introduce una finestra di race.
        $updateStmt = $this->pdo->prepare(
            "UPDATE flussi_rendicontazioni
             SET rendicontazione_stato = 'GESTITO', rendicontazione_handler = 'GIL_MANUALE',
                 rendicontazione_confermato_da = :user_id, rendicontazione_confermato_at = NOW()
             WHERE id IN ($idInClause) AND rendicontazione_stato = 'IN_ATTESA_CONFERMA'
               AND cod_entrata IN ($entInClause)"
        );
        foreach ($params as $key => $value) {
            if ($key === ':user_id' || str_starts_with($key, ':id')) {
                $updateStmt->bindValue($key, $value, \PDO::PARAM_INT);
            } else {
                $updateStmt->bindValue($key, $value);
            }
        }
        $updateStmt->execute();

        if ($updateStmt->rowCount() === 0) {
            return [];
        }

        $selectStmt = $this->pdo->prepare(
            "SELECT id FROM flussi_rendicontazioni
             WHERE id IN ($idInClause) AND rendicontazione_confermato_da = :user_id
               AND rendicontazione_stato = 'GESTITO'"
        );
        // Solo i placeholder :user_id e :idN compaiono in questa query (niente :entN).
        $selectStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        foreach ($ids as $i => $id) {
            $selectStmt->bindValue(':id' . $i, (int)$id, \PDO::PARAM_INT);
        }
        $selectStmt->execute();

        return array_map('intval', array_column($selectStmt->fetchAll(\PDO::FETCH_ASSOC), 'id'));
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

    public function isFlussoRegolarizzato(string $idDominio, string $idFlusso): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND id_flusso = :flusso'
        );
        $stmt->execute([':dom' => $idDominio, ':flusso' => $idFlusso]);
        $totale = (int)$stmt->fetchColumn();
        if ($totale === 0) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND id_flusso = :flusso AND rendicontazione_regolarizzato = 0'
        );
        $stmt->execute([':dom' => $idDominio, ':flusso' => $idFlusso]);
        return (int)$stmt->fetchColumn() === 0;
    }

    public function getDatiAggregatiFlusso(string $idDominio, string $idFlusso): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT SUM(importo) as importo_totale, MAX(trn) as trn FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND id_flusso = :flusso'
        );
        $stmt->execute([':dom' => $idDominio, ':flusso' => $idFlusso]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function marcaFlussoRegolarizzato(string $idDominio, string $idFlusso): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE flussi_rendicontazioni SET rendicontazione_regolarizzato = 1
             WHERE id_dominio = :dom AND id_flusso = :flusso'
        );
        $stmt->execute([':dom' => $idDominio, ':flusso' => $idFlusso]);
    }

    public function getFlussiDaRegolarizzare(string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT id_flusso FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND id_flusso IS NOT NULL AND id_flusso != ''
               AND rendicontazione_regolarizzato = 0
               AND id_flusso NOT IN (
                   SELECT DISTINCT id_flusso FROM flussi_rendicontazioni
                   WHERE id_dominio = :dom_sub AND id_flusso IS NOT NULL AND id_flusso != ''
                     AND rendicontazione_stato IN ('PENDING', 'IN_ATTESA_CONFERMA', 'ERRORE')
               )"
        );
        $stmt->execute([':dom' => $idDominio, ':dom_sub' => $idDominio]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id_flusso');
    }

    public function getUnaRigaPerFlusso(string $idDominio, string $idFlusso): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, id_flusso FROM flussi_rendicontazioni
             WHERE id_dominio = :dom AND id_flusso = :flusso LIMIT 1'
        );
        $stmt->execute([':dom' => $idDominio, ':flusso' => $idFlusso]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
