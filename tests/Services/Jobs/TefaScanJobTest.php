<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\TefaRepository;
use App\Services\Jobs\TefaScanJob;
use App\Services\TefaScannerService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\RecordingJobContext;
use Tests\Support\Synthetic;

/**
 * Caratterizzazione di una iterazione del demone TEFA scanner (TefaScanJob::runOnce).
 * Nessuna chiamata HTTP: il servizio legge solo biz_ricevute gia' salvate.
 */
#[Group('db')]
final class TefaScanJobTest extends DbTestCase
{
    protected static array $tables = ['tefa_ricevute', 'biz_ricevute', 'flussi_rendicontazioni', 'settings'];

    private const PROV = '80000000001';
    private const COMUNE = '80000000002';

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
    }

    // ---------------------------------------------------------------- helpers

    private function job(?BizRepository $biz = null): TefaScanJob
    {
        $repo = new TefaRepository(self::pdo());
        $biz ??= new BizRepository(self::pdo());
        return new TefaScanJob($repo, $biz, new TefaScannerService($repo, $biz), self::PROV);
    }

    /**
     * Crea riga flussi (is_govpay=0 salvo override) + riga biz PROCESSED.
     *
     * @param array<string,mixed> $o
     * @param list<array{fiscal_code_pa:string,transfer_amount:float}>|null $transfers
     */
    private function pagamento(int $n, ?array $transfers, array $o = []): string
    {
        $iur = Synthetic::iur($n);
        $o += [
            'id_flusso' => '2026-03-01TEST-0001',
            'data_pagamento' => '2026-03-01',
            'is_govpay' => 0,
            'biz_stato' => 'PROCESSED',
            'company_name' => 'Comune Sintetico',
            'flusso' => true,
        ];
        $pdo = self::pdo();
        if ($o['flusso']) {
            $pdo->prepare(
                'INSERT INTO flussi_rendicontazioni
                 (id_dominio, id_flusso, anno, mese, iur, iuv, importo, data_pagamento, is_govpay, vocab_stato)
                 VALUES (?, ?, YEAR(?), MONTH(?), ?, ?, 100.00, ?, ?, \'PENDING\')'
            )->execute([self::PROV, $o['id_flusso'], $o['data_pagamento'], $o['data_pagamento'], $iur, Synthetic::iuv('02', $n), $o['data_pagamento'], $o['is_govpay']]);
        }
        $json = $transfers === null ? null : json_encode(array_map(static fn(array $t): array => $t + [
            'id_transfer' => 1, 'remittance_information' => 'x', 'iban' => 'IT00A0000000000000000000001',
        ], $transfers), JSON_THROW_ON_ERROR);
        $pdo->prepare(
            'INSERT INTO biz_ricevute
             (id_dominio, id_flusso, iur, iuv, anno, mese, data_pagamento, importo, company_name, trasferimenti, stato)
             VALUES (?, ?, ?, ?, YEAR(?), MONTH(?), ?, 100.00, ?, ?, ?)'
        )->execute([self::PROV, $o['id_flusso'], $iur, Synthetic::iuv('02', $n), $o['data_pagamento'], $o['data_pagamento'], $o['data_pagamento'], $o['company_name'], $json, $o['biz_stato']]);
        return $iur;
    }

    /** @return list<array{fiscal_code_pa:string,transfer_amount:float}> */
    private static function tefaPair(float $comune = 95.0, float $prov = 5.0): array
    {
        return [
            ['fiscal_code_pa' => self::COMUNE, 'transfer_amount' => $comune],
            ['fiscal_code_pa' => self::PROV, 'transfer_amount' => $prov],
        ];
    }

    /** @return array<string,mixed>|null */
    private function tefa(string $iur): ?array
    {
        $st = self::pdo()->prepare('SELECT * FROM tefa_ricevute WHERE iur = ? AND id_dominio = ?');
        $st->execute([$iur, self::PROV]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed> */
    private function flusso(string $iur): array
    {
        $st = self::pdo()->prepare('SELECT mapping_stato, vocab_stato, cod_entrata FROM flussi_rendicontazioni WHERE iur = ?');
        $st->execute([$iur]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    // ------------------------------------------------------------------ casi

    public function testTefaPatternIsProcessedAndFlussoRowIsMarkedTefa(): void
    {
        $iur = $this->pagamento(1, self::tefaPair(95.0, 5.0));

        $res = $this->job()->runOnce(new RecordingJobContext());

        $t = $this->tefa($iur);
        self::assertSame('PROCESSED', $t['stato']);
        self::assertSame(self::COMUNE, $t['cf_comune']);
        self::assertSame('Comune Sintetico', $t['denominazione_comune']);
        self::assertEquals(5.0, $t['importo_tefa']);
        self::assertEquals(95.0, $t['importo_comune']);
        self::assertSame('biz_events', $t['sorgente']);
        self::assertNull($t['error_msg']);
        self::assertSame(0, (int)$t['is_govpay']);
        self::assertNull($t['is_multibeneficiario']);
        self::assertSame(['mapping_stato' => 'PROCESSED', 'vocab_stato' => 'PROCESSED', 'cod_entrata' => 'TEFA'], $this->flusso($iur));
        self::assertSame([1, 1, 0, 0, true], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
    }

    public function testNonTefaFewerThanTwoTransfersIsSkippedAndFlussoUntouched(): void
    {
        $one = $this->pagamento(1, [['fiscal_code_pa' => self::COMUNE, 'transfer_amount' => 100.0]]);
        $none = $this->pagamento(2, []);
        $null = $this->pagamento(3, null);

        $res = $this->job()->runOnce(new RecordingJobContext());

        foreach ([$one, $none, $null] as $iur) {
            $t = $this->tefa($iur);
            self::assertSame('SKIPPED', $t['stato']);
            self::assertSame('Transfer list con meno di 2 beneficiari — non è pagamento multi-transfer', $t['error_msg']);
            self::assertSame(['mapping_stato' => 'PENDING', 'vocab_stato' => 'PENDING', 'cod_entrata' => null], $this->flusso($iur));
        }
        self::assertSame([3, 0, 3, 0, true], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
    }

    public function testCannotSeparateProvinceAndComuneIsSkipped(): void
    {
        $bothProv = $this->pagamento(1, [
            ['fiscal_code_pa' => self::PROV, 'transfer_amount' => 5.0],
            ['fiscal_code_pa' => self::PROV, 'transfer_amount' => 95.0],
        ]);
        $noProv = $this->pagamento(2, [
            ['fiscal_code_pa' => self::COMUNE, 'transfer_amount' => 5.0],
            ['fiscal_code_pa' => '80000000003', 'transfer_amount' => 95.0],
        ]);

        $this->job()->runOnce(new RecordingJobContext());

        foreach ([$bothProv, $noProv] as $iur) {
            $t = $this->tefa($iur);
            self::assertSame('SKIPPED', $t['stato']);
            self::assertSame('Impossibile separare transfer provincia/comune dalla lista', $t['error_msg']);
            self::assertNull($t['cf_comune']);
        }
    }

    public function testThreeTransfersUseLastProvinceAndLastComune(): void
    {
        $iur = $this->pagamento(1, [
            ['fiscal_code_pa' => self::PROV, 'transfer_amount' => 1.0],
            ['fiscal_code_pa' => self::COMUNE, 'transfer_amount' => 90.0],
            ['fiscal_code_pa' => '80000000003', 'transfer_amount' => 100.0],
            ['fiscal_code_pa' => self::PROV, 'transfer_amount' => 5.0],
        ]);

        $this->job()->runOnce(new RecordingJobContext());

        $t = $this->tefa($iur);
        self::assertSame('PROCESSED', $t['stato']);
        self::assertSame('80000000003', $t['cf_comune']);
        self::assertEquals(5.0, $t['importo_tefa']);
        self::assertEquals(100.0, $t['importo_comune']);
    }

    public function testComuneDenominationFallsBackToCfWhenEmptyOrEqualToProvinceCf(): void
    {
        $empty = $this->pagamento(1, self::tefaPair(), ['company_name' => '']);
        $sameAsProv = $this->pagamento(2, self::tefaPair(), ['company_name' => self::PROV]);

        $this->job()->runOnce(new RecordingJobContext());

        self::assertSame(self::COMUNE, $this->tefa($empty)['denominazione_comune']);
        self::assertSame(self::COMUNE, $this->tefa($sameAsProv)['denominazione_comune']);
    }

    public function testRatioOutOfRangeIsSkippedWithDataAndFlussoUntouched(): void
    {
        $low = $this->pagamento(1, self::tefaPair(100.0, 0.5));
        $high = $this->pagamento(2, self::tefaPair(100.0, 11.0));

        $res = $this->job()->runOnce(new RecordingJobContext());

        foreach ([$low, $high] as $iur) {
            $t = $this->tefa($iur);
            self::assertSame('SKIPPED', $t['stato']);
            self::assertStringStartsWith('Rapporto importo TEFA/comune fuori range:', (string)$t['error_msg']);
            self::assertSame(self::COMUNE, $t['cf_comune']);
            self::assertSame(1, (int)$t['is_multibeneficiario']);
            self::assertEquals(100.0, $t['importo_comune']);
            self::assertSame('PENDING', $this->flusso($iur)['mapping_stato']);
        }
        self::assertEquals(0.5, $this->tefa($low)['importo_tefa']);
        self::assertSame([2, 0, 2, 0], [$res->processed, $res->ok, $res->skipped, $res->errors]);
    }

    public function testRatioBoundariesAndZeroComuneAmountAreProcessed(): void
    {
        $lo = $this->pagamento(1, self::tefaPair(100.0, 1.0));   // esattamente 1%
        $hi = $this->pagamento(2, self::tefaPair(100.0, 10.0));  // esattamente 10%
        $zero = $this->pagamento(3, self::tefaPair(0.0, 5.0));   // importo_comune == 0: controllo rapporto saltato

        $this->job()->runOnce(new RecordingJobContext());

        foreach ([$lo, $hi, $zero] as $iur) {
            self::assertSame('PROCESSED', $this->tefa($iur)['stato'], $iur);
            self::assertSame('TEFA', $this->flusso($iur)['cod_entrata']);
        }
    }

    public function testQueueSelectionExcludesGovpayNonProcessedBizAndAlreadyClassified(): void
    {
        $govpay = $this->pagamento(1, self::tefaPair(), ['is_govpay' => 1]);
        $bizPending = $this->pagamento(2, self::tefaPair(), ['biz_stato' => 'PENDING']);
        $bizSkipped = $this->pagamento(3, self::tefaPair(), ['biz_stato' => 'SKIPPED']);
        $already = $this->pagamento(4, self::tefaPair());
        self::pdo()->prepare(
            'INSERT INTO tefa_ricevute (id_dominio, anno, mese, id_flusso, iur, iuv, data_pagamento, stato)
             VALUES (?, 2026, 3, ?, ?, ?, ?, \'SKIPPED\')'
        )->execute([self::PROV, '2026-03-01TEST-0001', $already, Synthetic::iuv('02', 4), '2026-03-01']);
        $noFlusso = $this->pagamento(5, self::tefaPair(), ['flusso' => false]);

        $res = $this->job()->runOnce(new RecordingJobContext());

        self::assertNull($this->tefa($govpay));
        self::assertNull($this->tefa($bizPending));
        self::assertNull($this->tefa($bizSkipped));
        self::assertSame('SKIPPED', $this->tefa($already)['stato'], 'riga gia classificata non rielaborata');
        self::assertSame('PROCESSED', $this->tefa($noFlusso)['stato'], 'la coda parte da biz_ricevute, non serve riga flussi');
        self::assertSame(1, $res->processed);
    }

    public function testEmptyQueueIsIdleAndSecondIterationHasNoWork(): void
    {
        self::assertFalse($this->job()->runOnce(new RecordingJobContext())->hadWork);

        $this->pagamento(1, self::tefaPair());
        $job = $this->job();
        self::assertTrue($job->runOnce(new RecordingJobContext())->hadWork);
        $res = $job->runOnce(new RecordingJobContext());
        self::assertSame([0, 0, 0, 0, false], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
    }

    public function testScanDaFiltersOldBizRows(): void
    {
        $old = $this->pagamento(1, self::tefaPair(), ['data_pagamento' => '2025-01-10', 'id_flusso' => '2025-01-10TEST-0001']);
        $new = $this->pagamento(2, self::tefaPair());
        SettingsRepository::set('backoffice', 'ragioneria_scan_da', '2026-01-01');

        $res = $this->job()->runOnce(new RecordingJobContext());

        self::assertNull($this->tefa($old));
        self::assertSame('PROCESSED', $this->tefa($new)['stato']);
        self::assertSame(1, $res->processed);

        SettingsRepository::set('backoffice', 'ragioneria_scan_da', 'non-una-data');
        $res = $this->job()->runOnce(new RecordingJobContext());
        self::assertSame('PROCESSED', $this->tefa($old)['stato']);
        self::assertSame(1, $res->processed);
    }

    public function testOldestFlussoIsClassifiedFirst(): void
    {
        $recent = $this->pagamento(1, self::tefaPair(), ['data_pagamento' => '2026-03-05', 'id_flusso' => 'FLUSSO-R']);
        $oldest = $this->pagamento(2, self::tefaPair(), ['data_pagamento' => '2026-02-01', 'id_flusso' => 'FLUSSO-O']);
        $ctx = new RecordingJobContext();

        $this->job()->runOnce($ctx);

        $flussoLogs = array_values(array_filter($ctx->messages, static fn(string $m): bool => str_starts_with($m, 'Flusso FLUSSO-') && str_contains($m, 'pendenze')));
        self::assertSame(['Flusso FLUSSO-O: 1 pendenze', 'Flusso FLUSSO-R: 1 pendenze'], $flussoLogs, 'ordine ASC (opposto al demone Biz)');
        self::assertSame('PROCESSED', $this->tefa($recent)['stato']);
        self::assertSame('PROCESSED', $this->tefa($oldest)['stato']);
    }

    public function testQueueFailureIsLoggedAndExistingPendingRowsAreStillClassified(): void
    {
        $queued = $this->pagamento(1, self::tefaPair());
        $pendingIur = $this->pagamento(2, self::tefaPair());
        self::pdo()->prepare(
            'INSERT INTO tefa_ricevute (id_dominio, anno, mese, id_flusso, iur, iuv, data_pagamento, stato, is_govpay)
             VALUES (?, 2026, 3, ?, ?, ?, ?, \'PENDING\', 0)'
        )->execute([self::PROV, '2026-03-01TEST-0001', $pendingIur, Synthetic::iuv('02', 2), '2026-03-01']);
        $biz = new class (self::pdo()) extends BizRepository {
            public function countProcessedForTefa(string $idDominio, ?string $minDate = null): int
            {
                throw new \RuntimeException('db down');
            }
        };
        $ctx = new RecordingJobContext();

        $res = $this->job($biz)->runOnce($ctx);

        self::assertContains('ERRORE queueFromCache: db down', $ctx->messages);
        self::assertNull($this->tefa($queued));
        self::assertSame('PROCESSED', $this->tefa($pendingIur)['stato']);
        self::assertSame(1, $res->ok);
    }

    public function testEnrichOneExceptionIsCountedAndRowStaysPending(): void
    {
        $iur = $this->pagamento(1, self::tefaPair());
        $ctx = new RecordingJobContext();
        $repo = new TefaRepository(self::pdo());
        $biz = new BizRepository(self::pdo());
        $service = new class ($repo, $biz, $ctx) extends TefaScannerService {
            public function __construct(TefaRepository $r, BizRepository $b, private RecordingJobContext $ctx)
            {
                parent::__construct($r, $b);
            }

            public function enrichOne(array $row, string $idDominioProvincia): array
            {
                $this->ctx->stop = true; // senza stop il loop riproverebbe all'infinito (riga resta PENDING)
                throw new \RuntimeException('boom');
            }
        };

        $res = (new TefaScanJob($repo, $biz, $service, self::PROV))->runOnce($ctx);

        self::assertSame('PENDING', $this->tefa($iur)['stato']);
        self::assertSame([['ref' => $iur, 'reason' => 'Eccezione RuntimeException']], $res->errorDetails);
        self::assertSame(1, $res->errors);
    }

    public function testStopSignalAfterQueueingLeavesRowsPending(): void
    {
        $iur = $this->pagamento(1, self::tefaPair());
        $ctx = new RecordingJobContext();
        $ctx->stop = true;

        $res = $this->job()->runOnce($ctx);

        self::assertSame('PENDING', $this->tefa($iur)['stato']);
        self::assertSame([0, 0, 0, 0, false], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
    }

    public function testEnrichOneSkipsWhenBizRowMissingOrNotProcessed(): void
    {
        // Rami non raggiungibili dalla coda (che prende solo biz PROCESSED): chiamata diretta al servizio.
        $repo = new TefaRepository(self::pdo());
        $biz = new BizRepository(self::pdo());
        $service = new TefaScannerService($repo, $biz);
        $pdo = self::pdo();
        $ins = $pdo->prepare(
            'INSERT INTO tefa_ricevute (id_dominio, anno, mese, id_flusso, iur, iuv, data_pagamento, stato)
             VALUES (?, 2026, 3, \'F\', ?, ?, \'2026-03-01\', \'PENDING\')'
        );
        $missing = Synthetic::iur(1);
        $ins->execute([self::PROV, $missing, Synthetic::iuv('02', 1)]);
        $missingRow = ['id' => (int)$pdo->lastInsertId(), 'iur' => $missing];
        $pending = $this->pagamento(2, self::tefaPair(), ['biz_stato' => 'PENDING', 'flusso' => false]);
        $ins->execute([self::PROV, $pending, Synthetic::iuv('02', 2)]);
        $pendingRow = ['id' => (int)$pdo->lastInsertId(), 'iur' => $pending];

        $r1 = $service->enrichOne($missingRow, self::PROV);
        $r2 = $service->enrichOne($pendingRow, self::PROV);

        self::assertSame('SKIPPED', $r1['status']);
        self::assertSame('Dati Biz Events non disponibili: IUR non trovato in biz_ricevute', $this->tefa($missing)['error_msg']);
        self::assertSame('SKIPPED', $r2['status']);
        self::assertSame('Biz Events non ancora elaborato (stato: PENDING)', $this->tefa($pending)['error_msg']);
    }

    public function testStartupAlignmentFixesNullFlagsAndMirrorsProcessedTefaOnFlussi(): void
    {
        // fixProcessedTefaMapping() e' chiamato dallo script all'avvio, non da runOnce().
        $pdo = self::pdo();
        $iur = $this->pagamento(1, self::tefaPair());
        $pdo->exec("UPDATE flussi_rendicontazioni SET is_multibeneficiario = NULL, is_govpay = NULL");
        $pdo->prepare(
            'INSERT INTO tefa_ricevute (id_dominio, anno, mese, id_flusso, iur, iuv, data_pagamento, stato, is_govpay, is_multibeneficiario)
             VALUES (?, 2026, 3, \'F\', ?, ?, \'2026-03-01\', \'PROCESSED\', NULL, NULL)'
        )->execute([self::PROV, $iur, Synthetic::iuv('02', 1)]);

        $fixed = (new TefaRepository($pdo))->fixProcessedTefaMapping(self::PROV);

        self::assertSame(1, $fixed);
        self::assertSame(['mapping_stato' => 'PROCESSED', 'vocab_stato' => 'PROCESSED', 'cod_entrata' => 'TEFA'], $this->flusso($iur));
        $f = $pdo->query('SELECT is_govpay, is_multibeneficiario FROM flussi_rendicontazioni')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['is_govpay' => 0, 'is_multibeneficiario' => 0], array_map('intval', $f), 'id_pendenza assente -> is_govpay 0');
        $t = $this->tefa($iur);
        self::assertSame([0, 0], [(int)$t['is_govpay'], (int)$t['is_multibeneficiario']]);
        self::assertNotNull($t['is_govpay']);
        self::assertNotNull($t['is_multibeneficiario']);
    }

    public function testScriptExitsImmediatelyWhenTefaIsNotEnabled(): void
    {
        // Il gate `backoffice.tefa_enabled` resta nello script: lo si verifica eseguendolo come sottoprocesso
        // sul DB di test (senza gate attivo lo script entrerebbe nel loop infinito).
        $script = dirname(__DIR__, 3) . '/scripts/cron_tefa_scanner.php';
        $env = array_merge(getenv(), [
            'DB_HOST' => (string)getenv('DB_HOST'),
            'DB_PORT' => (string)getenv('DB_PORT'),
            'DB_NAME' => (string)getenv('DB_NAME'),
            'DB_USER' => (string)getenv('DB_USER'),
            'DB_PASSWORD' => (string)getenv('DB_PASSWORD'),
            // DSN fittizio non vuoto: impedisce a Dotenv (.env locale) di impostarne uno reale.
            'SENTRY_DSN' => 'http://key@127.0.0.1:9/1',
        ]);
        $args = [PHP_BINARY];
        if (extension_loaded('pdo_mysql')) {
            $args[] = '-d';
            $args[] = 'extension=pdo_mysql';
        }
        $args[] = $script;

        foreach ([null, 'false'] as $value) {
            self::pdo()->exec('TRUNCATE TABLE settings');
            SettingsRepository::flush();
            if ($value !== null) {
                SettingsRepository::set('backoffice', 'tefa_enabled', $value);
            }
            $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($script, 2), $env);
            self::assertIsResource($proc);
            $out = (string)stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            self::assertSame(0, $code, (string)$value);
            self::assertStringContainsString('TEFA non abilitato. Uscita.', $out);
            self::assertStringNotContainsString('Loop avviato', $out);
        }
    }
}
