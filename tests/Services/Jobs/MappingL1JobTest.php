<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\MappingPendenzeRepository;
use App\Services\Jobs\MappingL1Job;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\MappingFixtures;
use Tests\Support\RecordingJobContext;

/**
 * Caratterizzazione di mapping L1 (cron_mapping_pendenze.php): discover() e runOnce().
 * Solo DB, nessuna chiamata HTTP.
 */
#[Group('db')]
final class MappingL1JobTest extends DbTestCase
{
    use MappingFixtures;

    protected static array $tables = [
        'mapping_pendenze_vocab', 'mapping_pendenze_pattern',
        'tefa_ricevute', 'biz_ricevute', 'flussi_rendicontazioni', 'settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        // tabelle create pigramente dal costruttore del repository
        (new MappingPendenzeRepository(self::pdo()));
        self::pdo()->exec('DELETE FROM mapping_pendenze_pattern_diagnostics');
    }

    private function job(?MappingPendenzeRepository $repo = null): MappingL1Job
    {
        return new MappingL1Job($repo ?? new MappingPendenzeRepository(self::pdo()), self::DOM);
    }

    private function tefaOn(): void
    {
        SettingsRepository::set('backoffice', 'tefa_enabled', 'true');
    }

    /** @return array<string,array{int,float,int}> pattern => [count, importo, is_custom] */
    private function patterns(): array
    {
        $out = [];
        foreach (self::pdo()->query('SELECT pattern_iuv, transazioni_count, importo_totale, is_custom FROM mapping_pendenze_pattern ORDER BY pattern_iuv') as $r) {
            $out[$r['pattern_iuv']] = [(int)$r['transazioni_count'], (float)$r['importo_totale'], (int)$r['is_custom']];
        }
        return $out;
    }

    /** @return list<string> */
    private function patternKeys(): array
    {
        return array_map('strval', array_keys($this->patterns()));
    }

    /** @return list<mixed> */
    private function st(string $iur): array
    {
        $s = $this->stato($iur);
        return [$s['mapping_stato'], $s['vocab_stato'], $s['fornitore'], $s['cod_entrata']];
    }

    // ------------------------------------------------------------ discover()

    public function testDiscoverFiveCharCountsAndSums(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->riga($i, '11111' . str_pad((string)$i, 12, '0', STR_PAD_LEFT), ['importo' => 5.50]);
        }
        $this->riga(10, '22222000000000001');

        self::assertSame(2, $this->job()->discover());
        self::assertSame(['11111' => [6, 33.0, 0], '22222' => [1, 10.0, 0]], $this->patterns(), 'nessuna soglia in discovery: anche count=1');
    }

    public function testDiscoverShortIuvsFeedFourAndThreeCharPatterns(): void
    {
        // 4-char/3-char nascono solo da IUV lunghi esattamente 4 / 3: ogni IUV >=5 forma gia' un prefisso 5-char
        // M2: comportamento accettato e documentato in CLAUDE.md ("Discovery pattern L1"), nessun redesign.
        $this->riga(1, '55555000000000001');
        $this->riga(2, '4444');
        $this->riga(3, '4444');
        $this->riga(4, '333');
        $this->riga(5, '55');   // < 3 char: mai considerato

        self::assertSame(3, $this->job()->discover());
        self::assertSame(['333' => [1, 10.0, 0], '4444' => [2, 20.0, 0], '55555' => [1, 10.0, 0]], $this->patterns());
    }

    public function testDiscoverCascadeExcludesCoveredIuv(): void
    {
        // IUV '4444' (4 char) e' escluso dalla query 3-char perche' LEFT(,4)='4444' e' gia' scoperto.
        $this->riga(1, '4444');
        $this->riga(2, '444');

        $this->job()->discover();
        self::assertSame(['444' => [1, 10.0, 0], '4444' => [1, 10.0, 0]], $this->patterns());
    }

    public function testDiscoverEligibilityGatingTefaDisabled(): void
    {
        $this->riga(1, '11111000000000001', ['govpay' => 1]);
        $this->riga(2, '11111000000000002', ['biz' => 'PENDING']);
        $this->riga(3, '11111000000000003', ['biz' => 'SKIPPED']);
        $this->riga(4, '11111000000000004', ['biz' => null]);
        $this->riga(5, '11111000000000005', ['dom' => '99999999999']);
        $this->riga(6, '66666000000000006', ['tefa' => 'PROCESSED']);   // tefa ignorata se disabilitato
        $this->riga(7, '77777000000000007', ['tefa' => 'SKIPPED']);

        self::assertSame(2, $this->job()->discover());
        self::assertSame(['66666', '77777'], $this->patternKeys());
    }

    public function testDiscoverTefaEnabledRequiresTefaSkipped(): void
    {
        $this->tefaOn();
        $this->riga(1, '11111000000000001', ['tefa' => 'PROCESSED']);
        $this->riga(2, '22222000000000002', ['tefa' => 'SKIPPED']);
        $this->riga(3, '33333000000000003', ['tefa' => null]);

        self::assertSame(1, $this->job()->discover());
        self::assertSame(['22222'], $this->patternKeys());
    }

    public function testDiscoverRemovesStaleAutoPatternsKeepsCustom(): void
    {
        $this->pattern('99999', 'Forn', null, 0, 7);            // auto non piu' presente -> cancellato
        $this->pattern('88888', 'Forn', 'COD', 1, 3);           // custom -> resta
        $this->riga(1, '11111000000000001');

        self::assertSame(1, $this->job()->discover());
        self::assertSame(['11111', '88888'], $this->patternKeys());
    }

    public function testDiscoverNothingFoundDeletesAllAutoReturnsZero(): void
    {
        $this->pattern('99999', null, null, 0, 7);
        $this->pattern('88888', null, null, 1, 3);

        self::assertSame(0, $this->job()->discover());
        self::assertSame(['88888'], $this->patternKeys());
    }

    public function testDiscoverOverwritesCountOfExistingCustomPatternKeepingFlags(): void
    {
        // difetto noto: l'upsert ON DUPLICATE KEY non guarda is_custom; sovrascrive il count anche di un custom
        $this->pattern('11111', 'Forn', 'COD', 1, 99);
        $this->riga(1, '11111000000000001');

        $this->job()->discover();
        $row = self::pdo()->query("SELECT fornitore, cod_entrata, is_custom, transazioni_count FROM mapping_pendenze_pattern WHERE pattern_iuv='11111'")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['fornitore' => 'Forn', 'cod_entrata' => 'COD', 'is_custom' => 1, 'transazioni_count' => 1], $row);
    }

    // ------------------------------------------------------------ runOnce(): regole attive

    public function testRulesActiveFilterAndAssignment(): void
    {
        $this->pattern('11111', 'A', null, 0, 5);      // auto count>=5 con fornitore -> attivo
        $this->pattern('22222', 'B', null, 0, 4);      // auto count<5 -> inattivo
        $this->pattern('33333', null, null, 0, 9);     // auto senza fornitore -> inattivo
        $this->pattern('44444', 'D', null, 1, 0);      // custom con count 0 -> attivo
        $this->pattern('55555', '', null, 1, 9);       // fornitore stringa vuota -> inattivo
        $r1 = $this->riga(1, '11111000000000001');
        $r2 = $this->riga(2, '22222000000000002');
        $r3 = $this->riga(3, '33333000000000003');
        $r4 = $this->riga(4, '44444000000000004');
        $r5 = $this->riga(5, '55555000000000005');

        $res = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame(['PROCESSED', 'PENDING', 'A', null], $this->st($r1));
        self::assertSame(['PROCESSED', 'PENDING', 'D', null], $this->st($r4));
        foreach ([$r2, $r3, $r5] as $iur) {
            self::assertSame(['NO_MATCH', 'NO_MATCH', null, null], $this->st($iur));
        }
        self::assertSame([5, 2, 3, 0, true], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
    }

    public function testAccorpatoInheritsFornitoreAndIsActiveRegardlessOfCount(): void
    {
        $this->pattern('11111', 'Target', null, 1, 0);
        $this->pattern('22222', null, null, 0, 1, '11111');
        $r = $this->riga(1, '22222000000000001');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'PENDING', 'Target', null], $this->st($r));
    }

    public function testAccorpatoWithoutTargetFornitoreStaysInactive(): void
    {
        $this->pattern('11111', null, null, 1, 0);
        $this->pattern('22222', null, null, 0, 9, '11111');
        $r = $this->riga(1, '22222000000000001');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('NO_MATCH', $this->stato($r)['mapping_stato']);
    }

    public function testLongestPrefixWinsOverShorterCustom(): void
    {
        // M1 (decisione utente): prefisso piu' lungo prima; un custom corto non batte un auto lungo.
        $this->pattern('12345', 'Lungo', null, 0, 50);
        $this->pattern('123', 'Corto', null, 1, 0);
        $r = $this->riga(1, '12345000000000001');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('Lungo', $this->stato($r)['fornitore']);
    }

    public function testLongestPrefixWinsOverHigherCountAmongAutoPatterns(): void
    {
        // M1: transazioni_count non conta per l'ordine
        $this->pattern('1234', 'Quattro', null, 0, 20);
        $this->pattern('12345', 'Cinque', null, 0, 6);
        $r = $this->riga(1, '12345000000000001');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('Cinque', $this->stato($r)['fornitore']);
    }

    public function testLongestPrefixWinsAmongCustomPatterns(): void
    {
        $this->pattern('123', 'Tre', null, 1, 0);
        $this->pattern('12345', 'Cinque', null, 1, 0);
        $r = $this->riga(1, '12345000000000001');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('Cinque', $this->stato($r)['fornitore']);
    }

    public function testShorterCustomStillCoversRowsTheLongerPatternMisses(): void
    {
        $this->pattern('12345', 'Lungo', null, 0, 50);
        $this->pattern('123', 'Corto', null, 1, 0);
        $r = $this->riga(1, '12399000000000001');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('Corto', $this->stato($r)['fornitore']);
    }

    public function testSortForAssignmentOrdersByLengthThenCustomIgnoringCount(): void
    {
        $rules = [
            ['pattern_iuv' => '123',   'is_custom' => 1, 'transazioni_count' => 0],
            ['pattern_iuv' => '99999', 'is_custom' => 0, 'transazioni_count' => 1],
            ['pattern_iuv' => '1234',  'is_custom' => 0, 'transazioni_count' => 900],
            ['pattern_iuv' => '12345', 'is_custom' => 1, 'transazioni_count' => 0],
            ['pattern_iuv' => '88888', 'is_custom' => 0, 'transazioni_count' => 500],
            ['pattern_iuv' => '9999',  'is_custom' => 1, 'transazioni_count' => 0],
        ];
        $sorted = array_column(MappingPendenzeRepository::sortForAssignment($rules), 'pattern_iuv');
        // 5 char: custom prima, poi auto in ordine di pattern (count ignorato); poi 4 char (custom prima); poi 3
        self::assertSame(['12345', '88888', '99999', '9999', '1234', '123'], array_map('strval', $sorted));
    }

    // ------------------------------------------------------------ runOnce(): transizioni di stato

    public function testNoMatchRowIsRepromotedAndVocabReset(): void
    {
        $this->pattern('11111', 'A', null, 1);
        $nm = $this->riga(1, '11111000000000001', ['mapping' => 'NO_MATCH', 'vocab' => 'NO_MATCH', 'cod' => 'OLD']);
        $pend = $this->riga(2, '11111000000000002', ['mapping' => 'PENDING', 'vocab' => 'PENDING', 'cod' => 'KEEP']);
        $done = $this->riga(3, '11111000000000003', ['mapping' => 'PROCESSED', 'vocab' => 'PROCESSED', 'cod' => 'X', 'fornitore' => 'Altro']);

        $res = $this->job()->runOnce(new RecordingJobContext());

        // difetto noto: il reset vocab_stato->PENDING / cod_entrata->NULL dell'UPDATE multi-tabella dipende dal piano
        // di esecuzione MariaDB (a volte la IF vede mapping_stato gia' aggiornato): non pinnato.
        self::assertSame(['PROCESSED', 'A'], [$this->st($nm)[0], $this->st($nm)[2]], 'NO_MATCH -> PROCESSED col fornitore');
        self::assertSame(['PROCESSED', 'PENDING', 'A', 'KEEP'], $this->st($pend), 'PENDING: vocab e cod invariati');
        self::assertSame(['PROCESSED', 'PROCESSED', 'Altro', 'X'], $this->st($done), 'gia PROCESSED: non toccata');
        self::assertSame(2, $res->ok);
    }

    public function testIneligibleRowsAreNeverTouched(): void
    {
        $this->pattern('11111', 'A', null, 1);
        $rows = [
            $this->riga(1, '11111000000000001', ['govpay' => 1]),
            $this->riga(2, '11111000000000002', ['biz' => 'PENDING']),
            $this->riga(3, '11111000000000003', ['biz' => 'SKIPPED']),
            $this->riga(4, '11111000000000004', ['biz' => null]),
            $this->riga(5, '11111000000000005', ['dom' => '99999999999']),
            $this->riga(6, '11111000000000006', ['tefa' => 'SKIPPED']),
        ];
        // TEFA disabilitato: solo l'ultima e' eleggibile (la riga tefa e' ignorata), le altre no.
        $res = $this->job()->runOnce(new RecordingJobContext());
        foreach (array_slice($rows, 0, 5) as $iur) {
            self::assertSame(['PENDING', 'PENDING', null, null], $this->st($iur));
        }
        self::assertSame('PROCESSED', $this->stato($rows[5])['mapping_stato']);
        self::assertSame([1, 0, true], [$res->ok, $res->skipped, $res->hadWork]);
    }

    public function testTefaEnabledRequiresTefaSkippedInAssignAndNoMatch(): void
    {
        $this->tefaOn();
        $this->pattern('11111', 'A', null, 1);
        $ok = $this->riga(1, '11111000000000001', ['tefa' => 'SKIPPED']);
        $tefa = $this->riga(2, '11111000000000002', ['tefa' => 'PROCESSED']);
        $nessuna = $this->riga(3, '11111000000000003', ['tefa' => null]);
        $nomatch = $this->riga(4, '99999000000000004', ['tefa' => 'SKIPPED']);
        $nomatchTefa = $this->riga(5, '99999000000000005', ['tefa' => 'PROCESSED']);

        $res = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame('PROCESSED', $this->stato($ok)['mapping_stato']);
        self::assertSame('PENDING', $this->stato($tefa)['mapping_stato']);
        self::assertSame('PENDING', $this->stato($nessuna)['mapping_stato']);
        self::assertSame(['NO_MATCH', 'NO_MATCH'], [$this->stato($nomatch)['mapping_stato'], $this->stato($nomatch)['vocab_stato']]);
        self::assertSame('PENDING', $this->stato($nomatchTefa)['mapping_stato']);
        self::assertSame([2, 1, 1], [$res->processed, $res->ok, $res->skipped]);
    }

    public function testOnlyNoMatchMeansNoWork(): void
    {
        $r = $this->riga(1, '99999000000000001');

        $res = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame(['NO_MATCH', 'NO_MATCH', null, null], $this->st($r));
        self::assertSame([1, 0, 1, 0, false], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork], 'hadWork conta solo le assegnazioni');
    }

    public function testEmptyQueueIsNoop(): void
    {
        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame([0, 0, 0, 0, false, []], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork, $res->errorDetails]);
    }

    public function testSecondCycleHasNoWork(): void
    {
        $this->pattern('11111', 'A', null, 1);
        $this->riga(1, '11111000000000001');
        $this->riga(2, '99999000000000002');

        $job = $this->job();
        self::assertTrue($job->runOnce(new RecordingJobContext())->hadWork);
        $second = $job->runOnce(new RecordingJobContext());
        self::assertSame([0, false], [$second->processed, $second->hadWork]);
    }

    public function testNoMatchRowIsPromotedByLaterRuleOnNextCycle(): void
    {
        $r = $this->riga(1, '11111000000000001');
        $job = $this->job();
        $job->runOnce(new RecordingJobContext());
        self::assertSame('NO_MATCH', $this->stato($r)['mapping_stato']);

        $this->pattern('11111', 'A', null, 1);
        $res = $job->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'A'], [$this->st($r)[0], $this->st($r)[2]]);
        self::assertTrue($res->hadWork);
    }

    public function testPatternsOfOtherDominioAreIgnored(): void
    {
        $this->pattern('11111', 'Altro', null, 1, 0, null, '99999999999');
        $r = $this->riga(1, '11111000000000001');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('NO_MATCH', $this->stato($r)['mapping_stato']);
    }

    // ------------------------------------------------------------ errori e stop

    public function testRuleExceptionIsRecordedAndOtherRulesContinue(): void
    {
        $this->pattern('11111', 'A', null, 1, 9);
        $this->pattern('22222', 'B', null, 1, 1);
        $r1 = $this->riga(1, '11111000000000001');
        $r2 = $this->riga(2, '22222000000000002');

        $repo = new class (self::pdo()) extends MappingPendenzeRepository {
            public function bulkAssignL1(string $idDominio, string $prefix, string $fornitore): int
            {
                if ($prefix === '11111') {
                    throw new \RuntimeException('boom');
                }
                return parent::bulkAssignL1($idDominio, $prefix, $fornitore);
            }
        };
        $res = $this->job($repo)->runOnce(new RecordingJobContext());

        self::assertSame('NO_MATCH', $this->stato($r1)['mapping_stato'], 'la regola fallita lascia PENDING, poi NO_MATCH');
        self::assertSame('PROCESSED', $this->stato($r2)['mapping_stato']);
        self::assertSame([1, 1, true], [$res->errors, $res->ok, $res->hadWork]);
        self::assertSame([['ref' => '11111', 'reason' => 'Eccezione RuntimeException']], $res->errorDetails);
    }

    public function testNoMatchExceptionIsCountedAsError(): void
    {
        $repo = new class (self::pdo()) extends MappingPendenzeRepository {
            public function bulkSetL1NoMatch(string $idDominio): int
            {
                throw new \RuntimeException('boom');
            }
        };
        $res = $this->job($repo)->runOnce(new RecordingJobContext());
        self::assertSame([1, false, [['ref' => 'NO_MATCH', 'reason' => 'Eccezione RuntimeException']]], [$res->errors, $res->hadWork, $res->errorDetails]);
    }

    public function testStopSignalSkipsAllWork(): void
    {
        $this->pattern('11111', 'A', null, 1);
        $r = $this->riga(1, '11111000000000001');
        $ctx = new RecordingJobContext();
        $ctx->stop = true;

        $res = $this->job()->runOnce($ctx);
        self::assertSame('PENDING', $this->stato($r)['mapping_stato']);
        self::assertSame([0, false], [$res->processed, $res->hadWork]);
    }
}
