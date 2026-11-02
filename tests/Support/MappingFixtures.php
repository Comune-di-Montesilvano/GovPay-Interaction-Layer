<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Fixture sintetiche per i test dei job mapping L1/L2 (flussi + biz + tefa + pattern + vocab).
 * Richiede che la classe che lo usa estenda DbTestCase.
 */
trait MappingFixtures
{
    protected const DOM = '80000000001';

    /**
     * Inserisce una riga flussi (+ biz e tefa opzionali). Ritorna lo IUR.
     *
     * Opzioni: dom, govpay (0|1), mapping, vocab, cod, fornitore, descr_entrata,
     * biz (PROCESSED|PENDING|SKIPPED|null = nessuna riga), descr (biz.descrizione),
     * tefa (null = nessuna riga | stato tefa_ricevute), importo.
     *
     * @param array<string,mixed> $o
     */
    protected function riga(int $n, string $iuv, array $o = []): string
    {
        $o += [
            'dom' => self::DOM, 'govpay' => 0, 'mapping' => 'PENDING', 'vocab' => 'PENDING',
            'cod' => null, 'fornitore' => null, 'descr_entrata' => null,
            'biz' => 'PROCESSED', 'descr' => null, 'tefa' => null, 'importo' => 10.00,
        ];
        $iur = Synthetic::iur($n);
        $pdo = self::pdo();
        $pdo->prepare(
            'INSERT INTO flussi_rendicontazioni
             (id_dominio, id_flusso, anno, mese, iur, iuv, importo, data_pagamento, is_govpay,
              mapping_stato, vocab_stato, cod_entrata, fornitore, descrizione_entrata)
             VALUES (?, \'F1\', 2026, 3, ?, ?, ?, \'2026-03-01\', ?, ?, ?, ?, ?, ?)'
        )->execute([$o['dom'], $iur, $iuv, $o['importo'], $o['govpay'], $o['mapping'], $o['vocab'], $o['cod'], $o['fornitore'], $o['descr_entrata']]);
        if ($o['biz'] !== null) {
            $pdo->prepare(
                'INSERT INTO biz_ricevute (id_dominio, id_flusso, iur, iuv, anno, mese, data_pagamento, importo, descrizione, stato)
                 VALUES (?, \'F1\', ?, ?, 2026, 3, \'2026-03-01\', ?, ?, ?)'
            )->execute([$o['dom'], $iur, $iuv, $o['importo'], $o['descr'], $o['biz']]);
        }
        if ($o['tefa'] !== null) {
            $pdo->prepare(
                'INSERT INTO tefa_ricevute (id_dominio, anno, mese, id_flusso, iur, iuv, stato)
                 VALUES (?, 2026, 3, \'F1\', ?, ?, ?)'
            )->execute([$o['dom'], $iur, $iuv, $o['tefa']]);
        }
        return $iur;
    }

    /** @return array{mapping_stato:string,vocab_stato:string,fornitore:?string,cod_entrata:?string} */
    protected function stato(string $iur): array
    {
        $st = self::pdo()->prepare('SELECT mapping_stato, vocab_stato, fornitore, cod_entrata FROM flussi_rendicontazioni WHERE iur = ?');
        $st->execute([$iur]);
        /** @var array{mapping_stato:string,vocab_stato:string,fornitore:?string,cod_entrata:?string} */
        return $st->fetch(\PDO::FETCH_ASSOC);
    }

    protected function pattern(string $p, ?string $fornitore, ?string $cod = null, int $custom = 0, int $count = 0, ?string $accorpatoA = null, string $dom = self::DOM): void
    {
        self::pdo()->prepare(
            'INSERT INTO mapping_pendenze_pattern (pattern_iuv, id_dominio, fornitore, cod_entrata, accorpato_a, is_custom, transazioni_count)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$p, $dom, $fornitore, $cod, $accorpatoA, $custom, $count]);
    }

    protected function vocab(string $p, string $kw, string $cod, int $prio = 10): void
    {
        self::pdo()->prepare(
            'INSERT INTO mapping_pendenze_vocab (pattern_iuv, id_dominio, keyword, cod_entrata, priorita) VALUES (?, ?, ?, ?, ?)'
        )->execute([$p, self::DOM, $kw, $cod, $prio]);
    }
}
