<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Config\SettingsRepository;
use App\Controllers\ReportTefaController;
use App\Database\JobStateRepository;
use App\Security\JobControlCsrf;
use App\Services\Jobs\JobControl;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\Support\DbTestCase;

/**
 * Fix round 1: avvio/pausa dei servizi TEFA/Biz dal report = flag gil_jobs. Un errore nel
 * salvataggio del flag torna come errore JSON (mai eccezione non gestita) e il messaggio di
 * avvio dice cosa succede davvero (servizio attivato, scansione al prossimo giro).
 */
#[Group('db')]
final class ReportTefaControllerTest extends DbTestCase
{
    protected static array $tables = ['settings', 'gil_jobs', 'tefa_ricevute'];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        SettingsRepository::set('backoffice', 'tefa_enabled', 'true');
        SettingsRepository::set('entity', 'id_dominio', '80000000001');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['user'] = ['id' => 1, 'role' => 'admin'];
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user']);
        SettingsRepository::flush();
        parent::tearDown();
    }

    private function controller(?\Closure $jobControl = null): ReportTefaController
    {
        return new ReportTefaController(Twig::create(dirname(__DIR__, 2) . '/backoffice/templates'), $jobControl);
    }

    private static function failingControl(): \Closure
    {
        return static fn(): JobControl => new JobControl(new JobStateRepository(static function (): \PDO {
            throw new \PDOException('SQLSTATE[HY000] [2002] db giu');
        }));
    }

    /** @param array<string,string> $body */
    private static function post(array $body = [], bool $withToken = true): \Psr\Http\Message\ServerRequestInterface
    {
        if ($withToken) {
            $body['csrf_token'] = JobControlCsrf::token();
        }
        return (new ServerRequestFactory())->createServerRequest('POST', '/pagamenti/report-tefa/scan')->withParsedBody($body);
    }

    /** Fix round 1, item 7: avvio/pausa senza token CSRF valido -> 403, flag gil_jobs invariato. */
    public function testControlsWithoutValidCsrfTokenAre403AndChangeNothing(): void
    {
        self::pdo()->exec("INSERT INTO gil_jobs (name, enabled) VALUES ('tefa', 0), ('biz', 1)");
        $c = $this->controller();
        foreach (['scan', 'stop', 'bizScan', 'bizStop'] as $method) {
            foreach ([self::post([], false), self::post(['csrf_token' => 'sbagliato'], false)] as $req) {
                $r = $c->{$method}($req, new Response());
                self::assertSame(403, $r->getStatusCode(), $method);
                self::assertSame(JobControlCsrf::errorPayload(), self::json($r), $method);
            }
        }
        $repo = JobStateRepository::default();
        self::assertFalse($repo->isEnabled('tefa'));
        self::assertTrue($repo->isEnabled('biz'));
    }

    /**
     * Fix round 2 (finding 7): anche riprova errori / riprova non-TEFA / correggi date e
     * override anomalie richiedono il token: senza o sbagliato 403 e nessuna riga toccata.
     */
    public function testDataResetAndAnomalyPostsRequireCsrfToken(): void
    {
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO tefa_ricevute (id, id_dominio, anno, mese, id_flusso, iur, iuv, stato, error_msg) VALUES
            (1, '80000000001', 2026, 3, '2026-03-01PROVA', 'IUR-E', 'IUV-E', 'ERROR', 'x'),
            (2, '80000000001', 2026, 3, '2026-03-01PROVA', 'IUR-S', 'IUV-S', 'SKIPPED', NULL),
            (3, '80000000001', 2026, 3, '2026-03-01PROVA', 'IUR-A', 'IUV-A', 'SKIPPED', NULL)");
        $snapshot = static fn(): array => $pdo->query('SELECT id, stato, data_pagamento FROM tefa_ricevute ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $before = $snapshot();
        $c = $this->controller();
        $actions = [
            'retryErrors'   => static fn($req) => $c->retryErrors($req, new Response()),
            'retrySkipped'  => static fn($req) => $c->retrySkipped($req, new Response()),
            'fixDates'      => static fn($req) => $c->fixDates($req, new Response()),
            'anomalyAccept' => static fn($req) => $c->anomalyAccept($req, new Response(), ['id' => '3']),
            'anomalySkip'   => static fn($req) => $c->anomalySkip($req, new Response(), ['id' => '3']),
        ];
        foreach ($actions as $name => $action) {
            foreach ([self::post([], false), self::post(['csrf_token' => 'sbagliato'], false)] as $req) {
                $r = $action($req);
                self::assertSame(403, $r->getStatusCode(), $name);
                self::assertSame(JobControlCsrf::errorPayload(), self::json($r), $name);
            }
        }
        self::assertSame($before, $snapshot(), 'nessuna riga modificata senza token');

        self::assertSame(['ok' => true, 'reset' => 1], self::json($actions['retryErrors'](self::post())));
        self::assertSame(['ok' => true, 'reset' => 2], self::json($actions['retrySkipped'](self::post())));
        foreach (['fixDates', 'anomalyAccept', 'anomalySkip'] as $name) {
            $r = $actions[$name](self::post());
            self::assertSame(200, $r->getStatusCode(), $name . ': ' . $r->getBody());
            self::assertTrue(self::json($r)['ok'], $name);
        }
    }

    /** @return array<string,mixed> */
    private static function json(ResponseInterface $r): array
    {
        $d = json_decode((string)$r->getBody(), true);
        self::assertIsArray($d);
        return $d;
    }

    public function testScanStorageFailureReturnsJsonError(): void
    {
        $r = $this->controller(self::failingControl())->scan(self::post(), new Response());

        self::assertSame(500, $r->getStatusCode());
        self::assertSame(['ok' => false, 'error' => 'Attivazione del servizio tefa non riuscita: riprovare.'], self::json($r));
    }

    public function testStopAndBizControlsStorageFailureReturnJsonError(): void
    {
        $c = $this->controller(self::failingControl());

        foreach (['stop' => 'Pausa del servizio tefa', 'bizScan' => 'Attivazione del servizio biz', 'bizStop' => 'Pausa del servizio biz'] as $method => $prefix) {
            $r = $c->{$method}(self::post(), new Response());
            self::assertSame(500, $r->getStatusCode(), $method);
            self::assertSame(['ok' => false, 'error' => $prefix . ' non riuscita: riprovare.'], self::json($r), $method);
        }
    }

    public function testRangeScanMessageSaysServiceIsEnabledAndScanStartsAtNextRun(): void
    {
        $r = $this->controller()->scan(self::post(['dataDa' => '2026-01-01', 'dataA' => '2026-01-31']), new Response());

        self::assertSame(200, $r->getStatusCode());
        $d = self::json($r);
        self::assertTrue($d['ok']);
        self::assertSame('range', $d['mode']);
        self::assertSame(
            "Servizio TEFA attivato: la scansione parte al prossimo giro del worker ed elabora tutte le ricevute in coda, non solo l'intervallo 2026-01-01÷2026-01-31.",
            $d['result']['message']
        );
        self::assertTrue(JobStateRepository::default()->isEnabled('tefa'));
    }

    public function testLoopScanMessage(): void
    {
        $d = self::json($this->controller()->scan(self::post(), new Response()));

        self::assertSame('Servizio TEFA attivato: la scansione parte al prossimo giro del worker.', $d['result']['message']);
    }
}
