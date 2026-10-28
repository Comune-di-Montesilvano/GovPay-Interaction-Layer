<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Services\BizScannerService;
use App\Services\Jobs\BizScanJob;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\RecordingJobContext;
use Tests\Support\Synthetic;

/**
 * Caratterizzazione di una iterazione del demone Biz scanner (BizScanJob::runOnce).
 * HTTP verso Biz Events simulato con Guzzle MockHandler; DB MariaDB reale (usa-e-getta).
 */
#[Group('db')]
final class BizScanJobTest extends DbTestCase
{
    protected static array $tables = ['biz_ricevute', 'flussi_rendicontazioni', 'settings'];

    private const DOMINIO = '80000000001';

    /** @var list<int> */
    private array $sleeps = [];

    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        SettingsRepository::set('pagopa', 'biz_events_host', 'https://biz.example.test');
        SettingsRepository::set('pagopa', 'biz_events_api_key', 'test-key');
        $this->sleeps = [];
        $this->requests = [];
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<Response|\Throwable> $responses */
    private function job(array $responses, ?FlussiRendicontazioniRepository $flussi = null, ?BizScannerService $service = null): BizScanJob
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->requests));
        $http = new Client(['handler' => $stack]);

        $repo = new BizRepository(self::pdo());
        $flussi ??= new FlussiRendicontazioniRepository(self::pdo());
        $service ??= new BizScannerService($repo, $flussi, $http);

        return new BizScanJob($repo, $flussi, $service, self::DOMINIO, function (int $s): void {
            $this->sleeps[] = $s;
        });
    }

    /** @param array<string,mixed> $o */
    private function flusso(int $n, array $o = []): string
    {
        $iur = Synthetic::iur($n);
        $o += [
            'id_flusso' => '2026-03-01TEST-0001',
            'iuv' => Synthetic::iuv('02', $n),
            'data_pagamento' => '2026-03-01',
            'is_govpay' => 0,
            'vocab_stato' => 'PENDING',
            'importo' => 10.50,
        ];
        self::pdo()->prepare(
            'INSERT INTO flussi_rendicontazioni
             (id_dominio, id_flusso, anno, mese, iur, iuv, importo, data_pagamento, is_govpay, vocab_stato)
             VALUES (?, ?, YEAR(?), MONTH(?), ?, ?, ?, ?, ?, ?)'
        )->execute([
            self::DOMINIO, $o['id_flusso'], $o['data_pagamento'], $o['data_pagamento'], $iur, $o['iuv'],
            $o['importo'], $o['data_pagamento'], $o['is_govpay'], $o['vocab_stato'],
        ]);
        return $iur;
    }

    /** @param array<string,mixed> $o */
    private function bizRow(string $iur, string $stato, array $o = []): void
    {
        $o += ['id_flusso' => '2026-03-01TEST-0001', 'data_pagamento' => '2026-03-01'];
        self::pdo()->prepare(
            'INSERT INTO biz_ricevute (id_dominio, id_flusso, iur, iuv, anno, mese, data_pagamento, importo, stato)
             VALUES (?, ?, ?, ?, YEAR(?), MONTH(?), ?, 10.50, ?)'
        )->execute([self::DOMINIO, $o['id_flusso'], $iur, '02000000000000001', $o['data_pagamento'], $o['data_pagamento'], $o['data_pagamento'], $stato]);
    }

    /** @return array<string,mixed>|null */
    private function biz(string $iur): ?array
    {
        $st = self::pdo()->prepare('SELECT * FROM biz_ricevute WHERE iur = ? AND id_dominio = ?');
        $st->execute([$iur, self::DOMINIO]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function flussoMappingStato(string $iur): string
    {
        $st = self::pdo()->prepare('SELECT mapping_stato FROM flussi_rendicontazioni WHERE iur = ?');
        $st->execute([$iur]);
        return (string)$st->fetchColumn();
    }

    /** @param array<string,mixed> $over */
    private static function receipt(array $over = []): Response
    {
        $body = $over + [
            'companyName' => 'Comune Sintetico',
            'description' => 'Tributo sintetico',
            'debtor' => ['fullName' => Synthetic::nome(1), 'entityUniqueIdentifierValue' => Synthetic::cf(1)],
            'payer' => ['fullName' => Synthetic::nome(2), 'entityUniqueIdentifierValue' => Synthetic::cf(2)],
            'transferList' => [
                ['idTransfer' => 1, 'transferAmount' => 95.0, 'fiscalCodePA' => '80000000002', 'iban' => 'IT00A0000000000000000000001', 'remittanceInformation' => 'quota comune'],
                ['idTransfer' => 2, 'transferAmount' => 5.0, 'fiscalCodePA' => self::DOMINIO, 'iban' => 'IT00A0000000000000000000002', 'remittanceInformation' => 'quota provincia'],
            ],
        ];
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function http(int $code): Response
    {
        return new Response($code, ['Content-Type' => 'application/json'], '{"status":' . $code . ',"title":"x"}');
    }

    // ------------------------------------------------------------------ casi

    public function testPendingRowSuccessSavesAllReceiptFieldsAndRequestsExpectedUrl(): void
    {
        $iur = $this->flusso(1);
        $job = $this->job([self::receipt()]);

        $res = $job->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertSame('PROCESSED', $row['stato']);
        self::assertNull($row['error_msg']);
        self::assertSame('Tributo sintetico', $row['descrizione']);
        self::assertSame(Synthetic::cf(1), $row['cf_debitore']);
        self::assertSame(Synthetic::nome(1), $row['nominativo_debitore']);
        self::assertSame(Synthetic::cf(2), $row['cf_pagante']);
        self::assertSame(Synthetic::nome(2), $row['nominativo_pagante']);
        self::assertSame('Comune Sintetico', $row['company_name']);
        $tr = json_decode((string)$row['trasferimenti'], true);
        self::assertCount(2, $tr);
        self::assertSame(['id_transfer', 'fiscal_code_pa', 'transfer_amount', 'remittance_information', 'iban'], array_keys($tr[0]));
        self::assertSame(self::DOMINIO, $tr[1]['fiscal_code_pa']);
        self::assertEquals(5.0, $tr[1]["transfer_amount"]);

        self::assertSame([1, 1, 0, 0, true], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
        self::assertSame([5], $this->sleeps, 'pausa di 5s dopo ogni riga elaborata');
        self::assertCount(1, $this->requests);
        self::assertStringContainsString(self::DOMINIO, (string)$this->requests[0]["request"]->getUri());
        self::assertStringContainsString($iur, (string)$this->requests[0]["request"]->getUri());
        self::assertSame('test-key', $this->requests[0]["request"]->getHeaderLine('Ocp-Apim-Subscription-Key'));
    }

    public function testReceiptWithoutPayerDebtorAndLongFieldsAreNullOrTruncated(): void
    {
        $iur = $this->flusso(1);
        $job = $this->job([self::receipt([
            'description' => str_repeat('d', 600),
            'companyName' => str_repeat('c', 300),
            'debtor' => null,
            'payer' => null,
            'transferList' => [],
        ])]);

        $job->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertSame('PROCESSED', $row['stato']);
        self::assertNull($row['cf_debitore']);
        self::assertNull($row['nominativo_debitore']);
        self::assertNull($row['cf_pagante']);
        self::assertNull($row['nominativo_pagante']);
        self::assertSame(512, strlen((string)$row['descrizione']));
        self::assertSame(255, strlen((string)$row['company_name']));
        self::assertSame([], json_decode((string)$row['trasferimenti'], true));
    }

    public function testSecondIterationWithEmptyQueueIsIdleAndHasNoWork(): void
    {
        $this->flusso(1);
        $job = $this->job([self::receipt()]);
        $job->runOnce(new RecordingJobContext());

        $res = $job->runOnce(new RecordingJobContext());

        self::assertSame([0, 0, 0, 0, false], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
        self::assertCount(1, $this->requests, 'nessuna nuova chiamata a Biz Events');
    }

    public function testEmptyDatabaseIsIdle(): void
    {
        $res = $this->job([])->runOnce(new RecordingJobContext());

        self::assertFalse($res->hadWork);
        self::assertSame(0, $res->processed);
    }

    public function test404IsSkippedAndFlussoMappingIsNoMatch(): void
    {
        $iur = $this->flusso(1);
        $job = $this->job([self::http(404)]);

        $res = $job->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertSame('SKIPPED', $row['stato']);
        self::assertSame('Ricevuta non trovata in Biz Events (404)', $row['error_msg']);
        self::assertSame([1, 0, 1, 0, true], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
        // B1: la riga 404 non potra' mai essere mappata -> flusso NO_MATCH (non resta PENDING per sempre)
        self::assertSame('NO_MATCH', $this->flussoMappingStato($iur));
    }

    public function testServerErrorMarksErrorThenIdleIterationResetsToPendingAndRestartsImmediately(): void
    {
        $iur = $this->flusso(1);
        $job = $this->job([self::http(500), self::http(500)]);

        $res = $job->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertSame('ERROR', $row['stato']);
        self::assertStringStartsWith('Errore API Biz Events HTTP 500:', (string)$row['error_msg']);
        self::assertSame([1, 0, 0, 1, true], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
        self::assertSame([['ref' => $iur, 'reason' => mb_substr((string)$row['error_msg'], 0, 200)]], $res->errorDetails);

        // Iterazione successiva: coda vuota -> resetErrors (ERROR -> PENDING), ciclo immediato (hadWork).
        $res2 = $job->runOnce(new RecordingJobContext());
        $row = $this->biz($iur);
        self::assertSame('PENDING', $row['stato']);
        self::assertNull($row['error_msg']);
        self::assertSame([0, 0, 0, 0, true], [$res2->processed, $res2->ok, $res2->skipped, $res2->errors, $res2->hadWork]);

        // Terza iterazione: il PENDING viene riprovato (qui ancora 500) e torna ERROR.
        $res3 = $job->runOnce(new RecordingJobContext());
        self::assertSame('ERROR', $this->biz($iur)['stato']);
        self::assertSame(1, $res3->errors);
    }

    public function testNotConfiguredBizEventsMarksRowError(): void
    {
        SettingsRepository::set('pagopa', 'biz_events_host', '');
        SettingsRepository::set('pagopa', 'biz_events_api_key', '');
        $iur = $this->flusso(1);

        $res = $this->job([])->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame('Biz Events non configurato (host/api_key mancanti)', $row['error_msg']);
        self::assertSame(1, $res->errors);
        self::assertCount(0, $this->requests);
    }

    public function testNetworkExceptionMarksErrorEccezione(): void
    {
        $iur = $this->flusso(1);
        $job = $this->job([new \GuzzleHttp\Exception\ConnectException('boom', new \GuzzleHttp\Psr7\Request('GET', 'https://biz.example.test'))]);

        $job->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertSame('ERROR', $row['stato']);
        self::assertStringStartsWith('Errore API Biz Events HTTP 0:', (string)$row['error_msg']);
    }

    public function testRateLimitRetriesThenSucceeds(): void
    {
        $iur = $this->flusso(1);
        $job = $this->job([self::http(429), self::http(429), self::receipt()]);

        $res = $job->runOnce(new RecordingJobContext());

        self::assertSame('PROCESSED', $this->biz($iur)['stato']);
        self::assertSame([2, 3, 5], $this->sleeps, 'backoff 2s, 3s poi pausa standard 5s');
        self::assertSame([1, 1, 0, 0], [$res->processed, $res->ok, $res->skipped, $res->errors]);
        self::assertCount(3, $this->requests);
    }

    public function testPersistentRateLimitMarksErrorAfterSixCallsAndSleepsTen(): void
    {
        $iur = $this->flusso(1);
        $job = $this->job(array_fill(0, 6, self::http(429)));

        $res = $job->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame('Rate limit Biz Events (429) dopo 5 tentativi', $row['error_msg']);
        self::assertSame([2, 3, 4, 5, 6, 10], $this->sleeps, 'backoff attempt+1 per 5 tentativi, poi 10s (nessuna pausa da 5s: continue)');
        self::assertCount(6, $this->requests);
        self::assertSame([1, 0, 0, 1], [$res->processed, $res->ok, $res->skipped, $res->errors]);
        self::assertSame([['ref' => $iur, 'reason' => 'Rate limit Biz Events (429) dopo 5 tentativi']], $res->errorDetails);
    }

    /**
     * Servizio che risponde con la sequenza data: array = esito, Throwable = eccezione.
     * Salvagente: dalla 4a chiamata attiva lo stop (senza fix B2 il loop girerebbe all'infinito).
     *
     * @param list<array{status:string,reason:string}|\Throwable> $script
     */
    private function scriptedService(RecordingJobContext $ctx, FlussiRendicontazioniRepository $flussi, array $script): BizScannerService
    {
        return new class (new BizRepository(self::pdo()), $flussi, $ctx, $script) extends BizScannerService {
            public int $calls = 0;

            /** @param list<array{status:string,reason:string}|\Throwable> $script */
            public function __construct(BizRepository $r, FlussiRendicontazioniRepository $f, private RecordingJobContext $ctx, private array $script)
            {
                parent::__construct($r, $f);
            }

            public function enrichOne(array $row, string $idDominio): array
            {
                if (++$this->calls >= 4) {
                    $this->ctx->stop = true;
                }
                $next = $this->script[min($this->calls - 1, count($this->script) - 1)];
                if ($next instanceof \Throwable) {
                    throw $next;
                }
                return $next;
            }
        };
    }

    public function testExceptionFromServiceMarksRowErrorAndLoopMovesOn(): void
    {
        $iur = $this->flusso(1);
        $ctx = new RecordingJobContext();
        $flussi = new FlussiRendicontazioniRepository(self::pdo());
        $service = $this->scriptedService($ctx, $flussi, [new \RuntimeException('boom')]);

        $res = $this->job([], $flussi, $service)->runOnce($ctx);

        // B2: la riga in eccezione passa a ERROR, il loop interno non la riseleziona
        self::assertSame(1, $service->calls);
        self::assertSame('ERROR', $this->biz($iur)['stato']);
        self::assertSame('Eccezione RuntimeException', $this->biz($iur)['error_msg']);
        self::assertSame(1, $res->errors);
        self::assertSame([['ref' => $iur, 'reason' => 'Eccezione RuntimeException']], $res->errorDetails);
    }

    public function testExceptionDuringRateLimitRetryMarksRowErrorAndLoopMovesOn(): void
    {
        $iur = $this->flusso(1);
        $ctx = new RecordingJobContext();
        $flussi = new FlussiRendicontazioniRepository(self::pdo());
        $service = $this->scriptedService($ctx, $flussi, [
            ['status' => 'RATE_LIMITED', 'reason' => '429'],
            new \RuntimeException('dati personali'),
        ]);

        $res = $this->job([], $flussi, $service)->runOnce($ctx);

        self::assertSame(2, $service->calls);
        self::assertSame('ERROR', $this->biz($iur)['stato']);
        self::assertSame('Eccezione RuntimeException', $this->biz($iur)['error_msg']);
        self::assertSame([1, 0, 0, 1], [$res->processed, $res->ok, $res->skipped, $res->errors]);
        self::assertSame([['ref' => $iur, 'reason' => 'Eccezione RuntimeException']], $res->errorDetails);
    }

    public function testQueueFailureIsLoggedAndPendingRowsAreStillProcessed(): void
    {
        $iur = $this->flusso(1);
        $this->bizRow('IURPRE0000000000001', 'PENDING');
        $flussi = new class (self::pdo()) extends FlussiRendicontazioniRepository {
            public function countUnprocessedForBiz(string $idDominio, ?string $minDate = null): int
            {
                throw new \RuntimeException('db down');
            }
        };
        $ctx = new RecordingJobContext();

        $res = $this->job([self::receipt()], $flussi)->runOnce($ctx);

        // La riga già PENDING viene elaborata; la coda da flussi non è stata accodata.
        self::assertSame('PROCESSED', $this->biz('IURPRE0000000000001')['stato']);
        self::assertNull($this->biz($iur));
        self::assertSame(1, $res->ok);
        self::assertContains('ERRORE queueFromCache: db down', $ctx->messages);
    }

    public function testQueueExcludesGovpayProcessedVocabAndAlreadyKnownRows(): void
    {
        $govpay = $this->flusso(1, ['is_govpay' => 1]);
        $done = $this->flusso(2, ['vocab_stato' => 'PROCESSED']);
        $known = $this->flusso(3);
        $this->bizRow($known, 'PROCESSED');
        $noMatch = $this->flusso(4, ['vocab_stato' => 'NO_MATCH']);

        $res = $this->job([self::receipt()])->runOnce(new RecordingJobContext());

        self::assertNull($this->biz($govpay));
        self::assertNull($this->biz($done));
        self::assertSame('PROCESSED', $this->biz($known)['stato']);
        self::assertSame('PROCESSED', $this->biz($noMatch)['stato'], 'vocab NO_MATCH viene accodato');
        self::assertSame(1, $res->processed);
    }

    public function testValidScanDaFiltersOldRowsAndInvalidScanDaIsIgnored(): void
    {
        $old = $this->flusso(1, ['data_pagamento' => '2025-01-10', 'id_flusso' => '2025-01-10TEST-0001']);
        $new = $this->flusso(2, ['data_pagamento' => '2026-03-01']);
        SettingsRepository::set('backoffice', 'ragioneria_scan_da', '2026-01-01');

        $res = $this->job([self::receipt()])->runOnce(new RecordingJobContext());

        self::assertNull($this->biz($old));
        self::assertSame('PROCESSED', $this->biz($new)['stato']);
        self::assertSame(1, $res->processed);

        // Formato non valido: nessun filtro data.
        SettingsRepository::set('backoffice', 'ragioneria_scan_da', '01/01/2026');
        $res = $this->job([self::receipt()])->runOnce(new RecordingJobContext());
        self::assertSame('PROCESSED', $this->biz($old)['stato']);
        self::assertSame(1, $res->processed);
    }

    public function testPendingOutsideScanWindowIsIdleAndNotProcessed(): void
    {
        $old = $this->flusso(1, ['data_pagamento' => '2025-01-10', 'id_flusso' => '2025-01-10TEST-0001']);
        $this->bizRow($old, 'PENDING', ['data_pagamento' => '2025-01-10', 'id_flusso' => '2025-01-10TEST-0001']);
        SettingsRepository::set('backoffice', 'ragioneria_scan_da', '2026-01-01');
        $ctx = new RecordingJobContext();

        $res = $this->job([])->runOnce($ctx);

        self::assertSame('PENDING', $this->biz($old)['stato']);
        self::assertFalse($res->hadWork);
        self::assertCount(0, $this->requests);
    }

    public function testFlussiAreProcessedMostRecentFirstAndRowsInIdOrder(): void
    {
        $a = $this->flusso(1, ['data_pagamento' => '2026-02-01', 'id_flusso' => 'FLUSSO-A']);
        $b = $this->flusso(2, ['data_pagamento' => '2026-03-05', 'id_flusso' => 'FLUSSO-B']);
        $c = $this->flusso(3, ['data_pagamento' => '2026-03-05', 'id_flusso' => 'FLUSSO-B']);

        $this->job([self::receipt(), self::receipt(), self::receipt()])->runOnce(new RecordingJobContext());

        $order = array_map(static fn($r) => basename($r["request"]->getUri()->getPath()), $this->requests);
        // Flusso B (data piu' recente) per primo, poi A; dentro B l'ordine e' quello di id (coda: data DESC, iur DESC).
        self::assertSame([$c, $b, $a], $order);
    }

    public function testStopSignalAfterQueueingLeavesRowsPending(): void
    {
        $iur = $this->flusso(1);
        $ctx = new RecordingJobContext();
        $ctx->stop = true;

        $res = $this->job([])->runOnce($ctx);

        self::assertSame('PENDING', $this->biz($iur)['stato'], 'accodato ma non elaborato');
        self::assertSame([0, 0, 0, 0, false], [$res->processed, $res->ok, $res->skipped, $res->errors, $res->hadWork]);
        self::assertCount(0, $this->requests);
    }
}
