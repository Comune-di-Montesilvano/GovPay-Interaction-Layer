<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Database;

use App\Logger;
use PDO;

class EntrateRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getPDO();
    }

    /**
     * Restituisce le tipologie di pendenza abilitate per il dominio
     * @return array<int,array{id_entrata:string,descrizione:string}>
     */
    public function listAbilitateByDominio(string $idDominio): array
    {
        $stmt = $this->pdo->prepare('SELECT id_entrata, COALESCE(descrizione_locale, descrizione) AS descrizione, tipo_contabilita FROM entrate_tipologie WHERE id_dominio = :id AND abilitato_backoffice = 1 AND external_url IS NULL ORDER BY COALESCE(descrizione_locale, descrizione) ASC');
        $stmt->execute([':id' => $idDominio]);
        return $stmt->fetchAll();
    }

    /**
     * Restituisce le tipologie abilitate per un utente specifico in un dominio.
     * Se l'utente è admin/superadmin oppure non ha tipologie assegnate: restituisce tutte.
     * Altrimenti: solo quelle assegnate tramite entrate_tipologie_users.
     *
     * @return array<int,array{id_entrata:string,descrizione:string}>
     */
    public function listAbilitateByDominioForUser(string $idDominio, int $userId, string $userRole): array
    {
        // Admin e superadmin vedono sempre tutto
        if (in_array($userRole, ['admin', 'superadmin'], true)) {
            return $this->listAbilitateByDominio($idDominio);
        }

        // Conta tipologie individuali dell'utente
        $cntStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM entrate_tipologie_users WHERE user_id = :uid AND id_dominio = :dom'
        );
        $cntStmt->execute([':uid' => $userId, ':dom' => $idDominio]);
        $individualCount = (int)$cntStmt->fetchColumn();

        // Tipologie abilitate dai gruppi dell'utente
        $groupRepo = new UserGroupRepository();
        $groupTipologie = $groupRepo->getTipologieForUser($userId, $idDominio);

        if ($individualCount === 0 && empty($groupTipologie)) {
            return $this->listAbilitateByDominio($idDominio);
        }

        // Union: tipologie da gruppi + tipologie individuali
        $allEntrate = $groupTipologie;
        if ($individualCount > 0) {
            $individualTipologie = $this->getEnabledTipologieForUser($userId, $idDominio);
            $allEntrate = array_unique(array_merge($allEntrate, $individualTipologie));
        }

        if (empty($allEntrate)) {
            return $this->listAbilitateByDominio($idDominio);
        }

        $placeholders = implode(',', array_fill(0, count($allEntrate), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id_entrata, COALESCE(descrizione_locale, descrizione) AS descrizione, tipo_contabilita
             FROM entrate_tipologie
             WHERE id_dominio = ? AND abilitato_backoffice = 1 AND external_url IS NULL
               AND id_entrata IN ({$placeholders})
             ORDER BY COALESCE(descrizione_locale, descrizione) ASC"
        );
        $stmt->execute(array_merge([$idDominio], $allEntrate));
        return $stmt->fetchAll();
    }

    /**
     * Restituisce gli ID entrata abilitati per un utente in un dominio.
     *
     * @return array<int,string>
     */
    public function getEnabledTipologieForUser(int $userId, string $idDominio): array
    {
        $stmt = $this->pdo->prepare('SELECT id_entrata FROM entrate_tipologie_users WHERE user_id = :uid AND id_dominio = :dom ORDER BY id_entrata ASC');
        $stmt->execute([':uid' => $userId, ':dom' => $idDominio]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Imposta le tipologie abilitate per un utente in un dominio.
     */
    public function setEnabledTipologieForUser(int $userId, string $idDominio, array $idEntrate): void
    {
        // Elimina le assegnazioni precedenti
        $stmt = $this->pdo->prepare('DELETE FROM entrate_tipologie_users WHERE user_id = :uid AND id_dominio = :dom');
        $stmt->execute([':uid' => $userId, ':dom' => $idDominio]);

        if (empty($idEntrate)) {
            return;
        }

        // Inserisce le nuove assegnazioni
        $sql = 'INSERT INTO entrate_tipologie_users (id_dominio, id_entrata, user_id) VALUES (:dom, :entrata, :uid)';
        $stmt = $this->pdo->prepare($sql);
        foreach ($idEntrate as $idEntrata) {
            $stmt->execute([':dom' => $idDominio, ':entrata' => $idEntrata, ':uid' => $userId]);
        }
    }

    /**
     * Upsert di una tipologia proveniente dal Backoffice
    * @param array{idEntrata?:string,tipoEntrata?:array,ibanAccredito?:string,codiceContabilita?:string,tipoBollo?:string,abilitato?:bool} $e
     */
    public function upsertFromBackoffice(string $idDominio, array $e): void
    {
        $idEntrata = $e['idEntrata'] ?? ($e['tipoEntrata']['idEntrata'] ?? null);
        if (!$idEntrata) {
            return;
        }

    $descrizione = $e['tipoEntrata']['descrizione'] ?? null;
    $iban = $e['ibanAccredito'] ?? null;
    $codiceCont = $e['codiceContabilita'] ?? ($e['tipoEntrata']['codiceContabilita'] ?? null);
    $tipoBollo = $e['tipoBollo'] ?? ($e['tipoEntrata']['tipoBollo'] ?? null);
    $tipoContabilita = $e['tipoContabilita'] ?? ($e['tipoEntrata']['tipoContabilita'] ?? null);
    $abilitatoBo = !empty($e['abilitato']);
    $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO entrate_tipologie (id_dominio, id_entrata, descrizione, iban_accredito, codice_contabilita, tipo_bollo, tipo_contabilita, abilitato_backoffice, sorgente, created_at, updated_at)
        VALUES (:id_dominio, :id_entrata, :descrizione, :iban, :codice, :tipo_bollo, :tipo_contabilita, :bo, "backoffice", :now_created, :now_updated)
        ON DUPLICATE KEY UPDATE descrizione = VALUES(descrizione), iban_accredito = VALUES(iban_accredito), codice_contabilita = VALUES(codice_contabilita), tipo_bollo = VALUES(tipo_bollo), tipo_contabilita = VALUES(tipo_contabilita), abilitato_backoffice = VALUES(abilitato_backoffice), updated_at = VALUES(updated_at)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id_dominio' => $idDominio,
            ':id_entrata' => $idEntrata,
            ':descrizione' => $descrizione,
            ':iban' => $iban,
            ':codice' => $codiceCont,
            ':tipo_bollo' => $tipoBollo,
            ':tipo_contabilita' => $tipoContabilita,
            ':bo' => $abilitatoBo ? 1 : 0,
            ':now_created' => $now,
            ':now_updated' => $now,
        ]);
    }

    /**
     * Sanitizza un valore di codice entrata per rispettare il pattern accettato dall'API
     */
    private static function sanitizeCodEntrata(?string $value): ?string
    {
        if ($value === null) return null;
        $san = preg_replace('/[^A-Za-z0-9\-_.]/', '', $value);
        $san = substr($san, 0, 35);
        return $san === '' ? null : $san;
    }

    /**
     * Recupera i dettagli di una singola tipologia (iban, codice contabilita, tipo_bollo, descrizione)
     * @return array<string,mixed>|null
     */
    public function findDetails(string $idDominio, string $idEntrata): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_entrata, descrizione, descrizione_locale, descrizione_estesa, iban_accredito, codice_contabilita, tipo_bollo, tipo_contabilita, abilitato_backoffice, override_locale, external_url, COALESCE(descrizione_locale, descrizione) AS descrizione_effettiva FROM entrate_tipologie WHERE id_dominio = :dom AND id_entrata = :ent LIMIT 1');
        $stmt->execute([':dom' => $idDominio, ':ent' => $idEntrata]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Ritorna l’elenco tipologie per dominio
     * @return array<int,array>
     */
    public function listByDominio(string $idDominio): array
    {
    $stmt = $this->pdo->prepare('SELECT id_entrata, descrizione, descrizione_locale, descrizione_estesa, COALESCE(descrizione_locale, descrizione) AS descrizione_effettiva, iban_accredito, codice_contabilita, tipo_contabilita, abilitato_backoffice, override_locale, external_url FROM entrate_tipologie WHERE id_dominio = :id ORDER BY id_entrata ASC');
        $stmt->execute([':id' => $idDominio]);
        return $stmt->fetchAll();
    }


    /** @return array{id_entrata:string,abilitato_backoffice:int,override_locale:?int,external_url:?string}|null */
    public function findOne(string $idDominio, string $idEntrata): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id_entrata, abilitato_backoffice, override_locale, external_url FROM entrate_tipologie WHERE id_dominio = :dom AND id_entrata = :ent LIMIT 1');
        $stmt->execute([':dom' => $idDominio, ':ent' => $idEntrata]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Imposta override locale (true/false) */
    public function setOverride(string $idDominio, string $idEntrata, ?bool $override): void
    {
        $now = date('Y-m-d H:i:s');
        if ($override === null) {
            $sql = 'UPDATE entrate_tipologie SET override_locale = NULL, updated_at = :now WHERE id_dominio = :dom AND id_entrata = :ent';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':now' => $now, ':dom' => $idDominio, ':ent' => $idEntrata]);
            return;
        }
        $sql = 'UPDATE entrate_tipologie SET override_locale = :ovr, updated_at = :now WHERE id_dominio = :dom AND id_entrata = :ent';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':ovr' => $override ? 1 : 0,
            ':now' => $now,
            ':dom' => $idDominio,
            ':ent' => $idEntrata,
        ]);
    }

    /** Imposta l'URL esterna per la tipologia */
    public function setExternalUrl(string $idDominio, string $idEntrata, ?string $url): void
    {
        $sql = 'UPDATE entrate_tipologie SET external_url = :url, updated_at = :now WHERE id_dominio = :dom AND id_entrata = :ent';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':url' => ($url === null || $url === '') ? null : $url,
            ':now' => date('Y-m-d H:i:s'),
            ':dom' => $idDominio,
            ':ent' => $idEntrata,
        ]);
    }

    /** Aggiorna la descrizione locale (override) della tipologia. */
    public function updateDescrizione(string $idDominio, string $idEntrata, string $descrizione): void
    {
        $sql = 'UPDATE entrate_tipologie SET descrizione_locale = :descrizione, updated_at = :now WHERE id_dominio = :dom AND id_entrata = :ent';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':descrizione' => $descrizione,
            ':now' => date('Y-m-d H:i:s'),
            ':dom' => $idDominio,
            ':ent' => $idEntrata,
        ]);
    }

    /** Rimuove la descrizione locale (ripristina il valore GovPay) */
    /**
     * Rimuove la descrizione locale (ripristina il valore GovPay)
     * @return int number of affected rows
     */
    public function clearDescrizioneLocale(string $idDominio, string $idEntrata): int
    {
        $sql = 'UPDATE entrate_tipologie SET descrizione_locale = NULL, updated_at = :now WHERE id_dominio = :dom AND id_entrata = :ent';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':now' => date('Y-m-d H:i:s'),
            ':dom' => $idDominio,
            ':ent' => $idEntrata,
        ]);
        return $stmt->rowCount();
    }

    /** Aggiorna la descrizione estesa (testo lungo locale) della tipologia. */
    public function updateDescrizioneEstesa(string $idDominio, string $idEntrata, ?string $descrizioneEstesa): void
    {
        $sql = 'UPDATE entrate_tipologie SET descrizione_estesa = :descrizione, updated_at = :now WHERE id_dominio = :dom AND id_entrata = :ent';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':descrizione' => ($descrizioneEstesa === null || trim($descrizioneEstesa) === '') ? null : $descrizioneEstesa,
            ':now' => date('Y-m-d H:i:s'),
            ':dom' => $idDominio,
            ':ent' => $idEntrata,
        ]);
    }

    /** Rimuove la descrizione estesa (set NULL). */
    public function clearDescrizioneEstesa(string $idDominio, string $idEntrata): int
    {
        $sql = 'UPDATE entrate_tipologie SET descrizione_estesa = NULL, updated_at = :now WHERE id_dominio = :dom AND id_entrata = :ent';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':now' => date('Y-m-d H:i:s'),
            ':dom' => $idDominio,
            ':ent' => $idEntrata,
        ]);
        return $stmt->rowCount();
    }

    /**
     * Restituisce solo le colonne di override locale per tutte le tipologie del dominio.
     * Usato per il backup della configurazione.
     * @return array<int, array{id_entrata:string,descrizione_locale:?string,descrizione_estesa:?string,override_locale:?int,external_url:?string,abilitato_backoffice:int}>
     */
    public function listLocalOverrides(string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_entrata, descrizione_locale, descrizione_estesa, override_locale, external_url, abilitato_backoffice
             FROM entrate_tipologie WHERE id_dominio = :dom ORDER BY id_entrata ASC'
        );
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->fetchAll();
    }

    /**
     * REPLACE degli override locali: azzera tutti, poi applica le righe dal backup.
     * Non tocca le colonne sincronizzate da GovPay (descrizione, iban, ecc.).
     * @param array<int, array> $rows array di righe nel formato listLocalOverrides()
     * @return int numero di righe aggiornate dal backup
     */
    public function replaceLocalOverrides(string $idDominio, array $rows): int
    {
        $now = date('Y-m-d H:i:s');
        // Azzera tutti gli override del dominio
        $this->pdo->prepare(
            'UPDATE entrate_tipologie
             SET descrizione_locale = NULL, descrizione_estesa = NULL,
                 override_locale = NULL, external_url = NULL, updated_at = :now
             WHERE id_dominio = :dom'
        )->execute([':now' => $now, ':dom' => $idDominio]);

        if (empty($rows)) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE entrate_tipologie
             SET descrizione_locale = :dl, descrizione_estesa = :de,
                 override_locale = :ol, external_url = :eu,
                 abilitato_backoffice = :ab, updated_at = :now
             WHERE id_dominio = :dom AND id_entrata = :ent'
        );
        $updated = 0;
        foreach ($rows as $row) {
            $stmt->execute([
                ':dl'  => $row['descrizione_locale'] ?? null,
                ':de'  => $row['descrizione_estesa'] ?? null,
                ':ol'  => isset($row['override_locale']) ? (int)$row['override_locale'] : null,
                ':eu'  => $row['external_url'] ?? null,
                ':ab'  => isset($row['abilitato_backoffice']) ? (int)$row['abilitato_backoffice'] : 1,
                ':now' => $now,
                ':dom' => $idDominio,
                ':ent' => $row['id_entrata'],
            ]);
            $updated += $stmt->rowCount();
        }
        return $updated;
    }

    /**
     * Popola descrizione_estesa solo dove è vuota (NULL o stringa vuota).
     * @param array<string,string> $descrizioniByIdEntrata mappa id_entrata => descrizione_estesa
     * @return int numero righe aggiornate
     */
    public function bulkFillDescrizioneEstesaIfEmpty(string $idDominio, array $descrizioniByIdEntrata): int
    {
        if (empty($descrizioniByIdEntrata)) {
            return 0;
        }

        $sql = 'UPDATE entrate_tipologie
                SET descrizione_estesa = :descrizione, updated_at = :now
                WHERE id_dominio = :dom AND id_entrata = :ent
                  AND (descrizione_estesa IS NULL OR descrizione_estesa = "")';
        $stmt = $this->pdo->prepare($sql);
        $now = date('Y-m-d H:i:s');
        $updated = 0;

        foreach ($descrizioniByIdEntrata as $idEntrata => $descrizione) {
            $idEntrata = (string)$idEntrata;
            $descrizione = trim((string)$descrizione);
            if ($idEntrata === '' || $descrizione === '') {
                continue;
            }
            $stmt->execute([
                ':descrizione' => $descrizione,
                ':now' => $now,
                ':dom' => $idDominio,
                ':ent' => $idEntrata,
            ]);
            $updated += $stmt->rowCount();
        }

        return $updated;
    }

}
