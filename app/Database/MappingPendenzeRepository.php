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
    }

    /**
     * Ritorna tutti i pattern (scoperti e custom) con le keyword vocabolario L2 associate.
     * Il campo `insufficiente` è true quando transazioni_count < 5 (pattern non usato per il matching).
     */
    public function getRules(string $idDominio): array
    {
        $sql = "SELECT * FROM mapping_pendenze_pattern
                WHERE id_dominio = :dom
                ORDER BY is_custom DESC, CHAR_LENGTH(pattern_iuv) DESC, pattern_iuv ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
            $p['vocab_rules']  = $vocabMap[$p['pattern_iuv']] ?? [];
            $p['insufficiente'] = (int)$p['transazioni_count'] < 5;

            if ($tefaEnabled) {
                $sqlEx = "SELECT f.iuv, f.importo, f.id_flusso, COALESCE(b.descrizione, f.descrizione_entrata) AS descrizione_entrata
                          FROM flussi_rendicontazioni f
                          INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                          INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                          WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.iuv LIKE :pat
                            AND b.stato = 'PROCESSED' AND t.stato = 'SKIPPED'
                          ORDER BY f.data_pagamento DESC, f.id DESC LIMIT 3";
            } else {
                $sqlEx = "SELECT f.iuv, f.importo, f.id_flusso, COALESCE(b.descrizione, f.descrizione_entrata) AS descrizione_entrata
                          FROM flussi_rendicontazioni f
                          INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                          WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.iuv LIKE :pat
                            AND b.stato = 'PROCESSED'
                          ORDER BY f.data_pagamento DESC, f.id DESC LIMIT 3";
            }
            $stmtEx = $this->pdo->prepare($sqlEx);
            $stmtEx->execute([':dom' => $idDominio, ':pat' => $p['pattern_iuv'] . '%']);
            $p['examples'] = $stmtEx->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

    // ── Vocabolario L2 ──────────────────────────────────────────────────────

    /**
     * Ritorna tutte le keyword vocabolario L2 per dominio, raggruppate per pattern_iuv.
     * Ogni elemento include il cod_entrata di fallback del pattern padre.
     */
    public function getVocabRules(string $idDominio): array
    {
        $sqlPatterns = "SELECT pattern_iuv, cod_entrata FROM mapping_pendenze_pattern
                        WHERE id_dominio = :dom AND fornitore IS NOT NULL";
        $stmtPat = $this->pdo->prepare($sqlPatterns);
        $stmtPat->execute([':dom' => $idDominio]);
        $defaults = [];
        foreach ($stmtPat->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $defaults[$row['pattern_iuv']] = $row['cod_entrata'];
        }

        $sqlVocab = "SELECT * FROM mapping_pendenze_vocab
                     WHERE id_dominio = :dom
                     ORDER BY pattern_iuv ASC, priorita DESC, id ASC";
        $stmt = $this->pdo->prepare($sqlVocab);
        $stmt->execute([':dom' => $idDominio]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $pat = $row['pattern_iuv'];
            if (!isset($result[$pat])) {
                $result[$pat] = [
                    'keywords'           => [],
                    'default_cod_entrata' => $defaults[$pat] ?? null,
                ];
            }
            $result[$pat]['keywords'][] = $row;
        }

        // Aggiungi pattern senza vocab ma con default cod_entrata (solo fallback)
        foreach ($defaults as $pat => $default) {
            if (!isset($result[$pat]) && $default !== null) {
                $result[$pat] = ['keywords' => [], 'default_cod_entrata' => $default];
            }
        }

        return $result;
    }

    /**
     * Aggiunge una keyword vocabolario L2 per un pattern IUV.
     */
    public function addVocabRule(string $idDominio, string $patternIuv, string $keyword, string $codEntrata, int $priorita = 10): void
    {
        // Assicura che il pattern esista (per il vincolo FK)
        $this->savePatternRule($idDominio, $patternIuv, null, null, 1);

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
     * Solo righe con mapping_stato='PENDING'. Restituisce righe aggiornate.
     */
    public function bulkAssignL1(string $idDominio, string $prefix, string $fornitore): int
    {
        $sql = "UPDATE flussi_rendicontazioni
                SET mapping_stato = 'PROCESSED', fornitore = :fornitore
                WHERE id_dominio = :dom
                  AND is_govpay = 0
                  AND mapping_stato = 'PENDING'
                  AND iuv LIKE :prefix";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio, ':fornitore' => $fornitore, ':prefix' => $prefix . '%']);
        return $stmt->rowCount();
    }

    /**
     * Segna tutte le righe PENDING rimanenti come NO_MATCH (nessun pattern attivo le copre).
     */
    public function bulkSetL1NoMatch(string $idDominio): int
    {
        $sql = "UPDATE flussi_rendicontazioni
                SET mapping_stato = 'NO_MATCH', vocab_stato = 'NO_MATCH'
                WHERE id_dominio = :dom AND is_govpay = 0 AND mapping_stato = 'PENDING'";
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
        // LEFT JOIN + COALESCE: usa b.descrizione se disponibile, fallback su f.descrizione_entrata
        $sql = "UPDATE flussi_rendicontazioni f
                LEFT JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio AND b.stato = 'PROCESSED'
                SET f.vocab_stato = 'PROCESSED', f.cod_entrata = :cod
                WHERE f.id_dominio = :dom
                  AND f.is_govpay = 0
                  AND f.mapping_stato = 'PROCESSED'
                  AND f.vocab_stato = 'PENDING'
                  AND f.iuv LIKE :prefix
                  AND COALESCE(b.descrizione, f.descrizione_entrata) LIKE :kw";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':dom'    => $idDominio,
            ':cod'    => $codEntrata,
            ':prefix' => $prefix . '%',
            ':kw'     => '%' . $keyword . '%',
        ]);
        return $stmt->rowCount();
    }

    /**
     * Assegna il cod_entrata di fallback del pattern a tutte le righe vocab PENDING del prefisso.
     */
    public function bulkAssignVocabDefault(string $idDominio, string $prefix, string $codEntrata): int
    {
        $sql = "UPDATE flussi_rendicontazioni
                SET vocab_stato = 'PROCESSED', cod_entrata = :cod
                WHERE id_dominio = :dom
                  AND is_govpay = 0
                  AND mapping_stato = 'PROCESSED'
                  AND vocab_stato = 'PENDING'
                  AND iuv LIKE :prefix";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio, ':cod' => $codEntrata, ':prefix' => $prefix . '%']);
        return $stmt->rowCount();
    }

    /**
     * Segna tutte le righe L1-PROCESSED vocab-PENDING rimanenti come vocab NO_MATCH.
     */
    public function bulkSetVocabNoMatch(string $idDominio): int
    {
        $sql = "UPDATE flussi_rendicontazioni
                SET vocab_stato = 'NO_MATCH'
                WHERE id_dominio = :dom AND is_govpay = 0
                  AND mapping_stato = 'PROCESSED' AND vocab_stato = 'PENDING'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        return $stmt->rowCount();
    }

    // ── Demone L1 ──────────────────────────────────────────────────────────

    /**
     * Esegue la scoperta automatica dei pattern analizzando le pendenze esterne.
     */
    public function discoverPatterns(string $idDominio): int
    {
        $tefaEnabled = \App\Config\SettingsRepository::get('backoffice', 'tefa_enabled', 'false') === 'true';

        if ($tefaEnabled) {
            $sql = "SELECT LEFT(f.iuv, 5) AS pattern_extracted, COUNT(*) AS cnt, SUM(f.importo) AS tot
                    FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    INNER JOIN tefa_ricevute t ON t.iur = f.iur AND t.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.iuv IS NOT NULL
                      AND CHAR_LENGTH(f.iuv) >= 5 AND b.stato = 'PROCESSED' AND t.stato = 'SKIPPED'
                    GROUP BY pattern_extracted";
        } else {
            $sql = "SELECT LEFT(f.iuv, 5) AS pattern_extracted, COUNT(*) AS cnt, SUM(f.importo) AS tot
                    FROM flussi_rendicontazioni f
                    INNER JOIN biz_ricevute b ON b.iur = f.iur AND b.id_dominio = f.id_dominio
                    WHERE f.id_dominio = :dom AND f.is_govpay = 0 AND f.iuv IS NOT NULL
                      AND CHAR_LENGTH(f.iuv) >= 5 AND b.stato = 'PROCESSED'
                    GROUP BY pattern_extracted";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':dom' => $idDominio]);
        $discovered = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($discovered === []) {
            $this->pdo->prepare("DELETE FROM mapping_pendenze_pattern WHERE id_dominio = :dom AND is_custom = 0")
                      ->execute([':dom' => $idDominio]);
            return 0;
        }

        $this->pdo->beginTransaction();
        try {
            $activePatterns = [];
            foreach ($discovered as $d) {
                $prefix = $d['pattern_extracted'];
                $activePatterns[] = $prefix;
                $sqlUpsert = "INSERT INTO mapping_pendenze_pattern
                                (pattern_iuv, id_dominio, is_custom, transazioni_count, importo_totale)
                              VALUES (:pattern, :dom, 0, :cnt, :tot)
                              ON DUPLICATE KEY UPDATE transazioni_count = :cnt2, importo_totale = :tot2";
                $stmtUpsert = $this->pdo->prepare($sqlUpsert);
                $stmtUpsert->execute([
                    ':pattern' => $prefix, ':dom' => $idDominio,
                    ':cnt' => (int)$d['cnt'], ':tot' => (float)$d['tot'],
                    ':cnt2' => (int)$d['cnt'], ':tot2' => (float)$d['tot'],
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
