<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Database;

use PDO;

class MappingPendenzeRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connection::getPDO();
        $this->ensureCustomTipologieTable();
    }

    /**
     * Ritorna tutti i pattern (scoperti e custom) con le keyword vocabolario L2 associate.
     * Il campo `insufficiente` è true quando transazioni_count < 5 (pattern non usato per il matching).
     */
    public function getRules(string $idDominio): array
    {
        $sql = "SELECT * FROM mapping_pendenze_pattern
                WHERE id_dominio = :dom
                ORDER BY is_custom DESC, transazioni_count DESC, CHAR_LENGTH(pattern_iuv) DESC, pattern_iuv ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Costruisce mappa di tutti i pattern per risoluzione ereditarietà
        $patternMap = [];
        foreach ($patterns as $p) {
            $patternMap[$p['pattern_iuv']] = $p;
        }

        // Risolve fornitore e cod_entrata ereditati per i pattern accorpati
        foreach ($patterns as &$p) {
            if (!empty($p['accorpato_a'])) {
                $targetPat = $p['accorpato_a'];
                $visited = [$p['pattern_iuv'] => true];
                while (!empty($targetPat) && isset($patternMap[$targetPat]) && !isset($visited[$targetPat])) {
                    $visited[$targetPat] = true;
                    $target = $patternMap[$targetPat];
                    if (!empty($target['fornitore'])) {
                        $p['fornitore'] = $target['fornitore'];
                    }
                    if (!empty($target['cod_entrata'])) {
                        $p['cod_entrata'] = $target['cod_entrata'];
                    }
                    $targetPat = $target['accorpato_a'] ?? null;
                }
            }
        }
        unset($p);

        // Ricrea la mappa dei pattern ereditati per il vocabolario
        $patternMap = [];
        foreach ($patterns as $p) {
            $patternMap[$p['pattern_iuv']] = $p;
        }

        // Carica tutte le keyword vocabolario L2
        $sqlVocab = "SELECT * FROM mapping_pendenze_vocab
                     WHERE id_dominio = :dom
                     ORDER BY pattern_iuv ASC, priorita DESC, id ASC";
        $stmtVocab = $this->pdo->prepare($sqlVocab);
        $stmtVocab->execute([':dom' => $idDominio]);
        $vocabRows = $stmtVocab->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $vocabMap = [];
        foreach ($vocabRows as $vk) {
            $vocabMap[$vk['pattern_iuv']][] = $vk;
        }

        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';
        foreach ($patterns as &$p) {
            // Eredita i vocab_rules dal target se accorpato
            $targetPat = $p['pattern_iuv'];
            if (!empty($p['accorpato_a'])) {
                $targetPat = $p['accorpato_a'];
                $visited = [$p['pattern_iuv'] => true];
                while (isset($patternMap[$targetPat]) && !isset($visited[$targetPat])) {
                    $visited[$targetPat] = true;
                    $target = $patternMap[$targetPat];
                    if (!empty($target['accorpato_a'])) {
                        $targetPat = $target['accorpato_a'];
                    } else {
                        break;
                    }
                }
            }

            $p['vocab_rules']  = $vocabMap[$targetPat] ?? [];
            $p['insufficiente'] = empty($p['accorpato_a']) && !(bool)($p['is_custom'] ?? false) && (int)$p['transazioni_count'] < 5;

            $vocabRules  = $p['vocab_rules'];
            $col         = "LOWER(COALESCE(b.descrizione, f.descrizione_entrata))";
            $notClauses  = [];
            $exParams    = [':dom' => $idDominio, ':pat' => $p['pattern_iuv'] . '%'];
            foreach ($vocabRules as $i => $vk) {
                $key = ':kw' . $i;
                $notClauses[] = "$col NOT LIKE $key";
                $exParams[$key] = '%' . mb_strtolower((string)$vk['keyword']) . '%';
            }
            $kwWhere  = $notClauses !== [] ? ' AND ' . implode(' AND ', $notClauses) : '';
            $tefaJoin = $tefaEnabled ? "INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio" : '';
            $tefaCond = $tefaEnabled ? "AND t.stato = 'SKIPPED'" : '';
            $baseFrom = "FROM flussi_rendicontazioni f
                         INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                         $tefaJoin
                         WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.iuv LIKE :pat
                           AND b.stato = 'PROCESSED' $tefaCond";

            $sqlEx = "SELECT f.iuv, f.importo, f.id_flusso, COALESCE(b.descrizione, f.descrizione_entrata) AS descrizione_entrata
                      $baseFrom $kwWhere
                      ORDER BY f.data_pagamento DESC, f.id DESC LIMIT 6";
            $stmtEx = $this->pdo->prepare($sqlEx);
            $stmtEx->execute($exParams);
            $p['examples'] = $stmtEx->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Statistiche copertura L2 (una query aggregata per pattern)
            $p['stats_uncovered']  = null;
            $p['stats_by_keyword'] = [];
            if ($vocabRules !== []) {
                $statParams = [':dom' => $idDominio, ':pat' => $p['pattern_iuv'] . '%'];
                $sumCases   = [];
                $notParts   = [];
                foreach ($vocabRules as $i => $vk) {
                    $lkw         = '%' . mb_strtolower((string)$vk['keyword']) . '%';
                    $keyA        = ':kwa' . $i;
                    $keyB        = ':kwb' . $i;
                    $statParams[$keyA] = $lkw;
                    $statParams[$keyB] = $lkw;
                    $sumCases[]  = "SUM(CASE WHEN $col LIKE $keyA THEN 1 ELSE 0 END) AS kw$i";
                    $notParts[]  = "$col NOT LIKE $keyB";
                }
                $notExpr    = implode(' AND ', $notParts);
                $colList    = implode(', ', $sumCases) . ", SUM(CASE WHEN $notExpr THEN 1 ELSE 0 END) AS uncovered";
                $sqlStats   = "SELECT $colList $baseFrom";
                // baseFrom usa :dom e :pat già in $statParams
                $statParams[':dom'] = $idDominio;
                $statParams[':pat'] = $p['pattern_iuv'] . '%';
                $stmtSt = $this->pdo->prepare($sqlStats);
                $stmtSt->execute($statParams);
                $sr = $stmtSt->fetch(PDO::FETCH_ASSOC) ?: [];
                foreach ($vocabRules as $i => $vk) {
                    $p['stats_by_keyword'][$vk['keyword']] = (int)($sr['kw' . $i] ?? 0);
                }
                $p['stats_uncovered'] = (int)($sr['uncovered'] ?? 0);
            }
        }

        return $patterns;
    }

    /**
     * Aggiunge o aggiorna un pattern IUV (Livello 1).
     */
    public function savePatternRule(string $idDominio, string $patternIuv, ?string $fornitore, ?string $codEntrata, int $isCustom = 1): void
    {
        $sql = "INSERT INTO mapping_pendenze_pattern (pattern_iuv, id_dominio, fornitore, cod_entrata, is_custom)
                VALUES (:pattern_iuv, :dom, :fornitore, :cod_entrata, :is_custom)
                ON DUPLICATE KEY UPDATE
                    fornitore = VALUES(fornitore),
                    cod_entrata = VALUES(cod_entrata),
                    is_custom = VALUES(is_custom)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':pattern_iuv' => $patternIuv,
            ':dom'         => $idDominio,
            ':fornitore'   => ($fornitore !== '' && $fornitore !== null) ? $fornitore : null,
            ':cod_entrata' => ($codEntrata !== '' && $codEntrata !== null) ? $codEntrata : null,
            ':is_custom'   => $isCustom,
        ]);

        // Reset vocab_stato su righe già classificate L1: il cron L2 le riprocessa.
        $stmtReset = $this->pdo->prepare(
            "UPDATE flussi_rendicontazioni
             SET vocab_stato = 'PENDING', cod_entrata = NULL
             WHERE id_dominio = :dom
               AND is_govpay = 0
               AND mapping_stato = 'PROCESSED'
               AND vocab_stato IN ('NO_MATCH', 'PROCESSED')
               AND iuv LIKE :prefix"
        );
        $stmtReset->execute([':dom' => $idDominio, ':prefix' => $patternIuv . '%']);

        // Reset righe in NO_MATCH che ora sarebbero coperte dal nuovo pattern:
        // il cron L1 le riprocesserà al prossimo ciclo.
        $stmtResetNoMatch = $this->pdo->prepare(
            "UPDATE flussi_rendicontazioni
             SET mapping_stato = 'PENDING', vocab_stato = 'PENDING', fornitore = NULL, cod_entrata = NULL
             WHERE id_dominio = :dom
               AND is_govpay = 0
               AND mapping_stato = 'NO_MATCH'
               AND iuv LIKE :prefix"
        );
        $stmtResetNoMatch->execute([':dom' => $idDominio, ':prefix' => $patternIuv . '%']);
    }

    /**
     * Elimina un intero pattern IUV e tutte le sue keyword vocabolario (CASCADE).
     */
    public function deletePatternRule(string $patternIuv, string $idDominio): void
    {
        $sql = "DELETE FROM mapping_pendenze_pattern WHERE pattern_iuv = :pattern_iuv AND id_dominio = :dom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pattern_iuv' => $patternIuv, ':dom' => $idDominio]);
    }

    /**
     * Accorpa un pattern IUV a un altro esistente.
     * Se accorpato, pulisce i campi locali per evitare conflitti, dato che verranno ereditati.
     */
    public function accorpaPattern(string $idDominio, string $patternIuv, ?string $accorpatoA): void
    {
        $isCustom = ($accorpatoA !== '' && $accorpatoA !== null) ? 1 : 0;
        $sql = "UPDATE mapping_pendenze_pattern
                SET accorpato_a = :accorpato_a,
                    fornitore = IF(:accorpato_a2 IS NULL, fornitore, NULL),
                    cod_entrata = IF(:accorpato_a3 IS NULL, cod_entrata, NULL),
                    is_custom = :is_custom
                WHERE pattern_iuv = :pattern_iuv AND id_dominio = :dom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':accorpato_a'  => ($accorpatoA !== '' && $accorpatoA !== null) ? $accorpatoA : null,
            ':accorpato_a2' => ($accorpatoA !== '' && $accorpatoA !== null) ? $accorpatoA : null,
            ':accorpato_a3' => ($accorpatoA !== '' && $accorpatoA !== null) ? $accorpatoA : null,
            ':is_custom'    => $isCustom,
            ':pattern_iuv'  => $patternIuv,
            ':dom'          => $idDominio,
        ]);
    }

    // ── Vocabolario L2 ──────────────────────────────────────────────────────

    /**
     * Ritorna tutte le keyword vocabolario L2 per dominio, raggruppate per pattern_iuv.
     * Ogni elemento include il cod_entrata di fallback del pattern padre.
     */
    public function getVocabRules(string $idDominio): array
    {
        $sqlPatterns = "SELECT pattern_iuv, cod_entrata, accorpato_a, fornitore FROM mapping_pendenze_pattern
                        WHERE id_dominio = :dom";
        $stmtPat = $this->pdo->prepare($sqlPatterns);
        $stmtPat->execute([':dom' => $idDominio]);
        $patterns = $stmtPat->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $defaults = [];
        $accorpati = [];
        $fornitori = [];
        foreach ($patterns as $row) {
            $defaults[$row['pattern_iuv']] = $row['cod_entrata'];
            $fornitori[$row['pattern_iuv']] = $row['fornitore'];
            if (!empty($row['accorpato_a'])) {
                $accorpati[$row['pattern_iuv']] = $row['accorpato_a'];
            }
        }

        $sqlVocab = "SELECT * FROM mapping_pendenze_vocab
                     WHERE id_dominio = :dom
                     ORDER BY pattern_iuv ASC, priorita DESC, id ASC";
        $stmt = $this->pdo->prepare($sqlVocab);
        $stmt->execute([':dom' => $idDominio]);
        $vocabRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $vocabMap = [];
        foreach ($vocabRows as $row) {
            $vocabMap[$row['pattern_iuv']][] = $row;
        }

        $result = [];

        foreach ($patterns as $patRow) {
            $pat = $patRow['pattern_iuv'];

            // Trova il target finale per questo pattern (può essere se stesso)
            $targetPat = $pat;
            $visited = [$pat => true];
            while (isset($accorpati[$targetPat]) && !isset($visited[$accorpati[$targetPat]])) {
                $targetPat = $accorpati[$targetPat];
                $visited[$targetPat] = true;
            }

            // Se il target finale (o il pattern corrente) ha un fornitore impostato
            $resolvedFornitore = $fornitori[$targetPat] ?? null;

            if ($resolvedFornitore !== null && $resolvedFornitore !== '') {
                // Eredita le keyword dal target
                $keywords = $vocabMap[$targetPat] ?? [];

                // Se ereditate da un altro pattern, riscriviamo il pattern_iuv per il matching
                if ($targetPat !== $pat) {
                    $adaptedKeywords = [];
                    foreach ($keywords as $kw) {
                        $adaptedKw = $kw;
                        $adaptedKw['pattern_iuv'] = $pat;
                        $adaptedKeywords[] = $adaptedKw;
                    }
                    $keywords = $adaptedKeywords;
                }

                // Fallback default cod_entrata
                $defaultCod = $defaults[$targetPat] ?? null;
                if (empty($defaultCod) && $targetPat !== $pat) {
                    $defaultCod = $defaults[$pat] ?? null;
                }

                $result[$pat] = [
                    'keywords'            => $keywords,
                    'default_cod_entrata' => $defaultCod,
                ];
            }
        }

        return $result;
    }

    /**
     * Assicura che la riga pattern esista senza sovrascrivere fornitore/cod_entrata esistenti.
     */
    private function ensurePatternExists(string $idDominio, string $patternIuv): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO mapping_pendenze_pattern (pattern_iuv, id_dominio, is_custom)
             VALUES (:pat, :dom, 1)"
        );
        $stmt->execute([':pat' => $patternIuv, ':dom' => $idDominio]);
    }

    // ── Tipologie Custom ─────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    public function getCustomTipologie(string $idDominio): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, cod_entrata, descrizione FROM mapping_tipologie_custom
             WHERE id_dominio = :dom ORDER BY descrizione ASC"
        );
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addCustomTipologia(string $idDominio, string $codEntrata, string $descrizione): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO mapping_tipologie_custom (id_dominio, cod_entrata, descrizione)
             VALUES (:dom, :cod, :desc)
             ON DUPLICATE KEY UPDATE descrizione = VALUES(descrizione)"
        );
        $stmt->execute([':dom' => $idDominio, ':cod' => strtoupper(trim($codEntrata)), ':desc' => trim($descrizione)]);
    }

    public function deleteCustomTipologia(string $idDominio, int $id): void
    {
        $stmtGet = $this->pdo->prepare(
            "SELECT cod_entrata FROM mapping_tipologie_custom WHERE id = :id AND id_dominio = :dom"
        );
        $stmtGet->execute([':id' => $id, ':dom' => $idDominio]);
        $row = $stmtGet->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $codEntrata = (string)$row['cod_entrata'];

        $stmtL1 = $this->pdo->prepare(
            "SELECT COUNT(*) FROM mapping_pendenze_pattern WHERE id_dominio = :dom AND cod_entrata = :cod"
        );
        $stmtL1->execute([':dom' => $idDominio, ':cod' => $codEntrata]);
        $countL1 = (int)$stmtL1->fetchColumn();

        $stmtL2 = $this->pdo->prepare(
            "SELECT COUNT(*) FROM mapping_pendenze_desc_regole WHERE id_dominio = :dom AND cod_entrata = :cod"
        );
        $stmtL2->execute([':dom' => $idDominio, ':cod' => $codEntrata]);
        $countL2 = (int)$stmtL2->fetchColumn();

        if ($countL1 > 0 || $countL2 > 0) {
            throw new \RuntimeException(
                "Tipologia '{$codEntrata}' in uso: {$countL1} regole L1, {$countL2} L2. Rimuoverle prima."
            );
        }

        $stmtDel = $this->pdo->prepare(
            "DELETE FROM mapping_tipologie_custom WHERE id = :id AND id_dominio = :dom"
        );
        $stmtDel->execute([':id' => $id, ':dom' => $idDominio]);
    }

    private function ensureCustomTipologieTable(): void
    {
        try {
            $this->pdo->exec(
                "CREATE TABLE IF NOT EXISTS mapping_tipologie_custom (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    id_dominio  VARCHAR(20) NOT NULL,
                    cod_entrata VARCHAR(100) NOT NULL,
                    descrizione VARCHAR(255) NOT NULL,
                    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_dom_cod (id_dominio, cod_entrata),
                    INDEX idx_dominio (id_dominio)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $_) {}
    }

    /**
     * Aggiunge una keyword vocabolario L2 per un pattern IUV.
     */
    public function addVocabRule(string $idDominio, string $patternIuv, string $keyword, string $codEntrata, int $priorita = 10): void
    {
        // Assicura che il pattern esista senza toccare fornitore/cod_entrata esistenti
        $this->ensurePatternExists($idDominio, $patternIuv);

        $sql = "INSERT INTO mapping_pendenze_vocab (pattern_iuv, id_dominio, keyword, cod_entrata, priorita)
                VALUES (:pat, :dom, :kw, :cod, :prio)
                ON DUPLICATE KEY UPDATE cod_entrata = VALUES(cod_entrata), priorita = VALUES(priorita)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':pat'  => $patternIuv,
            ':dom'  => $idDominio,
            ':kw'   => $keyword,
            ':cod'  => $codEntrata,
            ':prio' => $priorita,
        ]);

        // Resetta vocab_stato su righe già classificate che matchano questa keyword,
        // così il cron L2 le riprocessa con la nuova regola.
        $stmtReset = $this->pdo->prepare(
            "UPDATE flussi_rendicontazioni f
             LEFT JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
             SET f.vocab_stato = 'PENDING', f.cod_entrata = NULL
             WHERE f.id_dominio = :dom
               AND f.is_govpay = 0
               AND f.mapping_stato = 'PROCESSED'
               AND f.vocab_stato IN ('NO_MATCH', 'PROCESSED')
               AND f.iuv LIKE :prefix
               AND LOWER(COALESCE(b.descrizione, f.descrizione_entrata)) LIKE :kw"
        );
        $stmtReset->execute([
            ':dom'    => $idDominio,
            ':prefix' => $patternIuv . '%',
            ':kw'     => '%' . mb_strtolower($keyword) . '%',
        ]);
    }

    /**
     * Elimina una singola keyword vocabolario L2.
     */
    public function deleteVocabRule(int $id, string $idDominio): void
    {
        $sql = "DELETE FROM mapping_pendenze_vocab WHERE id = :id AND id_dominio = :dom";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $id, ':dom' => $idDominio]);
    }

    // ── Bulk assign L1 ────────────────────────────────────────────────────

    /**
     * Assegna in blocco il fornitore L1 a tutte le righe con IUV che inizia per $prefix.
     * Processa sia righe PENDING che NO_MATCH: se un pattern attivo le copre, vengono
     * promosse a PROCESSED e rimesse in coda a L2 (vocab_stato = PENDING).
     * Restituisce righe aggiornate.
     */
    public function bulkAssignL1(string $idDominio, string $prefix, string $fornitore): int
    {
        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

        if ($tefaEnabled) {
            $sql = "UPDATE flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                    INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio AND t.stato = 'SKIPPED'
                    SET f.mapping_stato = 'PROCESSED',
                        f.fornitore     = :fornitore,
                        f.vocab_stato   = IF(f.mapping_stato = 'NO_MATCH', 'PENDING', f.vocab_stato),
                        f.cod_entrata   = IF(f.mapping_stato = 'NO_MATCH', NULL, f.cod_entrata)
                    WHERE f.id_dominio = :dom
                      AND f.is_govpay = 0
                      AND f.mapping_stato IN ('PENDING', 'NO_MATCH')
                      AND f.iuv LIKE :prefix";
        } else {
            $sql = "UPDATE flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                    SET f.mapping_stato = 'PROCESSED',
                        f.fornitore     = :fornitore,
                        f.vocab_stato   = IF(f.mapping_stato = 'NO_MATCH', 'PENDING', f.vocab_stato),
                        f.cod_entrata   = IF(f.mapping_stato = 'NO_MATCH', NULL, f.cod_entrata)
                    WHERE f.id_dominio = :dom
                      AND f.is_govpay = 0
                      AND f.mapping_stato IN ('PENDING', 'NO_MATCH')
                      AND f.iuv LIKE :prefix";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio, ':fornitore' => $fornitore, ':prefix' => $prefix . '%']);
        return $stmt->rowCount();
    }

    /**
     * Segna tutte le righe PENDING rimanenti come NO_MATCH (nessun pattern attivo le copre).
     */
    public function bulkSetL1NoMatch(string $idDominio): int
    {
        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

        if ($tefaEnabled) {
            $sql = "UPDATE flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                    INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio AND t.stato = 'SKIPPED'
                    SET f.mapping_stato = 'NO_MATCH', f.vocab_stato = 'NO_MATCH'
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.mapping_stato = 'PENDING'";
        } else {
            $sql = "UPDATE flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                    SET f.mapping_stato = 'NO_MATCH', f.vocab_stato = 'NO_MATCH'
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.mapping_stato = 'PENDING'";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->rowCount();
    }

    // ── Bulk assign L2 ────────────────────────────────────────────────────

    /**
     * Assegna cod_entrata a tutte le righe del pattern che hanno la keyword nella descrizione Biz.
     * Solo righe con vocab_stato='PENDING'. Restituisce righe aggiornate.
     */
    public function bulkAssignVocabKeyword(string $idDominio, string $prefix, string $keyword, string $codEntrata): int
    {
                $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

                if ($tefaEnabled) {
                        $sql = "UPDATE flussi_rendicontazioni f
                                        INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                                        INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio AND t.stato = 'SKIPPED'
                                        SET f.vocab_stato = 'PROCESSED', f.cod_entrata = :cod
                                        WHERE f.id_dominio = :dom
                                            AND f.is_govpay = 0
                                            AND f.mapping_stato = 'PROCESSED'
                                            AND f.vocab_stato IN ('PENDING', 'NO_MATCH')
                                            AND f.iuv LIKE :prefix
                                            AND LOWER(COALESCE(b.descrizione, f.descrizione_entrata)) LIKE :kw";
                } else {
                        $sql = "UPDATE flussi_rendicontazioni f
                                        INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                                        SET f.vocab_stato = 'PROCESSED', f.cod_entrata = :cod
                                        WHERE f.id_dominio = :dom
                                            AND f.is_govpay = 0
                                            AND f.mapping_stato = 'PROCESSED'
                                            AND f.vocab_stato IN ('PENDING', 'NO_MATCH')
                                            AND f.iuv LIKE :prefix
                                            AND LOWER(COALESCE(b.descrizione, f.descrizione_entrata)) LIKE :kw";
                }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':dom'    => $idDominio,
            ':cod'    => $codEntrata,
            ':prefix' => $prefix . '%',
            ':kw'     => '%' . mb_strtolower($keyword) . '%',
        ]);
        return $stmt->rowCount();
    }

    /**
     * Assegna il cod_entrata di fallback del pattern a tutte le righe vocab PENDING del prefisso.
     */
    public function bulkAssignVocabDefault(string $idDominio, string $prefix, string $codEntrata): int
    {
                $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

                if ($tefaEnabled) {
                        $sql = "UPDATE flussi_rendicontazioni f
                                        INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                                        INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio AND t.stato = 'SKIPPED'
                                        SET f.vocab_stato = 'PROCESSED', f.cod_entrata = :cod
                                        WHERE f.id_dominio = :dom
                                            AND f.is_govpay = 0
                                            AND f.mapping_stato = 'PROCESSED'
                                            AND f.vocab_stato IN ('PENDING', 'NO_MATCH')
                                            AND f.iuv LIKE :prefix";
                } else {
                        $sql = "UPDATE flussi_rendicontazioni f
                                        INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                                        SET f.vocab_stato = 'PROCESSED', f.cod_entrata = :cod
                                        WHERE f.id_dominio = :dom
                                            AND f.is_govpay = 0
                                            AND f.mapping_stato = 'PROCESSED'
                                            AND f.vocab_stato IN ('PENDING', 'NO_MATCH')
                                            AND f.iuv LIKE :prefix";
                }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio, ':cod' => $codEntrata, ':prefix' => $prefix . '%']);
        return $stmt->rowCount();
    }

    /**
     * Segna tutte le righe L1-PROCESSED vocab-PENDING rimanenti come vocab NO_MATCH.
     */
    public function bulkSetVocabNoMatch(string $idDominio): int
    {
                $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

                if ($tefaEnabled) {
                        $sql = "UPDATE flussi_rendicontazioni f
                                        INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                                        INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio AND t.stato = 'SKIPPED'
                                        SET f.vocab_stato = 'NO_MATCH'
                                        WHERE f.id_dominio = :dom AND f.is_govpay = 0
                                            AND f.mapping_stato = 'PROCESSED' AND f.vocab_stato = 'PENDING'";
                } else {
                        $sql = "UPDATE flussi_rendicontazioni f
                                        INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                                        SET f.vocab_stato = 'NO_MATCH'
                                        WHERE f.id_dominio = :dom AND f.is_govpay = 0
                                            AND f.mapping_stato = 'PROCESSED' AND f.vocab_stato = 'PENDING'";
                }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->rowCount();
    }

    // ── Demone L1 ──────────────────────────────────────────────────────────

    /**
     * Esegue la scoperta automatica dei pattern analizzando le pendenze esterne.
     * Discovery a cascata: 5-char prima, poi 4-char escludendo IUV già coperti da 5-char,
     * poi 3-char escludendo IUV già coperti da 5 o 4-char.
     * Così transazioni_count riflette solo le righe non ancora coperte da un prefisso più lungo.
     */
    public function discoverPatterns(string $idDominio): int
    {
        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';
        $tefaJoin    = $tefaEnabled ? "INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio" : '';
        $tefaCond    = $tefaEnabled ? "AND t.stato = 'SKIPPED'" : '';

        $baseJoin = "FROM flussi_rendicontazioni f
                     INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                     $tefaJoin
                     WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.iuv IS NOT NULL
                       AND b.stato = 'PROCESSED' $tefaCond";

        // helper: build NOT IN clause + bind params for a given prefix length and exclusion list
        $buildExclusion = static function (array $prefixes, int $len, string $paramPrefix): array {
            if ($prefixes === []) {
                return ['clause' => '', 'params' => []];
            }
            $keys   = [];
            $params = [];
            foreach (array_values($prefixes) as $i => $p) {
                $k          = ":{$paramPrefix}{$i}";
                $keys[]     = $k;
                $params[$k] = $p;
            }
            return [
                'clause' => "AND LEFT(f.iuv, $len) NOT IN (" . implode(',', $keys) . ")",
                'params' => $params,
            ];
        };

        $discovered = [];

        // ── 5-char ──────────────────────────────────────────────────────────
        $sql5 = "SELECT LEFT(f.iuv, 5) AS p, COUNT(*) AS cnt, SUM(f.importo) AS tot
                 $baseJoin AND CHAR_LENGTH(f.iuv) >= 5
                 GROUP BY p";
        $stmt = $this->pdo->prepare($sql5);
        $stmt->execute([':dom' => $idDominio]);
        $rows5     = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $prefixes5 = array_column($rows5, 'p');
        foreach ($rows5 as $r) {
            $discovered[$r['p']] = $r;
        }

        // ── 4-char (escludi IUV con prefisso 5-char già scoperto) ───────────
        $excl5 = $buildExclusion($prefixes5, 5, 'e5_');
        $sql4  = "SELECT LEFT(f.iuv, 4) AS p, COUNT(*) AS cnt, SUM(f.importo) AS tot
                  $baseJoin AND CHAR_LENGTH(f.iuv) >= 4 {$excl5['clause']}
                  GROUP BY p";
        $stmt  = $this->pdo->prepare($sql4);
        $stmt->execute(array_merge([':dom' => $idDominio], $excl5['params']));
        $rows4     = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $prefixes4 = array_column($rows4, 'p');
        foreach ($rows4 as $r) {
            $discovered[$r['p']] = $r;
        }

        // ── 3-char (escludi IUV con prefisso 5 o 4-char già scoperto) ───────
        $excl5b = $buildExclusion($prefixes5, 5, 'e5b_');
        $excl4  = $buildExclusion($prefixes4, 4, 'e4_');
        $sql3   = "SELECT LEFT(f.iuv, 3) AS p, COUNT(*) AS cnt, SUM(f.importo) AS tot
                   $baseJoin AND CHAR_LENGTH(f.iuv) >= 3 {$excl5b['clause']} {$excl4['clause']}
                   GROUP BY p";
        $stmt   = $this->pdo->prepare($sql3);
        $stmt->execute(array_merge([':dom' => $idDominio], $excl5b['params'], $excl4['params']));
        $rows3  = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows3 as $r) {
            $discovered[$r['p']] = $r;
        }

        if ($discovered === []) {
            $this->pdo->prepare("DELETE FROM mapping_pendenze_pattern WHERE id_dominio = :dom AND is_custom = 0")
                      ->execute([':dom' => $idDominio]);
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $activePatterns = [];
            foreach ($discovered as $d) {
                $prefix = $d['p'];
                $activePatterns[] = $prefix;
                $sqlUpsert = "INSERT INTO mapping_pendenze_pattern
                                (pattern_iuv, id_dominio, is_custom, transazioni_count, importo_totale)
                              VALUES (:pattern, :dom, 0, :cnt, :tot)
                              ON DUPLICATE KEY UPDATE transazioni_count = :cnt2, importo_totale = :tot2";
                $stmtUpsert = $this->pdo->prepare($sqlUpsert);
                $stmtUpsert->execute([
                    ':pattern' => $prefix, ':dom' => $idDominio,
                    ':cnt'  => (int)$d['cnt'],   ':tot'  => (float)$d['tot'],
                    ':cnt2' => (int)$d['cnt'],   ':tot2' => (float)$d['tot'],
                ]);
            }

            $placeholders = implode(',', array_fill(0, count($activePatterns), '?'));
            $stmtClean = $this->pdo->prepare(
                "DELETE FROM mapping_pendenze_pattern WHERE id_dominio = ? AND is_custom = 0 AND pattern_iuv NOT IN ($placeholders)"
            );
            $stmtClean->execute(array_merge([$idDominio], $activePatterns));

            $this->pdo->commit();
            return count($activePatterns);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Ritorna un campione di pendenze in stato NO_MATCH che hanno completato l'intero
     * pipeline (biz PROCESSED + eventuale TEFA SKIPPED). Stesse JOIN di getMappingStats().
     */
    public function getNoMatchExamples(string $idDominio, int $limit = 6): array
    {
        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

        if ($tefaEnabled) {
            $sql = "SELECT f.iuv, f.importo, f.id_flusso,
                           COALESCE(b.descrizione, f.descrizione_entrata) AS descrizione_entrata
                    FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom
                      AND f.is_govpay = 0
                      AND f.mapping_stato = 'NO_MATCH'
                      AND b.stato = 'PROCESSED'
                      AND t.stato = 'SKIPPED'
                    ORDER BY f.data_pagamento DESC, f.id DESC
                    LIMIT :limit";
        } else {
            $sql = "SELECT f.iuv, f.importo, f.id_flusso,
                           COALESCE(b.descrizione, f.descrizione_entrata) AS descrizione_entrata
                    FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom
                      AND f.is_govpay = 0
                      AND f.mapping_stato = 'NO_MATCH'
                      AND b.stato = 'PROCESSED'
                    ORDER BY f.data_pagamento DESC, f.id DESC
                    LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dom', $idDominio, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Estrae le pendenze esterne pronte per il mapping L1 (biz PROCESSED, mapping_stato PENDING).
     */
    public function fetchPendingMapping(string $idDominio, int $limit = 500): array
    {
        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

        if ($tefaEnabled) {
            $sql = "SELECT f.id, f.iuv, b.descrizione
                    FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.mapping_stato = 'PENDING'
                      AND b.stato = 'PROCESSED' AND t.stato = 'SKIPPED'
                    LIMIT :limit";
        } else {
            $sql = "SELECT f.id, f.iuv, b.descrizione
                    FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.mapping_stato = 'PENDING'
                      AND b.stato = 'PROCESSED'
                    LIMIT :limit";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dom', $idDominio, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Aggiorna lo stato L1 (solo fornitore e mapping_stato).
     * Se NO_MATCH propaga anche vocab_stato a NO_MATCH.
     * cod_entrata è di competenza del demone L2.
     */
    public function updateMappingResult(int $id, string $stato, ?string $fornitore): void
    {
        $sql = "UPDATE flussi_rendicontazioni
                SET mapping_stato = :stato,
                    fornitore = :fornitore,
                    vocab_stato = IF(:stato2 = 'NO_MATCH', 'NO_MATCH', vocab_stato)
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':stato'    => $stato,
            ':stato2'   => $stato,
            ':fornitore'=> $fornitore,
            ':id'       => $id,
        ]);
    }

    // ── Demone L2 ──────────────────────────────────────────────────────────

    /**
     * Estrae le pendenze L1-processate in attesa di classificazione vocab L2.
     */
    public function fetchPendingVocab(string $idDominio, int $limit = 500): array
    {
        $sql = "SELECT f.id, f.iuv, b.descrizione
                FROM flussi_rendicontazioni f
                INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                WHERE f.id_dominio = :dom
                  AND f.is_govpay = 0
                  AND f.mapping_stato = 'PROCESSED'
                  AND f.vocab_stato = 'PENDING'
                ORDER BY f.id ASC
                LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':dom', $idDominio, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Aggiorna vocab_stato e cod_entrata per una pendenza dopo la scansione L2.
     */
    public function updateVocabResult(int $id, string $stato, ?string $codEntrata): void
    {
        $sql = "UPDATE flussi_rendicontazioni
                SET vocab_stato = :stato, cod_entrata = :cod_entrata
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':stato' => $stato, ':cod_entrata' => $codEntrata, ':id' => $id]);
    }

    // ── Reset ──────────────────────────────────────────────────────────────

    /**
     * Resetta l'intero mapping (L1 + L2) per rielaborare tutto da zero.
     */
    public function resetAllMappings(string $idDominio): int
    {
        $sql = "UPDATE flussi_rendicontazioni
                SET mapping_stato = 'PENDING',
                    vocab_stato = 'PENDING',
                    cod_entrata = NULL,
                    fornitore = NULL
                WHERE id_dominio = :dom AND is_govpay = 0";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->rowCount();
    }

    // ── Statistiche ────────────────────────────────────────────────────────

    /**
     * Ritorna le statistiche aggregate per L1 (mapping_stato) e L2 (vocab_stato).
     * Struttura backward-compat: chiavi top-level per L1, chiave 'vocab' per L2.
     */
    public function getMappingStats(string $idDominio): array
    {
        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

        if ($tefaEnabled) {
            $sql = "SELECT f.mapping_stato, f.vocab_stato, COUNT(*) as c, SUM(f.importo) as a
                    FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0
                      AND b.stato = 'PROCESSED' AND t.stato = 'SKIPPED'
                    GROUP BY f.mapping_stato, f.vocab_stato";
        } else {
            $sql = "SELECT f.mapping_stato, f.vocab_stato, COUNT(*) as c, SUM(f.importo) as a
                    FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND b.stato = 'PROCESSED'
                    GROUP BY f.mapping_stato, f.vocab_stato";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stats = [
            'PENDING'   => ['count' => 0, 'amount' => 0.0],
            'PROCESSED' => ['count' => 0, 'amount' => 0.0],
            'NO_MATCH'  => ['count' => 0, 'amount' => 0.0],
            'total'     => ['count' => 0, 'amount' => 0.0],
            'vocab'     => [
                'PENDING'   => ['count' => 0, 'amount' => 0.0],
                'PROCESSED' => ['count' => 0, 'amount' => 0.0],
                'NO_MATCH'  => ['count' => 0, 'amount' => 0.0],
            ],
        ];

        foreach ($rows as $row) {
            $l1 = $row['mapping_stato'];
            $l2 = $row['vocab_stato'];
            $c  = (int)$row['c'];
            $a  = (float)($row['a'] ?? 0.0);

            if (isset($stats[$l1])) {
                $stats[$l1]['count']  += $c;
                $stats[$l1]['amount'] += $a;
            }
            $stats['total']['count']  += $c;
            $stats['total']['amount'] += $a;

            // L2 stats: solo righe che L1 ha già processato
            if ($l1 === 'PROCESSED' && isset($stats['vocab'][$l2])) {
                $stats['vocab'][$l2]['count']  += $c;
                $stats['vocab'][$l2]['amount'] += $a;
            }
        }

        return $stats;
    }

    public function countTotalPendingMapping(string $idDominio): int
    {
        $sql = "SELECT COUNT(*) FROM flussi_rendicontazioni
                WHERE id_dominio = :dom AND is_govpay = 0 AND mapping_stato = 'PENDING'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        return (int)$stmt->fetchColumn();
    }

    public function countReadyForMapping(string $idDominio): int
    {
        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';
        if ($tefaEnabled) {
            $sql = "SELECT COUNT(*) FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.mapping_stato = 'PENDING'
                      AND b.stato = 'PROCESSED' AND t.stato = 'SKIPPED'";
        } else {
            $sql = "SELECT COUNT(*) FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.mapping_stato = 'PENDING'
                      AND b.stato = 'PROCESSED'";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        return (int)$stmt->fetchColumn();
    }

    public function countPendingVocab(string $idDominio): int
    {
        $sql = "SELECT COUNT(*) FROM flussi_rendicontazioni
                WHERE id_dominio = :dom AND is_govpay = 0
                  AND mapping_stato = 'PROCESSED' AND vocab_stato = 'PENDING'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        return (int)$stmt->fetchColumn();
    }
}
