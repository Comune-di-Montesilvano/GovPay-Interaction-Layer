<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Config\SettingsRepository;
use App\Controllers\ReportTefaController;
use App\Database\JobStateRepository;
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
    protected static array $tables = ['settings', 'gil_jobs'];

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
    private static function post(array $body = []): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/pagamenti/report-tefa/scan')->withParsedBody($body);
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
