<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\MappingPendenzeRepository;
use App\Services\Jobs\MappingL2Job;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\MappingFixtures;
use Tests\Support\RecordingJobContext;

/**
 * Caratterizzazione di mapping L2 (cron_vocab_mapping.php): MappingL2Job::runOnce().
 * Solo DB, nessuna chiamata HTTP.
 */
#[Group('db')]
final class MappingL2JobTest extends DbTestCase
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

    private function job(?MappingPendenzeRepository $repo = null): MappingL2Job
    {
        return new MappingL2Job($repo ?? new MappingPendenzeRepository(self::pdo()), self::DOM);
    }

    /**
     * Riga gia' passata da L1 (mapping PROCESSED, vocab PENDING).
     *
     * @param array<string,mixed> $o
     */
    private function l1(int $n, string $iuv, string $descr, array $o = []): string
    {
        return $this->riga($n, $iuv, $o + ['mapping' => 'PROCESSED', 'fornitore' => 'F', 'descr' => $descr]);
    }

    /** @return list<mixed> */
    private function vc(string $iur): array
    {
        $s = $this->stato($iur);
        return [$s['vocab_stato'], $s['cod_entrata']];
    }

    public function testKeywordMatchSetsCodEntrataCaseInsensitive(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $this->vocab('11111', 'Tari', 'COD_TARI');
        $hit = $this->l1(1, '11111000000000001', 'Pagamento TARI 2026');

        $res = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame(['PROCESSED', 'COD_TARI'], $this->vc($hit));
        self::assertSame([1, 1, 0, 0, true], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
    }

    public function testKeywordPriorityDescWinsOverInsertionOrder(): void
    {
        $this->pattern('11111', 'F');
        $this->vocab('11111', 'tari', 'BASSA', 5);          // id minore, priorita minore
        $this->vocab('11111', 'tari 2026', 'ALTA', 9);
        $both = $this->l1(1, '11111000000000001', 'TARI 2026');
        $only = $this->l1(2, '11111000000000002', 'tari 2025');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('ALTA', $this->stato($both)['cod_entrata']);
        self::assertSame('BASSA', $this->stato($only)['cod_entrata']);
    }

    public function testEqualPriorityIsOrderedById(): void
    {
        $this->pattern('11111', 'F');
        $this->vocab('11111', 'imu', 'PRIMA', 10);
        $this->vocab('11111', 'imu 2026', 'SECONDA', 10);
        $r = $this->l1(1, '11111000000000001', 'imu 2026');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('PRIMA', $this->stato($r)['cod_entrata']);
    }

    public function testKeywordMatchesAreRestrictedToPatternPrefix(): void
    {
        $this->pattern('11111', 'F');
        $this->pattern('22222', 'F');
        $this->vocab('11111', 'tari', 'C1');
        $a = $this->l1(1, '11111000000000001', 'tari');
        $b = $this->l1(2, '22222000000000002', 'tari');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'C1'], $this->vc($a));
        self::assertSame(['NO_MATCH', null], $this->vc($b));
    }

    public function testDescrizioneEntrataIsFallbackOnlyWhenBizDescrizioneNull(): void
    {
        $this->pattern('11111', 'F');
        $this->vocab('11111', 'rifiuti', 'COD_R');
        $r = $this->riga(1, '11111000000000001', ['mapping' => 'PROCESSED', 'descr' => null, 'descr_entrata' => 'Tassa RIFIUTI']);
        $s = $this->riga(2, '11111000000000002', ['mapping' => 'PROCESSED', 'descr' => 'altro', 'descr_entrata' => 'rifiuti']);

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'COD_R'], $this->vc($r));
        self::assertSame(['NO_MATCH', null], $this->vc($s));
    }

    public function testDefaultFallbackWithoutKeywords(): void
    {
        $this->pattern('11111', 'F', 'DEFAULT');
        $r = $this->l1(1, '11111000000000001', 'qualsiasi');

        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'DEFAULT'], $this->vc($r));
        self::assertTrue($res->hadWork);
    }

    public function testKeywordTakesPrecedenceOverDefaultAndDefaultCoversRest(): void
    {
        $this->pattern('11111', 'F', 'DEFAULT');
        $this->vocab('11111', 'tari', 'COD_TARI');
        $kw = $this->l1(1, '11111000000000001', 'tari');
        $rest = $this->l1(2, '11111000000000002', 'altro');

        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('COD_TARI', $this->stato($kw)['cod_entrata']);
        self::assertSame('DEFAULT', $this->stato($rest)['cod_entrata']);
        self::assertSame(2, $res->ok);
    }

    public function testNoKeywordNoDefaultBecomesNoMatch(): void
    {
        $this->pattern('11111', 'F');
        $this->vocab('11111', 'tari', 'C');
        $r = $this->l1(1, '11111000000000001', 'altro');

        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['NO_MATCH', null], $this->vc($r));
        self::assertSame([1, 0, 1, false], [$res->processed, $res->ok, $res->skipped, $res->hadWork], 'NO_MATCH non e hadWork');
    }

    public function testPatternWithoutFornitoreIsNotEvaluatedAndRowStaysPending(): void
    {
        // M5: un pattern senza fornitore risolto non entra nel vocab -> L2 non valuta le sue righe e non le
        // marca NO_MATCH (restano PENDING finche' il pattern non ha un fornitore).
        $this->pattern('11111', null, 'DEF');
        $this->vocab('11111', 'tari', 'C');
        $r = $this->l1(1, '11111000000000001', 'tari');

        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PENDING', null], $this->vc($r));
        self::assertSame([0, 0, 0, false], [$res->processed, $res->ok, $res->skipped, $res->hadWork]);
    }

    public function testRowWithoutAnyPatternIsNotEvaluatedAndStaysPending(): void
    {
        // M5: nessun pattern copre la riga -> L2 non l'ha valutata -> resta PENDING
        $r = $this->l1(1, '99999000000000001', 'tari');
        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('PENDING', $this->stato($r)['vocab_stato']);
        self::assertSame([0, false], [$res->skipped, $res->hadWork]);
    }

    public function testOnlyRowsOfEvaluatedPatternsBecomeNoMatch(): void
    {
        $this->pattern('11111', 'F');                 // valutato: keyword senza match -> NO_MATCH
        $this->vocab('11111', 'tari', 'C');
        $this->pattern('22222', null, 'DEF');         // senza fornitore: non valutato
        $this->pattern('33333', null, null, 0, 1, '11111');   // accorpato a un pattern con fornitore: valutato
        $a = $this->l1(1, '11111000000000001', 'altro');
        $b = $this->l1(2, '22222000000000002', 'altro');
        $c = $this->l1(3, '33333000000000003', 'altro');

        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['NO_MATCH', null], $this->vc($a));
        self::assertSame(['PENDING', null], $this->vc($b));
        self::assertSame(['NO_MATCH', null], $this->vc($c));
        self::assertSame(2, $res->skipped);
    }

    public function testRepositoryNoMatchWithEmptyPrefixListTouchesNothing(): void
    {
        $r = $this->l1(1, '11111000000000001', 'x');
        self::assertSame(0, (new MappingPendenzeRepository(self::pdo()))->bulkSetVocabNoMatch(self::DOM, []));
        self::assertSame('PENDING', $this->stato($r)['vocab_stato']);
    }

    public function testEmptyKeywordOrCodEntrataAreSkipped(): void
    {
        $this->pattern('11111', 'F');
        $this->vocab('11111', '   ', 'C1');   // keyword solo spazi -> trim vuoto
        $this->vocab('11111', 'tari', '  ');  // cod solo spazi -> trim vuoto
        $r = $this->l1(1, '11111000000000001', 'tari   ');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['NO_MATCH', null], $this->vc($r));
    }

    public function testKeywordAndCodAreTrimmedBeforeMatching(): void
    {
        $this->pattern('11111', 'F');
        $this->vocab('11111', '  tari  ', '  COD  ');
        $r = $this->l1(1, '11111000000000001', 'la tari');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'COD'], $this->vc($r));
    }

    public function testAccorpatoInheritsKeywordsAndDefaultFromTarget(): void
    {
        $this->pattern('11111', 'F', 'TGT_DEF');
        $this->vocab('11111', 'tari', 'TGT_KW');
        $this->pattern('22222', null, 'OWN_DEF', 0, 0, '11111');   // fornitore risolto dal target
        $kw = $this->l1(1, '22222000000000001', 'tari');
        $rest = $this->l1(2, '22222000000000002', 'altro');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('TGT_KW', $this->stato($kw)['cod_entrata']);
        self::assertSame('TGT_DEF', $this->stato($rest)['cod_entrata']);
    }

    public function testAccorpatoFallsBackToOwnDefaultWhenTargetHasNone(): void
    {
        $this->pattern('11111', 'F', null);
        $this->pattern('22222', null, 'OWN_DEF', 0, 0, '11111');
        $r = $this->l1(1, '22222000000000001', 'altro');

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('OWN_DEF', $this->stato($r)['cod_entrata']);
    }

    public function testNoMatchRowsAreReevaluatedByKeywordAndDefault(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $this->vocab('11111', 'tari', 'KW');
        $a = $this->l1(1, '11111000000000001', 'tari', ['vocab' => 'NO_MATCH']);
        $b = $this->l1(2, '11111000000000002', 'altro', ['vocab' => 'NO_MATCH']);

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'KW'], $this->vc($a));
        self::assertSame(['PROCESSED', 'DEF'], $this->vc($b));
    }

    public function testProcessedAndIneligibleRowsUntouched(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $done = $this->l1(1, '11111000000000001', 'x', ['vocab' => 'PROCESSED', 'cod' => 'GIA']);
        $l1pending = $this->riga(2, '11111000000000002', ['mapping' => 'PENDING', 'descr' => 'x']);
        $gov = $this->l1(3, '11111000000000003', 'x', ['govpay' => 1]);
        $bizPend = $this->l1(4, '11111000000000004', 'x', ['biz' => 'PENDING']);
        $bizNone = $this->l1(5, '11111000000000005', 'x', ['biz' => null]);
        $other = $this->l1(6, '11111000000000006', 'x', ['dom' => '99999999999']);

        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'GIA'], $this->vc($done));
        foreach ([$l1pending, $gov, $bizPend, $bizNone, $other] as $iur) {
            self::assertSame(['PENDING', null], $this->vc($iur));
        }
        self::assertFalse($res->hadWork);
    }

    public function testTefaEnabledRequiresTefaSkipped(): void
    {
        SettingsRepository::set('backoffice', 'tefa_enabled', 'true');
        $this->pattern('11111', 'F', 'DEF');
        $ok = $this->l1(1, '11111000000000001', 'x', ['tefa' => 'SKIPPED']);
        $tefa = $this->l1(2, '11111000000000002', 'x', ['tefa' => 'PROCESSED']);
        $none = $this->l1(3, '11111000000000003', 'x', ['tefa' => null]);

        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'DEF'], $this->vc($ok));
        self::assertSame(['PENDING', null], $this->vc($tefa));
        self::assertSame(['PENDING', null], $this->vc($none));
    }

    public function testTefaDisabledIgnoresTefaRows(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $r = $this->l1(1, '11111000000000001', 'x', ['tefa' => 'PROCESSED']);
        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(['PROCESSED', 'DEF'], $this->vc($r));
    }

    public function testRefreshesPatternDiagnosticsEachCycle(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $this->l1(1, '11111000000000001', 'x');

        $this->job()->runOnce(new RecordingJobContext());
        $n = (int)self::pdo()->query("SELECT COUNT(*) FROM mapping_pendenze_pattern_diagnostics WHERE pattern_iuv='11111'")->fetchColumn();
        self::assertSame(1, $n);
    }

    public function testSecondCycleHasNoWork(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $this->l1(1, '11111000000000001', 'x');
        $job = $this->job();
        self::assertTrue($job->runOnce(new RecordingJobContext())->hadWork);
        self::assertFalse($job->runOnce(new RecordingJobContext())->hadWork);
    }

    public function testKeywordExceptionRecordedAndRunContinues(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $this->vocab('11111', 'boom', 'C1');
        $r = $this->l1(1, '11111000000000001', 'x');

        $repo = new class (self::pdo()) extends MappingPendenzeRepository {
            public function bulkAssignVocabKeyword(string $idDominio, string $prefix, string $keyword, string $codEntrata): int
            {
                throw new \RuntimeException('boom');
            }
        };
        $res = $this->job($repo)->runOnce(new RecordingJobContext());

        self::assertSame('DEF', $this->stato($r)['cod_entrata'], 'il fallback default parte comunque');
        self::assertSame([['ref' => '11111', 'reason' => 'Eccezione RuntimeException']], $res->errorDetails);
        self::assertSame([1, 1], [$res->errors, $res->ok]);
    }

    public function testDefaultAndNoMatchExceptionsAreRecorded(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $repo = new class (self::pdo()) extends MappingPendenzeRepository {
            public function bulkAssignVocabDefault(string $idDominio, string $prefix, string $codEntrata): int
            {
                throw new \RuntimeException('boom');
            }

            public function bulkSetVocabNoMatch(string $idDominio, ?array $prefixes = null): int
            {
                throw new \RuntimeException('boom');
            }
        };
        $res = $this->job($repo)->runOnce(new RecordingJobContext());
        self::assertSame(2, $res->errors);
        self::assertSame(['11111', 'NO_MATCH'], array_column($res->errorDetails, 'ref'));
    }

    public function testStopSignalSkipsAllWork(): void
    {
        $this->pattern('11111', 'F', 'DEF');
        $r = $this->l1(1, '11111000000000001', 'x');
        $ctx = new RecordingJobContext();
        $ctx->stop = true;

        $res = $this->job()->runOnce($ctx);
        self::assertSame(['PENDING', null], $this->vc($r));
        self::assertSame([0, false], [$res->processed, $res->hadWork]);
    }
}
