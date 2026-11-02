<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Config\SettingsRepository;
use App\Controllers\CronController;
use App\Database\JobStateRepository;
use App\Database\MassivePendenzeRepository;
use App\Security\JobControlCsrf;
use App\Services\Jobs\JobControl;
use App\Services\Jobs\RagioneriaSyncJob;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\Support\DbTestCase;

/**
 * Fix round 1, item 7: ogni POST di controllo lavori della pagina Cron / Services Hub / riprova
 * massive richiede il token CSRF condiviso (sessione `cron_csrf`, campo `csrf_token`). Senza
 * token o con token sbagliato: 403 JSON (form: errore flash + redirect) e nessuna modifica.
 */
#[Group('db')]
final class CronControllerCsrfTest extends DbTestCase
{
    protected static array $tables = ['gil_jobs', 'gil_job_runs', 'gil_job_run_errors', 'lock_keys', 'pendenze_massive'];

    private JobStateRepository $state;
    /** @var list<string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = new JobStateRepository(static fn(): \PDO => self::pdo());
        $this->enqueued = [];
        self::pdo()->exec("DELETE FROM settings WHERE section = 'backoffice' AND key_name IN ('" . RagioneriaSyncJob::RESCAN_SETTING . "', 'ragioneria_scan_da')");
        SettingsRepository::flush();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = ['user' => ['id' => 1, 'role' => 'admin']];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        SettingsRepository::flush();
        parent::tearDown();
    }

    private function controller(): CronController
    {
        $control = new JobControl(
            $this->state,
            function (string $job): void {
                $this->enqueued[] = $job;
            },
            static fn(): MassivePendenzeRepository => new MassivePendenzeRepository(self::pdo()),
            static fn(string $job): bool => false
        );
        return new CronController(Twig::create(dirname(__DIR__, 2) . '/backoffice/templates'), $control);
    }

    /** @param array<string,string> $body */
    private static function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/funzioni-avanzate/cron/x')->withParsedBody($body);
    }

    /** @return array<string,mixed> */
    private static function json(ResponseInterface $r): array
    {
        $d = json_decode((string)$r->getBody(), true);
        self::assertIsArray($d);
        return $d;
    }

    /** @return array<string, \Closure(CronController, array<string,string>): ResponseInterface> */
    private function jsonActions(int $rowId): array
    {
        return [
            'enabled' => static fn(CronController $c, array $b) => $c->setEnabled(self::post($b + ['enabled' => '1']), new Response(), ['job' => 'biz']),
            'run'     => static fn(CronController $c, array $b) => $c->run(self::post($b), new Response(), ['job' => 'vocab']),
            'stop'    => static fn(CronController $c, array $b) => $c->stop(self::post($b), new Response(), ['job' => 'tefa']),
            'rescan'  => static fn(CronController $c, array $b) => $c->forceRescan(self::post($b), new Response()),
            'retry'   => static fn(CronController $c, array $b) => $c->retryMassiveRow(self::post($b), new Response(), ['rowId' => (string)$rowId]),
        ];
    }

    private function snapshot(int $rowId): array
    {
        SettingsRepository::flush();
        return [
            'jobs' => self::pdo()->query('SELECT name, enabled, next_run_at FROM gil_jobs ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC),
            'row' => self::pdo()->query('SELECT stato FROM pendenze_massive WHERE id = ' . $rowId)->fetchColumn(),
            'rescan' => SettingsRepository::get('backoffice', RagioneriaSyncJob::RESCAN_SETTING),
            'enqueued' => $this->enqueued,
        ];
    }

    private function seed(): int
    {
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO gil_jobs (name, enabled, next_run_at) VALUES ('biz', 0, '2099-01-01 00:00:00'), ('tefa', 1, '2099-01-01 00:00:00'), ('ragioneria', 1, '2099-01-01 00:00:00'), ('pendenze-massive', 1, '2099-01-01 00:00:00')");
        $pdo->exec("INSERT INTO pendenze_massive (file_batch_id, riga, stato, errore, payload_json, response_json, claimed_at) VALUES ('B1', 1, 'ERROR', 'HTTP 503', '{}', '{\"idPendenza\":\"GIL-r\"}', NOW())");
        return (int)$pdo->lastInsertId();
    }

    public function testJsonActionsWithoutOrWithWrongTokenAre403AndChangeNothing(): void
    {
        $rowId = $this->seed();
        JobControlCsrf::token();
        $before = $this->snapshot($rowId);

        foreach ($this->jsonActions($rowId) as $name => $action) {
            foreach ([[], ['csrf_token' => 'sbagliato']] as $body) {
                $r = $action($this->controller(), $body);
                self::assertSame(403, $r->getStatusCode(), $name);
                self::assertSame(JobControlCsrf::errorPayload(), self::json($r), $name);
            }
        }

        self::assertSame($before, $this->snapshot($rowId), 'nessuna modifica di stato');
    }

    public function testJsonActionsWithValidTokenSucceed(): void
    {
        $rowId = $this->seed();
        $token = ['csrf_token' => JobControlCsrf::token()];

        foreach ($this->jsonActions($rowId) as $name => $action) {
            $r = $action($this->controller(), $token);
            self::assertSame(200, $r->getStatusCode(), $name . ': ' . $r->getBody());
            self::assertTrue(self::json($r)['ok'], $name);
        }

        self::assertTrue($this->state->isEnabled('biz'));
        self::assertFalse($this->state->isEnabled('tefa'));
        self::assertSame(['vocab'], $this->enqueued);
        self::assertSame('PENDING', self::pdo()->query('SELECT stato FROM pendenze_massive WHERE id = ' . $rowId)->fetchColumn());
        SettingsRepository::flush();
        self::assertSame('1', SettingsRepository::get('backoffice', RagioneriaSyncJob::RESCAN_SETTING));
    }

    public function testScanDateFormRequiresToken(): void
    {
        JobControlCsrf::token();
        $r = $this->controller()->setScanDate(self::post(['ragioneria_scan_da' => '2025-03-01']), new Response());

        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/funzioni-avanzate/cron', $r->getHeaderLine('Location'));
        self::assertSame(JobControlCsrf::ERROR_MESSAGE, end($_SESSION['flash'])['text']);
        SettingsRepository::flush();
        self::assertNull(SettingsRepository::get('backoffice', 'ragioneria_scan_da'));

        $r = $this->controller()->setScanDate(self::post(['ragioneria_scan_da' => '2025-03-01', 'csrf_token' => JobControlCsrf::token()]), new Response());
        self::assertSame(302, $r->getStatusCode());
        SettingsRepository::flush();
        self::assertSame('2025-03-01', SettingsRepository::get('backoffice', 'ragioneria_scan_da'));
    }

    public function testResetRangeFormRequiresTokenAndDeletesNothingWithout(): void
    {
        $r = $this->controller()->resetDateRange(self::post(['reset_data_da' => '2026-01-01', 'reset_data_a' => '2026-01-31']), new Response());

        self::assertSame(303, $r->getStatusCode());
        self::assertSame(JobControlCsrf::ERROR_MESSAGE, end($_SESSION['flash'])['text']);
        self::assertStringNotContainsString('Reset completato', json_encode($_SESSION['flash']));
    }

    /**
     * Fix round 2 (minor): sulla GET la sessione e' gia' chiusa da ReleaseSessionLockMiddleware.
     * Token presente: letto senza riaprire la sessione. Assente: aperta, scritto, richiusa subito,
     * e il token resta persistito per il POST successivo.
     */
    public function testTokenDoesNotReopenClosedSession(): void
    {
        $existing = JobControlCsrf::token();
        session_write_close();
        self::assertSame($existing, JobControlCsrf::token());
        self::assertSame(PHP_SESSION_NONE, session_status(), 'token presente: nessun session_start');

        @session_start();
        unset($_SESSION[JobControlCsrf::SESSION_KEY]);
        session_write_close();
        $fresh = JobControlCsrf::token();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fresh);
        self::assertSame(PHP_SESSION_NONE, session_status(), 'token assente: sessione richiusa subito');

        $_SESSION = [];
        @session_start();
        self::assertSame($fresh, $_SESSION[JobControlCsrf::SESSION_KEY] ?? null, 'token persistito');
    }

    public function testTokenIsStablePerSessionAndRandom(): void
    {
        $t = JobControlCsrf::token();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t);
        self::assertSame($t, JobControlCsrf::token());
        self::assertSame($t, $_SESSION[JobControlCsrf::SESSION_KEY]);
    }
}
