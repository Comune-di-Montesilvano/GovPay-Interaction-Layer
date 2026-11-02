<?php
declare(strict_types=1);

namespace Tests\Services\Jobs;

use App\Auth\UserRepository;
use App\Config\SettingsRepository;
use App\Database\RendicontazioneRepository;
use App\Database\UserGroupRepository;
use App\Services\GovPayClientFactory;
use App\Services\Jobs\RendicontazioneJob;
use App\Services\LegacyRendicontazioneBridgeClient;
use App\Services\MailerService;
use App\Services\RendicontazioneEngineService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use Tests\Support\DbTestCase;
use Tests\Support\FakeGovPayPendenzeController;
use Tests\Support\RecordingJobContext;
use Tests\Support\Synthetic;

/**
 * Caratterizzazione di una iterazione del demone rendicontazione GovPay (RendicontazioneJob::runOnce).
 * GovPay e ponte legacy simulati al confine HTTP (Guzzle); mailer sostituito da uno stub che registra
 * le chiamate (nessuna mail reale); DB MariaDB reale usa-e-getta. Le pendenze di default non hanno
 * soggetto persona fisica ne' email: App IO / email delle righe risultano NON_APPLICABILE senza rete.
 *
 * Asserzioni sui conteggi esatti delle chiamate esterne (bridge, incassi GovPay, digest) per
 * intercettare duplicati.
 */
#[Group('db')]
final class RendicontazioneJobTest extends DbTestCase
{
    protected static array $tables = [
        'flussi_rendicontazioni', 'biz_ricevute', 'settings', 'rendicontazione_digest_log',
        'rendicontazione_gruppo_tipologie', 'rendicontazione_regole_esterne',
        'user_group_members', 'user_groups', 'users',
    ];

    private const DOMINIO = '80000000001';
    private const A2A = 'GIL-TEST';

    /** @var list<array{kind:string,method:string,path:string,query:string,body:string}> chiamate GovPay */
    private array $calls = [];

    /** @var list<array<string,mixed>> payload inviati al ponte legacy */
    private array $bridgeCalls = [];

    /** @var array<string,mixed> idPendenza => array|Response|\Throwable */
    private array $pendenze = [];

    /** @var Response|\Throwable|null risposta di GET /flussiRendicontazione (null = lista vuota) */
    private $flussoResponse = null;

    /** @var Response|\Throwable risposta di POST /incassi */
    private $incassoResponse;

    /** @var list<Response|\Throwable> coda risposte del ponte (ultima ripetuta) */
    private array $bridgeResponses = [];

    /** @var list<array{emails:array,gruppo:string,daConfermare:array,informative:array,url:string}> */
    private array $opCalls = [];

    /** @var list<array{destinatari:array,righe:array}> */
    private array $adminCalls = [];

    /** @var list<array<string,mixed>|\Throwable> */
    private array $opResults = [];

    /** @var list<array<string,mixed>|\Throwable> */
    private array $adminResults = [];

    /** @var list<array{emails:array,gruppo:string,righe:array,url:string,giorni:int}> */
    private array $solCalls = [];

    /** @var list<array<string,mixed>|\Throwable> */
    private array $solResults = [];

    private MailerService $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        SettingsRepository::set('govpay', 'backoffice_url', 'https://govpay.example.test/backend/');
        SettingsRepository::set('rendicontazione', 'iuv_prefix_gil', 'GIL');
        SettingsRepository::flush();

        $this->calls = [];
        $this->bridgeCalls = [];
        $this->pendenze = [];
        $this->flussoResponse = null;
        $this->incassoResponse = new Response(200, [], '{}');
        $this->bridgeResponses = [new Response(200, [], json_encode(['esito' => true, 'messaggio' => 'registrato']))];
        $this->opCalls = [];
        $this->adminCalls = [];
        $this->opResults = [];
        $this->adminResults = [];
        $this->solCalls = [];
        $this->solResults = [];

        $this->mailer = $this->createStub(MailerService::class);
        $this->mailer->method('sendRendicontazioneSollecitoSmarcatura')->willReturnCallback(
            function (array $emails, string $gruppo, array $righe, string $url, int $giorni): array {
                $this->solCalls[] = ['emails' => $emails, 'gruppo' => $gruppo, 'righe' => $righe, 'url' => $url, 'giorni' => $giorni];
                $r = array_shift($this->solResults) ?? ['esito' => 'OK'];
                if ($r instanceof \Throwable) {
                    throw $r;
                }
                return $r;
            }
        );
        $this->mailer->method('sendRendicontazioneOperatoreDigest')->willReturnCallback(
            function (array $emails, string $gruppo, array $daConf, array $info, string $url): array {
                $this->opCalls[] = ['emails' => $emails, 'gruppo' => $gruppo, 'daConfermare' => $daConf, 'informative' => $info, 'url' => $url];
                $r = array_shift($this->opResults) ?? ['esito' => 'OK'];
                if ($r instanceof \Throwable) {
                    throw $r;
                }
                return $r;
            }
        );
        $this->mailer->method('sendRendicontazioneAdminDigest')->willReturnCallback(
            function (array $dest, array $righe): array {
                $this->adminCalls[] = ['destinatari' => $dest, 'righe' => $righe];
                $r = array_shift($this->adminResults) ?? ['esito' => 'OK'];
                if ($r instanceof \Throwable) {
                    throw $r;
                }
                return $r;
            }
        );
    }

    // ---------------------------------------------------------------- helpers

    private function setting(string $key, string $value): void
    {
        SettingsRepository::set('rendicontazione', $key, $value);
        SettingsRepository::flush();
    }

    private function govPayClient(): Client
    {
        $handler = function (RequestInterface $req, array $opts) {
            $path = $req->getUri()->getPath();
            $kind = 'altro';
            if ($req->getMethod() === 'GET' && preg_match('#/pendenze/[^/]+/([^/]+)$#', $path, $m) === 1) {
                $kind = 'pendenza';
                $id = rawurldecode($m[1]);
                $resp = $this->pendenze[$id] ?? ['tipoPendenza' => ['idTipoPendenza' => 'TIPO'], 'importo' => 10.5, 'dataPagamento' => '2026-01-01'];
            } elseif ($req->getMethod() === 'GET' && str_contains($path, '/flussiRendicontazione')) {
                $kind = 'flusso';
                $resp = $this->flussoResponse ?? new Response(200, [], '{"risultati":[]}');
            } elseif ($req->getMethod() === 'POST' && str_contains($path, '/incassi/')) {
                $kind = 'incasso';
                $resp = $this->incassoResponse;
            } elseif ($req->getMethod() === 'GET' && str_contains($path, '/incassi/')) {
                // lista incassi GovPay: 200 (cio' che un redirect POST->GET otterrebbe)
                $kind = 'incasso-get';
                $resp = new Response(200, ['Content-Type' => 'application/json'], '{"risultati":[]}');
            } else {
                throw new \LogicException('Richiesta GovPay inattesa: ' . $req->getMethod() . ' ' . $path);
            }
            $this->calls[] = [
                'kind' => $kind, 'method' => $req->getMethod(), 'path' => $path,
                'query' => $req->getUri()->getQuery(), 'body' => (string)$req->getBody(),
            ];
            if ($resp instanceof \Throwable) {
                throw $resp;
            }
            if (is_array($resp)) {
                $resp = new Response(200, ['Content-Type' => 'application/json'], json_encode($resp, JSON_THROW_ON_ERROR));
            }
            return Create::promiseFor($resp);
        };
        // stack reale della factory (http_errors, retry, circuit breaker): solo l'handler finale e' finto
        @unlink(FakeGovPayPendenzeController::circuitBreakerFile());
        return GovPayClientFactory::makeBackofficeClient(['handler' => $handler]);
    }

    private function bridge(): LegacyRendicontazioneBridgeClient
    {
        $handler = function (RequestInterface $req, array $opts) {
            $this->bridgeCalls[] = json_decode((string)$req->getBody(), true);
            $r = count($this->bridgeResponses) > 1 ? array_shift($this->bridgeResponses) : $this->bridgeResponses[0];
            if ($r instanceof \Throwable) {
                throw $r;
            }
            return Create::promiseFor($r);
        };
        return new LegacyRendicontazioneBridgeClient(new Client(['handler' => HandlerStack::create($handler)]));
    }

    private function job(?callable $engineFactory = null): RendicontazioneJob
    {
        $repo = new RendicontazioneRepository(self::pdo());
        $client = $this->govPayClient();
        $bridge = $this->bridge();
        $engineFactory ??= static fn(): RendicontazioneEngineService => new RendicontazioneEngineService($repo, $bridge, $client);
        return new RendicontazioneJob(
            $repo,
            $engineFactory,
            new UserGroupRepository(),
            new UserRepository(),
            $this->mailer,
            self::DOMINIO,
            self::A2A,
            self::pdo()
        );
    }

    private static function today(int $minusDays = 0): string
    {
        return date('Y-m-d', strtotime("-{$minusDays} days"));
    }

    /**
     * Inserisce una riga flussi_rendicontazioni (default: govpay, PENDING, oggi, gia' regolarizzata
     * cosi' il ciclo non genera chiamate di regolarizzazione se non richieste).
     *
     * @param array<string,mixed> $o
     */
    private function riga(int $n, array $o = []): int
    {
        $o += [
            'id_dominio' => self::DOMINIO,
            'id_flusso' => 'FLUSSO-' . $n,
            'anno' => 2026, 'mese' => 10,
            'iur' => Synthetic::iur($n),
            'iuv' => Synthetic::iuv('0', $n),
            'importo' => 10.50,
            'data_pagamento' => self::today(),
            'cod_entrata' => 'TARI',
            'id_pendenza' => 'GIL' . str_pad((string)$n, 17, '0', STR_PAD_LEFT),
            'is_govpay' => 1,
            'rendicontazione_stato' => 'PENDING',
            'rendicontazione_regolarizzato' => 1,
        ];
        $cols = array_keys($o);
        $sql = 'INSERT INTO flussi_rendicontazioni (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $pdo = self::pdo(); // lastInsertId va chiamato sulla stessa connessione senza altre query in mezzo (getPDO fa un ping)
        $pdo->prepare($sql)->execute(array_values($o));
        return (int)$pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function row(int $id): array
    {
        $st = self::pdo()->prepare('SELECT * FROM flussi_rendicontazioni WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($r);
        return $r;
    }

    private function callsOf(string $kind): int
    {
        return count(array_filter($this->calls, static fn($c) => $c['kind'] === $kind));
    }

    private function regola(string $tipo, string $valore, string $handler): void
    {
        self::pdo()->prepare('INSERT INTO rendicontazione_regole_esterne (id_dominio, pattern_tipo, pattern_valore, handler) VALUES (?,?,?,?)')
            ->execute([self::DOMINIO, $tipo, $valore, $handler]);
    }

    /** '0' se la riga non esiste (il job scrive solo quando il valore cambia). */
    private function counter(): string
    {
        $st = self::pdo()->prepare('SELECT value FROM settings WHERE section = ? AND key_name = ?');
        $st->execute([RendicontazioneJob::COUNTER_SECTION, RendicontazioneJob::COUNTER_KEY]);
        $v = $st->fetchColumn();
        return $v === false ? '0' : (string)$v;
    }

    /** @return list<array{int,int}> */
    private function digestLog(): array
    {
        $rows = self::pdo()->query('SELECT righe_operatore, righe_admin FROM rendicontazione_digest_log ORDER BY id')->fetchAll(\PDO::FETCH_NUM);
        return array_map(static fn($r) => [(int)$r[0], (int)$r[1]], $rows);
    }

    private function user(string $email, bool $globale = false, bool $disabled = false): int
    {
        $pdo = self::pdo();
        $pdo->prepare(
            'INSERT INTO users (email, password_hash, role, notifica_tutte_rendicontazioni, is_disabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$email, 'x', 'user', $globale ? 1 : 0, $disabled ? 1 : 0]);
        return (int)$pdo->lastInsertId();
    }

    /** @param array<string,string> $tipologie id_entrata => modalita @param list<int> $members */
    private function gruppo(string $nome, array $tipologie, array $members = []): int
    {
        $pdo = self::pdo();
        $pdo->prepare('INSERT INTO user_groups (nome) VALUES (?)')->execute([$nome]);
        $gid = (int)$pdo->lastInsertId();
        foreach ($tipologie as $ent => $mod) {
            self::pdo()->prepare('INSERT INTO rendicontazione_gruppo_tipologie (group_id, id_dominio, id_entrata, modalita) VALUES (?,?,?,?)')
                ->execute([$gid, self::DOMINIO, $ent, $mod]);
        }
        foreach ($members as $uid) {
            self::pdo()->prepare('INSERT INTO user_group_members (group_id, user_id) VALUES (?,?)')->execute([$gid, $uid]);
        }
        return $gid;
    }

    private function runJob(?RendicontazioneJob $job = null, ?RecordingJobContext $ctx = null): \App\Services\Jobs\JobResult
    {
        return ($job ?? $this->job())->runOnce($ctx ?? new RecordingJobContext());
    }

    // ------------------------------------------------- finestra e selezione

    public function testProcessaSoloRigheInFinestraStatoEDominio(): void
    {
        $this->setting('max_giorni_retry', '5');
        $this->setting('scansioni_quiete_soglia', '99');
        $pending   = $this->riga(1, ['data_pagamento' => self::today(2)]);
        $errore    = $this->riga(2, ['rendicontazione_stato' => 'ERRORE', 'data_pagamento' => self::today(5)]);
        $fuori     = $this->riga(3, ['data_pagamento' => self::today(6)]);
        $gestito   = $this->riga(4, ['rendicontazione_stato' => 'GESTITO', 'rendicontazione_handler' => 'X']);
        $attesa    = $this->riga(5, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA']);
        $nonGovpay = $this->riga(6, ['is_govpay' => 0]);
        $altroDom  = $this->riga(7, ['id_dominio' => '99999999999']);

        $res = $this->runJob();

        $this->assertSame(2, $res->processed);
        $this->assertSame(2, $res->ok);
        $this->assertSame(0, $res->errors);
        $this->assertTrue($res->hadWork);
        $this->assertSame('GESTITO', $this->row($pending)['rendicontazione_stato']);
        $this->assertSame('GESTITO', $this->row($errore)['rendicontazione_stato']);
        $this->assertSame('PENDING', $this->row($fuori)['rendicontazione_stato'], 'oltre la finestra: mai selezionata');
        $this->assertSame('X', $this->row($gestito)['rendicontazione_handler']);
        $this->assertSame('IN_ATTESA_CONFERMA', $this->row($attesa)['rendicontazione_stato']);
        $this->assertSame('PENDING', $this->row($nonGovpay)['rendicontazione_stato']);
        $this->assertSame('PENDING', $this->row($altroDom)['rendicontazione_stato']);
        $this->assertSame(2, $this->callsOf('pendenza'), 'una sola GET GovPay per riga processata');
    }

    public function testNessunaRigaHadWorkFalse(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $res = $this->runJob();
        $this->assertSame(0, $res->processed);
        $this->assertFalse($res->hadWork);
        $this->assertSame([], $this->calls);
    }

    public function testSecondaIterazioneNonRielaboraRigheGestite(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $id = $this->riga(1);
        $this->runJob();
        $res = $this->runJob();
        $this->assertSame(0, $res->processed);
        $this->assertSame(1, $this->callsOf('pendenza'));
        $this->assertSame('GESTITO', $this->row($id)['rendicontazione_stato']);
    }

    public function testImpostazioneCambiataSuDbTraDueRunOnceVieneVista(): void
    {
        // N5 (fix 8b): stesso job (processo longevo), impostazione cambiata da UI tra due iterazioni
        // (scritta su DB senza passare dalla cache statica di questo processo).
        $this->setting('scansioni_quiete_soglia', '99');
        $job = $this->job();
        $this->riga(1);
        $this->runJob($job);
        $this->assertSame(1, $this->callsOf('pendenza'));

        self::pdo()->exec("UPDATE settings SET value = '' WHERE section = 'govpay' AND key_name = 'backoffice_url'");
        $this->riga(2);
        $res = $this->runJob($job);

        $this->assertSame(1, $this->callsOf('pendenza'), 'backoffice_url vuoto: nessuna chiamata GovPay nella seconda iterazione');
        $this->assertFalse($res->hadWork);
    }

    public function testBackofficeUrlVuotoNonFaNullaNeToccaContatore(): void
    {
        SettingsRepository::set('govpay', 'backoffice_url', '');
        SettingsRepository::flush();
        $this->riga(1);
        $ctx = new RecordingJobContext();
        $res = $this->runJob(null, $ctx);
        $this->assertFalse($res->hadWork);
        $this->assertSame([], $this->calls);
        $this->assertSame(0, (int)self::pdo()->query("SELECT COUNT(*) FROM settings WHERE key_name = '" . RendicontazioneJob::COUNTER_KEY . "'")->fetchColumn());
        $this->assertSame([], $this->opCalls);
    }

    // ----------------------------------------------------------- instradamento

    public function testGilEEsternoGestitiSenzaBridgeNeNotifiche(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $gil = $this->riga(1);
        $ext = $this->riga(2, ['id_pendenza' => 'EXT-0002']);

        $this->runJob();

        $this->assertSame('GIL_MANUALE', $this->row($gil)['rendicontazione_handler']);
        $this->assertSame('AUTO_ESTERNO', $this->row($ext)['rendicontazione_handler']);
        $this->assertSame([], $this->bridgeCalls);
        foreach ([$gil, $ext] as $id) {
            $r = $this->row($id);
            $this->assertSame('GESTITO', $r['rendicontazione_stato']);
            $this->assertSame('NON_APPLICABILE', $r['rendicontazione_appio_stato']);
            $this->assertSame('NON_APPLICABILE', $r['rendicontazione_email_stato']);
        }
    }

    public function testGilConGruppoNotificaESmarcaturaVaInAttesaConfermaSenzaNotifiche(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->gruppo('G1', ['TARI' => 'NOTIFICA_E_SMARCATURA']);
        $id = $this->riga(1);

        $this->runJob();

        $r = $this->row($id);
        $this->assertSame('IN_ATTESA_CONFERMA', $r['rendicontazione_stato']);
        $this->assertNull($r['rendicontazione_handler']);
        $this->assertSame('PENDING', $r['rendicontazione_appio_stato'], 'nessuna notifica prima della conferma');
        $this->assertSame('PENDING', $r['rendicontazione_email_stato']);
        $this->assertSame(0, $this->callsOf('flusso') + $this->callsOf('incasso'));
        $this->assertSame([], $this->bridgeCalls);
    }

    public function testGilConGruppoSoloNotificaEGestito(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA']);
        $id = $this->riga(1);
        $this->runJob();
        $this->assertSame('GIL_MANUALE', $this->row($id)['rendicontazione_handler']);
        $this->assertSame('GESTITO', $this->row($id)['rendicontazione_stato']);
    }

    public function testCodEntrataVuotoPresoDaTipoPendenzaEPersistito(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $a = $this->riga(1, ['cod_entrata' => null]);
        $b = $this->riga(2, ['cod_entrata' => null, 'id_pendenza' => 'GIL0000000000000002']);
        $this->pendenze['GIL00000000000000001'] = ['tipoPendenza' => ['idTipoPendenza' => 'IMU']];
        $this->pendenze['GIL0000000000000002'] = ['voci' => [['codEntrata' => ''], ['codEntrata' => 'COSAP']]];

        $this->runJob();

        $this->assertSame('IMU', $this->row($a)['cod_entrata']);
        $this->assertSame('COSAP', $this->row($b)['cod_entrata'], 'fallback su voci[].codEntrata');
    }

    // ------------------------------------------------------------ ponte legacy

    public function testGeriSuccessoUnaSolaChiamataConIuvPaddatoENessunDuplicato(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->regola('IUV_PREFIX', '006', 'GERI');
        $id = $this->riga(1, ['id_pendenza' => 'EXT-1', 'iuv' => '00607000007021733', 'importo' => 12.34]);
        $this->pendenze['EXT-1'] = [
            'documento' => ['identificativo' => 'ATTO-9', 'rata' => 2],
            'dataPagamento' => '2026-10-01', 'importo' => 12.34,
        ];

        $this->runJob();
        $this->runJob();
        $this->runJob();

        $this->assertCount(1, $this->bridgeCalls, 'GERI inviato una sola volta: la riga GESTITO non rientra');
        $this->assertSame([
            'handler' => 'GERI',
            'iuv' => '300607000007021733',
            'id_atto' => 'ATTO-9',
            'data_pagamento' => '2026-10-01',
            'importo' => 12.34,
            'rata' => '2',
        ], $this->bridgeCalls[0]);
        $r = $this->row($id);
        $this->assertSame('GESTITO', $r['rendicontazione_stato']);
        $this->assertSame('GERI', $r['rendicontazione_handler']);
        $this->assertSame('registrato', $r['rendicontazione_note']);
    }

    public function testBridgeIuvGiaLungoNonVienePaddatoEAuxDigitDaSettings(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        SettingsRepository::set('entity', 'aux_digit', '0');
        SettingsRepository::flush();
        $this->regola('IUV_PREFIX', 'RF', 'DILAZIONE');
        $this->riga(1, ['id_pendenza' => 'EXT-1', 'iuv' => 'RF1234567890123456789012']);
        $this->riga(2, ['id_pendenza' => 'EXT-2', 'iuv' => 'RF12']);

        $this->runJob();

        $this->assertCount(2, $this->bridgeCalls);
        $this->assertSame('RF1234567890123456789012', $this->bridgeCalls[0]['iuv'], 'strlen >= 18: invariato');
        $this->assertSame('0' . str_pad('RF12', 17, '0', STR_PAD_LEFT), $this->bridgeCalls[1]['iuv']);
        $this->assertArrayNotHasKey('rata', $this->bridgeCalls[0], 'senza rata il campo non viene inviato');
    }

    public function testIdAppAgidRuleInstradaVersoDilazione(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->regola('ID_APP_AGID', '7', 'DILAZIONE');
        $hit = $this->riga(1, ['id_pendenza' => 'EXT-1', 'iuv' => '00707000007021733']);
        $miss = $this->riga(2, ['id_pendenza' => 'EXT-2', 'iuv' => '00607000007021733']);

        $this->runJob();

        $this->assertSame('DILAZIONE', $this->row($hit)['rendicontazione_handler']);
        $this->assertSame('AUTO_ESTERNO', $this->row($miss)['rendicontazione_handler']);
        $this->assertCount(1, $this->bridgeCalls);
    }

    public function testGeriEsitoNegativoIncrementaTentativiFinoAlCapPoiNonChiamaPiu(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->setting('geri_max_tentativi', '3');
        $this->regola('IUV_PREFIX', '006', 'GERI');
        $this->bridgeResponses = [new Response(200, [], json_encode(['esito' => false, 'messaggio' => 'Rata non trovata']))];
        $id = $this->riga(1, ['id_pendenza' => 'EXT-1', 'iuv' => '00607000007021733']);

        for ($i = 1; $i <= 3; $i++) {
            $res = $this->runJob();
            $this->assertSame(1, $res->processed, "ciclo {$i}: la riga ERRORE viene ritentata");
            $r = $this->row($id);
            $this->assertSame('ERRORE', $r['rendicontazione_stato']);
            $this->assertSame($i, (int)$r['rendicontazione_tentativi_geri']);
            $this->assertSame('Bridge GERI: Rata non trovata', $r['rendicontazione_note']);
            $this->assertCount($i, $this->bridgeCalls);
        }

        $res = $this->runJob();
        $this->assertSame(1, $res->processed, 'la riga e` ancora selezionata (ERRORE in finestra)');
        $this->assertCount(3, $this->bridgeCalls, 'cap raggiunto: nessuna quarta chiamata al ponte');
        $r = $this->row($id);
        $this->assertSame('Cap tentativi Geri raggiunto, richiede intervento manuale', $r['rendicontazione_note']);
        $this->assertSame(3, (int)$r['rendicontazione_tentativi_geri']);
    }

    public function testDilazioneEsitoNegativoRispettaLoStessoCapDiGeri(): void
    {
        // N2 (fix 8b): DILAZIONE usa lo stesso cap di GERI (rendicontazione.geri_max_tentativi),
        // con un contatore dedicato: ponte chiamato esattamente cap volte, poi mai piu'.
        $this->setting('scansioni_quiete_soglia', '99');
        $this->setting('geri_max_tentativi', '3');
        $this->regola('IUV_PREFIX', '006', 'DILAZIONE');
        $this->bridgeResponses = [new Response(200, [], json_encode(['esito' => false, 'messaggio' => 'KO']))];
        $id = $this->riga(1, ['id_pendenza' => 'EXT-1', 'iuv' => '00607000007021733']);

        for ($i = 1; $i <= 3; $i++) {
            $this->runJob();
            $this->assertCount($i, $this->bridgeCalls);
            $r = $this->row($id);
            $this->assertSame('ERRORE', $r['rendicontazione_stato']);
            $this->assertSame('Bridge DILAZIONE: KO', $r['rendicontazione_note']);
            $this->assertSame($i, (int)$r['rendicontazione_tentativi_dilazione']);
        }

        for ($i = 0; $i < 3; $i++) {
            $this->runJob();
        }

        $this->assertCount(3, $this->bridgeCalls, 'cap raggiunto: nessuna chiamata ulteriore al ponte');
        $r = $this->row($id);
        $this->assertSame('ERRORE', $r['rendicontazione_stato']);
        $this->assertSame('Cap tentativi Dilazione raggiunto, richiede intervento manuale', $r['rendicontazione_note']);
        $this->assertSame(3, (int)$r['rendicontazione_tentativi_dilazione']);
        $this->assertSame(0, (int)$r['rendicontazione_tentativi_geri']);
    }

    public function testDilazioneSuccessoDopoUnFallimentoUnaSolaRegistrazione(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->setting('geri_max_tentativi', '3');
        $this->regola('IUV_PREFIX', '006', 'DILAZIONE');
        $this->bridgeResponses = [
            new Response(200, [], json_encode(['esito' => false, 'messaggio' => 'KO'])),
            new Response(200, [], json_encode(['esito' => true, 'messaggio' => 'ok'])),
        ];
        $id = $this->riga(1, ['id_pendenza' => 'EXT-1', 'iuv' => '00607000007021733']);

        for ($i = 0; $i < 4; $i++) {
            $this->runJob();
        }

        $this->assertCount(2, $this->bridgeCalls, 'un fallimento + un successo, mai rinviata dopo GESTITO');
        $this->assertSame('GESTITO', $this->row($id)['rendicontazione_stato']);
    }

    public function testBridgeEccezioneDiRetePerGeriContaComeTentativo(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->regola('IUV_PREFIX', '006', 'GERI');
        $this->bridgeResponses = [new ConnectException('timeout https://bridge.example.test/?token=segreto', new Request('POST', ''))];
        $id = $this->riga(1, ['id_pendenza' => 'EXT-1', 'iuv' => '00607000007021733']);

        $this->runJob();

        $r = $this->row($id);
        $this->assertSame('ERRORE', $r['rendicontazione_stato']);
        $this->assertSame('Bridge GERI: ConnectException', $r['rendicontazione_note'], 'N4: mai il messaggio della eccezione');
        $this->assertSame(1, (int)$r['rendicontazione_tentativi_geri']);
        $this->assertCount(1, $this->bridgeCalls);
    }

    // ------------------------------------------------------------ errori riga

    public function testFetchPendenzaFallitaMarcaErroreERitentaAlCicloSuccessivo(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $id = $this->riga(1);
        $this->pendenze[(string)$this->row($id)['id_pendenza']] = new Response(500, [], '{"descrizione":"segreto RSSMRA"}');

        $res = $this->runJob();

        $r = $this->row($id);
        $this->assertSame('ERRORE', $r['rendicontazione_stato']);
        // N4 (fix 8b): classe eccezione + status HTTP, mai messaggio/URL/corpo upstream
        $this->assertSame('Errore fetch pendenza GovPay: ServerException (HTTP 500)', (string)$r['rendicontazione_note']);
        $this->assertSame(1, $res->processed);
        $this->assertSame(0, $res->errors, 'errori per-riga restano solo su DB (stato ERRORE), non in errorDetails');

        $this->pendenze = [];
        $this->runJob();
        $this->assertSame('GESTITO', $this->row($id)['rendicontazione_stato']);
        $this->assertSame(2, $this->callsOf('pendenza'));
    }

    public function testRispostaPendenzaNonJsonMarcaErrore(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $id = $this->riga(1);
        $this->pendenze[(string)$this->row($id)['id_pendenza']] = new Response(200, [], 'non-json');
        $this->runJob();
        $this->assertSame('ERRORE', $this->row($id)['rendicontazione_stato']);
        $this->assertSame('Errore fetch pendenza GovPay: Risposta GovPay non valida', $this->row($id)['rendicontazione_note']);
    }

    public function testIdPendenzaOIuvMancanteMarcaErroreSenzaChiamate(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $a = $this->riga(1, ['id_pendenza' => null]);
        $b = $this->riga(2, ['iuv' => null]);
        $this->runJob();
        $this->assertSame('id_pendenza o iuv mancante sulla riga', $this->row($a)['rendicontazione_note']);
        $this->assertSame('ERRORE', $this->row($b)['rendicontazione_stato']);
        $this->assertSame([], $this->calls);
    }

    public function testEngineFactoryCheLanciaIncrementaContatoreEMarcaErroreCiclo(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $id = $this->riga(1);
        $job = $this->job(static function (): RendicontazioneEngineService {
            throw new \RuntimeException('client non costruibile');
        });

        $res = $this->runJob($job);

        $this->assertSame(0, $res->processed);
        $this->assertFalse($res->hadWork);
        $this->assertSame(1, $res->errors);
        $this->assertSame([['ref' => 'ciclo', 'reason' => 'Errore ciclo processaCiclo']], $res->errorDetails);
        $this->assertSame('1', $this->counter());
        $this->assertSame('PENDING', $this->row($id)['rendicontazione_stato']);
    }

    // ------------------------------------------------- guardie idempotenza

    public function testNotificheGiaInviateNonVengonoRipetute(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $id = $this->riga(1, ['rendicontazione_appio_stato' => 'INVIATO', 'rendicontazione_email_stato' => 'INVIATO']);
        $this->pendenze[(string)$this->row($id)['id_pendenza']] = [
            'soggettoPagatore' => ['tipo' => 'F', 'identificativo' => Synthetic::cf(3), 'email' => 'cittadino@example.test'],
        ];

        $this->runJob();

        $r = $this->row($id);
        $this->assertSame('GESTITO', $r['rendicontazione_stato']);
        $this->assertSame('INVIATO', $r['rendicontazione_appio_stato']);
        $this->assertSame('INVIATO', $r['rendicontazione_email_stato']);
    }

    // ----------------------------------------------------- regolarizzazione

    public function testUltimaRigaDelFlussoRegolarizzaUnaSolaVoltaAncheConSweep(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $a = $this->riga(1, ['id_flusso' => 'F1', 'rendicontazione_regolarizzato' => 0, 'importo' => 10.0, 'trn' => 'TRN1']);
        $b = $this->riga(2, ['id_flusso' => 'F1', 'rendicontazione_regolarizzato' => 0, 'importo' => 5.5, 'trn' => 'TRN1']);
        $this->flussoResponse = new Response(200, [], json_encode(['risultati' => [['idFlusso' => 'F1', 'importoTotale' => 15.5, 'trn' => 'TRN-GP']]]));

        $this->runJob();
        $this->runJob();

        $this->assertSame(1, $this->callsOf('incasso'), 'un solo POST incassi per flusso');
        $incasso = array_values(array_filter($this->calls, static fn($c) => $c['kind'] === 'incasso'))[0];
        $this->assertStringEndsWith('/incassi/' . self::DOMINIO, $incasso['path']);
        $this->assertSame(['importo' => 15.5, 'idFlusso' => 'F1', 'sct' => 'TRN-GP'], json_decode($incasso['body'], true));
        $this->assertSame(1, (int)$this->row($a)['rendicontazione_regolarizzato']);
        $this->assertSame(1, (int)$this->row($b)['rendicontazione_regolarizzato']);
    }

    public function testFlussoConRigaAncoraPendingNonRegolarizza(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->pendenze['EXT-ERR'] = new Response(500, [], '{}');
        $this->riga(1, ['id_flusso' => 'F1', 'rendicontazione_regolarizzato' => 0]);
        $err = $this->riga(2, ['id_flusso' => 'F1', 'rendicontazione_regolarizzato' => 0, 'id_pendenza' => 'EXT-ERR']);

        $this->runJob();

        $this->assertSame('ERRORE', $this->row($err)['rendicontazione_stato']);
        $this->assertSame(0, $this->callsOf('incasso'));
        $this->assertSame(0, $this->callsOf('flusso'));
    }

    public function testSweepRegolarizzaFlussoCompletoUnaSolaVoltaPoiNonRipete(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $a = $this->riga(1, ['id_flusso' => 'F1', 'rendicontazione_stato' => 'GESTITO', 'rendicontazione_regolarizzato' => 0, 'importo' => 7.25, 'trn' => 'TRN-L']);

        $res = $this->runJob();
        $this->assertSame(0, $res->processed);
        $this->assertSame(1, $this->callsOf('incasso'));
        $incasso = array_values(array_filter($this->calls, static fn($c) => $c['kind'] === 'incasso'))[0];
        $this->assertSame(['importo' => 7.25, 'idFlusso' => 'F1', 'sct' => 'TRN-L'], json_decode($incasso['body'], true), 'fallback su aggregato locale (GovPay non ha il flusso)');
        $this->assertSame(1, (int)$this->row($a)['rendicontazione_regolarizzato']);

        $this->runJob();
        $this->assertSame(1, $this->callsOf('incasso'));
    }

    public function testSweepIncasso422CountsAsRegolarizzatoMentre500RitentaOgniCiclo(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $a = $this->riga(1, ['id_flusso' => 'F422', 'rendicontazione_stato' => 'GESTITO', 'rendicontazione_regolarizzato' => 0]);
        $this->incassoResponse = new Response(422, [], '{"descrizione":"versamento non esistente"}');
        $this->runJob();
        $this->assertSame(1, (int)$this->row($a)['rendicontazione_regolarizzato'], '422 bypass: marcato');
        $this->assertSame(1, $this->callsOf('incasso'));

        $b = $this->riga(2, ['id_flusso' => 'F500', 'rendicontazione_stato' => 'GESTITO', 'rendicontazione_regolarizzato' => 0]);
        $this->incassoResponse = new Response(500, [], '{}');
        $this->runJob();
        $this->runJob();
        $this->assertSame(0, (int)$this->row($b)['rendicontazione_regolarizzato']);
        $this->assertSame(3, $this->callsOf('incasso'), '1 (422) + 2 tentativi sul flusso in errore');
    }

    /**
     * Fix round 2 (B1): POST /incassi con 302 same-origin non diventa un GET "riuscito" (redirect
     * mai seguiti): una sola chiamata, flusso NON marcato regolarizzato.
     */
    public function testSweepIncassoRedirectIsNotFollowedAndNotRegolarizzato(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $a = $this->riga(1, ['id_flusso' => 'F302', 'rendicontazione_stato' => 'GESTITO', 'rendicontazione_regolarizzato' => 0]);
        $this->incassoResponse = new Response(302, ['Location' => '/incassi/' . self::DOMINIO . '/']);

        $this->runJob();

        $this->assertSame(1, $this->callsOf('incasso'));
        $this->assertSame(0, $this->callsOf('incasso-get'), 'redirect non seguito');
        $this->assertSame(0, (int)$this->row($a)['rendicontazione_regolarizzato']);
    }

    public function testSweepImportoNulloNonInviaIncasso(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $a = $this->riga(1, ['id_flusso' => 'F0', 'rendicontazione_stato' => 'GESTITO', 'rendicontazione_regolarizzato' => 0, 'importo' => 0]);
        $this->runJob();
        $this->assertSame(0, $this->callsOf('incasso'));
        $this->assertSame(0, (int)$this->row($a)['rendicontazione_regolarizzato']);
    }

    public function testSweepFlussoSoloNonGovpayNonVieneRegolarizzato(): void
    {
        // N3 (fix 8b): un flusso composto solo da righe esterne (is_govpay=0) non genera mai
        // un POST /incassi verso GovPay; un flusso misto completo si' (una sola volta).
        $this->setting('scansioni_quiete_soglia', '99');
        $ext = $this->riga(1, ['id_flusso' => 'FEXT', 'is_govpay' => 0, 'rendicontazione_regolarizzato' => 0, 'importo' => 9.0]);
        $this->riga(2, ['id_flusso' => 'FMIX', 'is_govpay' => 0, 'rendicontazione_regolarizzato' => 0, 'importo' => 9.0]);
        $this->riga(3, ['id_flusso' => 'FMIX', 'rendicontazione_stato' => 'GESTITO', 'rendicontazione_regolarizzato' => 0, 'importo' => 1.0]);

        $this->runJob();
        $this->runJob();

        $incassi = array_values(array_filter($this->calls, static fn($c) => $c['kind'] === 'incasso'));
        $this->assertCount(1, $incassi, 'solo il flusso misto');
        $this->assertStringContainsString('FMIX', $incassi[0]['body']);
        $this->assertSame(0, (int)$this->row($ext)['rendicontazione_regolarizzato']);
    }

    public function testSweepErroreNonFermaIlCicloEIndicaFase(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $repo = $this->createStub(RendicontazioneRepository::class);
        $repo->method('getFlussiDaRegolarizzare')->willThrowException(new \RuntimeException('db giu'));
        $engine = $this->createStub(RendicontazioneEngineService::class);
        $engine->method('processaCiclo')->willReturn(['processate' => 2, 'nuove' => 2]);
        $job = new RendicontazioneJob($repo, static fn() => $engine, new UserGroupRepository(), new UserRepository(), $this->mailer, self::DOMINIO, self::A2A, self::pdo());

        $res = $job->runOnce(new RecordingJobContext());

        $this->assertSame(2, $res->processed);
        $this->assertTrue($res->hadWork);
        $this->assertSame([['ref' => 'sweep', 'reason' => 'Errore sweep regolarizzazione flussi']], $res->errorDetails);
        $this->assertSame('0', $this->counter(), 'nuove>0 azzera il contatore');
    }

    // --------------------------------------------- contatore cicli quieti/digest

    public function testDigestParteSoloDopoNCicliQuietiConsecutiviAcrossJobInstances(): void
    {
        $this->setting('scansioni_quiete_soglia', '3');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA'], [$this->user('op@example.test')]);
        $id = $this->riga(1, ['rendicontazione_stato' => 'GESTITO', 'rendicontazione_handler' => 'GIL_MANUALE']);

        $this->runJob($this->job());
        $this->assertSame('1', $this->counter());
        $this->assertCount(0, $this->opCalls);
        $this->runJob($this->job());
        $this->assertSame('2', $this->counter());
        $this->assertCount(0, $this->opCalls, 'non prima di N cicli');
        $this->assertSame(0, (int)$this->row($id)['rendicontazione_notificato']);

        $this->runJob($this->job());
        $this->assertCount(1, $this->opCalls, 'esattamente al terzo ciclo quieto, nuova istanza del job');
        $this->assertSame('0', $this->counter());
        $this->assertSame(1, (int)$this->row($id)['rendicontazione_notificato']);
        $this->assertSame([[1, 0]], $this->digestLog());

        // Riga gia' notificata: nei cicli successivi nessun nuovo invio.
        for ($i = 0; $i < 3; $i++) {
            $this->runJob($this->job());
        }
        $this->assertCount(1, $this->opCalls, 'nessun digest duplicato');
        $this->assertSame([[1, 0]], $this->digestLog(), 'coda vuota: nessuna riga di log');
        $this->assertSame('0', $this->counter());
    }

    public function testRigheNuoveAzzeranoIlContatore(): void
    {
        $this->setting('scansioni_quiete_soglia', '3');
        $this->runJob();
        $this->runJob();
        $this->assertSame('2', $this->counter());
        $this->riga(1);
        $this->runJob();
        $this->assertSame('0', $this->counter());
        $this->runJob();
        $this->assertSame('1', $this->counter());
    }

    public function testRigheInErroreNonSonoNuoveEIncrementanoContatore(): void
    {
        // `nuove` conta solo le righe che erano PENDING: una riga ERRORE ritentata non azzera il contatore.
        $this->setting('scansioni_quiete_soglia', '99');
        $this->riga(1, ['rendicontazione_stato' => 'ERRORE']);
        $res = $this->runJob();
        $this->assertSame(1, $res->processed);
        $this->assertSame('1', $this->counter());
    }

    public function testDigestSoglia1PartePerOgniCicloSenzaNovita(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA'], [$this->user('op@example.test')]);
        $this->riga(1, ['rendicontazione_stato' => 'GESTITO']);
        $this->runJob();
        $this->assertCount(1, $this->opCalls);
    }

    public function testDigestRaccogliePerGruppoETipologiaConEmailGlobaliUniche(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $u1 = $this->user('a@example.test');
        $this->user('globale@example.test', true);
        $this->user('globale-disabilitato@example.test', true, true);
        $this->gruppo('Tributi', ['TARI' => 'SOLO_NOTIFICA', 'IMU' => 'NOTIFICA_E_SMARCATURA'], [$u1, $this->user('globale2@example.test', true)]);
        $this->gruppo('Altro', ['COSAP' => 'SOLO_NOTIFICA'], [$this->user('b@example.test')]);
        $info = $this->riga(1, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'TARI']);
        $conf = $this->riga(2, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'cod_entrata' => 'IMU']);
        $this->riga(3, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'XYZ']);
        $this->riga(4, ['rendicontazione_stato' => 'PENDING', 'cod_entrata' => 'TARI', 'data_pagamento' => self::today(60)]); // PENDING fuori finestra: mai elaborata, esclusa dal digest
        $this->riga(5, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'TARI', 'rendicontazione_notificato' => 1]);
        $this->riga(6, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'TARI', 'is_govpay' => 0]);

        $this->runJob();

        $this->assertCount(1, $this->opCalls, 'gruppo Altro: nessuna riga pertinente, nessuna mail');
        $call = $this->opCalls[0];
        $this->assertSame('Tributi', $call['gruppo']);
        $emails = $call['emails'];
        sort($emails);
        $this->assertSame(['a@example.test', 'globale2@example.test', 'globale@example.test'], array_values($emails));
        $this->assertCount(count(array_unique($emails)), $emails);
        $this->assertSame([$conf], array_map(static fn($r) => (int)$r['id'], $call['daConfermare']));
        $this->assertSame([$info], array_map(static fn($r) => (int)$r['id'], $call['informative']));
        $this->assertSame('/rendicontazione/da-confermare', $call['url']);
        $this->assertSame(1, (int)$this->row($info)['rendicontazione_notificato']);
        $this->assertSame(1, (int)$this->row($conf)['rendicontazione_notificato']);
        $this->assertSame([[2, 0]], $this->digestLog());
        $unmarked = (int)self::pdo()->query("SELECT COUNT(*) FROM flussi_rendicontazioni WHERE is_govpay = 1 AND rendicontazione_notificato = 0 AND rendicontazione_stato <> 'PENDING'")->fetchColumn();
        $this->assertSame(0, $unmarked, 'N1: riga XYZ non coperta da alcun gruppo/admin -> marcata notificata senza mail');
    }

    public function testDigestRigheNonCoperteMarcateUnaVoltaSenzaMailNeLogZeroZero(): void
    {
        // N1 (fix 8b): una riga che nessun destinatario riceverebbe (nessun gruppo, admin non
        // configurato) viene marcata notificata al primo digest: i digest successivi non la
        // ripescano, nessuna mail e nessuna riga (0,0) in rendicontazione_digest_log.
        $this->setting('scansioni_quiete_soglia', '2');
        $id = $this->riga(1, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'SENZA-GRUPPO']);

        $this->runJob($this->job());
        $this->runJob($this->job()); // primo digest
        $this->assertSame(1, (int)$this->row($id)['rendicontazione_notificato']);

        for ($i = 0; $i < 4; $i++) {
            $this->runJob($this->job()); // altri due digest, nessuna riga nuova
        }

        $this->assertSame([], $this->digestLog(), 'nessuna riga (0,0)');
        $this->assertSame([], $this->opCalls);
        $this->assertSame([], $this->adminCalls);
    }

    public function testDigestOperatoreErroreNonMarcaNotificateELoggaZeroZero(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA'], [$this->user('op@example.test')]);
        $id = $this->riga(1, ['rendicontazione_stato' => 'GESTITO']);
        $this->opResults = [['esito' => 'ERRORE', 'errore' => 'smtp down segreto']];

        $res = $this->runJob();

        $this->assertSame(0, (int)$this->row($id)['rendicontazione_notificato']);
        $this->assertSame([[0, 0]], $this->digestLog());
        $this->assertCount(1, $res->errorDetails);
        $this->assertMatchesRegularExpression('/^gruppo-\d+$/', $res->errorDetails[0]['ref']);
        $this->assertSame('Invio digest operatore fallito', $res->errorDetails[0]['reason']);
        $this->assertStringNotContainsString('segreto', json_encode($res->errorDetails));
        $this->assertSame('0', $this->counter(), 'contatore comunque azzerato');

        // al giro successivo la stessa riga viene reinviata (un nuovo tentativo, non un duplicato)
        $this->runJob();
        $this->assertCount(2, $this->opCalls);
        $this->assertSame(1, (int)$this->row($id)['rendicontazione_notificato']);
    }

    public function testDigestOperatoreSkippedSenzaDestinatariNonMarca(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA'], []);
        $id = $this->riga(1, ['rendicontazione_stato' => 'GESTITO']);
        $this->opResults = [['esito' => 'SKIPPED']];

        $res = $this->runJob();

        $this->assertCount(1, $this->opCalls);
        $this->assertSame([], $this->opCalls[0]['emails']);
        $this->assertSame(0, (int)$this->row($id)['rendicontazione_notificato']);
        $this->assertSame([[0, 0]], $this->digestLog());
        $this->assertSame(0, $res->errors);
    }

    public function testDigestAdminSoloConNotificaAdminAutoEDestinatariEGestite(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');

        // notifica_admin_auto assente: nessuna mail admin; la riga non e' coperta da nessuno -> marcata (N1)
        $a1 = $this->riga(1, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'XYZ']);
        $this->runJob();
        $this->assertSame([], $this->adminCalls);
        $this->assertSame(1, (int)$this->row($a1)['rendicontazione_notificato']);

        // true ma senza admin_emails: nessuna mail admin
        $this->setting('notifica_admin_auto', 'true');
        $a2 = $this->riga(2, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'XYZ']);
        $this->runJob();
        $this->assertSame([], $this->adminCalls);
        $this->assertSame(1, (int)$this->row($a2)['rendicontazione_notificato']);
        $this->assertSame([], $this->digestLog());

        // true + admin_emails con separatori misti
        $this->setting('admin_emails', ' a1@example.test ; a2@example.test,a3@example.test  a1b@example.test');
        $a = $this->riga(3, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'XYZ']);
        $b = $this->riga(4, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'cod_entrata' => 'XYZ']);
        $this->runJob();
        $this->assertCount(1, $this->adminCalls);
        $this->assertSame(['a1@example.test', 'a2@example.test', 'a3@example.test', 'a1b@example.test'], $this->adminCalls[0]['destinatari']);
        $this->assertSame([$a], array_map(static fn($r) => (int)$r['id'], $this->adminCalls[0]['righe']), 'solo righe gestite (non IN_ATTESA_CONFERMA)');
        $this->assertSame(1, (int)$this->row($a)['rendicontazione_notificato']);
        $this->assertSame(1, (int)$this->row($b)['rendicontazione_notificato'], 'IN_ATTESA senza gruppo: nessun destinatario -> marcata');
        $this->assertSame([[0, 1]], $this->digestLog());

        // gia' notificata: nessun invio admin ripetuto
        $this->runJob();
        $this->assertCount(1, $this->adminCalls);
        $this->assertSame([[0, 1]], $this->digestLog());
    }

    public function testDigestAdminErroreNonMarcaEDettaglio(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $this->setting('notifica_admin_auto', 'true');
        $this->setting('admin_emails', 'adm@example.test');
        $id = $this->riga(1, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'XYZ']);
        $this->adminResults = [['esito' => 'ERRORE', 'errore' => 'boom']];

        $res = $this->runJob();

        $this->assertSame(0, (int)$this->row($id)['rendicontazione_notificato']);
        $this->assertSame([['ref' => 'admin', 'reason' => 'Invio digest admin fallito']], $res->errorDetails);
        $this->assertSame([[0, 0]], $this->digestLog());
    }

    public function testDigestOperatoreEAdminSulloStessoGiroContanoRigheDistinte(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $this->setting('notifica_admin_auto', 'true');
        $this->setting('admin_emails', 'adm@example.test');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA'], [$this->user('op@example.test')]);
        $this->riga(1, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'TARI']);
        $this->riga(2, ['rendicontazione_stato' => 'GESTITO', 'cod_entrata' => 'XYZ']);

        $this->runJob();

        $this->assertCount(1, $this->opCalls);
        $this->assertCount(1, $this->adminCalls);
        $this->assertCount(2, $this->adminCalls[0]['righe'], 'admin riceve tutte le gestite, anche quelle gia` nel digest operatore');
        $this->assertSame([[1, 2]], $this->digestLog());
        $this->assertSame(2, (int)self::pdo()->query('SELECT COUNT(*) FROM flussi_rendicontazioni WHERE rendicontazione_notificato = 1')->fetchColumn());
    }

    public function testDigestEccezioneAzzeraComunqueContatoreENonScriveLog(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA'], [$this->user('op@example.test')]);
        $id = $this->riga(1, ['rendicontazione_stato' => 'GESTITO']);
        $this->opResults = [new \RuntimeException('mailer rotto')];

        $res = $this->runJob();

        $this->assertSame([['ref' => 'digest', 'reason' => 'Errore invio digest']], $res->errorDetails);
        $this->assertSame([], $this->digestLog());
        $this->assertSame(0, (int)$this->row($id)['rendicontazione_notificato']);
        $this->assertSame('0', $this->counter());
        $this->assertCount(1, $this->opCalls);
    }

    public function testStopRichiestoSaltaIlDigestMaPersisteContatore(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA'], [$this->user('op@example.test')]);
        $this->riga(1, ['rendicontazione_stato' => 'GESTITO']);
        $ctx = new RecordingJobContext();
        $ctx->stop = true;

        $this->runJob(null, $ctx);

        $this->assertSame([], $this->opCalls);
        $this->assertSame('1', $this->counter());
    }

    public function testStessoGiroProcessaERiportaSenzaDigestSeCiSonoNuove(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $this->gruppo('G1', ['TARI' => 'SOLO_NOTIFICA'], [$this->user('op@example.test')]);
        $this->riga(1);                                          // PENDING: sara' GESTITA (GIL_MANUALE) ora
        $this->riga(2, ['rendicontazione_stato' => 'GESTITO']);  // gia' gestita, non notificata

        $res = $this->runJob();

        $this->assertSame(1, $res->processed);
        $this->assertSame([], $this->opCalls, 'nuove > 0: il giro non e` quieto');
        $this->assertSame('0', $this->counter());
    }

    public function testContatoreCorrottoOAssenteParteDaZero(): void
    {
        $this->setting('scansioni_quiete_soglia', '3');
        SettingsRepository::set(RendicontazioneJob::COUNTER_SECTION, RendicontazioneJob::COUNTER_KEY, 'abc');
        SettingsRepository::flush();
        $this->runJob();
        $this->assertSame('1', $this->counter());
    }

    public function testScritturaContatoreFallitaNonLanciaERitornaRisultato(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $this->riga(1, ['rendicontazione_stato' => 'ERRORE']); // non nuova: il contatore cambia e va scritto
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME'));
        $failing = new class($dsn, (string)getenv('DB_USER'), (string)getenv('DB_PASSWORD')) extends \PDO {
            #[\ReturnTypeWillChange]
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                if (str_contains($query, 'INSERT INTO settings')) {
                    throw new \PDOException('db blip');
                }
                return parent::prepare($query, $options);
            }
        };
        $failing->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $repo = new RendicontazioneRepository(self::pdo());
        $client = $this->govPayClient();
        $bridge = $this->bridge();
        $job = new RendicontazioneJob(
            $repo,
            static fn(): RendicontazioneEngineService => new RendicontazioneEngineService($repo, $bridge, $client),
            new UserGroupRepository(), new UserRepository(), $this->mailer, self::DOMINIO, self::A2A, $failing
        );

        $res = $job->runOnce(new RecordingJobContext());

        $this->assertSame(1, $res->processed);
        $this->assertTrue($res->hadWork);
    }

    // ------------------------------------------------------------ solleciti smarcatura

    /** Data Y-m-d dell'ultimo sollecito del gruppo ('' se mai inviato). */
    private function sollecitoGuard(int $groupId): string
    {
        $st = self::pdo()->prepare('SELECT value FROM settings WHERE section = ? AND key_name = ?');
        $st->execute([RendicontazioneJob::COUNTER_SECTION, RendicontazioneJob::SOLLECITO_KEY_PREFIX . $groupId]);
        $v = $st->fetchColumn();
        return $v === false ? '' : (string)$v;
    }

    private function setSollecitoGuard(int $groupId, string $date): void
    {
        self::pdo()->prepare('INSERT INTO settings (section, key_name, value, encrypted) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([RendicontazioneJob::COUNTER_SECTION, RendicontazioneJob::SOLLECITO_KEY_PREFIX . $groupId, $date]);
    }

    /** Scenario base: soglia quiete 1 (digest a ogni giro), un gruppo con smarcatura su TARI. */
    private function scenarioSollecito(): int
    {
        $this->setting('scansioni_quiete_soglia', '1');
        return $this->gruppo('Tributi', ['TARI' => 'NOTIFICA_E_SMARCATURA'], [$this->user('op@example.test')]);
    }

    public function testSollecitoInviatoAllaSogliaDi7GiorniEUnaSolaVoltaAlGiorno(): void
    {
        $gid = $this->scenarioSollecito();
        $this->user('globale@example.test', true);
        $vecchia = $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(7)]);
        $this->riga(2, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(6)]);                       // troppo recente
        $this->riga(3, ['rendicontazione_stato' => 'GESTITO', 'data_flusso' => self::today(30)]);                                 // non in attesa
        $this->riga(4, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(30), 'cod_entrata' => 'IMU']); // tipologia senza smarcatura
        $this->riga(5, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(30), 'is_govpay' => 0]);     // non GovPay
        $this->riga(6, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(30), 'id_dominio' => '80000000002']); // altro dominio

        $res = $this->runJob();

        $this->assertCount(1, $this->solCalls);
        $c = $this->solCalls[0];
        $this->assertSame('Tributi', $c['gruppo']);
        $this->assertSame(7, $c['giorni']);
        $this->assertSame('/rendicontazione/da-confermare', $c['url']);
        $this->assertSame([$vecchia], array_map(static fn($r) => (int)$r['id'], $c['righe']));
        $emails = $c['emails'];
        sort($emails);
        $this->assertSame(['globale@example.test', 'op@example.test'], $emails);
        $this->assertSame(self::today(), $this->sollecitoGuard($gid));
        $this->assertSame(0, $res->errors);

        // stesso giorno, altri giri (anche con nuova istanza del job): nessun secondo sollecito
        $this->runJob($this->job());
        $this->runJob($this->job());
        $this->assertCount(1, $this->solCalls, 'una sola volta al giorno');
    }

    public function testSollecitoRipetutoSoloDopoAltriGiorniDiAttesa(): void
    {
        $gid = $this->scenarioSollecito();
        $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(20)]);

        $this->setSollecitoGuard($gid, self::today(6));
        $this->runJob();
        $this->assertCount(0, $this->solCalls, 'ultimo sollecito 6 giorni fa: non ancora');

        $this->setSollecitoGuard($gid, self::today(7));
        $this->runJob();
        $this->assertCount(1, $this->solCalls, 'ultimo sollecito 7 giorni fa: di nuovo');
        $this->assertSame(self::today(), $this->sollecitoGuard($gid));
    }

    public function testSollecitoNonPartePerRigaMaSoloNelRamoDigest(): void
    {
        $this->setting('scansioni_quiete_soglia', '99');
        $gid = $this->gruppo('Tributi', ['TARI' => 'NOTIFICA_E_SMARCATURA'], [$this->user('op@example.test')]);
        $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(30)]);

        $this->runJob();
        $this->runJob();

        $this->assertSame([], $this->solCalls);
        $this->assertSame('', $this->sollecitoGuard($gid));
    }

    public function testSollecitoNessunaRigaNessunaMail(): void
    {
        $gid = $this->scenarioSollecito();
        $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(6)]);
        $this->riga(2, ['rendicontazione_stato' => 'GESTITO', 'data_flusso' => self::today(60)]);

        $res = $this->runJob();

        $this->assertSame([], $this->solCalls);
        $this->assertSame('', $this->sollecitoGuard($gid));
        $this->assertSame(0, $res->errors);
    }

    public function testSollecitoErroreMailerNonScriveGuardRegistraDettaglioERitenta(): void
    {
        $gid = $this->scenarioSollecito();
        $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(8)]);
        $this->solResults = [['esito' => 'ERRORE', 'errore' => 'smtp down segreto']];

        $res = $this->runJob();

        $this->assertCount(1, $this->solCalls);
        $this->assertSame('', $this->sollecitoGuard($gid), 'guard non scritto su errore');
        $sol = array_values(array_filter($res->errorDetails, static fn($d) => str_starts_with($d['ref'], 'sollecito')));
        $this->assertSame([['ref' => 'sollecito-gruppo-' . $gid, 'reason' => 'Invio sollecito operatore fallito']], $sol);
        $this->assertStringNotContainsString('segreto', json_encode($res->errorDetails));

        // al giro successivo viene ritentato e va a buon fine
        $res2 = $this->runJob();
        $this->assertCount(2, $this->solCalls);
        $this->assertSame(self::today(), $this->sollecitoGuard($gid));
        $this->assertSame([], array_values(array_filter($res2->errorDetails, static fn($d) => str_starts_with($d['ref'], 'sollecito'))));
    }

    public function testSollecitoEccezioneMailerSegnalatoSenzaMessaggioENonFermaIlGiro(): void
    {
        $gid = $this->scenarioSollecito();
        $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(8)]);
        $this->solResults = [new \RuntimeException('boom segreto')];

        $res = $this->runJob();

        $this->assertSame('', $this->sollecitoGuard($gid));
        $this->assertContains(['ref' => 'sollecito', 'reason' => 'Errore invio solleciti'], $res->errorDetails);
        $this->assertStringNotContainsString('segreto', json_encode($res->errorDetails));
        $this->assertSame('0', $this->counter(), 'contatore comunque gestito');
    }

    public function testSollecitoGruppoFallitoNonRimandaAiGruppiGiaServiti(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $g1 = $this->gruppo('Alfa', ['TARI' => 'NOTIFICA_E_SMARCATURA'], [$this->user('a@example.test')]);
        $g2 = $this->gruppo('Beta', ['IMU' => 'NOTIFICA_E_SMARCATURA'], [$this->user('b@example.test')]);
        $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(9), 'cod_entrata' => 'TARI']);
        $this->riga(2, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(9), 'cod_entrata' => 'IMU']);
        $this->solResults = [['esito' => 'OK'], ['esito' => 'ERRORE', 'errore' => 'x']];

        $this->runJob();
        $this->assertCount(2, $this->solCalls);
        $this->assertSame(self::today(), $this->sollecitoGuard($g1));
        $this->assertSame('', $this->sollecitoGuard($g2));

        $this->runJob();
        $this->assertCount(3, $this->solCalls, 'solo il gruppo fallito viene ritentato');
        $this->assertSame('Beta', $this->solCalls[2]['gruppo']);
        $this->assertSame(self::today(), $this->sollecitoGuard($g2));
    }

    public function testSollecitoSenzaDestinatariSkippedSenzaGuardNeErrori(): void
    {
        $this->setting('scansioni_quiete_soglia', '1');
        $gid = $this->gruppo('Vuoto', ['TARI' => 'NOTIFICA_E_SMARCATURA'], []);
        $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(8)]);
        $this->solResults = [['esito' => 'SKIPPED']];

        $res = $this->runJob();

        $this->assertCount(1, $this->solCalls);
        $this->assertSame([], $this->solCalls[0]['emails']);
        $this->assertSame('', $this->sollecitoGuard($gid));
        $this->assertSame([], array_values(array_filter($res->errorDetails, static fn($d) => str_starts_with($d['ref'], 'sollecito'))));
    }

    public function testSollecitoAdminSempreInCopiaAncheSenzaNotificaAdminAuto(): void
    {
        $this->scenarioSollecito();
        $this->setting('notifica_admin_auto', 'false');
        $this->setting('admin_emails', 'admin@example.test; op@example.test');
        $this->riga(1, ['rendicontazione_stato' => 'IN_ATTESA_CONFERMA', 'data_flusso' => self::today(8)]);

        $this->runJob();

        $this->assertCount(1, $this->solCalls);
        $emails = $this->solCalls[0]['emails'];
        sort($emails);
        $this->assertSame(['admin@example.test', 'op@example.test'], $emails, 'admin inclusi con notifica_admin_auto=false, duplicati rimossi');
    }
}
