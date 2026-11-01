<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Services\GovPayDebitoreService;
use App\Services\Jobs\GovPayDebitoreJob;
use App\Services\Jobs\JobContext;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\FakeGovPayPendenzeController;
use Tests\Support\RecordingJobContext;
use Tests\Support\Synthetic;

/**
 * Caratterizzazione di una iterazione del demone GovPay debitore (GovPayDebitoreJob::runOnce).
 * HTTP verso GovPay Backoffice simulato con Guzzle MockHandler; DB MariaDB reale (usa-e-getta).
 */
#[Group('db')]
final class GovPayDebitoreJobTest extends DbTestCase
{
    protected static array $tables = ['biz_ricevute', 'flussi_rendicontazioni', 'settings'];

    private const DOMINIO = '80000000001';
    private const A2A = 'GIL-TEST';

    /** @var list<int> */
    private array $sleeps = [];

    /** @var list<array{request:\Psr\Http\Message\RequestInterface}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        SettingsRepository::set('govpay', 'backoffice_url', 'https://govpay.example.test/backend/');
        SettingsRepository::set('entity', 'id_a2a', self::A2A);
        $this->sleeps = [];
        $this->requests = [];
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<Response|\Throwable> $responses */
    private function job(array $responses, ?GovPayDebitoreService $service = null): GovPayDebitoreJob
    {
        // stack reale della factory (http_errors, retry, circuit breaker): solo l'handler finale e' finto
        $http = FakeGovPayPendenzeController::factoryClient(new MockHandler($responses), $this->requests);

        $service ??= new GovPayDebitoreService(
            new BizRepository(self::pdo()),
            new FlussiRendicontazioniRepository(self::pdo()),
            $http
        );

        return new GovPayDebitoreJob($service, self::DOMINIO, function (int $s): void {
            $this->sleeps[] = $s;
        });
    }

    /** @param array<string,mixed> $o */
    private function flusso(int $n, array $o = []): string
    {
        $iur = Synthetic::iur($n);
        $o += [
            'id_flusso' => '2026-03-01TEST-0001',
            'iuv' => Synthetic::iuv('GIL', $n),
            'data_pagamento' => '2026-03-01',
            'is_govpay' => 1,
            'id_pendenza' => 'PEND-' . $n,
            'importo' => 10.50,
            'id_dominio' => self::DOMINIO,
        ];
        self::pdo()->prepare(
            'INSERT INTO flussi_rendicontazioni
             (id_dominio, id_flusso, anno, mese, iur, iuv, importo, data_pagamento, is_govpay, id_pendenza)
             VALUES (?, ?, YEAR(?), MONTH(?), ?, ?, ?, ?, ?, ?)'
        )->execute([
            $o['id_dominio'], $o['id_flusso'], $o['data_pagamento'], $o['data_pagamento'], $iur, $o['iuv'],
            $o['importo'], $o['data_pagamento'], $o['is_govpay'], $o['id_pendenza'],
        ]);
        return $iur;
    }

    private function bizRow(string $iur): void
    {
        self::pdo()->prepare(
            'INSERT INTO biz_ricevute (id_dominio, id_flusso, iur, iuv, anno, mese, data_pagamento, importo, stato)
             VALUES (?, ?, ?, ?, 2026, 3, ?, 10.50, ?)'
        )->execute([self::DOMINIO, '2026-03-01TEST-0001', $iur, 'GIL00000000000001', '2026-03-01', 'PROCESSED']);
    }

    /** @return array<string,mixed>|null */
    private function biz(string $iur): ?array
    {
        $st = self::pdo()->prepare('SELECT * FROM biz_ricevute WHERE iur = ? AND id_dominio = ?');
        $st->execute([$iur, self::DOMINIO]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function bizCount(): int
    {
        return (int)self::pdo()->query('SELECT COUNT(*) FROM biz_ricevute')->fetchColumn();
    }

    /** @param array<string,mixed> $body */
    private static function pendenza(array $body = []): Response
    {
        $body += [
            'causale' => 'Causale sintetica',
            'soggettoPagatore' => ['identificativo' => Synthetic::cf(1), 'anagrafica' => Synthetic::nome(1)],
        ];
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function http(int $code): Response
    {
        return new Response($code, ['Content-Type' => 'application/json'], '{"categoria":"X","descrizione":"segreto-upstream-' . $code . '"}');
    }

    // ------------------------------------------------------------------ casi

    public function testSuccessSavesDebitoreAndRequestsExpectedUrl(): void
    {
        $iur = $this->flusso(1, ['id_pendenza' => 'PEND 1/x']);
        $ctx = new RecordingJobContext();

        $res = $this->job([self::pendenza()])->runOnce($ctx);

        self::assertSame(1, $res->processed);
        self::assertSame(1, $res->ok);
        self::assertSame(0, $res->skipped);
        self::assertSame(0, $res->errors);
        self::assertTrue($res->hadWork);
        self::assertSame([], $res->errorDetails);

        $row = $this->biz($iur);
        self::assertNotNull($row);
        self::assertSame('PROCESSED', $row['stato']);
        self::assertSame(Synthetic::cf(1), $row['cf_debitore']);
        self::assertSame(Synthetic::nome(1), $row['nominativo_debitore']);
        self::assertSame('Causale sintetica', $row['descrizione']);
        self::assertNull($row['trasferimenti']);
        // base record (D14)
        self::assertSame(self::DOMINIO, $row['id_dominio']);
        self::assertSame('2026-03-01TEST-0001', $row['id_flusso']);
        self::assertSame(Synthetic::iuv('GIL', 1), $row['iuv']);
        self::assertSame('2026-03-01', $row['data_pagamento']);
        self::assertSame(2026, (int)$row['anno']);
        self::assertSame(3, (int)$row['mese']);
        self::assertSame('10.50', $row['importo']);

        self::assertCount(1, $this->requests);
        $req = $this->requests[0]['request'];
        self::assertSame('GET', $req->getMethod());
        self::assertSame('https://govpay.example.test/backend/pendenze/GIL-TEST/PEND%201%2Fx', (string)$req->getUri());
        self::assertSame('application/json', $req->getHeaderLine('Accept'));
        self::assertSame([], $this->sleeps); // nessuna pausa dopo l'ultima riga
    }

    public function testFieldsAreTrimmedAndTruncated(): void
    {
        $iur = $this->flusso(1);
        $res = $this->job([self::pendenza([
            'causale' => '  ' . str_repeat('c', 600) . '  ',
            'soggettoPagatore' => ['identificativo' => ' ' . str_repeat('9', 50) . ' ', 'anagrafica' => str_repeat('n', 300)],
        ])])->runOnce(new RecordingJobContext());

        self::assertSame(1, $res->ok);
        $row = $this->biz($iur);
        self::assertSame(str_repeat('c', 512), $row['descrizione']);
        self::assertSame(str_repeat('9', 35), $row['cf_debitore']);
        self::assertSame(str_repeat('n', 255), $row['nominativo_debitore']);
    }

    public function testDescrizioneFallsBackToNomeWhenCausaleAbsent(): void
    {
        $iur = $this->flusso(1);
        $this->job([new Response(200, [], json_encode(['nome' => 'Nome pendenza'], JSON_THROW_ON_ERROR))])
            ->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertSame('Nome pendenza', $row['descrizione']);
        // soggettoPagatore assente: debitore NULL ma riga PROCESSED
        self::assertNull($row['cf_debitore']);
        self::assertNull($row['nominativo_debitore']);
        self::assertSame('PROCESSED', $row['stato']);
    }

    public function testBlankCausaleFallsBackToNome(): void
    {
        // D4: causale vuota/solo spazi cade su nome come se fosse assente.
        $a = $this->flusso(1, ['data_pagamento' => '2026-03-02']); // coda: data DESC -> $a per prima
        $b = $this->flusso(2);
        $this->job([
            new Response(200, [], json_encode(['causale' => '  ', 'nome' => ' Nome pendenza '], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode(['causale' => '', 'nome' => 'Altro nome'], JSON_THROW_ON_ERROR)),
        ])->runOnce(new RecordingJobContext());

        self::assertSame('Nome pendenza', $this->biz($a)['descrizione']);
        self::assertSame('Altro nome', $this->biz($b)['descrizione']);
    }

    public function testBlankSoggettoFieldsStoredAsNull(): void
    {
        $iur = $this->flusso(1);
        $this->job([self::pendenza(['soggettoPagatore' => ['identificativo' => '  ', 'anagrafica' => '']])])
            ->runOnce(new RecordingJobContext());

        $row = $this->biz($iur);
        self::assertNull($row['cf_debitore']);
        self::assertNull($row['nominativo_debitore']);
        self::assertSame('Causale sintetica', $row['descrizione']);
    }

    public function testEmptyJsonObjectIsTreatedAsError(): void
    {
        // D3: `{}` (o `[]`) non e' una pendenza -> stesso percorso degli errori (ERROR + backoff).
        $a = $this->flusso(1);
        $b = $this->flusso(2);
        $res = $this->job([new Response(200, [], '{}'), new Response(200, [], '[]')])->runOnce(new RecordingJobContext());

        self::assertSame(0, $res->ok);
        self::assertSame(2, $res->errors);
        self::assertSame('Risposta GovPay non valida', $res->errorDetails[0]['reason']);
        $this->assertErrorRow($a, 'Risposta GovPay non valida');
        $this->assertErrorRow($b, 'Risposta GovPay non valida');
        self::assertFalse($this->job([])->runOnce(new RecordingJobContext())->hadWork);
    }

    public function testNotFound404InsertsNotFoundRecordAndCountsAsSkipped(): void
    {
        // D2: 404 -> riga NOT_FOUND (non PROCESSED senza dati), mai ritentata.
        $iur = $this->flusso(1);
        $ctx = new RecordingJobContext();
        $res = $this->job([self::http(404)])->runOnce($ctx);

        self::assertSame(1, $res->processed);
        self::assertSame(0, $res->ok);
        self::assertSame(1, $res->skipped);
        self::assertSame(0, $res->errors);
        self::assertTrue($res->hadWork);

        $row = $this->biz($iur);
        self::assertNotNull($row);
        self::assertSame('NOT_FOUND', $row['stato']);
        self::assertNull($row['error_msg']);
        self::assertNull($row['descrizione']);
        self::assertNull($row['cf_debitore']);
        self::assertNull($row['nominativo_debitore']);
        self::assertNull($row['trasferimenti']);
        self::assertSame(Synthetic::iuv('GIL', 1), $row['iuv']);
        self::assertContains("  IUR={$iur}: skip - Pendenza non trovata in GovPay (404)", $ctx->messages);

        // Secondo giro: coda vuota, anche molto dopo (NOT_FOUND non e' mai ritentato).
        $res2 = $this->job([])->runOnce(new RecordingJobContext());
        self::assertFalse($res2->hadWork);
        $this->backdateBiz($iur, 600);
        self::assertFalse($this->job([])->runOnce(new RecordingJobContext())->hadWork);
        self::assertCount(1, $this->requests);
        self::assertSame(0, (new FlussiRendicontazioniRepository(self::pdo()))->countUnprocessedGovPayForDebitore(self::DOMINIO));
    }

    public function testNotFoundRowStillAppearsInRagioneriaCsvWithoutDebitore(): void
    {
        // Lettore a valle (CSV ragioneria, LEFT JOIN biz stato='PROCESSED'): la riga c'e' ancora,
        // con debitore NULL come prima del fix (quando era PROCESSED senza dati).
        $iur = $this->flusso(1);
        $this->job([self::http(404)])->runOnce(new RecordingJobContext());

        $rows = iterator_to_array((new FlussiRendicontazioniRepository(self::pdo()))
            ->getForCsvWithBiz(self::DOMINIO, '2026-03-01', '2026-03-31', []), false);

        self::assertCount(1, $rows);
        self::assertSame($iur, $rows[0]['iur']);
        self::assertNull($rows[0]['cf_debitore']);
        self::assertNull($rows[0]['nominativo_debitore']);
        self::assertNull($rows[0]['biz_descrizione']);
    }

    public function testNotFoundAfterEarlierErrorReplacesErrorRow(): void
    {
        $iur = $this->flusso(1);
        $this->job([self::http(503)])->runOnce(new RecordingJobContext());
        $this->backdateBiz($iur, 61);

        $res = $this->job([self::http(404)])->runOnce(new RecordingJobContext());

        self::assertSame(1, $res->skipped);
        $row = $this->biz($iur);
        self::assertSame('NOT_FOUND', $row['stato']);
        self::assertNull($row['error_msg']);
    }

    private function backdateBiz(string $iur, int $minutes): void
    {
        self::pdo()->prepare('UPDATE biz_ricevute SET updated_at = NOW() - INTERVAL ? MINUTE WHERE iur = ?')
            ->execute([$minutes, $iur]);
    }

    /** Riga ERROR registrata per il tentativo fallito: solo motivo sicuro, nessun dato debitore. */
    private function assertErrorRow(string $iur, string $msg): void
    {
        $row = $this->biz($iur);
        self::assertNotNull($row);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame($msg, $row['error_msg']);
        self::assertNull($row['descrizione']);
        self::assertNull($row['cf_debitore']);
        self::assertNull($row['nominativo_debitore']);
        self::assertStringNotContainsString('segreto-upstream', (string)$row['error_msg']);
    }

    public function testOtherClientErrorIsRecordedAndRetriedOnlyAfterSixtyMinutes(): void
    {
        $iur = $this->flusso(1);
        $res = $this->job([self::http(403)])->runOnce(new RecordingJobContext());

        self::assertSame(1, $res->processed);
        self::assertSame(1, $res->errors);
        self::assertSame(0, $res->ok);
        self::assertTrue($res->hadWork);
        self::assertSame([['ref' => $iur, 'reason' => 'HTTP 403']], $res->errorDetails);
        self::assertStringNotContainsString('segreto-upstream', $res->errorDetails[0]['reason']);
        // D1: tentativo fallito registrato (ERROR + timestamp)
        $this->assertErrorRow($iur, 'HTTP 403');

        // Errore recente (< 60 min): fuori dal batch, nessuna chiamata, nessun busy-loop.
        $res2 = $this->job([])->runOnce(new RecordingJobContext());
        self::assertSame(0, $res2->processed);
        self::assertFalse($res2->hadWork);
        $this->backdateBiz($iur, 59);
        self::assertFalse($this->job([])->runOnce(new RecordingJobContext())->hadWork);
        self::assertCount(1, $this->requests);

        // Dopo 60 minuti la riga torna nel batch; successo -> PROCESSED, error_msg azzerato.
        $this->backdateBiz($iur, 61);
        $res3 = $this->job([self::pendenza()])->runOnce(new RecordingJobContext());
        self::assertSame(1, $res3->ok);
        self::assertCount(2, $this->requests);
        $row = $this->biz($iur);
        self::assertSame('PROCESSED', $row['stato']);
        self::assertNull($row['error_msg']);
        self::assertSame(Synthetic::cf(1), $row['cf_debitore']);
    }

    public function testRecentlyErroredRowsDoNotStarveOthers(): void
    {
        for ($n = 1; $n <= 101; $n++) {
            // la 101 (piu' vecchia) resta fuori dal primo batch
            $this->flusso($n, ['data_pagamento' => $n === 101 ? '2026-01-01' : '2026-03-01']);
        }
        $this->job(array_fill(0, 100, self::http(500)))->runOnce(new RecordingJobContext());

        $res = $this->job([self::pendenza()])->runOnce(new RecordingJobContext());

        self::assertSame(1, $res->processed);
        self::assertSame(1, $res->ok);
        self::assertSame('PROCESSED', $this->biz(Synthetic::iur(101))['stato']);
        self::assertCount(101, $this->requests);
    }

    public function testErroredGovPayRowsAreNotResetByBizRetry(): void
    {
        // Le righe ERROR del debitore (IUR is_govpay=1) non devono tornare PENDING per il demone Biz.
        $iur = $this->flusso(1);
        $this->job([self::http(403)])->runOnce(new RecordingJobContext());
        $this->backdateBiz($iur, 120);

        $bizRepo = new BizRepository(self::pdo());
        // Etichetta "Reset errori Biz (N)" (report ragioneria): N = righe che il reset tocca.
        self::assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM biz_ricevute WHERE stato = 'ERROR'")->fetchColumn());
        self::assertSame(0, $bizRepo->countResettableErrors(self::DOMINIO));
        self::assertSame(0, $bizRepo->resetErrors(self::DOMINIO, 60));
        self::assertSame(0, $bizRepo->resetErrors(self::DOMINIO));
        self::assertSame('ERROR', $this->biz($iur)['stato']);
    }

    public function testQueueCountIncludesErroredRows(): void
    {
        $this->flusso(1);
        $this->flusso(2);
        $this->job([self::http(403), self::pendenza()])->runOnce(new RecordingJobContext());

        $flussi = new FlussiRendicontazioniRepository(self::pdo());
        // la riga in errore resta "da scansionare" (anche se in backoff), quella riuscita no
        self::assertSame(1, $flussi->countUnprocessedGovPayForDebitore(self::DOMINIO));
        self::assertSame([], $flussi->getUnprocessedGovPayForDebitore(self::DOMINIO, 100));
    }

    public function testServerErrorKeepsStatusCodeAndIsRecorded(): void
    {
        $iur = $this->flusso(1);
        $ctx = new RecordingJobContext();
        $res = $this->job([self::http(500)])->runOnce($ctx);

        self::assertSame(1, $res->errors);
        // D5: il motivo riporta lo status code (mai il corpo)
        $this->assertErrorRow($iur, 'HTTP 500');
        self::assertSame([['ref' => $iur, 'reason' => 'HTTP 500']], $res->errorDetails);
        // il log (non l'errorDetails) riporta ancora il messaggio Guzzle
        self::assertNotEmpty(array_filter($ctx->messages, fn (string $m) => str_contains($m, "IUR={$iur}: errore - ")));
    }

    public function testConnectionExceptionIsErrorAndRecorded(): void
    {
        $iur = $this->flusso(1);
        $res = $this->job([new ConnectException('boom', new Request('GET', 'https://govpay.example.test'))])
            ->runOnce(new RecordingJobContext());

        self::assertSame(1, $res->errors);
        $this->assertErrorRow($iur, 'Errore chiamata GovPay');
        self::assertSame([['ref' => $iur, 'reason' => 'Errore chiamata GovPay']], $res->errorDetails);
    }

    public function testNonArrayJsonResponseIsError(): void
    {
        $a = $this->flusso(1);
        $b = $this->flusso(2);
        $res = $this->job([
            new Response(200, [], '<html>gateway</html>'),
            new Response(200, [], '123'),
        ])->runOnce(new RecordingJobContext());

        self::assertSame(2, $res->errors);
        $this->assertErrorRow($a, 'Risposta GovPay non valida');
        $this->assertErrorRow($b, 'Risposta GovPay non valida');
        self::assertSame('Risposta GovPay non valida', $res->errorDetails[0]['reason']);
        self::assertSame('Risposta GovPay non valida', $res->errorDetails[1]['reason']);
    }

    public function testMissingConfigYieldsSkipWithoutHttpCallOrInsert(): void
    {
        $iur = $this->flusso(1);
        SettingsRepository::set('entity', 'id_a2a', '');
        SettingsRepository::flush();

        $ctx = new RecordingJobContext();
        $res = $this->job([])->runOnce($ctx);

        self::assertSame(1, $res->skipped);
        self::assertSame(0, $res->errors);
        self::assertCount(0, $this->requests);
        self::assertNull($this->biz($iur));
        self::assertContains(
            "  IUR={$iur}: configurazione mancante - Config mancante (backoffice_url, id_a2a o id_pendenza vuoti)",
            $ctx->messages
        );
    }

    public function testWhitespaceOnlyIdPendenzaIsExcludedByQueue(): void
    {
        // Con collation PAD SPACE di MariaDB `'   ' != ''` e' falso: la riga non entra mai in coda,
        // quindi il ramo SKIP di enrichOne (trim -> vuoto) non e' raggiungibile da qui.
        $iur = $this->flusso(1, ['id_pendenza' => '   ']);
        $res = $this->job([])->runOnce(new RecordingJobContext());

        self::assertSame(0, $res->processed);
        self::assertFalse($res->hadWork);
        self::assertNull($this->biz($iur));
    }

    public function testEmptyQueueReturnsNoWork(): void
    {
        $ctx = new RecordingJobContext();
        $res = $this->job([])->runOnce($ctx);

        self::assertSame(0, $res->processed);
        self::assertFalse($res->hadWork);
        self::assertSame(['Coda vuota. Prossimo controllo tra 15 minuti...'], $ctx->messages);
        self::assertSame([], $this->sleeps);
    }

    public function testQueueSelectionExcludesNonQualifyingRows(): void
    {
        $this->flusso(1, ['is_govpay' => 0]);                // non GovPay
        $this->flusso(2, ['id_pendenza' => '']);             // id_pendenza vuoto
        $this->flusso(3, ['id_pendenza' => null]);           // id_pendenza NULL
        $this->flusso(4, ['id_dominio' => '80000000999']);   // altro dominio
        $withBiz = $this->flusso(5);                         // gia' con biz_ricevute
        $this->bizRow($withBiz);
        $ok = $this->flusso(6);

        $res = $this->job([self::pendenza()])->runOnce(new RecordingJobContext());

        self::assertSame(1, $res->processed);
        self::assertCount(1, $this->requests);
        self::assertSame('PROCESSED', $this->biz($ok)['stato']);
        self::assertSame(2, $this->bizCount()); // quella preesistente + $ok
    }

    public function testBatchIsCappedAt100AndOrderedByDataPagamentoDesc(): void
    {
        self::assertSame(100, GovPayDebitoreJob::BATCH_SIZE);
        for ($n = 1; $n <= 105; $n++) {
            // le 5 piu' vecchie (n 1..5) restano fuori dal batch
            $this->flusso($n, ['data_pagamento' => $n <= 5 ? '2026-01-01' : '2026-03-01']);
        }

        $res = $this->job(array_fill(0, 100, self::http(404)))->runOnce(new RecordingJobContext());

        self::assertSame(100, $res->processed);
        self::assertSame(100, $res->skipped);
        self::assertCount(100, $this->requests);
        self::assertSame(100, $this->bizCount());
        for ($n = 1; $n <= 5; $n++) {
            self::assertNull($this->biz(Synthetic::iur($n)));
        }
        // 1s tra una riga e l'altra, non dopo l'ultima
        self::assertCount(99, $this->sleeps);
        self::assertSame([1], array_values(array_unique($this->sleeps)));
    }

    public function testSecondRunProcessesRemainderAfterBatchCap(): void
    {
        for ($n = 1; $n <= 101; $n++) {
            $this->flusso($n);
        }
        $this->job(array_fill(0, 100, self::http(404)))->runOnce(new RecordingJobContext());
        $res = $this->job([self::http(404)])->runOnce(new RecordingJobContext());

        self::assertSame(1, $res->processed);
        self::assertSame(101, $this->bizCount());
    }

    public function testMinDateWindowFromSettingIsRespected(): void
    {
        $old = $this->flusso(1, ['data_pagamento' => '2026-02-28']);
        $edge = $this->flusso(2, ['data_pagamento' => '2026-03-01']);
        $new = $this->flusso(3, ['data_pagamento' => '2026-03-15']);
        SettingsRepository::set('backoffice', 'ragioneria_scan_da', '2026-03-01');
        SettingsRepository::flush();

        $res = $this->job([self::pendenza(), self::pendenza()])->runOnce(new RecordingJobContext());

        self::assertSame(2, $res->processed);
        self::assertNull($this->biz($old));
        self::assertNotNull($this->biz($edge));  // inclusivo (>=)
        self::assertNotNull($this->biz($new));
        // ordine data_pagamento DESC: prima il 15 marzo
        self::assertStringEndsWith('/PEND-3', (string)$this->requests[0]['request']->getUri());
        self::assertStringEndsWith('/PEND-2', (string)$this->requests[1]['request']->getUri());
    }

    public function testInvalidMinDateSettingMeansNoWindow(): void
    {
        $old = $this->flusso(1, ['data_pagamento' => '2020-01-01']);
        SettingsRepository::set('backoffice', 'ragioneria_scan_da', '01/03/2026');
        SettingsRepository::flush();

        $res = $this->job([self::pendenza()])->runOnce(new RecordingJobContext());
        self::assertSame(1, $res->ok);
        self::assertNotNull($this->biz($old));
    }

    public function testRowExceptionIsRecordedSleepsTwoSecondsAndContinues(): void
    {
        $this->flusso(1);
        $this->flusso(2);
        $rows = (new FlussiRendicontazioniRepository(self::pdo()))->getUnprocessedGovPayForDebitore(self::DOMINIO, 100);

        $service = $this->createMock(GovPayDebitoreService::class);
        $service->method('getBatch')->willReturn($rows);
        $service->expects(self::exactly(2))->method('enrichOne')->willReturnCallback(
            static function (array $row): array {
                if ($row['iur'] === Synthetic::iur(1)) {
                    throw new \RuntimeException('dati personali');
                }
                return ['status' => 'PROCESSED', 'reason' => ''];
            }
        );
        // D1: anche l'eccezione registra il tentativo fallito (solo classe, mai il messaggio)
        $service->expects(self::once())->method('recordError')->with(
            self::callback(static fn (array $row): bool => $row['iur'] === Synthetic::iur(1)),
            'Eccezione RuntimeException'
        );

        $res = $this->job([], $service)->runOnce(new RecordingJobContext());

        self::assertSame(2, $res->processed);
        self::assertSame(1, $res->ok);
        self::assertSame(1, $res->errors);
        self::assertSame([['ref' => Synthetic::iur(1), 'reason' => 'Eccezione RuntimeException']], $res->errorDetails);
        // la riga in eccezione dorme 2s (non 1s); l'altra e' l'ultima o precede: al piu' un altro sleep(1)
        self::assertContains(2, $this->sleeps);
        self::assertLessThanOrEqual(1, count(array_filter($this->sleeps, fn (int $s) => $s === 1)));
    }

    public function testGetBatchFailurePropagatesToCaller(): void
    {
        $service = $this->createStub(GovPayDebitoreService::class);
        $service->method('getBatch')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db down');
        $this->job([], $service)->runOnce(new RecordingJobContext());
    }

    public function testStopSignalBeforeFirstRowReturnsWithoutWork(): void
    {
        $iur = $this->flusso(1);
        $ctx = new RecordingJobContext();
        $ctx->stop = true;

        $res = $this->job([])->runOnce($ctx);

        self::assertSame(0, $res->processed);
        self::assertFalse($res->hadWork);
        self::assertCount(0, $this->requests);
        self::assertNull($this->biz($iur));
    }

    public function testStopMidBatchKeepsAlreadyProcessedWork(): void
    {
        $this->flusso(1, ['data_pagamento' => '2026-03-02']);
        $second = $this->flusso(2, ['data_pagamento' => '2026-03-01']);
        $ctx = new class () implements JobContext {
            private int $calls = 0;

            public function shouldStop(): bool
            {
                return ++$this->calls > 1; // stop dalla seconda riga
            }

            public function debug(string $msg): void
            {
            }
        };

        $res = $this->job([self::pendenza()])->runOnce($ctx);

        self::assertSame(1, $res->processed);
        self::assertSame(1, $res->ok);
        self::assertTrue($res->hadWork);
        self::assertNull($this->biz($second));
    }
}
