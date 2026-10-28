<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Services\Jobs\JobContext;
use App\Services\Jobs\RagioneriaSyncJob;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\RecordingJobContext;
use Tests\Support\Synthetic;

/**
 * Caratterizzazione di una iterazione del demone ragioneria (RagioneriaSyncJob::runOnce).
 * HTTP verso GovPay (/flussiRendicontazione) simulato con Guzzle MockHandler; DB MariaDB reale (usa-e-getta).
 *
 * Le date dei flussi sono relative a oggi perche' il job usa la finestra "oggi - 15 giorni".
 */
#[Group('db')]
final class RagioneriaSyncJobTest extends DbTestCase
{
    protected static array $tables = ['flussi_rendicontazioni', 'settings'];

    private const DOMINIO = '80000000001';
    private const A2A = 'GIL-TEST';
    private const BASE = 'https://govpay.example.test/backend/api/backoffice/v1';
    private const SCAN_DA = '2026-01-01';

    private MockHandler $mock;

    /** @var list<array{request:\Psr\Http\Message\RequestInterface,response:?\Psr\Http\Message\ResponseInterface}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        SettingsRepository::set('backoffice', 'ragioneria_scan_da', self::SCAN_DA);
        $this->requests = [];
        $this->mock = new MockHandler([]);
    }

    // ---------------------------------------------------------------- helpers

    private function job(string $a2a = self::A2A): RagioneriaSyncJob
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->requests));
        return new RagioneriaSyncJob(
            self::DOMINIO,
            $a2a,
            self::BASE . '/',
            new Client(['handler' => $stack]),
            new FlussiRendicontazioniRepository(self::pdo())
        );
    }

    private static function day(int $daysAgo): string
    {
        return date('Y-m-d', strtotime("-{$daysAgo} days"));
    }

    /** @param list<array<string,mixed>> $items */
    private function queueList(array $items, ?string $next = null): void
    {
        $body = ['risultati' => $items];
        if ($next !== null) {
            $body['prossimiRisultati'] = $next;
        }
        $this->mock->append(new Response(200, [], json_encode($body)));
    }

    /** @param array<string,mixed> $detail */
    private function queueDetail(array $detail): void
    {
        $this->mock->append(new Response(200, [], json_encode($detail)));
    }

    /**
     * @param list<array<string,mixed>> $rends
     * @return array<string,mixed>
     */
    private static function detail(string $data, array $rends): array
    {
        return ['dataFlusso' => $data . 'T10:00:00Z', 'dataRegolamento' => $data, 'trn' => 'TRN-' . $data, 'rendicontazioni' => $rends];
    }

    /** @return array<string,mixed> */
    private static function rend(int $n, array $extra = []): array
    {
        return $extra + [
            'iur' => Synthetic::iur($n),
            'iuv' => Synthetic::iuv('GIL', $n),
            'importo' => 10.5,
            'esito' => 0,
            'indice' => 1,
            'riscossione' => ['vocePendenza' => [
                'codEntrata' => 'ENT', 'descrizione' => 'Desc ' . $n, 'idPendenza' => 'PEND-' . $n,
                'pendenza' => ['idA2A' => self::A2A],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private static function item(string $idFlusso, string $data): array
    {
        return ['idFlusso' => $idFlusso, 'dataFlusso' => $data . 'T10:00:00Z'];
    }

    /** Un flusso presente su GovPay: lista (1 flusso) + dettaglio (1 rendicontazione). */
    private function queueOneFlusso(string $id, string $data, int $n = 1): void
    {
        $this->queueList([self::item($id, $data)]);
        $this->queueDetail(self::detail($data, [self::rend($n)]));
    }

    /** @return array<string,string> query della richiesta $i (0-based) */
    private function query(int $i): array
    {
        parse_str($this->requests[$i]['request']->getUri()->getQuery(), $q);
        return $q;
    }

    private function path(int $i): string
    {
        return $this->requests[$i]['request']->getUri()->getPath();
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return self::pdo()->query('SELECT * FROM flussi_rendicontazioni ORDER BY iur, indice')->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function insertFlusso(string $idFlusso, string $data, int $n): void
    {
        self::pdo()->prepare(
            'INSERT INTO flussi_rendicontazioni (id_dominio, id_flusso, data_flusso, anno, mese, iur, data_pagamento, is_govpay)
             VALUES (?, ?, ?, YEAR(?), MONTH(?), ?, ?, 1)'
        )->execute([self::DOMINIO, $idFlusso, $data, $data, $data, Synthetic::iur($n), $data]);
    }

    private function setting(string $key): mixed
    {
        SettingsRepository::flush();
        return SettingsRepository::get('backoffice', $key);
    }

    // ---------------------------------------------------------------- scan completo / finestra

    public function testFirstRunDoesFullScanFromConfiguredScanDa(): void
    {
        $d = self::day(3);
        $this->queueOneFlusso('FL-A', $d);

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertCount(2, $this->requests);
        self::assertSame(self::BASE . '/flussiRendicontazione', (string)$this->requests[0]['request']->getUri()->withQuery(''));
        self::assertSame(
            ['pagina' => '1', 'risultatiPerPagina' => '100', 'metadatiPaginazione' => 'true', 'maxRisultati' => 'true',
                'idDominio' => self::DOMINIO, 'dataDa' => self::SCAN_DA],
            $this->query(0)
        );
        self::assertSame('/backend/api/backoffice/v1/flussiRendicontazione/' . self::DOMINIO . '/FL-A', $this->path(1));
        self::assertCount(1, $this->rows());
        self::assertSame([1, 1, 0, 0, true], [$r->processed, $r->ok, $r->skipped, $r->errors, $r->hadWork]);
    }

    public function testInvalidOrEmptyScanDaFallsBackToJanFirstOfLastYear(): void
    {
        foreach (['', '2026/01/01', 'ieri', '2026-1-1'] as $bad) {
            SettingsRepository::set('backoffice', 'ragioneria_scan_da', $bad);
            $this->requests = [];
            $this->queueList([]);
            $this->job()->runOnce(new RecordingJobContext());
            self::assertSame(date('Y-01-01', strtotime('-1 year')), $this->query(0)['dataDa'], "scan_da='{$bad}'");
        }
    }

    public function testEmptyListDoesNothingAndDoesNotTouchProgress(): void
    {
        $this->queueList([]);
        $ctx = new RecordingJobContext();

        $r = $this->job()->runOnce($ctx);

        self::assertCount(1, $this->requests);
        self::assertSame([0, 0, 0, 0, false], [$r->processed, $r->ok, $r->skipped, $r->errors, $r->hadWork]);
        self::assertNull($this->setting('ragioneria_progress_rolling_from'));
        self::assertNull($this->setting('ragioneria_progress_scan_da'));
    }

    public function testCycleSavesRollingWindowAsMaxDataFlussoMinus15DaysAndNextRunUsesIt(): void
    {
        $d = self::day(5);
        $job = $this->job();
        $this->queueOneFlusso('FL-A', $d);
        $job->runOnce(new RecordingJobContext());

        $expected = date('Y-m-d', strtotime($d . ' -15 days'));
        self::assertSame($expected, $this->setting('ragioneria_progress_rolling_from'));
        self::assertSame(self::SCAN_DA, $this->setting('ragioneria_progress_scan_da'));

        // difetto noto: la richiesta/commento parlano di "ultimo sync - 3 giorni", il codice usa 15 giorni
        $this->requests = [];
        $this->queueList([]);
        $job->runOnce(new RecordingJobContext());
        self::assertSame($expected, $this->query(0)['dataDa']);
    }

    public function testRollingWindowUsesMaxDataFlussoInDbForDominioNotOnlyThisCycle(): void
    {
        $this->insertFlusso('FL-OLD', self::day(2), 99);
        $this->queueOneFlusso('FL-A', self::day(10));

        $this->job()->runOnce(new RecordingJobContext());

        self::assertSame(date('Y-m-d', strtotime(self::day(2) . ' -15 days')), $this->setting('ragioneria_progress_rolling_from'));
    }

    public function testRestoresProgressWhenSavedScanDaMatchesConfig(): void
    {
        SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', self::SCAN_DA);
        SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', '2026-02-10');
        $this->queueList([]);

        $this->job()->runOnce(new RecordingJobContext());

        self::assertSame('2026-02-10', $this->query(0)['dataDa']);
    }

    public function testFullScanWhenSavedProgressBelongsToDifferentScanDaOrRollingEmpty(): void
    {
        // scan_da salvato diverso dalla configurazione: il primo ciclo riparte da scan_da config
        // (poi il ramo "data modificata in esecuzione" azzera comunque la finestra)
        SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', '2025-05-05');
        SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', '2026-02-10');
        $this->queueList([]);
        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(self::SCAN_DA, $this->query(0)['dataDa']);

        // rolling_from vuoto: scan completo
        $this->requests = [];
        SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', self::SCAN_DA);
        SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', null);
        $this->queueList([]);
        $this->job()->runOnce(new RecordingJobContext());
        self::assertSame(self::SCAN_DA, $this->query(0)['dataDa']);
    }

    public function testScanDaChangedWhileRunningResetsRollingWindow(): void
    {
        $job = $this->job();
        $this->queueOneFlusso('FL-A', self::day(5));
        $job->runOnce(new RecordingJobContext());
        self::assertNotNull($this->setting('ragioneria_progress_rolling_from'));

        SettingsRepository::set('backoffice', 'ragioneria_scan_da', '2026-02-02');
        $this->requests = [];
        $this->queueList([]);
        $job->runOnce(new RecordingJobContext());

        self::assertSame('2026-02-02', $this->query(0)['dataDa']);
        self::assertSame('2026-02-02', $this->setting('ragioneria_progress_scan_da'));
        // la finestra e' azzerata e, con lista vuota, riscritta dal MAX(data_flusso) del DB (-15 giorni)
        self::assertSame(date('Y-m-d', strtotime(self::day(5) . ' -15 days')), $this->setting('ragioneria_progress_rolling_from'));
    }

    // ---------------------------------------------------------------- rescan

    public function testRequestRescanForcesFullScanOnceAndResetsStoredWindow(): void
    {
        $job = $this->job();
        $this->queueOneFlusso('FL-A', self::day(5));
        $job->runOnce(new RecordingJobContext());
        $rolling = $this->setting('ragioneria_progress_rolling_from');
        self::assertNotNull($rolling);

        $job->requestRescan();
        $this->requests = [];
        $this->queueList([]);
        $ctx = new RecordingJobContext();
        $job->runOnce($ctx);
        self::assertSame(self::SCAN_DA, $this->query(0)['dataDa']);
        self::assertContains('Segnale rescan ricevuto: prossimo ciclo scan completo da data configurazione.', $ctx->messages);

        // il flag e' consumato: il ciclo dopo torna alla finestra (riscritta a fine ciclo dal MAX nel DB)
        $this->requests = [];
        $this->queueList([]);
        $job->runOnce(new RecordingJobContext());
        self::assertSame($rolling, $this->query(0)['dataDa']);
    }

    public function testRescanBeforeFirstRunOverridesRestoredProgress(): void
    {
        SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', self::SCAN_DA);
        SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', '2026-02-10');
        $job = $this->job();
        $job->requestRescan();
        $this->queueList([]);

        $job->runOnce(new RecordingJobContext());

        self::assertSame(self::SCAN_DA, $this->query(0)['dataDa']);
    }

    // ---------------------------------------------------------------- paginazione

    public function testFollowsPaginationUntilNoNextPage(): void
    {
        $this->queueList([self::item('FL-A', self::day(3))], '/flussiRendicontazione?pagina=2');
        $this->queueList([self::item('FL-B', self::day(2))]);
        $this->queueDetail(self::detail(self::day(3), [self::rend(1)]));
        $this->queueDetail(self::detail(self::day(2), [self::rend(2)]));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame('1', $this->query(0)['pagina']);
        self::assertSame('2', $this->query(1)['pagina']);
        self::assertCount(4, $this->requests);
        self::assertCount(2, $this->rows());
        self::assertSame(2, $r->ok);
    }

    public function testStopsWhenNextPageIsNotGreaterThanCurrent(): void
    {
        $this->queueList([self::item('FL-A', self::day(3))], '/flussiRendicontazione?pagina=1');
        $this->queueDetail(self::detail(self::day(3), [self::rend(1)]));

        $this->job()->runOnce(new RecordingJobContext());

        self::assertCount(2, $this->requests); // una sola pagina lista + dettaglio
    }

    public function testListFailureOnFirstPageIsReportedAsListError(): void
    {
        // R2: un errore sulla prima pagina non deve sembrare un ciclo "a vuoto"
        $this->mock->append(new ConnectException('boom', new Request('GET', 'x')));
        $ctx = new RecordingJobContext();

        $r = $this->job()->runOnce($ctx);

        self::assertSame([0, 0, 0, 1, false], [$r->processed, $r->ok, $r->skipped, $r->errors, $r->hadWork]);
        self::assertSame([['ref' => 'lista', 'reason' => 'Eccezione GuzzleHttp\Exception\ConnectException']], $r->errorDetails);
        self::assertSame([], $this->rows());
    }

    public function testListHttpFailureOnFirstPageReportsStatusCode(): void
    {
        $this->mock->append(new Response(502, [], 'corpo upstream riservato'));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame(1, $r->errors);
        self::assertSame([['ref' => 'lista', 'reason' => 'HTTP 502']], $r->errorDetails);
    }

    public function testNonJsonListOnFirstPageIsReportedAsListError(): void
    {
        $this->mock->append(new Response(200, [], '<html>proxy</html>'));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame([1, false], [$r->errors, $r->hadWork]);
        self::assertSame([['ref' => 'lista', 'reason' => 'Risposta GovPay non valida']], $r->errorDetails);
    }

    public function testListFailureOnLaterPageStillScansFlussiAlreadyCollected(): void
    {
        $this->queueList([self::item('FL-A', self::day(3))], '/flussiRendicontazione?pagina=2');
        $this->mock->append(new Response(500, [], 'segreto upstream'));
        $this->queueDetail(self::detail(self::day(3), [self::rend(1)]));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertCount(1, $this->rows());
        self::assertSame(1, $r->ok);
        self::assertSame(1, $r->errors);
        self::assertSame([['ref' => 'pagina 2', 'reason' => 'HTTP 500']], $r->errorDetails);
    }

    // ---------------------------------------------------------------- scansione dettagli

    public function testFlussiAreScannedOldestFirstByDataThenId(): void
    {
        $this->queueList([
            self::item('FL-C', self::day(2)),
            self::item('FL-B', self::day(4)),
            self::item('FL-A', self::day(4)),
        ]);
        for ($i = 1; $i <= 3; $i++) {
            $this->queueDetail(self::detail(self::day(3), [self::rend($i)]));
        }

        $this->job()->runOnce(new RecordingJobContext());

        self::assertSame(
            ['FL-A', 'FL-B', 'FL-C'],
            [basename($this->path(1)), basename($this->path(2)), basename($this->path(3))]
        );
    }

    public function testSkipsKnownFlussoOlderThan15DaysButRescansRecentOnes(): void
    {
        $this->insertFlusso('FL-OLD', self::day(40), 90);
        $this->insertFlusso('FL-NEW', self::day(3), 91);
        $this->queueList([self::item('FL-OLD', self::day(40)), self::item('FL-NEW', self::day(3))]);
        $this->queueDetail(self::detail(self::day(3), [self::rend(91)]));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertCount(2, $this->requests); // lista + solo FL-NEW
        self::assertSame('FL-NEW', basename($this->path(1)));
        self::assertSame([2, 1, 1, 0], [$r->processed, $r->ok, $r->skipped, $r->errors]);
    }

    public function testUnknownOldFlussoIsScanned(): void
    {
        $this->queueOneFlusso('FL-OLD', self::day(40));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertCount(1, $this->rows());
        self::assertSame(0, $r->skipped);
    }

    public function testFlussoWithoutIdOrWithEmptyDominioIsIgnored(): void
    {
        $this->queueList([
            ['dataFlusso' => self::day(3) . 'T10:00:00Z'],
            ['idFlusso' => 'FL-X', 'idDominio' => '', 'dataFlusso' => self::day(3)],
            'non-array',
        ]);

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertCount(1, $this->requests);
        self::assertSame(0, $r->processed);
    }

    public function testDetailUsesFlussoDominioWhenPresent(): void
    {
        $this->queueList([['idFlusso' => 'FL/A', 'dominio' => ['idDominio' => '99999999999'], 'dataFlusso' => self::day(3)]]);
        $this->queueDetail(self::detail(self::day(3), [self::rend(1)]));

        $this->job()->runOnce(new RecordingJobContext());

        self::assertSame('/backend/api/backoffice/v1/flussiRendicontazione/99999999999/FL%2FA', $this->requests[1]['request']->getUri()->getPath());
        self::assertSame('99999999999', $this->rows()[0]['id_dominio']);
    }

    public function testDetailFailureIsReportedAndNextFlussoContinues(): void
    {
        $this->queueList([self::item('FL-A', self::day(4)), self::item('FL-B', self::day(3))]);
        $this->mock->append(new Response(503, [], 'dettagli interni upstream'));
        $this->queueDetail(self::detail(self::day(3), [self::rend(2)]));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertCount(1, $this->rows());
        self::assertSame([2, 1, 0, 1, true], [$r->processed, $r->ok, $r->skipped, $r->errors, $r->hadWork]);
        self::assertSame([['ref' => 'FL-A', 'reason' => 'HTTP 503']], $r->errorDetails);
    }

    public function testDetailFailureIsSentToSentryLikeListFailure(): void
    {
        // R3: il fallimento del dettaglio va a Sentry come quello della lista
        $captured = [];
        \Sentry\init([
            'dsn' => 'http://public@sentry.invalid/1',
            'default_integrations' => false, // niente handler globali di errori/eccezioni
            'before_send' => static function (\Sentry\Event $event) use (&$captured): ?\Sentry\Event {
                foreach ($event->getExceptions() as $ex) {
                    $captured[] = $ex->getType();
                }
                return null; // scarta: nessun invio di rete
            },
        ]);
        try {
            $this->queueList([self::item('FL-A', self::day(4)), self::item('FL-B', self::day(3))]);
            $this->mock->append(new Response(503, [], 'dettagli interni upstream'));
            $this->mock->append(new ConnectException('cURL error 7', new Request('GET', 'x')));

            $this->job()->runOnce(new RecordingJobContext());
        } finally {
            \Sentry\SentrySdk::setCurrentHub(new \Sentry\State\Hub());
        }

        self::assertSame(
            ['GuzzleHttp\Exception\ServerException', 'GuzzleHttp\Exception\ConnectException'],
            $captured
        );
    }

    public function testDetailConnectionErrorHasGenericReason(): void
    {
        $this->queueList([self::item('FL-A', self::day(3))]);
        $this->mock->append(new ConnectException('cURL error 7: host interno', new Request('GET', 'x')));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame([['ref' => 'FL-A', 'reason' => 'Errore chiamata GovPay']], $r->errorDetails);
    }

    public function testNonArrayDetailAndFlussoWithoutRowsAreIgnored(): void
    {
        $this->queueList([self::item('FL-A', self::day(4)), self::item('FL-B', self::day(3))]);
        $this->mock->append(new Response(200, [], '"stringa"'));
        $this->queueDetail(self::detail(self::day(3), [['iur' => '   ']]));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertSame([], $this->rows());
        self::assertSame([0, 0, 0, false], [$r->ok, $r->skipped, $r->errors, $r->hadWork]);
    }

    // ---------------------------------------------------------------- upsert

    public function testFirstScanInsertsRowsWithMappedFieldsAndPendingStates(): void
    {
        $d = self::day(3);
        $this->queueList([self::item('FL-A', $d)]);
        $this->queueDetail([
            'dataFlusso' => $d . 'T10:00:00Z', 'dataRegolamento' => $d, 'trn' => 'TRN-9',
            'psp' => ['idPsp' => 'PSP1', 'ragioneSociale' => 'Banca Test'],
            'rendicontazioni' => [self::rend(1, ['indice' => 2, 'esito' => 9])],
        ]);

        $this->job()->runOnce(new RecordingJobContext());

        $row = $this->rows()[0];
        self::assertSame(self::DOMINIO, $row['id_dominio']);
        self::assertSame('FL-A', $row['id_flusso']);
        self::assertSame($d, $row['data_flusso']);
        self::assertSame($d, $row['data_regolamento']);
        self::assertSame($d, $row['data_pagamento']);
        self::assertSame('TRN-9', $row['trn']);
        self::assertSame('PSP1', $row['id_psp']);
        self::assertSame('Banca Test', $row['ragione_psp']);
        self::assertSame((int)date('Y', strtotime($d)), (int)$row['anno']);
        self::assertSame((int)date('n', strtotime($d)), (int)$row['mese']);
        self::assertSame(Synthetic::iur(1), $row['iur']);
        self::assertSame(Synthetic::iuv('GIL', 1), $row['iuv']);
        self::assertSame('10.50', $row['importo']);
        self::assertSame(9, (int)$row['esito']);
        self::assertNull($row['stato_rend']);
        self::assertSame(2, (int)$row['indice']);
        self::assertSame('ENT', $row['cod_entrata']);
        self::assertSame('PEND-1', $row['id_pendenza']);
        self::assertSame(1, (int)$row['is_govpay']);
        self::assertNull($row['is_multibeneficiario']);
        self::assertSame(['PENDING', 'PENDING', 'PENDING'], [$row['mapping_stato'], $row['vocab_stato'], $row['rendicontazione_stato']]);
    }

    public function testIsGovPayClassificationPersistedOnRows(): void
    {
        $d = self::day(3);
        $this->queueList([self::item('FL-A', $d)]);
        $esterna = ['iur' => Synthetic::iur(2), 'iuv' => 'XYZ', 'importo' => 1];
        $altraA2a = self::rend(3);
        $altraA2a['riscossione']['vocePendenza']['pendenza']['idA2A'] = 'ALTRA';
        $this->queueDetail(self::detail($d, [self::rend(1), $esterna, $altraA2a]));

        $this->job()->runOnce(new RecordingJobContext());

        $flags = array_column($this->rows(), 'is_govpay', 'iur');
        self::assertSame(
            [Synthetic::iur(1) => 1, Synthetic::iur(2) => 0, Synthetic::iur(3) => 0],
            array_map('intval', $flags)
        );
    }

    public function testRunningTwiceIsIdempotentAndSecondRunReportsNoNewRows(): void
    {
        $d = self::day(3);
        $job = $this->job();
        $this->queueOneFlusso('FL-A', $d);
        $r1 = $job->runOnce(new RecordingJobContext());
        $first = $this->rows();

        $this->queueOneFlusso('FL-A', $d);
        $r2 = $job->runOnce(new RecordingJobContext());

        $second = $this->rows();
        self::assertCount(1, $second);
        unset($first[0]['synced_at'], $second[0]['synced_at']);
        self::assertSame($first, $second);
        self::assertTrue($r1->hadWork);
        // R1: nessun campo cambiato -> nessun lavoro (altrimenti il demone rilancerebbe senza pausa)
        self::assertFalse($r2->hadWork);
        self::assertSame(1, $r2->ok);
    }

    public function testUnchangedRescanIsNoWorkEvenWhenSyncedAtIsOld(): void
    {
        // R1: synced_at non deve contare come "modifica", altrimenti ogni rescan dei flussi recenti
        // (sempre riletti, < 15 giorni) produrrebbe hadWork=true e un loop senza pausa.
        $d = self::day(3);
        $job = $this->job();
        $this->queueOneFlusso('FL-A', $d);
        $job->runOnce(new RecordingJobContext());
        self::pdo()->exec("UPDATE flussi_rendicontazioni SET synced_at = '2020-01-01 00:00:00'");

        $this->queueOneFlusso('FL-A', $d);
        $r2 = $job->runOnce(new RecordingJobContext());

        self::assertFalse($r2->hadWork);
        self::assertSame('2020-01-01 00:00:00', $this->rows()[0]['synced_at'], 'riga invariata: synced_at non toccato');
    }

    public function testRescanWithChangedFieldsIsWork(): void
    {
        // R1: hadWork = righe inserite OPPURE aggiornate con modifiche
        $d = self::day(3);
        $job = $this->job();
        $this->queueOneFlusso('FL-A', $d);
        $job->runOnce(new RecordingJobContext());
        self::pdo()->exec("UPDATE flussi_rendicontazioni SET synced_at = '2020-01-01 00:00:00'");

        $this->queueList([self::item('FL-A', $d)]);
        $changed = self::rend(1);
        $changed['importo'] = 99.9;
        $this->queueDetail(self::detail($d, [$changed]));
        $r2 = $job->runOnce(new RecordingJobContext());

        self::assertTrue($r2->hadWork);
        self::assertNotSame('2020-01-01 00:00:00', $this->rows()[0]['synced_at'], 'riga cambiata: synced_at aggiornato');
    }

    public function testRescanUpdatesChangedFieldsWithoutDuplicating(): void
    {
        $d = self::day(3);
        $this->queueOneFlusso('FL-A', $d);
        $this->job()->runOnce(new RecordingJobContext());

        $this->queueList([self::item('FL-A', $d)]);
        $changed = self::rend(1);
        $changed['importo'] = 99.9;
        $changed['riscossione']['vocePendenza']['descrizione'] = 'Nuova';
        $this->queueDetail(self::detail($d, [$changed]));
        $this->job()->runOnce(new RecordingJobContext());

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('99.90', $rows[0]['importo']);
        self::assertSame('Nuova', $rows[0]['descrizione_entrata']);
    }

    public function testUpsertKeepsExistingCodEntrataForNonGovPayRowWithEmptyNewValue(): void
    {
        $d = self::day(3);
        $esterna = static fn(string $cod): array => [
            'iur' => Synthetic::iur(5), 'iuv' => 'EXT', 'importo' => 2,
            'riscossione' => ['vocePendenza' => ['codEntrata' => $cod]],
        ];
        $this->queueList([self::item('FL-A', $d)]);
        $this->queueDetail(self::detail($d, [$esterna('ENT-OLD')]));
        $this->job()->runOnce(new RecordingJobContext());

        $this->queueList([self::item('FL-A', $d)]);
        $this->queueDetail(self::detail($d, [$esterna('')]));
        $this->job()->runOnce(new RecordingJobContext());

        self::assertSame('ENT-OLD', $this->rows()[0]['cod_entrata']);
    }

    public function testNewRowsInsertedFlagHadWorkTrueEvenIfOtherFlussoFails(): void
    {
        $this->queueList([self::item('FL-A', self::day(4)), self::item('FL-B', self::day(3))]);
        $this->queueDetail(self::detail(self::day(4), [self::rend(1)]));
        $this->mock->append(new Response(500));

        $r = $this->job()->runOnce(new RecordingJobContext());

        self::assertTrue($r->hadWork);
        self::assertSame(1, $r->errors);
    }

    // ---------------------------------------------------------------- salvataggio periodico del progresso

    /**
     * Scansiona 27 flussi (uno per giorno, il piu' vecchio $oldestAgo giorni fa) fermando il job durante
     * il 26-esimo dettaglio: il job esce prima dell'aggiornamento di fine ciclo, quindi resta visibile
     * solo il salvataggio periodico fatto al 25-esimo flusso.
     */
    private function runUntilStopAfter26thDetail(int $oldestAgo, RecordingJobContext $ctx): void
    {
        $items = [];
        for ($i = 0; $i < 27; $i++) {
            $items[] = self::item(sprintf('FL-%02d', $i), self::day($oldestAgo - $i));
        }
        $this->queueList($items);
        for ($i = 0; $i < 27; $i++) {
            $n = $i + 1;
            $body = self::detail(self::day($oldestAgo - $i), [self::rend($n)]);
            if ($i === 25) {
                $this->mock->append(function () use ($ctx, $body): Response {
                    $ctx->stop = true;
                    return new Response(200, [], json_encode($body));
                });
            } else {
                $this->queueDetail($body);
            }
        }
        $this->job()->runOnce($ctx);
    }

    public function testPeriodicProgressSavedEvery25FlussiAsFlussoDateMinus2Days(): void
    {
        $this->runUntilStopAfter26thDetail(60, new RecordingJobContext());

        // il 25-esimo flusso (indice 24) ha data day(60-24); progresso = data - 2 giorni
        self::assertSame(date('Y-m-d', strtotime(self::day(36) . ' -2 days')), $this->setting('ragioneria_progress_rolling_from'));
        self::assertSame(self::SCAN_DA, $this->setting('ragioneria_progress_scan_da'));
    }

    public function testPeriodicProgressIsClampedToConfiguredScanDa(): void
    {
        SettingsRepository::set('backoffice', 'ragioneria_scan_da', self::day(30));

        $this->runUntilStopAfter26thDetail(60, new RecordingJobContext());

        self::assertSame(self::day(30), $this->setting('ragioneria_progress_rolling_from'));
    }

    public function testPeriodicProgressNeverMovesBackwards(): void
    {
        SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', self::SCAN_DA);
        SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', self::day(1));
        $ctx = new RecordingJobContext();
        // dataDa nella richiesta sara' day(1): irrilevante per il mock

        $this->runUntilStopAfter26thDetail(60, $ctx);

        self::assertSame(self::day(1), $this->setting('ragioneria_progress_rolling_from'));
    }

    // ---------------------------------------------------------------- stop

    public function testStopBeforeListingExitsWithoutRequests(): void
    {
        $ctx = new RecordingJobContext();
        $ctx->stop = true;

        $r = $this->job()->runOnce($ctx);

        self::assertSame([], $this->requests);
        self::assertFalse($r->hadWork);
        self::assertNull($this->setting('ragioneria_progress_rolling_from'));
    }

    // ---------------------------------------------------------------- gate di configurazione

    public function testConfigErrorGates(): void
    {
        self::assertNull(RagioneriaSyncJob::configError('80000000001', 'https://x'));
        self::assertSame('ERRORE: id_dominio non configurato.', RagioneriaSyncJob::configError('', 'https://x'));
        self::assertSame('ERRORE: govpay.backoffice_url non configurato.', RagioneriaSyncJob::configError('80000000001', ''));
        // l'ordine dei controlli e' quello dello script: prima id_dominio
        self::assertSame('ERRORE: id_dominio non configurato.', RagioneriaSyncJob::configError('', ''));
    }
}
