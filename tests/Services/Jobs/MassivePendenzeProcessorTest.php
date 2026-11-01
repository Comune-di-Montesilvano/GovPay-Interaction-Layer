<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\MassivePendenzeRepository;
use App\Services\Jobs\JobResult;
use App\Services\Jobs\MassivePendenzeProcessor;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DbTestCase;
use Tests\Support\FakeGovPayPendenzeController;
use Tests\Support\RecordingJobContext;
use Tests\Support\Synthetic;

/**
 * Caratterizzazione del demone pendenze massive (cron_pendenze_massive.php) estratto in
 * MassivePendenzeProcessor: claimBatch / processRow / runOnce.
 *
 * GovPay simulato al confine HTTP (MockHandler dentro un PendenzeController reale, vedi
 * FakeGovPayPendenzeController): ogni scrittura su GovPay (PUT creazione, PUT+PATCH annullamento)
 * e' verificata con il numero ESATTO di chiamate, cosi' un doppio invio verrebbe rilevato.
 * DB MariaDB reale (usa-e-getta). La tabella pendenze_massive e' creata dalla migrazione 035 e,
 * per le immagini vecchie, da MassivePendenzeRepository::ensureTable().
 */
#[Group('db')]
final class MassivePendenzeProcessorTest extends DbTestCase
{
    protected static array $tables = ['settings', 'entrate_tipologie'];

    private const DOMINIO = '80000000001';
    private const A2A = 'GIL-TEST';
    private const BASE = 'https://govpay.example.test/backoffice/v1';
    private const P = '/backoffice/v1/pendenze/' . self::A2A . '/';

    private FakeGovPayPendenzeController $gp;
    private MassivePendenzeRepository $repo;
    private RecordingJobContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new MassivePendenzeRepository(self::pdo()); // ensureTable() crea la tabella se manca
        self::pdo()->exec('TRUNCATE TABLE pendenze_massive');
        SettingsRepository::flush();
        SettingsRepository::set('govpay', 'backoffice_url', self::BASE);
        SettingsRepository::set('entity', 'id_a2a', self::A2A);
        SettingsRepository::set('entity', 'id_dominio', self::DOMINIO);
        $this->gp = new FakeGovPayPendenzeController();
        $this->ctx = new RecordingJobContext();
    }

    protected function tearDown(): void
    {
        SettingsRepository::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function processor(): MassivePendenzeProcessor
    {
        return new MassivePendenzeProcessor($this->gp, $this->repo);
    }

    /**
     * @param array<mixed>|string|null $payload array => json_encode; string => salvato com'e'
     * @param array<mixed>|null $response
     */
    private function insertRow(string $stato, array|string|null $payload, ?array $response = null, ?string $errore = null, string $batch = 'B1', int $riga = 1): int
    {
        $pdo = self::pdo(); // stessa istanza per lastInsertId (getPDO() fa un ping SELECT 1 che lo azzera)
        $stmt = $pdo->prepare('INSERT INTO pendenze_massive (file_batch_id, riga, stato, errore, payload_json, response_json) VALUES (?,?,?,?,?,?)');
        $stmt->execute([
            $batch,
            $riga,
            $stato,
            $errore,
            is_array($payload) ? json_encode($payload) : $payload,
            $response !== null ? json_encode($response) : null,
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

    /** @return array<string,mixed> payload CSV-like sintetico (come lo produce l'import massivo) */
    private static function payload(int $n = 1, array $extra = []): array
    {
        return array_replace([
            'idDominio' => self::DOMINIO,
            'idTipoPendenza' => 'TARI',
            'causale' => 'Causale sintetica ' . $n,
            'importo' => 12.5,
            'annoRiferimento' => 2026,
            'dataScadenza' => '2026-12-31',
            'soggettoPagatore' => [
                'tipo' => 'F',
                'identificativo' => Synthetic::cf($n),
                'anagrafica' => 'Rossi Mario',
            ],
            'voci' => [['idVocePendenza' => '1', 'descrizione' => 'Voce ' . $n, 'importo' => 12.5]],
            'datiAllegati' => ['rateizzazione' => ['causale' => 'C' . $n]],
        ], $extra);
    }

    private function queue(int $code, mixed $body = null): void
    {
        $this->gp->mock->append(new Response(
            $code,
            ['Content-Type' => 'application/json'],
            $body === null ? '' : (is_string($body) ? $body : (string)json_encode($body))
        ));
    }

    private static function assertResult(JobResult $r, int $processed, int $ok, int $skipped, int $errors, bool $hadWork): void
    {
        self::assertSame(
            [$processed, $ok, $skipped, $errors, $hadWork],
            [$r->processed, $r->ok, $r->skipped, $r->errors, $r->hadWork]
        );
    }

    // ============================================================ CREAZIONE (PENDING)

    public function testPendingRowIsCreatedOnGovPayWithOnePutAndSavedAsSuccess(): void
    {
        $id = $this->insertRow('PENDING', self::payload(1));
        $this->queue(201, ['idDominio' => self::DOMINIO, 'numeroAvviso' => '301000000000000017', 'UUID' => 'uuid-1']);

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertResult($r, 1, 1, 0, 0, true);
        $calls = $this->gp->calls();
        self::assertCount(1, $calls, 'esattamente una PUT di creazione');
        self::assertMatchesRegularExpression('#^PUT ' . preg_quote(self::P, '#') . 'GIL-[0-9a-f]{16}$#', $calls[0]);
        $idPendenza = substr($calls[0], strlen('PUT ' . self::P));

        $body = $this->gp->body(0);
        self::assertArrayNotHasKey('idPendenza', $body, 'idPendenza va nell URL, non nel body');
        // Voci ricostruite da buildVociWithAccounting (tipologia sconosciuta -> RiferimentoEntrata = idTipoPendenza)
        self::assertSame([['idVocePendenza' => '1', 'descrizione' => 'Voce 1', 'importo' => 12.5, 'codEntrata' => 'TARI']], $body['voci']);
        self::assertSame(['rateizzazione' => ['causale' => 'C1'], 'sorgente' => 'GIL-Massivo'], $body['datiAllegati']);

        $row = $this->row($id);
        self::assertSame('SUCCESS', $row['stato']);
        self::assertNull($row['errore']);
        self::assertSame(
            ['idDominio' => self::DOMINIO, 'numeroAvviso' => '301000000000000017', 'UUID' => 'uuid-1', 'idPendenza' => $idPendenza],
            $this->json($row['response_json'])
        );

        self::assertCount(1, $this->gp->notifications);
        $n = $this->gp->notifications[0];
        self::assertSame($idPendenza, $n['idPendenza']);
        self::assertSame('', $n['email']);
        self::assertSame('Rossi Mario', $n['anagrafica']);
        self::assertSame(Synthetic::cf(1), $n['identificativo']);
        self::assertSame('F', $n['tipoSog']);
        self::assertSame([
            'causale' => 'Causale sintetica 1',
            'importo' => 12.5,
            'iuv' => '301000000000000017',
            'numeroAvviso' => '301000000000000017',
            'dataScadenza' => '2026-12-31',
            'idTipoPendenza' => 'TARI',
        ], $n['pendenzaData']);

        self::assertContains('  [B1:1] ID-' . $id . ' OK (Creato: ' . $idPendenza . ')', $this->ctx->messages);
    }

    public function testResponseIdPendenzaIsKeptWhenGovPayReturnsIt(): void
    {
        $id = $this->insertRow('PENDING', self::payload(2));
        $this->queue(201, ['idDominio' => self::DOMINIO, 'numeroAvviso' => '301000000000000025', 'idPendenza' => 'DA-GOVPAY']);

        $this->processor()->processRow($id, $this->ctx);

        $resp = $this->json($this->row($id)['response_json']);
        self::assertSame('DA-GOVPAY', $resp['idPendenza'] ?? null);
    }

    public function testEmptySuccessBodyStoresOnlyIdPendenzaAndNotifiesWithEmptyIuv(): void
    {
        $id = $this->insertRow('PENDING', self::payload(3));
        $this->queue(201, null);

        $this->processor()->processRow($id, $this->ctx);

        $row = $this->row($id);
        self::assertSame('SUCCESS', $row['stato']);
        $resp = $this->json($row['response_json']);
        self::assertSame(['idPendenza'], array_keys($resp ?? []));
        self::assertCount(1, $this->gp->notifications);
        self::assertSame('', $this->gp->notifications[0]['pendenzaData']['iuv']);
        self::assertSame('', $this->gp->notifications[0]['pendenzaData']['numeroAvviso']);
    }

    public function testExplicitPayloadIdPendenzaIsUsedInUrl(): void
    {
        $id = $this->insertRow('PENDING', self::payload(4, ['idPendenza' => 'CSV-ID-4']));
        $this->queue(201, ['numeroAvviso' => '301000000000000041']);

        $this->processor()->processRow($id, $this->ctx);

        self::assertSame(['PUT ' . self::P . 'CSV-ID-4'], $this->gp->calls());
        self::assertSame('CSV-ID-4', $this->json($this->row($id)['response_json'])['idPendenza'] ?? null);
    }

    public function testDatiAllegatiAsJsonStringIsDecodedAndInvalidStringBecomesEmpty(): void
    {
        $a = $this->insertRow('PENDING', self::payload(5, ['datiAllegati' => '{"k":"v"}']), null, null, 'B1', 1);
        $b = $this->insertRow('PENDING', self::payload(6, ['datiAllegati' => 'non-json']), null, null, 'B1', 2);
        $c = $this->insertRow('PENDING', self::payload(7, ['datiAllegati' => null]), null, null, 'B1', 3);
        $this->queue(201, []);
        $this->queue(201, []);
        $this->queue(201, []);

        $p = $this->processor();
        $p->processRow($a, $this->ctx);
        $p->processRow($b, $this->ctx);
        $p->processRow($c, $this->ctx);

        self::assertSame(['k' => 'v', 'sorgente' => 'GIL-Massivo'], $this->gp->body(0)['datiAllegati']);
        self::assertSame(['sorgente' => 'GIL-Massivo'], $this->gp->body(1)['datiAllegati']);
        self::assertSame(['sorgente' => 'GIL-Massivo'], $this->gp->body(2)['datiAllegati']);
    }

    public function testVincolatoIuvPrefixChecksCandidateIdsWithGetBeforePut(): void
    {
        self::pdo()->exec("INSERT INTO entrate_tipologie (id_dominio, id_entrata, iuv_prefix, updated_at, created_at) VALUES ('" . self::DOMINIO . "', 'AFFITTO', '07', NOW(), NOW())");
        $id = $this->insertRow('PENDING', self::payload(8, ['idTipoPendenza' => 'AFFITTO']));
        $this->queue(200, ['idPendenza' => 'esiste-gia']); // primo candidato gia' usato
        $this->queue(404, ['categoria' => 'OPERAZIONE']); // secondo candidato libero
        $this->queue(201, ['numeroAvviso' => 'x']);

        $this->processor()->processRow($id, $this->ctx);

        $calls = $this->gp->calls();
        self::assertCount(3, $calls);
        self::assertMatchesRegularExpression('#^GET ' . preg_quote(self::P, '#') . '07\d{16}$#', $calls[0]);
        self::assertMatchesRegularExpression('#^GET ' . preg_quote(self::P, '#') . '07\d{16}$#', $calls[1]);
        $candidate = substr($calls[1], strlen('GET ' . self::P));
        self::assertSame('PUT ' . self::P . $candidate, $calls[2], 'una sola PUT, sul candidato libero');
        self::assertSame($candidate, $this->gp->body(2)['numeroAvviso'], 'numeroAvviso = idPendenza per IUV vincolato');
        self::assertSame('SUCCESS', $this->row($id)['stato']);
    }

    /** P2: payload mancante/non oggetto -> ERROR senza passare da PROCESSING, nessuna chiamata GovPay. */
    public function testInvalidPayloadGoesToErrorWithoutGovPayCall(): void
    {
        $nullPayload = $this->insertRow('PENDING', null, null, null, 'B1', 1);
        $scalar = $this->insertRow('PENDING', '"solo una stringa"', null, null, 'B1', 2);

        $p = $this->processor();
        $r1 = $p->processRow($nullPayload, $this->ctx);
        $r2 = $p->processRow($scalar, $this->ctx);

        self::assertSame([], $this->gp->calls());
        self::assertSame([], $this->gp->notifications);
        foreach ([$nullPayload, $scalar] as $id) {
            $row = $this->row($id);
            self::assertSame('ERROR', $row['stato']);
            self::assertSame('Payload JSON non valido o mancante', $row['errore']);
            self::assertNull($row['response_json']);
        }
        self::assertResult($r1, 1, 0, 0, 1, true);
        self::assertSame([['ref' => (string)$nullPayload, 'reason' => 'Payload JSON non valido o mancante']], $r1->errorDetails);
        self::assertSame(1, $r2->errors);
    }

    /** P4: buildVociWithAccounting con errori (nessuna tipologia -> nessun codEntrata) -> ERROR, nessuna PUT. */
    public function testAccountingErrorGoesToErrorWithoutGovPayCall(): void
    {
        $id = $this->insertRow('PENDING', self::payload(9, ['idTipoPendenza' => '']));

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertSame([], $this->gp->calls());
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertStringStartsWith('Errore contabilità: Per la voce è necessario inviare', (string)$row['errore']);
        self::assertNull($row['response_json']);
        self::assertResult($r, 1, 0, 0, 1, true);
        self::assertSame([['ref' => (string)$id, 'reason' => 'Errore contabilità']], $r->errorDetails);
    }

    /**
     * P8 + P6 (fix 8b): 4xx con corpo JSON -> ERROR, errore = 'HTTP <code> - <codice>: <descrizione>'
     * (solo campi ProblemJson, mai il corpo intero), response_json = solo categoria/codice/descrizione
     * + idPendenza usato (Task 11: la "riprova" aggiorna la stessa pendenza, mai un id nuovo).
     */
    public function testGovPay4xxJsonGoesToErrorWithStatusAndProblemFieldsOnlyAndOnePut(): void
    {
        $id = $this->insertRow('PENDING', self::payload(10));
        $upstream = ['categoria' => 'RICHIESTA', 'codice' => 'SINTASSI', 'descrizione' => 'Richiesta non valida', 'dettaglio' => 'segreto upstream RSSMRA'];
        $this->queue(422, $upstream);

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertCount(1, $this->gp->calls(), 'nessun retry: una sola PUT');
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame('HTTP 422 - SINTASSI: Richiesta non valida', $row['errore']);
        $idPendenza = substr($this->gp->calls()[0], strlen('PUT ' . self::P));
        self::assertSame(['categoria' => 'RICHIESTA', 'codice' => 'SINTASSI', 'descrizione' => 'Richiesta non valida', 'idPendenza' => $idPendenza], $this->json($row['response_json']));
        self::assertStringNotContainsString('segreto', (string)$row['errore'] . (string)$row['response_json']);
        self::assertSame([], $this->gp->notifications);
        self::assertResult($r, 1, 0, 0, 1, true);
        self::assertSame([['ref' => (string)$id, 'reason' => 'HTTP 4xx']], $r->errorDetails);
        self::assertStringNotContainsString('segreto', json_encode($r->errorDetails));
    }

    /**
     * P1 (fix 8b): 4xx con corpo NON JSON (es. pagina HTML di un proxy) -> ERROR con 'HTTP <code>'
     * (mai il corpo), response_json = solo l'idPendenza usato, nessuna eccezione, una sola PUT, nessuna notifica.
     */
    public function testGovPay4xxNonJsonBodyGoesToErrorWithStatusOnly(): void
    {
        $id = $this->insertRow('PENDING', self::payload(11));
        $this->queue(400, '<html>Bad Request segreto</html>');

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertCount(1, $this->gp->calls(), 'una sola PUT');
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame('HTTP 400', $row['errore']);
        self::assertSame(['idPendenza' => substr($this->gp->calls()[0], strlen('PUT ' . self::P))], $this->json($row['response_json']));
        self::assertSame([], $this->gp->notifications);
        self::assertResult($r, 1, 0, 0, 1, true);
        self::assertSame([['ref' => (string)$id, 'reason' => 'HTTP 400']], $r->errorDetails);
    }

    /** 5xx + P6 (fix 8b): ERROR con 'HTTP 500 - <codice>: <descrizione>' (mai URL/corpo), response_json = solo idPendenza, nessun retry (percorso legacy). */
    public function testGovPay5xxGoesToErrorWithStatusAndProblemFieldsAndOnePut(): void
    {
        $id = $this->insertRow('PENDING', self::payload(12));
        $this->queue(500, ['categoria' => 'INTERNO', 'codice' => 'ERR_500', 'descrizione' => str_repeat('x', 400), 'dettaglio' => 'segreto upstream']);

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertCount(1, $this->gp->calls());
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertStringStartsWith('HTTP 500 - ERR_500: xxx', (string)$row['errore']);
        self::assertSame(255, mb_strlen((string)$row['errore']), 'troncato a 255');
        self::assertStringNotContainsString('segreto', (string)$row['errore']);
        self::assertStringNotContainsString(self::BASE, (string)$row['errore']);
        self::assertSame(['idPendenza' => substr($this->gp->calls()[0], strlen('PUT ' . self::P))], $this->json($row['response_json']));
        self::assertSame([], $this->gp->notifications);
        self::assertSame([['ref' => (string)$id, 'reason' => 'HTTP 500']], $r->errorDetails);
    }

    private const ESITO_INCERTO = "Esito incerto: timeout/errore di rete verso GovPay (cURL %s) \u{2014} verificare prima di ripetere";

    /**
     * Fix round 1 (P5+P6): errore di trasporto senza risposta HTTP (la PUT puo' aver raggiunto
     * GovPay) -> 'Esito incerto' con errno cURL (mai messaggio/URL), idPendenza salvato sulla riga.
     */
    public function testNetworkErrorIsEsitoIncertoAndStoresIdPendenza(): void
    {
        $id = $this->insertRow('PENDING', self::payload(13));
        $this->gp->mock->append(new ConnectException('cURL error 7: connessione rifiutata https://x/?q=segreto', new Request('PUT', self::BASE), null, ['errno' => 7]));

        $r = $this->processor()->processRow($id, $this->ctx);

        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame(sprintf(self::ESITO_INCERTO, '7'), $row['errore']);
        $stored = $this->json($row['response_json']);
        self::assertMatchesRegularExpression('/^GIL-[0-9a-f]{16}$/', (string)($stored['idPendenza'] ?? ''));
        self::assertSame('PUT ' . self::P . $stored['idPendenza'], $this->gp->calls()[0]);
        self::assertSame([['ref' => (string)$id, 'reason' => 'Esito incerto: errore di rete (cURL 7)']], $r->errorDetails);
    }

    /**
     * Fix round 1: create lenta (cURL 28) -> esito incerto, 1 PUT, id salvato; la riga rimessa in
     * PENDING ("riprova") fa la PUT sullo STESSO idPendenza (update idempotente, nessun duplicato).
     */
    public function testTimeoutThenRetryReusesStoredIdPendenza(): void
    {
        $id = $this->insertRow('PENDING', self::payload(17));
        $this->gp->mock->append(new ConnectException('cURL error 28: Operation timed out', new Request('PUT', self::BASE), null, ['errno' => 28]));

        $this->processor()->processRow($id, $this->ctx);

        self::assertCount(1, $this->gp->calls(), 'esattamente 1 PUT');
        $row = $this->row($id);
        self::assertSame('ERROR', $row['stato']);
        self::assertSame(sprintf(self::ESITO_INCERTO, '28'), $row['errore']);
        $storedId = (string)($this->json($row['response_json'])['idPendenza'] ?? '');
        self::assertNotSame('', $storedId);
        self::assertSame([], $this->gp->notifications);

        // riprova: la riga torna PENDING
        self::pdo()->prepare("UPDATE pendenze_massive SET stato = 'PENDING' WHERE id = ?")->execute([$id]);
        $this->queue(201, ['numeroAvviso' => '301000000000000170']);
        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertSame(['PUT ' . self::P . $storedId, 'PUT ' . self::P . $storedId], $this->gp->calls(), 'stesso idPendenza');
        $row = $this->row($id);
        self::assertSame('SUCCESS', $row['stato']);
        self::assertNull($row['errore']);
        self::assertSame($storedId, $this->json($row['response_json'])['idPendenza']);
        self::assertCount(1, $this->gp->notifications, 'una sola creazione registrata');
        self::assertSame($storedId, $this->gp->notifications[0]['idPendenza']);
        self::assertSame(1, $r->ok);
    }

    /** Fix round 1: IUV vincolato -> il retry riusa anche il numeroAvviso, senza nuovi GET di verifica candidati. */
    public function testTimeoutThenRetryVincolatoReusesIdAndNumeroAvviso(): void
    {
        self::pdo()->exec("INSERT INTO entrate_tipologie (id_dominio, id_entrata, iuv_prefix, updated_at, created_at) VALUES ('" . self::DOMINIO . "', 'AFFITTO', '07', NOW(), NOW())");
        $id = $this->insertRow('PENDING', self::payload(18, ['idTipoPendenza' => 'AFFITTO']));
        $this->queue(404, ['categoria' => 'OPERAZIONE']);
        $this->gp->mock->append(new ConnectException('cURL error 28: timeout', new Request('PUT', self::BASE), null, ['errno' => 28]));

        $this->processor()->processRow($id, $this->ctx);
        $candidate = substr($this->gp->calls()[0], strlen('GET ' . self::P));
        $stored = $this->json($this->row($id)['response_json']);
        self::assertSame(['idPendenza' => $candidate, 'numeroAvviso' => $candidate], $stored);

        self::pdo()->prepare("UPDATE pendenze_massive SET stato = 'PENDING' WHERE id = ?")->execute([$id]);
        $this->queue(201, []);
        $this->processor()->processRow($id, $this->ctx);

        $calls = $this->gp->calls();
        self::assertSame(['GET ' . self::P . $candidate, 'PUT ' . self::P . $candidate, 'PUT ' . self::P . $candidate], $calls);
        self::assertSame($candidate, $this->gp->body(2)['numeroAvviso']);
        self::assertSame('SUCCESS', $this->row($id)['stato']);
    }

    /** Fix round 1: la PUT di creazione porta timeout=90 (override per richiesta, factory invariata). */
    public function testCreatePutCarriesTimeout90(): void
    {
        $id = $this->insertRow('PENDING', self::payload(19));
        $this->queue(201, []);

        $this->processor()->processRow($id, $this->ctx);

        self::assertSame(90, $this->gp->requests[0]['options']['timeout'] ?? null);
    }

    /**
     * P5 (fix 8b): la PUT di creazione passa da GovPayClientFactory (Connection: close, retry di rete).
     * Nota: con Guzzle 7 cURL 35/28 arrivano come ConnectException (non RequestException) e il
     * decider della factory oggi le scarta; qui si esercita il retry con cURL 56 (RequestException).
     * PUT /pendenze/{idA2A}/{idPendenza} e' idempotente (spec GovPay addPendenza: stesso id -> update),
     * quindi il retry di rete riusa lo STESSO idPendenza: una sola creazione registrata, una notifica.
     */
    public function testCreatePutUsesGovPayClientFactoryWithNetworkRetry(): void
    {
        $cb = dirname(__DIR__, 3) . '/backoffice/storage/tmp/govpay_circuit_breaker.json';
        @unlink($cb);
        $id = $this->insertRow('PENDING', self::payload(16));
        $this->gp->mock->append(new RequestException('cURL error 56: Connection reset by peer', new Request('PUT', self::BASE), null, null, ['errno' => 56]));
        $this->queue(201, ['numeroAvviso' => '301000000000000160']);

        $r = $this->processor()->processRow($id, $this->ctx);

        $calls = $this->gp->calls();
        self::assertCount(2, $calls, 'un tentativo fallito (cURL 56) + un retry');
        self::assertSame($calls[0], $calls[1], 'retry sullo stesso idPendenza');
        self::assertStringStartsWith('PUT ' . self::P . 'GIL-', $calls[0]);
        self::assertSame('close', $this->gp->requests[1]['request']->getHeaderLine('Connection'));
        $row = $this->row($id);
        self::assertSame('SUCCESS', $row['stato']);
        self::assertSame(1, $r->ok);
        self::assertCount(1, $this->gp->notifications, 'una sola creazione registrata/notificata');
        self::assertFileDoesNotExist($cb);
    }

    /**
     * Fix round 1: la creazione UI/frontoffice (sendPendenzaToBackoffice, stesso metodo della PUT
     * massiva) passa dallo stack reale della factory: un 4xx GovPay e' un fallimento CON dettaglio
     * (errori non vuoti, httpStatus, campi ProblemJson). Senza http_errors nella factory tornava
     * success=false con errors=[] (messaggio vuoto in UI/frontoffice; il percorso rate, che conta
     * solo gli errori, lo prendeva per un successo).
     */
    public function testUiCreate4xxIsReportedAsFailureWithDetail(): void
    {
        $this->queue(422, ['categoria' => 'RICHIESTA', 'codice' => 'SEMANTICA', 'descrizione' => 'Pendenza non valida']);

        $res = $this->gp->sendPendenzaToBackoffice(self::payload(30), 'GIL-UI-30');

        self::assertSame(['PUT ' . self::P . 'GIL-UI-30'], $this->gp->calls(), 'una sola PUT, nessun retry');
        self::assertFalse($res['success']);
        self::assertNotSame([], $res['errors']);
        self::assertNotSame('', implode('; ', $res['errors']), 'il frontoffice mostra implode(errors)');
        self::assertSame(422, $res['httpStatus'] ?? null);
        self::assertSame('SEMANTICA', $res['problem']['codice'] ?? null);
        self::assertArrayNotHasKey('esitoIncerto', $res);
    }

    /** Fix round 1: 5xx sulla creazione UI -> fallimento con httpStatus, nessun esito incerto (GovPay ha risposto). */
    public function testUiCreate5xxIsReportedAsFailureWithStatus(): void
    {
        $this->queue(503, ['categoria' => 'INTERNO', 'codice' => 'ERR_503', 'descrizione' => 'Servizio non disponibile']);

        $res = $this->gp->sendPendenzaToBackoffice(self::payload(31), 'GIL-UI-31');

        self::assertCount(1, $this->gp->calls());
        self::assertFalse($res['success']);
        self::assertNotSame([], $res['errors']);
        self::assertSame(503, $res['httpStatus'] ?? null);
        self::assertArrayNotHasKey('esitoIncerto', $res);
    }

    /**
     * Fix round 2 (B1): un 3xx sulla PUT di creazione/aggiornamento non e' seguito ed e' un
     * fallimento CON dettaglio ('HTTP 302', httpStatus): mai errors=[] (che il percorso rate e
     * fallbackFullPutUpdate/addNotificationToPendenza prenderebbero per un successo).
     */
    public function testUiCreate3xxIsNotFollowedAndIsAFailureWithDetail(): void
    {
        $this->gp->mock->append(new Response(302, ['Location' => self::BASE . '/pendenze/' . self::A2A . '/GIL-UI-32']));
        $this->gp->mock->append(new Response(200, ['Content-Type' => 'application/json'], '{}'));

        $res = $this->gp->sendPendenzaToBackoffice(self::payload(32), 'GIL-UI-32');

        self::assertSame(['PUT ' . self::P . 'GIL-UI-32'], $this->gp->calls(), 'redirect non seguito');
        self::assertFalse($res['success']);
        self::assertSame(['HTTP 302'], $res['errors']);
        self::assertSame(302, $res['httpStatus'] ?? null);
    }

    /** Fix round 2 (B1): download avviso/ricevuta operatore con 3xx GovPay -> errore 502, mai un "PDF" vuoto, 1 sola GET. */
    public function testOperatorPdfDownload3xxIsAnErrorNotAnAttachment(): void
    {
        $this->gp->mock->append(new Response(302, ['Location' => 'https://altro.example.test/a.pdf']));
        $this->gp->mock->append(new Response(301, ['Location' => 'https://altro.example.test/r.pdf']));

        $a = $this->gp->downloadAvviso(self::request(), new \Slim\Psr7\Response(), ['idDominio' => self::DOMINIO, 'numeroAvviso' => '301000000000000001']);
        $r = $this->gp->downloadRicevuta(self::request(), new \Slim\Psr7\Response(), ['idDominio' => self::DOMINIO, 'iuv' => 'IUV1', 'ccp' => 'CCP1']);

        self::assertSame([502, 'Errore scaricamento avviso: HTTP 302'], [$a->getStatusCode(), (string)$a->getBody()]);
        self::assertSame([502, 'Errore scaricamento ricevuta: HTTP 301'], [$r->getStatusCode(), (string)$r->getBody()]);
        self::assertSame('', $a->getHeaderLine('Content-Disposition'));
        self::assertCount(2, $this->gp->calls(), 'nessun redirect seguito');
    }

    /**
     * Fix round 2 (B2, ruling: comportamento cittadino di sempre): GovPay checkout che risponde con
     * un HTTP non-201 (4xx o 5xx, come su main 25f91fb) -> RuntimeException "Risposta inattesa",
     * che il frontoffice API mappa a 404 "non configurato" (fallback a pagoPA standard). Un errore
     * di rete resta un'eccezione di trasporto (503 lato API, come su main).
     */
    public function testGovPayCheckoutHttpErrorIsRuntimeExceptionLikeMain(): void
    {
        SettingsRepository::set('govpay', 'checkout_url', 'https://checkout.example.test/api');
        foreach ([400, 422, 500, 503] as $code) {
            $this->gp->mock->append(new Response($code, ['Content-Type' => 'application/json'], '{"descrizione":"segreto"}'));
            try {
                $this->gp->initiateGovPayCheckout('GIL-B1', 'https://fo.example.test/ret');
                self::fail("HTTP {$code} deve lanciare");
            } catch (\RuntimeException $e) {
                self::assertSame('Risposta inattesa da GovPay checkout: HTTP ' . $code, $e->getMessage());
            }
        }
        $this->gp->mock->append(new ConnectException('cURL error 7', new Request('POST', 'https://checkout.example.test/api/pagamenti')));
        try {
            $this->gp->initiateGovPayCheckout('GIL-B1', 'https://fo.example.test/ret');
            self::fail('errore di rete deve lanciare');
        } catch (ConnectException) {
            self::assertTrue(true);
        }
        self::assertCount(5, $this->gp->calls());
    }

    /** Fix round 2 (B2): lato API frontoffice ogni errore GovPay checkout -> 404 "non configurato" (fallback pagoPA), come su main. */
    public function testFrontofficeBolloGovPayCheckoutErrorsMapTo404LikeMain(): void
    {
        SettingsRepository::set('govpay', 'checkout_url', 'https://checkout.example.test/api');
        $gp = $this->gp;
        $api = new class (\Slim\Views\Twig::create(dirname(__DIR__, 3) . '/backoffice/templates'), $gp) extends \App\Controllers\FrontofficeApiController {
            public function __construct(\Slim\Views\Twig $twig, private readonly \App\Controllers\PendenzeController $pc)
            {
                parent::__construct($twig);
            }

            protected function pendenzeController(): \App\Controllers\PendenzeController
            {
                return $this->pc;
            }
        };
        $this->gp->mock->append(new Response(422, [], '{"descrizione":"segreto"}'));
        $this->gp->mock->append(new Response(503, [], '{}'));
        $this->gp->mock->append(new ConnectException('cURL error 7', new Request('POST', 'https://checkout.example.test/api/pagamenti')));
        $this->gp->mock->append(new Response(201, ['Content-Type' => 'application/json'], '{"redirect":"https://pay.example.test/go"}'));

        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $req = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/frontoffice/bollo/govpay-checkout');
            $req->getBody()->write('{"idPendenza":"GIL-B1","returnUrl":"https://fo.example.test/ret"}');
            $r = $api->bolloGovpayCheckout($req, new \Slim\Psr7\Response());
            $out[] = [$r->getHeaderLine('X-App-Error-Status'), json_decode((string)$r->getBody(), true)['message'] ?? null];
        }

        self::assertSame([
            ['404', 'GovPay checkout non configurato'],
            ['404', 'GovPay checkout non configurato'],
            ['404', 'GovPay checkout non configurato'],
            ['', 'Checkout GovPay avviato'],
        ], $out);
    }

    private static function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/x');
    }

    public function testMissingBackofficeUrlGoesToErrorWithoutCall(): void
    {
        SettingsRepository::set('govpay', 'backoffice_url', '');
        $id = $this->insertRow('PENDING', self::payload(14));

        $this->processor()->processRow($id, $this->ctx);

        self::assertSame([], $this->gp->calls());
        self::assertSame('ERROR', $this->row($id)['stato']);
        self::assertSame('GOVPAY_BACKOFFICE_URL non impostata', $this->row($id)['errore']);
    }

    public function testNotificationExceptionIsSwallowedAndRowStaysSuccess(): void
    {
        $id = $this->insertRow('PENDING', self::payload(15));
        $this->queue(201, ['numeroAvviso' => '301000000000000157']);
        $this->gp->notificationThrows = new \RuntimeException('smtp giu');

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertSame('SUCCESS', $this->row($id)['stato']);
        self::assertSame(1, $r->ok);
        self::assertContains('    Errore invio notifiche: smtp giu', $this->ctx->messages);
    }

    // ============================================================ ANNULLAMENTO (CANCEL_PENDING)

    /** P11: stato NON_ESEGUITA -> GET stato, GET+PUT (storico) via fallbackFullPutUpdate, PATCH ANNULLATA -> CANCELLED. */
    public function testCancelNonEseguitaDoesFullPutThenPatchAndMarksCancelled(): void
    {
        $id = $this->insertRow('CANCEL_PENDING', self::payload(20), ['idPendenza' => 'GIL-abc', 'numeroAvviso' => '301'], 'errore precedente');
        $pendenza = ['idPendenza' => 'GIL-abc', 'stato' => 'NON_ESEGUITA', 'idDominio' => self::DOMINIO, 'causale' => 'C', 'importo' => 1.0, 'voci' => []];
        $this->queue(200, $pendenza); // fetchPendenzaById
        $this->queue(200, $pendenza); // fallbackFullPutUpdate GET
        $this->queue(200, []);        // fallbackFullPutUpdate PUT
        $this->queue(200, null);      // PATCH stato

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertSame([
            'GET ' . self::P . 'GIL-abc',
            'GET ' . self::P . 'GIL-abc',
            'PUT ' . self::P . 'GIL-abc',
            'PATCH ' . self::P . 'GIL-abc',
        ], $this->gp->calls());
        $storico = $this->gp->body(2)['datiAllegati']['storico'] ?? [];
        self::assertCount(1, $storico);
        self::assertSame('Annullamento', $storico[0]['operazione']);
        self::assertSame('backoffice', $storico[0]['operatore']);
        self::assertSame([['op' => 'REPLACE', 'path' => '/stato', 'value' => 'ANNULLATA']], $this->gp->body(3));

        $row = $this->row($id);
        self::assertSame('CANCELLED', $row['stato']);
        self::assertNull($row['errore'], 'errore precedente azzerato');
        self::assertResult($r, 1, 1, 0, 0, true);
        self::assertSame([], $this->gp->notifications);
    }

    public function testCancelPatchFailureRevertsToSuccessWithError(): void
    {
        $id = $this->insertRow('CANCEL_PENDING', self::payload(21), ['idPendenza' => 'GIL-p']);
        $pendenza = ['stato' => 'NON_ESEGUITA', 'idDominio' => self::DOMINIO];
        $this->queue(200, $pendenza);
        $this->queue(200, $pendenza);
        $this->queue(200, []);
        $this->queue(500, 'dettaglio upstream');

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertCount(4, $this->gp->calls());
        $row = $this->row($id);
        self::assertSame('SUCCESS', $row['stato']);
        self::assertSame('Errore API di Annullamento: HTTP 500', $row['errore'], 'P6: mai il corpo upstream');
        self::assertResult($r, 1, 0, 0, 1, true);
        self::assertSame([['ref' => (string)$id, 'reason' => 'Annullamento rifiutato: HTTP 500']], $r->errorDetails);
    }

    /**
     * P3 (fix 8b): se la PUT completa (storico annullamento) fallisce NON si fa la PATCH: la riga va nel
     * percorso di errore degli altri annullamenti falliti (SUCCESS + errore, mai il corpo upstream).
     */
    public function testCancelFailedFullPutDoesNotPatch(): void
    {
        $id = $this->insertRow('CANCEL_PENDING', self::payload(22), ['idPendenza' => 'GIL-q']);
        $pendenza = ['stato' => 'NON_ESEGUITA', 'idDominio' => self::DOMINIO];
        $this->queue(200, $pendenza);
        $this->queue(200, $pendenza);
        $this->queue(422, ['descrizione' => 'put rifiutata segreto']);
        $this->queue(200, null); // PATCH: non deve essere consumata

        $r = $this->processor()->processRow($id, $this->ctx);

        $methods = array_map(static fn(string $c) => strtok($c, ' '), $this->gp->calls());
        self::assertSame(['GET', 'GET', 'PUT'], $methods, 'PUT 1, PATCH 0');
        self::assertSame(1, count(array_filter($methods, static fn($m) => $m === 'PUT')));
        $row = $this->row($id);
        self::assertSame('SUCCESS', $row['stato']);
        self::assertSame("Errore di Annullamento: aggiornamento storico rifiutato (HTTP 422)", $row['errore']);
        self::assertStringNotContainsString('segreto', (string)$row['errore']);
        self::assertResult($r, 1, 0, 0, 1, true);
        self::assertSame([['ref' => (string)$id, 'reason' => 'Annullamento rifiutato: HTTP 422']], $r->errorDetails);
    }

    /** P12 */
    public function testCancelAlreadyAnnullataMarksCancelledWithOnlyOneGet(): void
    {
        $id = $this->insertRow('CANCEL_PENDING', self::payload(23), ['idPendenza' => 'GIL-r']);
        $this->queue(200, ['stato' => 'ANNULLATA']);

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertSame(['GET ' . self::P . 'GIL-r'], $this->gp->calls());
        self::assertSame('CANCELLED', $this->row($id)['stato']);
        self::assertSame(1, $r->ok);
    }

    /** P13: stato diverso (anche assente -> '') -> torna SUCCESS con messaggio, nessuna scrittura su GovPay. */
    public function testCancelOtherStatoRevertsToSuccess(): void
    {
        $a = $this->insertRow('CANCEL_PENDING', self::payload(24), ['idPendenza' => 'GIL-s'], null, 'B1', 1);
        $b = $this->insertRow('CANCEL_PENDING', self::payload(25), ['idPendenza' => 'GIL-t'], null, 'B1', 2);
        $this->queue(200, ['stato' => 'ESEGUITA']);
        $this->queue(200, ['idPendenza' => 'GIL-t']);

        $p = $this->processor();
        $ra = $p->processRow($a, $this->ctx);
        $p->processRow($b, $this->ctx);

        self::assertSame(['GET ' . self::P . 'GIL-s', 'GET ' . self::P . 'GIL-t'], $this->gp->calls());
        self::assertSame('SUCCESS', $this->row($a)['stato']);
        self::assertSame("Impossibile annullare: lo stato attuale su GovPay è 'ESEGUITA'", $this->row($a)['errore']);
        self::assertSame("Impossibile annullare: lo stato attuale su GovPay è ''", $this->row($b)['errore']);
        self::assertSame([['ref' => (string)$a, 'reason' => 'Stato GovPay non annullabile: ESEGUITA']], $ra->errorDetails);
    }

    /** P14: GET pendenza fallita (404) -> SUCCESS + messaggio. */
    public function testCancelFetchFailureRevertsToSuccess(): void
    {
        $id = $this->insertRow('CANCEL_PENDING', self::payload(26), ['idPendenza' => 'GIL-u']);
        $this->queue(404, ['descrizione' => 'non trovata']);

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertCount(1, $this->gp->calls());
        self::assertSame('SUCCESS', $this->row($id)['stato']);
        self::assertSame('Impossibile recuperare lo stato della pendenza da GovPay', $this->row($id)['errore']);
        self::assertSame([['ref' => (string)$id, 'reason' => 'Pendenza non recuperabile da GovPay']], $r->errorDetails);
    }

    /**
     * P9 ordine di risoluzione idPendenza: response.idPendenza > response.pendenza.idPendenza >
     * response.numeroAvviso > payload.idPendenza. Nota: con numeroAvviso nella response si usa
     * direttamente come idPendenza (GET /pendenze/{a2a}/{numeroAvviso}), la ricerca per IUV NON parte.
     */
    public function testCancelIdPendenzaResolutionOrder(): void
    {
        $rows = [
            $this->insertRow('CANCEL_PENDING', ['idPendenza' => 'PAY'], ['idPendenza' => ' RESP ', 'numeroAvviso' => 'NA'], null, 'B1', 1),
            $this->insertRow('CANCEL_PENDING', ['idPendenza' => 'PAY'], ['pendenza' => ['idPendenza' => 'NESTED'], 'numeroAvviso' => 'NA'], null, 'B1', 2),
            $this->insertRow('CANCEL_PENDING', ['idPendenza' => 'PAY'], ['numero_avviso' => 'NA2', 'iuv' => 'IUV'], null, 'B1', 3),
            $this->insertRow('CANCEL_PENDING', ['id_pendenza' => 'PAY2'], ['iuv' => ''], null, 'B1', 4),
        ];
        foreach ($rows as $_) {
            $this->queue(200, ['stato' => 'ANNULLATA']);
        }

        $p = $this->processor();
        foreach ($rows as $id) {
            $p->processRow($id, $this->ctx);
        }

        self::assertSame([
            'GET ' . self::P . 'RESP',
            'GET ' . self::P . 'NESTED',
            'GET ' . self::P . 'NA2',
            'GET ' . self::P . 'PAY2',
        ], $this->gp->calls());
    }

    /** P10: nessun idPendenza/numeroAvviso ma iuv -> ricerca lista per IUV con idDominio della response. */
    public function testCancelFallsBackToIuvSearch(): void
    {
        $id = $this->insertRow('CANCEL_PENDING', ['causale' => 'x'], ['iuv' => 'IUV-1', 'idDominio' => '80000000099']);
        $this->queue(200, ['risultati' => [['idPendenza' => 'TROVATA', 'stato' => 'NON_ESEGUITA']]]);
        $this->queue(200, ['stato' => 'ANNULLATA']);

        $this->processor()->processRow($id, $this->ctx);

        self::assertSame([
            'GET /backoffice/v1/pendenze?idA2A=' . self::A2A . '&idDominio=80000000099&iuv=IUV-1&risultatiPerPagina=1',
            'GET ' . self::P . 'TROVATA',
        ], $this->gp->calls());
        self::assertSame('CANCELLED', $this->row($id)['stato']);
        self::assertContains('  [B1:1] ID-' . $id . ' Trovata via IUV: idPendenza=TROVATA', $this->ctx->messages);
    }

    /** P15: ricerca per IUV vuota, oppure nessun identificativo -> SUCCESS + "ID Pendenza non trovato". */
    public function testCancelUnresolvedIdRevertsToSuccess(): void
    {
        $a = $this->insertRow('CANCEL_PENDING', ['causale' => 'x'], ['iuvPagamento' => 'IUV-2'], null, 'B1', 1);
        $b = $this->insertRow('CANCEL_PENDING', null, null, null, 'B1', 2);
        $this->queue(200, ['risultati' => []]);

        $p = $this->processor();
        $ra = $p->processRow($a, $this->ctx);
        $p->processRow($b, $this->ctx);

        self::assertSame(['GET /backoffice/v1/pendenze?idA2A=' . self::A2A . '&idDominio=' . self::DOMINIO . '&iuv=IUV-2&risultatiPerPagina=1'], $this->gp->calls());
        foreach ([$a, $b] as $id) {
            self::assertSame('SUCCESS', $this->row($id)['stato']);
            self::assertSame('ID Pendenza non trovato (né direttamente né per IUV)', $this->row($id)['errore']);
        }
        self::assertSame([['ref' => (string)$a, 'reason' => 'ID pendenza non risolto']], $ra->errorDetails);
    }

    /** P16: eccezione durante l'annullamento -> SUCCESS + 'Errore di Annullamento: ...'. */
    public function testCancelExceptionRevertsToSuccess(): void
    {
        $id = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'GIL-v']);
        $this->gp->fetchByIdThrows = new \RuntimeException('esplosione');

        $r = $this->processor()->processRow($id, $this->ctx);

        self::assertSame('SUCCESS', $this->row($id)['stato']);
        self::assertSame('Errore di Annullamento: RuntimeException', $this->row($id)['errore'], 'P6: mai il messaggio');
        self::assertSame([['ref' => (string)$id, 'reason' => 'Eccezione RuntimeException']], $r->errorDetails);
    }

    /** setCancelProcessing: la riga passa da CANCEL_PROCESSING durante l'annullamento (visibile a GovPay-time). */
    public function testCancelMarksCancelProcessingBeforeCallingGovPay(): void
    {
        $id = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'GIL-w']);
        $seen = null;
        $this->gp->mock->append(function () use ($id, &$seen) {
            $seen = $this->row($id)['stato'];
            return new Response(200, [], (string)json_encode(['stato' => 'ANNULLATA']));
        });

        $this->processor()->processRow($id, $this->ctx);

        self::assertSame('CANCEL_PROCESSING', $seen);
        self::assertSame('CANCELLED', $this->row($id)['stato']);
    }

    public function testPendingMarksProcessingBeforeCallingGovPay(): void
    {
        $id = $this->insertRow('PENDING', self::payload(30));
        $seen = null;
        $this->gp->mock->append(function () use ($id, &$seen) {
            $seen = $this->row($id)['stato'];
            return new Response(201, [], '{}');
        });

        $this->processor()->processRow($id, $this->ctx);

        self::assertSame('PROCESSING', $seen);
    }

    /**
     * Task 11: l'idPendenza (e il numeroAvviso per IUV vincolato) e' salvato sulla riga PRIMA della
     * PUT: un processo ucciso durante la PUT lascia l'id per il nuovo tentativo.
     */
    public function testIdPendenzaIsStoredOnRowBeforeThePut(): void
    {
        self::pdo()->exec("INSERT INTO entrate_tipologie (id_dominio, id_entrata, iuv_prefix, updated_at, created_at) VALUES ('" . self::DOMINIO . "', 'AFFITTO', '07', NOW(), NOW())");
        $plain = $this->insertRow('PENDING', self::payload(31), null, null, 'B1', 1);
        $vinc = $this->insertRow('PENDING', self::payload(32, ['idTipoPendenza' => 'AFFITTO']), null, null, 'B1', 2);
        $seen = [];
        $this->gp->mock->append(function () use ($plain, &$seen) {
            $seen[] = $this->json($this->row($plain)['response_json']);
            return new Response(201, [], '{}');
        });
        $this->queue(404, []);
        $this->gp->mock->append(function () use ($vinc, &$seen) {
            $seen[] = $this->json($this->row($vinc)['response_json']);
            return new Response(201, [], '{}');
        });

        $this->processor()->processRow($plain, $this->ctx);
        $this->processor()->processRow($vinc, $this->ctx);

        $calls = $this->gp->calls();
        self::assertSame(['idPendenza' => substr($calls[0], strlen('PUT ' . self::P))], $seen[0]);
        $candidate = substr($calls[2], strlen('PUT ' . self::P));
        self::assertSame(['idPendenza' => $candidate, 'numeroAvviso' => $candidate], $seen[1]);
    }

    /**
     * Fix round 1 (Task 11): il percorso legacy marca la riga in modo condizionale. Se tra la
     * rilettura e la marcatura la riga viene presa dal worker (claim), lo script la salta: nessuna
     * chiamata GovPay, claim del worker intatto (mai due creazioni con id diversi).
     */
    public function testLegacyPathSkipsRowClaimedByWorkerAfterReread(): void
    {
        $create = $this->insertRow('PENDING', self::payload(33), null, null, 'B1', 1);
        $cancel = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'GIL-x'], null, 'B1', 2);
        $pdo = self::pdo();
        // findById restituisce lo stato letto, poi il worker prende la riga prima della marcatura
        $repo = new class($pdo) extends MassivePendenzeRepository {
            public function findById(int $id): ?array
            {
                $row = parent::findById($id);
                (new \ReflectionProperty(MassivePendenzeRepository::class, 'pdo'))->getValue($this)
                    ->prepare("UPDATE pendenze_massive SET stato = IF(stato = 'CANCEL_PENDING', 'CANCEL_PROCESSING', 'PROCESSING'), claimed_at = NOW(), claim_token = 'worker' WHERE id = ?")
                    ->execute([$id]);
                return $row;
            }
        };
        $p = new MassivePendenzeProcessor($this->gp, $repo);

        self::assertResult($p->processRow($create, $this->ctx), 0, 0, 1, 0, false);
        self::assertResult($p->processRow($cancel, $this->ctx), 0, 0, 1, 0, false);

        self::assertSame([], $this->gp->calls());
        self::assertSame(['PROCESSING', 'worker', null], [$this->row($create)['stato'], $this->row($create)['claim_token'], $this->row($create)['response_json']]);
        self::assertSame(['CANCEL_PROCESSING', 'worker'], [$this->row($cancel)['stato'], $this->row($cancel)['claim_token']]);
    }

    // ============================================================ processRow: stati non lavorabili

    /**
     * processRow rilegge la riga e lavora solo PENDING / CANCEL_PENDING; ogni altro stato (o id
     * inesistente) e' un no-op senza chiamate GovPay (protegge da doppia creazione su riconsegna).
     */
    public function testProcessRowIgnoresRowsNotInWorkableState(): void
    {
        $ids = [];
        foreach (['PROCESSING', 'SUCCESS', 'ERROR', 'PAUSED', 'CANCELLED', 'CANCEL_PROCESSING'] as $i => $stato) {
            $ids[$stato] = $this->insertRow($stato, self::payload(40 + $i), ['idPendenza' => 'X'], null, 'B1', $i + 1);
        }

        $p = $this->processor();
        foreach ($ids as $stato => $id) {
            $r = $p->processRow($id, $this->ctx);
            self::assertResult($r, 0, 0, 1, 0, false);
            self::assertSame($stato, $this->row($id)['stato']);
        }
        self::assertResult($p->processRow(999999, $this->ctx), 0, 0, 1, 0, false);
        self::assertSame([], $this->gp->calls());
    }

    // ============================================================ claimBatch / runOnce

    public function testClaimBatchReturnsCancelPendingFirstAndDoesNotChangeState(): void
    {
        $pending = $this->insertRow('PENDING', self::payload(50), null, null, 'B1', 1);
        $c1 = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'A'], null, 'B1', 2);
        $c2 = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'B'], null, 'B1', 3);
        foreach (['PROCESSING', 'SUCCESS', 'ERROR', 'PAUSED', 'CANCELLED', 'CANCEL_PROCESSING'] as $i => $stato) {
            $this->insertRow($stato, self::payload(60 + $i), null, null, 'B2', $i + 1);
        }

        $p = $this->processor();
        self::assertSame([$c1, $c2], $p->claimBatch());
        self::assertSame([$c1], $p->claimBatch(1));
        // Nessuna marcatura alla selezione (come oggi): restano CANCEL_PENDING / PENDING
        self::assertSame('CANCEL_PENDING', $this->row($c1)['stato']);
        self::assertSame('PENDING', $this->row($pending)['stato']);

        self::pdo()->exec("UPDATE pendenze_massive SET stato='CANCELLED' WHERE stato='CANCEL_PENDING'");
        self::assertSame([$pending], $p->claimBatch());
        self::assertSame('PENDING', $this->row($pending)['stato']);
    }

    public function testRunOnceProcessesAtMost50PendingRowsInIdOrder(): void
    {
        $ids = [];
        for ($i = 1; $i <= 51; $i++) {
            $ids[] = $this->insertRow('PENDING', self::payload($i), null, null, 'B1', $i);
            $this->queue(201, ['numeroAvviso' => sprintf('3%017d', $i)]);
        }

        $r = $this->processor()->runOnce($this->ctx);

        self::assertResult($r, 50, 50, 0, 0, true);
        self::assertCount(50, $this->gp->calls(), 'una PUT per riga, nessun doppio invio');
        self::assertCount(50, $this->gp->notifications);
        self::assertSame('PENDING', $this->row($ids[50])['stato'], 'la 51esima resta in coda');
        self::assertSame('SUCCESS', $this->row($ids[49])['stato']);
        self::assertSame('Elaboro batch di 50 pendenze...', $this->ctx->messages[0]);
        self::assertSame(1, count($this->gp->mock), 'resta in coda la sola risposta della 51esima');
    }

    public function testRunOnceHandlesCancelsBeforePendingInSeparateIterations(): void
    {
        $pending = $this->insertRow('PENDING', self::payload(70), null, null, 'B1', 1);
        $cancel = $this->insertRow('CANCEL_PENDING', null, ['idPendenza' => 'GIL-z'], null, 'B1', 2);
        $this->queue(200, ['stato' => 'ANNULLATA']);

        $p = $this->processor();
        $r1 = $p->runOnce($this->ctx);

        self::assertResult($r1, 1, 1, 0, 0, true);
        self::assertSame(['GET ' . self::P . 'GIL-z'], $this->gp->calls());
        self::assertSame('PENDING', $this->row($pending)['stato'], 'le PENDING aspettano la prossima iterazione');
        self::assertSame('CANCELLED', $this->row($cancel)['stato']);
        self::assertSame('Elaboro batch di 1 annullamenti...', $this->ctx->messages[0]);

        $this->queue(201, []);
        $r2 = $p->runOnce($this->ctx);
        self::assertResult($r2, 1, 1, 0, 0, true);
        self::assertSame('SUCCESS', $this->row($pending)['stato']);
        self::assertCount(2, $this->gp->calls());
    }

    public function testRunOnceAggregatesMixedOutcomes(): void
    {
        $ok = $this->insertRow('PENDING', self::payload(80), null, null, 'B1', 1);
        $bad = $this->insertRow('PENDING', null, null, null, 'B1', 2);
        $rej = $this->insertRow('PENDING', self::payload(82), null, null, 'B1', 3);
        $this->queue(201, []);
        $this->queue(422, ['descrizione' => 'no']);

        $r = $this->processor()->runOnce($this->ctx);

        self::assertResult($r, 3, 1, 0, 2, true);
        self::assertSame([
            ['ref' => (string)$bad, 'reason' => 'Payload JSON non valido o mancante'],
            ['ref' => (string)$rej, 'reason' => 'HTTP 4xx'],
        ], $r->errorDetails);
        self::assertSame('SUCCESS', $this->row($ok)['stato']);
    }

    public function testRunOnceWithEmptyQueueReportsNoWork(): void
    {
        $this->insertRow('SUCCESS', self::payload(90));

        $r = $this->processor()->runOnce($this->ctx);

        self::assertResult($r, 0, 0, 0, 0, false);
        self::assertSame([], $this->gp->calls());
        self::assertSame(['Nessuna pendenza PENDING o CANCEL_PENDING. Attendo 900s...'], $this->ctx->messages);
    }

    /** Come oggi: lo stop non interrompe un batch gia' avviato (il demone lo verifica solo tra un ciclo e l'altro). */
    public function testRunOnceDoesNotCheckStopBetweenRows(): void
    {
        $this->insertRow('PENDING', self::payload(91), null, null, 'B1', 1);
        $this->insertRow('PENDING', self::payload(92), null, null, 'B1', 2);
        $this->queue(201, []);
        $this->queue(201, []);
        $this->ctx->stop = true;

        $r = $this->processor()->runOnce($this->ctx);

        self::assertSame(2, $r->ok);
        self::assertCount(2, $this->gp->calls());
    }
}
