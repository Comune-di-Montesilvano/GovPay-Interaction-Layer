<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\DbalConnectionFactory;
use App\Database\JobStateRepository;
use App\Database\MassivePendenzeRepository;
use App\Services\Jobs\JobRegistry;
use App\Services\Jobs\JobResult;
use App\Services\Jobs\MassiveEnqueueJob;
use App\Services\Jobs\MassivePendenzeProcessor;
use App\Services\Jobs\Message\ProcessMassiveRow;
use App\Services\Jobs\Messenger\TransientOnlyRetryStrategy;
use App\Services\Jobs\WorkerKernel;
use Doctrine\DBAL\Connection as DbalConnection;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Tests\Support\DbTestCase;
use Tests\Support\FakeGovPayPendenzeController;
use Tests\Support\RecordingJobContext;
use Tests\Support\StubPeriodicJob;
use Tests\Support\Synthetic;

/**
 * Pendenze massive come messaggi Messenger (Task 11): claim atomico + accodamento
 * (MassiveEnqueueJob), elaborazione per messaggio (ProcessMassiveRowHandler nel worker reale),
 * retry solo transitori sulla coda `massive`, reset dei claim abbandonati, upgrade dal vecchio
 * demone, "riprova".
 *
 * Questo percorso SCRIVE su GovPay: ogni test verifica la lista ESATTA delle chiamate HTTP
 * (GovPay simulato con MockHandler dentro un PendenzeController reale), cosi' una doppia creazione
 * verrebbe rilevata. DB MariaDB usa-e-getta; orologio del worker finto (MockClock), tempi del DB reali.
 */
#[Group('db')]
final class MassiveMessengerTest extends DbTestCase
{
    protected static array $tables = ['gil_job_run_errors', 'gil_job_runs', 'gil_jobs', 'lock_keys', 'messenger_messages', 'settings', 'entrate_tipologie'];

    private const DOMINIO = '80000000001';
    private const A2A = 'GIL-TEST';
    private const BASE = 'https://govpay.example.test/backoffice/v1';
    private const P = '/backoffice/v1/pendenze/' . self::A2A . '/';
    private const ESITO_INCERTO = "Esito incerto: timeout/errore di rete verso GovPay (cURL %s) \u{2014} verificare prima di ripetere";

    private FakeGovPayPendenzeController $gp;
    private MockClock $clock;
    private JobStateRepository $state;
    private DbalConnection $dbal;
    /** @var list<string> */
    private array $out = [];
    /** @var list<\Throwable> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        new MassivePendenzeRepository(self::pdo()); // ensureTable() se la tabella manca
        self::pdo()->exec('TRUNCATE TABLE pendenze_massive');
        SettingsRepository::flush();
        SettingsRepository::set('govpay', 'backoffice_url', self::BASE);
        SettingsRepository::set('entity', 'id_a2a', self::A2A);
        SettingsRepository::set('entity', 'id_dominio', self::DOMINIO);
        $this->gp = new FakeGovPayPendenzeController();
        $this->clock = new MockClock('2026-10-28 10:00:00');
        $this->state = new JobStateRepository(static fn(): \PDO => self::pdo(), $this->clock);
        $this->dbal = DbalConnectionFactory::create();
        $this->out = [];
        $this->reported = [];
        // lavoro attivo: con `pendenze-massive` disabilitato il gestore rilascia le righe (pausa)
        $this->state->setEnabled(MassiveEnqueueJob::NAME, true);
        MassiveEnqueueJob::forgetStaleReset();
    }

    protected function tearDown(): void
    {
        SettingsRepository::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function kernel(?JobRegistry $registry = null, bool $productionRetry = false): WorkerKernel
    {
        return new WorkerKernel(
            $registry ?? new JobRegistry([]),
            $this->state,
            $this->dbal,
            $this->clock,
            function (string $line): void { $this->out[] = $line; },
            function (string $suite, \Throwable $e): void { $this->reported[] = $e; },
            null,
            new LockFactory(new DoctrineDbalStore($this->dbal, [], 0.0)),
            sys_get_temp_dir() . '/gil-worker-test-cache-assente',
            fn(): MassivePendenzeProcessor => new MassivePendenzeProcessor($this->gp, new MassivePendenzeRepository(self::pdo())),
            // stessa strategia di produzione (solo transitori, 3 retry) ma senza attesa reale
            $productionRetry ? null : new TransientOnlyRetryStrategy(new MultiplierRetryStrategy(3, 0, 1, 0, 0.0))
        );
    }

    private function scopedKernel(JobRegistry $registry, \App\Services\Jobs\WorkerScope $scope): WorkerKernel
    {
        return new WorkerKernel(
            $registry,
            $this->state,
            $this->dbal,
            $this->clock,
            function (string $line): void { $this->out[] = $line; },
            function (string $suite, \Throwable $e): void { $this->reported[] = $e; },
            null,
            new LockFactory(new DoctrineDbalStore($this->dbal, [], 0.0)),
            sys_get_temp_dir() . '/gil-worker-test-cache-assente',
            fn(): MassivePendenzeProcessor => new MassivePendenzeProcessor($this->gp, new MassivePendenzeRepository(self::pdo())),
            new TransientOnlyRetryStrategy(new MultiplierRetryStrategy(3, 0, 1, 0, 0.0)),
            $scope
        );
    }

    private function enqueueJob(?SenderInterface $sender = null): MassiveEnqueueJob
    {
        return new MassiveEnqueueJob($this->dbal, $sender ?? WorkerKernel::createMassiveTransport($this->dbal), $this->clock);
    }

    /** Worker reale finche' le code non sono vuote (il tempo finto avanza solo a vuoto). */
    private function drain(?WorkerKernel $kernel = null): WorkerKernel
    {
        $kernel ??= $this->kernel();
        $kernel->run(5.0);
        return $kernel;
    }

    /**
     * @param array<mixed>|null $payload
     * @param array<mixed>|null $response
     */
    private function insertRow(string $stato, ?array $payload, ?array $response = null, ?string $claimedAgo = null, ?string $token = null, ?string $updatedAgo = null, ?string $errore = null, int $riga = 1): int
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO pendenze_massive (file_batch_id, riga, stato, errore, payload_json, response_json, claimed_at, claim_token, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ' . ($claimedAgo === null ? 'NULL' : 'NOW() - INTERVAL ' . $claimedAgo) . ', ?, NOW(), '
             . ($updatedAgo === null ? 'NOW()' : 'NOW() - INTERVAL ' . $updatedAgo) . ')'
        );
        $stmt->execute([
            'B1',
            $riga,
            $stato,
            $errore,
            $payload !== null ? json_encode($payload) : null,
            $response !== null ? json_encode($response) : null,
            $token,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $stmt = self::pdo()->prepare('SELECT * FROM pendenze_massive WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    /** @return array<mixed>|null */
    private function json(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $d = json_decode($raw, true);
        self::assertIsArray($d);
        return $d;
    }

    private static function messages(): int
    {
        return (int)self::pdo()->query("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'massive'")->fetchColumn();
    }

    /** @return list<ProcessMassiveRow> messaggi in coda `massive` (letti senza consumarli) */
    private static function queuedMessages(): array
    {
        $out = [];
        foreach (self::pdo()->query("SELECT body FROM messenger_messages WHERE queue_name = 'massive' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN) as $body) {
            $m = unserialize(stripslashes((string)$body))->getMessage();
            self::assertInstanceOf(ProcessMassiveRow::class, $m);
            $out[] = $m;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function payload(int $n = 1, array $extra = []): array
    {
        return array_replace([
            'idDominio' => self::DOMINIO,
            'idTipoPendenza' => 'TARI',
            'causale' => 'Causale sintetica ' . $n,
            'importo' => 12.5,
            'annoRiferimento' => 2026,
            'dataScadenza' => '2026-12-31',
            'soggettoPagatore' => ['tipo' => 'F', 'identificativo' => Synthetic::cf($n), 'anagrafica' => 'Rossi Mario'],
            'voci' => [['idVocePendenza' => '1', 'descrizione' => 'Voce ' . $n, 'importo' => 12.5]],
        ], $extra);
    }

    private function queue(int $code, mixed $body = null): void
    {
        $this->gp->mock->append(new Response(
            $code,
            ['Content-Type' => 'application/json'],
            $body === null ? '' : (string)json_encode($body)
        ));
    }

    private function queueTimeout(int $times = 1): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->gp->mock->append(new ConnectException('cURL error 28: Operation timed out https://x/?q=segreto', new Request('PUT', self::BASE), null, ['errno' => 28]));
        }
    }

    /** @return list<string> idPendenza di ogni PUT, in ordine */
    private function putIds(): array
    {
        $ids = [];
        foreach ($this->gp->calls() as $c) {
            if (str_starts_with($c, 'PUT ' . self::P)) {
                $ids[] = substr($c, strlen('PUT ' . self::P));
            }
        }
        return $ids;
    }

    // ============================================================ enqueue: claim atomico

    public function testThreePendingRowsBecomeThreeMessagesAndSecondEnqueueAddsNone(): void
    {
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->insertRow('PENDING', self::payload($i), riga: $i);
        }
        $job = $this->enqueueJob();

        $r = $job->runOnce(new RecordingJobContext());

        self::assertSame([3, 3, 0, 0, true], [$r->processed, $r->ok, $r->skipped, $r->errors, $r->hadWork]);
        $msgs = self::queuedMessages();
        self::assertSame($ids, array_map(static fn(ProcessMassiveRow $m): int => $m->rowId, $msgs));
        $tokens = [];
        foreach ($msgs as $m) {
            $row = $this->row($m->rowId);
            self::assertSame('PROCESSING', $row['stato'], 'riga presa in carico alla creazione del messaggio');
            self::assertNotNull($row['claimed_at']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string)$row['claim_token']);
            self::assertSame($row['claim_token'], $m->claimToken, 'il messaggio porta il claim della riga');
            $tokens[] = $m->claimToken;
        }
        self::assertCount(3, array_unique($tokens));
        self::assertSame([], $this->gp->calls(), 'l\'accodamento non chiama GovPay');

        $r2 = $this->enqueueJob()->runOnce(new RecordingJobContext());
        self::assertSame([0, false], [$r2->processed, $r2->hadWork]);
        self::assertSame(3, self::messages(), 'nessun nuovo messaggio per righe gia\' prese');
        self::assertSame(MassiveEnqueueJob::IDLE_SECONDS, $job->emptyQueueIntervalSeconds());
    }

    public function testCancelPendingRowsAreClaimedFirstLikeToday(): void
    {
        $pending = $this->insertRow('PENDING', self::payload(1), riga: 1);
        $c1 = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'GIL-a'], riga: 2);
        $c2 = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'GIL-b'], riga: 3);
        $job = $this->enqueueJob();

        $job->runOnce(new RecordingJobContext());
        self::assertSame([$c1, $c2], array_map(static fn(ProcessMassiveRow $m): int => $m->rowId, self::queuedMessages()));
        self::assertSame('CANCEL_PROCESSING', $this->row($c1)['stato']);
        self::assertSame('PENDING', $this->row($pending)['stato']);

        $job->runOnce(new RecordingJobContext());
        self::assertSame('PROCESSING', $this->row($pending)['stato']);
        self::assertSame(3, self::messages());
    }

    /** Due accodatori sulla stessa riga: la seconda presa non cambia nulla e non crea messaggi. */
    public function testRowAlreadyClaimedIsNeverClaimedTwice(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));

        self::assertSame(1, $this->enqueueJob()->claimAndDispatch([$id], 'create'));
        $token = $this->row($id)['claim_token'];
        self::assertSame(0, $this->enqueueJob()->claimAndDispatch([$id], 'create'));

        self::assertSame(1, self::messages());
        self::assertSame($token, $this->row($id)['claim_token']);
    }

    /** Claim e inserimento del messaggio nella stessa transazione: un invio fallito annulla anche i claim. */
    public function testSendFailureRollsBackClaimsAndMessages(): void
    {
        $ids = [$this->insertRow('PENDING', self::payload(1), riga: 1), $this->insertRow('PENDING', self::payload(2), riga: 2)];
        $real = WorkerKernel::createMassiveTransport($this->dbal);
        $sender = new class($real) implements SenderInterface {
            private int $n = 0;
            public function __construct(private readonly SenderInterface $inner) {}
            public function send(Envelope $envelope): Envelope
            {
                if (++$this->n === 2) {
                    throw new \RuntimeException('trasporto giu');
                }
                return $this->inner->send($envelope);
            }
        };

        try {
            $this->enqueueJob($sender)->claimAndDispatch($ids, 'create');
            self::fail('eccezione attesa');
        } catch (\RuntimeException $e) {
            self::assertSame('trasporto giu', $e->getMessage());
        }

        self::assertSame(0, self::messages(), 'anche il primo messaggio e\' annullato');
        foreach ($ids as $id) {
            $row = $this->row($id);
            self::assertSame('PENDING', $row['stato']);
            self::assertNull($row['claim_token']);
            self::assertNull($row['claimed_at']);
        }
    }

    /** Al massimo BATCH_SIZE messaggi in coda: un giro prende al piu' 50 righe, poi contropressione. */
    public function testRunClaimsAtMostBatchSizeAndThenBacksOff(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->insertRow('PENDING', self::payload($i), riga: $i);
        }
        $kernel = $this->kernel();
        $job = $this->enqueueJob();
        $this->state->setEnabled('pendenze-massive', true);

        $result = $kernel->runner()->run($job);

        self::assertNotNull($result);
        self::assertSame(50, $result->processed);
        self::assertSame(50, self::messages());
        self::assertSame(MassiveEnqueueJob::BACKPRESSURE_SECONDS, $job->emptyQueueIntervalSeconds());
        $next = self::pdo()->query("SELECT next_run_at FROM gil_jobs WHERE name = 'pendenze-massive'")->fetchColumn();
        self::assertSame($this->clock->now()->modify('+' . MassiveEnqueueJob::BACKPRESSURE_SECONDS . ' seconds')->format('Y-m-d H:i:s'), $next);
        self::assertSame(5, (int)self::pdo()->query("SELECT COUNT(*) FROM pendenze_massive WHERE stato = 'PENDING'")->fetchColumn());

        // 10 messaggi elaborati -> 10 posti liberi: il giro successivo prende le 5 rimaste
        self::pdo()->exec("DELETE FROM messenger_messages WHERE queue_name = 'massive' ORDER BY id LIMIT 10");
        $r = $this->enqueueJob()->runOnce(new RecordingJobContext());
        self::assertSame(5, $r->processed);
        self::assertSame(45, self::messages());
    }

    /**
     * Fix round 1: coda piena e nessun reset da fare -> il giro non lascia traccia (nessuna riga
     * gil_job_runs, nessuna riga su stdout) e riprova dopo 30-60 s, non ogni 5 s.
     */
    public function testRunAtQueueCapIsSilentAndBacksOffAtLeast30s(): void
    {
        for ($i = 1; $i <= 52; $i++) {
            $this->insertRow('PENDING', self::payload($i), riga: $i);
        }
        $this->enqueueJob()->runOnce(new RecordingJobContext()); // 50 messaggi: coda piena
        self::assertSame(50, self::messages());
        $this->clock->sleep(120); // il reset dei claim e' di nuovo dovuto... ma non trova nulla

        $job = $this->enqueueJob();
        $result = $this->kernel()->runner()->run($job);

        self::assertNotNull($result);
        self::assertSame(0, $result->processed);
        self::assertSame(0, (int)self::pdo()->query("SELECT COUNT(*) FROM gil_job_runs WHERE job = 'pendenze-massive'")->fetchColumn());
        self::assertSame([], $this->out);
        self::assertGreaterThanOrEqual(30, MassiveEnqueueJob::BACKPRESSURE_SECONDS);
        self::assertLessThanOrEqual(60, MassiveEnqueueJob::BACKPRESSURE_SECONDS);
        self::assertSame(MassiveEnqueueJob::BACKPRESSURE_SECONDS, $job->emptyQueueIntervalSeconds());
        $next = self::pdo()->query("SELECT next_run_at FROM gil_jobs WHERE name = 'pendenze-massive'")->fetchColumn();
        self::assertSame($this->clock->now()->modify('+' . MassiveEnqueueJob::BACKPRESSURE_SECONDS . ' seconds')->format('Y-m-d H:i:s'), $next);
    }

    /** Fix round 1: il reset dei claim abbandonati gira al piu' una volta al minuto (per processo). */
    public function testStaleResetRunsAtMostOncePerMinute(): void
    {
        $this->enqueueJob()->runOnce(new RecordingJobContext()); // reset eseguito (nulla da fare)
        $stale = $this->insertRow('PROCESSING', self::payload(1), ['idPendenza' => 'GIL-s'], claimedAgo: '2 HOUR', token: 'old');

        $this->clock->sleep(59);
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        self::assertSame('old', $this->row($stale)['claim_token'], 'entro il minuto: nessun reset');
        self::assertSame(0, self::messages());

        $this->clock->sleep(2);
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        self::assertNotSame('old', $this->row($stale)['claim_token'], 'dopo il minuto: reset + nuovo claim');
        self::assertSame(1, self::messages());
    }

    // ============================================================ worker: elaborazione per messaggio

    /** Flusso completo dal tick dello scheduler: una riga -> esattamente una PUT -> SUCCESS. */
    public function testWorkerCreatesEachRowWithExactlyOnePut(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->state->setEnabled('pendenze-massive', true);
        $registry = new JobRegistry();
        $registry->register(MassiveEnqueueJob::NAME, MassiveEnqueueJob::IDLE_SECONDS, fn() => $this->enqueueJob());
        $this->queue(201, ['numeroAvviso' => '301000000000000017']);

        $this->kernel($registry)->run(30.0);

        self::assertCount(1, $this->gp->calls(), 'esattamente una PUT');
        $idPendenza = $this->putIds()[0];
        self::assertMatchesRegularExpression('/^GIL-[0-9a-f]{16}$/', $idPendenza);
        $row = $this->row($id);
        self::assertSame('SUCCESS', $row['stato']);
        self::assertNull($row['errore']);
        self::assertSame(['numeroAvviso' => '301000000000000017', 'idPendenza' => $idPendenza], $this->json($row['response_json']));
        self::assertCount(1, $this->gp->notifications);
        self::assertSame(0, self::messages(), 'messaggio confermato');
        $runs = self::pdo()->query("SELECT processed FROM gil_job_runs WHERE job = 'pendenze-massive'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['1'], array_map('strval', $runs));
    }

    /** Errore transitorio x4 (1 + 3 retry): 4 PUT sullo STESSO idPendenza, poi lo stato di errore di oggi. */
    public function testTransientFailureIsRetriedThreeTimesWithSameIdThenGoesToError(): void
    {
        $this->gp->circuitBreakerNeverOpens = true;
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->queueTimeout(4);

        $this->drain();

        $puts = $this->putIds();
        self::assertCount(4, $puts, '1 tentativo + 3 retry');
        self::assertCount(1, array_unique($puts), 'sempre lo stesso idPendenza');
        self::assertCount(4, $this->gp->calls(), 'nessun\'altra chiamata');
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame(sprintf(self::ESITO_INCERTO, '28'), $row['errore']);
        self::assertSame(['idPendenza' => $puts[0]], $this->json($row['response_json']));
        self::assertStringNotContainsString('segreto', (string)$row['errore'] . (string)$row['response_json']);
        self::assertSame([], $this->gp->notifications);
        self::assertSame(0, self::messages());
        self::assertCount(1, array_filter($this->out, static fn(string $l): bool => str_contains($l, "riga {$id}: tentativi esauriti (3 retry)")));
        self::assertSame([], $this->reported, 'errore transitorio esaurito: nessuna eccezione a Sentry');
    }

    public function testTransientFailureThenSuccessUsesSameIdAndNotifiesOnce(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->queueTimeout(2);
        $this->queue(201, ['numeroAvviso' => '301000000000000017']);

        $this->drain();

        $puts = $this->putIds();
        self::assertCount(3, $puts);
        self::assertCount(1, array_unique($puts));
        $row = $this->row($id);
        self::assertSame('SUCCESS', $row['stato']);
        self::assertNull($row['errore']);
        self::assertCount(1, $this->gp->notifications);
        self::assertSame($puts[0], $this->gp->notifications[0]['idPendenza']);
        self::assertSame(0, self::messages());
    }

    public function testGovPay5xxIsRetriedThenErrorWithStatusAndProblemFields(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        for ($i = 0; $i < 4; $i++) {
            $this->queue(503, ['categoria' => 'INTERNO', 'codice' => 'ERR_503', 'descrizione' => 'Servizio non disponibile', 'dettaglio' => 'segreto']);
        }

        $this->drain();

        $puts = $this->putIds();
        self::assertCount(4, $puts);
        self::assertCount(1, array_unique($puts));
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame('HTTP 503 - ERR_503: Servizio non disponibile', $row['errore']);
        self::assertSame(['idPendenza' => $puts[0]], $this->json($row['response_json']));
    }

    /**
     * Fix round 1: circuit breaker aperto = errore transitorio (ritentato), ma nessuna PUT e' partita:
     * il testo non dice "esito incerto (cURL n/d)".
     */
    public function testCircuitBreakerOpenIsTransientWithDedicatedText(): void
    {
        $cb = dirname(__DIR__, 3) . '/backoffice/storage/tmp/govpay_circuit_breaker.json';
        @mkdir(dirname($cb), 0775, true);
        file_put_contents($cb, (string)json_encode(['status' => 'OPEN', 'failures' => 3, 'last_failure_time' => time()]));
        try {
            $id = $this->insertRow('PENDING', self::payload(1));
            $this->enqueueJob()->runOnce(new RecordingJobContext());

            $this->drain();

            self::assertSame([], $this->gp->calls(), 'nessuna richiesta inviata a GovPay');
            $row = $this->row($id);
            self::assertSame('ERROR', $row['stato'], 'ritentato e poi stato di errore');
            self::assertSame("GovPay non raggiungibile (circuit breaker aperto) \u{2014} nessun invio effettuato", $row['errore']);
            self::assertStringNotContainsString('incerto', implode("\n", $this->out));
            self::assertCount(4, array_filter($this->out, static fn(string $l): bool => str_contains($l, 'circuit breaker aperto')), '1 tentativo + 3 retry');
        } finally {
            @unlink($cb);
        }
    }

    /** 4xx = rifiuto definitivo: una sola PUT, ERROR subito, nessun retry. */
    public function testGovPay4xxIsNotRetried(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->queue(422, ['categoria' => 'RICHIESTA', 'codice' => 'SINTASSI', 'descrizione' => 'Richiesta non valida', 'dettaglio' => 'segreto']);

        $this->drain();

        self::assertCount(1, $this->gp->calls());
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame('HTTP 422 - SINTASSI: Richiesta non valida', $row['errore']);
        self::assertSame(0, self::messages());
    }

    /** Errore di validazione (payload non valido): ERROR subito, nessuna chiamata GovPay, nessun retry. */
    public function testValidationErrorIsNotRetried(): void
    {
        $id = $this->insertRow('PENDING', null);
        $this->enqueueJob()->runOnce(new RecordingJobContext());

        $this->drain();

        self::assertSame([], $this->gp->calls());
        self::assertSame('ERROR', $this->row($id)['stato']);
        self::assertSame('Payload JSON non valido o mancante', $this->row($id)['errore']);
        self::assertSame(0, self::messages());
    }

    /** Eccezione imprevista nel gestore: nessun retry, riga in errore con la sola classe, Sentry. */
    public function testUnexpectedExceptionIsNotRetriedAndMarksRowError(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->gp->allocateThrows = new \LogicException('segreto RSSMRA');

        $this->drain();

        self::assertSame([], $this->gp->calls());
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame('Errore di elaborazione (LogicException)', $row['errore']);
        self::assertSame(0, self::messages());
        self::assertCount(1, $this->reported);
        self::assertInstanceOf(\LogicException::class, $this->reported[0]);
        self::assertCount(1, array_filter($this->out, static fn(string $l): bool => str_contains($l, 'errore non ritentabile (LogicException)')));
    }

    /** Messaggio duplicato (stesso claim) o con claim diverso: zero PUT in piu'. */
    public function testDuplicateOrForeignMessageIsANoOp(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $token = (string)$this->row($id)['claim_token'];
        $transport = WorkerKernel::createMassiveTransport($this->dbal);
        $transport->send(new Envelope(new ProcessMassiveRow($id, $token)));
        $transport->send(new Envelope(new ProcessMassiveRow($id, str_repeat('0', 32))));
        $this->queue(201, []);

        $this->drain();

        self::assertCount(1, $this->gp->calls(), 'una sola PUT per tre messaggi');
        self::assertSame('SUCCESS', $this->row($id)['stato']);
        self::assertSame(0, self::messages());
    }

    /**
     * Messaggio di un claim precedente (es. riconsegnato dopo il redeliver_timeout di 3600 s, quando
     * la riga e' gia' stata rimessa in coda e ripresa con un altro claim): no-op anche se la riga e'
     * in lavorazione. Solo il token lo distingue dal messaggio corrente.
     */
    public function testOldClaimMessageIsNoOpWhileRowIsHeldByNewClaim(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1), ['idPendenza' => 'GIL-k']);
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $current = (string)$this->row($id)['claim_token'];
        self::pdo()->exec("DELETE FROM messenger_messages WHERE queue_name = 'massive'");
        WorkerKernel::createMassiveTransport($this->dbal)->send(new Envelope(new ProcessMassiveRow($id, str_repeat('0', 32))));

        $this->drain();

        self::assertSame([], $this->gp->calls(), 'nessuna PUT dal messaggio obsoleto');
        $row = $this->row($id);
        self::assertSame('PROCESSING', $row['stato']);
        self::assertSame($current, $row['claim_token']);
        self::assertSame(0, self::messages(), 'messaggio obsoleto confermato e rimosso');
    }

    /**
     * Worker ucciso durante la PUT (la pendenza puo' essere gia' su GovPay): l'id era gia' salvato
     * sulla riga; dopo 1 h il claim viene rimesso in coda e il nuovo tentativo riusa lo STESSO id
     * (PUT idempotente). Il vecchio messaggio, riconsegnato dopo il redeliver_timeout, e' un no-op.
     */
    public function testKilledWorkerStaleResetRetriesWithSameIdAndOldMessageIsNoOp(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $oldToken = (string)$this->row($id)['claim_token'];

        // primo tentativo: fotografia della riga nel momento in cui GovPay riceve la PUT
        $atPut = null;
        $this->gp->mock->append(function () use ($id, &$atPut) {
            $atPut = $this->row($id);
            return new Response(201, [], '{}');
        });
        (new MassivePendenzeProcessor($this->gp, new MassivePendenzeRepository(self::pdo())))->processClaimedRow($id, $oldToken);
        self::assertIsArray($atPut);
        $firstId = $this->putIds()[0];
        self::assertSame('PROCESSING', $atPut['stato']);
        self::assertSame(['idPendenza' => $firstId], $this->json($atPut['response_json']), 'id salvato PRIMA della PUT');

        // "kill": il processo muore dopo la PUT, prima di scrivere l'esito; 2 h dopo...
        self::pdo()->prepare("UPDATE pendenze_massive SET stato = 'PROCESSING', errore = NULL, response_json = ?, claimed_at = NOW() - INTERVAL 2 HOUR WHERE id = ?")
            ->execute([$atPut['response_json'], $id]);
        $this->gp->notifications = [];
        $this->clock->sleep(61); // reset dei claim al piu' una volta al minuto

        $this->enqueueJob()->runOnce(new RecordingJobContext()); // reset del claim + nuovo claim
        $row = $this->row($id);
        self::assertSame('PROCESSING', $row['stato']);
        self::assertNotSame($oldToken, $row['claim_token']);
        self::assertSame(2, self::messages(), 'vecchio messaggio (riconsegnato) + nuovo');

        $this->queue(201, []);
        $this->drain();

        self::assertSame([$firstId, $firstId], $this->putIds(), 'mai un secondo idPendenza');
        self::assertSame('SUCCESS', $this->row($id)['stato']);
        self::assertCount(1, $this->gp->notifications);
        self::assertSame(0, self::messages());
    }

    // ============================================================ pausa (lavoro disabilitato)

    /**
     * Fix round 1: con `pendenze-massive` disabilitato anche le righe gia' in coda si fermano. Il
     * gestore non chiama GovPay, rilascia il claim (PENDING / CANCEL_PENDING, id conservato) e
     * conferma il messaggio; riattivato, ogni riga e' elaborata una sola volta.
     */
    public function testDisabledJobReleasesQueuedRowsWithoutGovPayCalls(): void
    {
        $create = $this->insertRow('PENDING', self::payload(1), ['idPendenza' => 'GIL-p'], riga: 1);
        $cancel = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'GIL-c'], riga: 2);
        $job = $this->enqueueJob();
        self::assertSame(1, $job->claimAndDispatch([$create], 'create'));
        self::assertSame(1, $job->claimAndDispatch([$cancel], 'cancel'));
        $this->state->setEnabled(MassiveEnqueueJob::NAME, false);

        $this->drain();

        self::assertSame([], $this->gp->calls(), 'pausa: nessuna chiamata GovPay');
        self::assertSame(0, self::messages(), 'messaggi confermati');
        $r = $this->row($create);
        self::assertSame(['PENDING', null, null], [$r['stato'], $r['claimed_at'], $r['claim_token']]);
        self::assertSame(['idPendenza' => 'GIL-p'], $this->json($r['response_json']));
        self::assertSame('CANCEL_PENDING', $this->row($cancel)['stato'], 'annullamento resta annullamento');
        self::assertNull($this->row($cancel)['claim_token']);

        $this->state->setEnabled(MassiveEnqueueJob::NAME, true);
        $this->queue(200, ['stato' => 'ANNULLATA']);
        $this->queue(201, []);
        $this->enqueueJob()->runOnce(new RecordingJobContext()); // annullamenti per primi
        $this->drain();
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->drain();

        self::assertSame(['GET ' . self::P . 'GIL-c', 'PUT ' . self::P . 'GIL-p'], $this->gp->calls());
        self::assertSame('SUCCESS', $this->row($create)['stato']);
        self::assertSame('CANCELLED', $this->row($cancel)['stato']);
    }

    /** Un retry (RedeliveryStamp 2) rilasciato in pausa non consuma tentativi: riattivato, 1 + 3 retry. */
    public function testRowReleasedDuringPauseKeepsFullRetryBudget(): void
    {
        $this->gp->circuitBreakerNeverOpens = true;
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $token = (string)$this->row($id)['claim_token'];
        self::pdo()->exec("DELETE FROM messenger_messages WHERE queue_name = 'massive'");
        WorkerKernel::createMassiveTransport($this->dbal)->send(
            (new Envelope(new ProcessMassiveRow($id, $token)))->with(new \Symfony\Component\Messenger\Stamp\RedeliveryStamp(2))
        );
        $this->state->setEnabled(MassiveEnqueueJob::NAME, false);

        $this->drain();
        self::assertSame([], $this->gp->calls());
        self::assertSame('PENDING', $this->row($id)['stato']);

        $this->state->setEnabled(MassiveEnqueueJob::NAME, true);
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->queueTimeout(4);
        $this->drain();

        self::assertCount(4, $this->putIds(), 'tentativi completi dopo la pausa');
        self::assertSame('ERROR', $this->row($id)['stato']);
    }

    // ============================================================ reset claim abbandonati / upgrade

    public function testStaleClaimsAreResetKeepingKindAndStoredId(): void
    {
        $oldCreate = $this->insertRow('PROCESSING', self::payload(1), ['idPendenza' => 'GIL-old'], '2 HOUR', str_repeat('a', 32), riga: 1);
        $freshCreate = $this->insertRow('PROCESSING', self::payload(2), null, '10 MINUTE', str_repeat('b', 32), riga: 2);
        $oldCancel = $this->insertRow('CANCEL_PROCESSING', null, ['idPendenza' => 'GIL-c'], '2 HOUR', str_repeat('c', 32), riga: 3);

        $reset = $this->enqueueJob()->resetStaleClaims();

        self::assertSame(1, $reset['creazioni rimesse in coda']);
        self::assertSame(1, $reset['annullamenti rimessi in coda']);
        $r = $this->row($oldCreate);
        self::assertSame(['PENDING', null, null], [$r['stato'], $r['claimed_at'], $r['claim_token']]);
        self::assertSame(['idPendenza' => 'GIL-old'], $this->json($r['response_json']), 'id conservato per la nuova PUT');
        self::assertSame('PROCESSING', $this->row($freshCreate)['stato'], 'claim recente: elaborazione in corso');
        self::assertSame(str_repeat('b', 32), $this->row($freshCreate)['claim_token']);
        self::assertSame('CANCEL_PENDING', $this->row($oldCancel)['stato'], 'mai un annullamento trasformato in creazione');
    }

    /**
     * Upgrade (Review Focus 3): righe PENDING / CANCEL_PENDING del vecchio demone elaborate una sola
     * volta; righe lasciate PROCESSING / CANCEL_PROCESSING dal vecchio demone (claimed_at NULL,
     * updated_at > 1 h): annullamenti di nuovo in coda, creazioni con id di nuovo in coda (stesso
     * id), creazioni senza id -> ERROR esito incerto (mai una seconda PUT con un id nuovo).
     */
    public function testUpgradeRowsAreProcessedExactlyOnce(): void
    {
        $pending = $this->insertRow('PENDING', self::payload(1), riga: 1);
        $cancel = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'GIL-old'], riga: 2);
        $legacyNoId = $this->insertRow('PROCESSING', self::payload(3), null, updatedAgo: '2 HOUR', riga: 3);
        $legacyWithId = $this->insertRow('PROCESSING', self::payload(4), ['idPendenza' => 'GIL-d'], updatedAgo: '2 HOUR', riga: 4);
        $legacyCancel = $this->insertRow('CANCEL_PROCESSING', null, ['idPendenza' => 'GIL-e'], updatedAgo: '2 HOUR', riga: 5);
        $legacyRecent = $this->insertRow('PROCESSING', self::payload(6), null, updatedAgo: '5 MINUTE', riga: 6);

        $this->state->setEnabled('pendenze-massive', true);
        $registry = new JobRegistry();
        $registry->register(MassiveEnqueueJob::NAME, MassiveEnqueueJob::IDLE_SECONDS, fn() => $this->enqueueJob());
        $this->queue(200, ['stato' => 'ANNULLATA']); // GET GIL-old
        $this->queue(200, ['stato' => 'ANNULLATA']); // GET GIL-e
        $this->queue(201, []);                       // PUT riga 1 (id nuovo)
        $this->queue(201, []);                       // PUT riga 4 (id salvato)

        $this->kernel($registry)->run(30.0);

        $calls = $this->gp->calls();
        self::assertCount(4, $calls);
        self::assertSame('GET ' . self::P . 'GIL-old', $calls[0]);
        self::assertSame('GET ' . self::P . 'GIL-e', $calls[1]);
        self::assertMatchesRegularExpression('#^PUT ' . preg_quote(self::P, '#') . 'GIL-[0-9a-f]{16}$#', $calls[2]);
        self::assertSame('PUT ' . self::P . 'GIL-d', $calls[3]);
        self::assertSame('SUCCESS', $this->row($pending)['stato']);
        self::assertSame('CANCELLED', $this->row($cancel)['stato']);
        self::assertSame('SUCCESS', $this->row($legacyWithId)['stato']);
        self::assertSame('CANCELLED', $this->row($legacyCancel)['stato']);
        $noId = $this->row($legacyNoId);
        self::assertSame('ERROR', $noId['stato']);
        self::assertSame(MassiveEnqueueJob::LEGACY_INTERRUPTED_ERRORE, $noId['errore']);
        self::assertNotNull($noId['claimed_at'], 'riga elaborata: "riprova" possibile dopo la verifica');
        self::assertSame('PROCESSING', $this->row($legacyRecent)['stato'], 'vecchio demone forse ancora attivo: non toccata');
        self::assertNull($this->row($legacyRecent)['claimed_at']);

        // nessuna seconda elaborazione
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->drain();
        self::assertCount(4, $this->gp->calls());
        self::assertSame(0, self::messages());
    }

    // ============================================================ riprova (UI)

    public function testRetryRequeuesOnlyWorkerErrorsAndReusesStoredId(): void
    {
        $err = $this->insertRow('ERROR', self::payload(1), ['idPendenza' => 'GIL-r'], '1 HOUR', str_repeat('a', 32), errore: sprintf(self::ESITO_INCERTO, '28'), riga: 1);
        $importReject = $this->insertRow('ERROR', self::payload(2), null, errore: 'Duplicato nel file', riga: 2);
        $ok = $this->insertRow('SUCCESS', self::payload(3), ['idPendenza' => 'GIL-s'], '1 HOUR', str_repeat('b', 32), riga: 3);
        $processing = $this->insertRow('PROCESSING', self::payload(4), null, '1 MINUTE', str_repeat('c', 32), riga: 4);
        $repo = new MassivePendenzeRepository(self::pdo());

        foreach ([$err, $importReject, $ok, $processing, 999999] as $id) {
            $repo->retry($id);
        }

        $r = $this->row($err);
        self::assertSame(['PENDING', null, null], [$r['stato'], $r['claimed_at'], $r['claim_token']]);
        self::assertSame(['idPendenza' => 'GIL-r'], $this->json($r['response_json']));
        self::assertSame('ERROR', $this->row($importReject)['stato'], 'scarto di validazione: mai su GovPay');
        self::assertSame('SUCCESS', $this->row($ok)['stato']);
        self::assertSame('PROCESSING', $this->row($processing)['stato']);
        self::assertSame(str_repeat('c', 32), $this->row($processing)['claim_token']);

        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->queue(201, []);
        $this->drain();
        self::assertSame(['PUT ' . self::P . 'GIL-r'], $this->gp->calls(), 'stessa pendenza, nessun id nuovo');
        self::assertSame('SUCCESS', $this->row($err)['stato']);
    }

    // ============================================================ retry strategy / ordine ricevitori

    public function testProductionRetryDelayIs30sOnTheMassiveQueue(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $this->queueTimeout(1);

        $this->drain($this->kernel(null, true));

        self::assertCount(1, $this->gp->calls(), 'il retry non e\' ancora disponibile');
        $row = $this->row($id);
        self::assertSame('PROCESSING', $row['stato'], 'in attesa del nuovo tentativo');
        self::assertSame(sprintf(self::ESITO_INCERTO, '28'), $row['errore']);
        self::assertSame(1, self::messages());
        $msg = self::pdo()->query("SELECT body, TIMESTAMPDIFF(SECOND, created_at, available_at) AS d FROM messenger_messages WHERE queue_name = 'massive'")->fetch(\PDO::FETCH_ASSOC);
        // available_at ha precisione al secondo: Doctrine arrotonda per eccesso (30 o 31 s)
        self::assertContains((int)$msg['d'], [30, 31]);
        $envelope = unserialize(stripslashes((string)$msg['body']));
        self::assertInstanceOf(Envelope::class, $envelope);
        self::assertSame(30000, $envelope->last(\Symfony\Component\Messenger\Stamp\DelayStamp::class)?->getDelay());
        self::assertSame(1, $envelope->last(\Symfony\Component\Messenger\Stamp\RedeliveryStamp::class)?->getRetryCount());
    }

    /**
     * Worker parallelo: il processo `massive` consuma solo la coda massive (nessun lavoro
     * periodico); un processo di gruppo esegue i suoi lavori e non tocca la coda massive.
     */
    public function testMassiveProcessAndJobProcessConsumeOnlyTheirOwnQueues(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertRow('PENDING', self::payload($i), riga: $i);
        }
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        self::assertSame(3, self::messages());
        $periodic = new StubPeriodicJob('periodico', static fn(): JobResult => new JobResult(0, 0, 0, 0, false));
        $this->state->setEnabled('periodico', true);
        $registry = new JobRegistry([$periodic]);

        $this->scopedKernel($registry, \App\Services\Jobs\WorkerScope::jobGroup('periodico', ['periodico']))->run(12.0);
        self::assertSame(1, $periodic->calls);
        self::assertSame(3, self::messages(), 'coda massive non toccata dal processo dei lavori');

        for ($i = 1; $i <= 3; $i++) {
            $this->gp->mock->append(new Response(201, [], '{}'));
        }
        $this->scopedKernel($registry, \App\Services\Jobs\WorkerScope::massive())->run(5.0);
        self::assertSame(0, self::messages());
        self::assertSame(1, $periodic->calls, 'nessun lavoro periodico nel processo massive');
    }

    public function testManualJobsQueueIsReadBeforeMassiveQueue(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertRow('PENDING', self::payload($i), riga: $i);
        }
        $this->enqueueJob()->runOnce(new RecordingJobContext());
        $order = [];
        for ($i = 1; $i <= 3; $i++) {
            $this->gp->mock->append(static function () use (&$order) {
                $order[] = 'M';
                return new Response(201, [], '{}');
            });
        }
        $manual = new StubPeriodicJob('manuale', static function () use (&$order): JobResult {
            $order[] = 'J';
            return new JobResult(0, 0, 0, 0, false);
        });
        WorkerKernel::enqueueManualRun($this->dbal, 'manuale');

        $this->kernel(new JobRegistry([$manual]))->run(5.0);

        self::assertSame(['J', 'M', 'M', 'M'], $order);
    }

    /** remaining() = righe da prendere in carico; 0 con la coda piena (giro silenzioso per contropressione). */
    public function testEnqueueRemainingCountsClaimableRowsAndIsZeroUnderBackpressure(): void
    {
        $this->insertRow('PENDING', self::payload(1), riga: 1);
        $this->insertRow('CANCEL_PENDING', self::payload(2), riga: 2);
        $this->insertRow('ERROR', self::payload(3), riga: 3);
        self::assertSame(2, $this->enqueueJob()->remaining());

        $transport = WorkerKernel::createMassiveTransport($this->dbal);
        for ($i = 0; $i < MassiveEnqueueJob::BATCH_SIZE; $i++) {
            $transport->send(new Envelope(new ProcessMassiveRow(999000 + $i, 'x')));
        }
        self::assertSame(0, $this->enqueueJob()->remaining());
    }
}
