<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Config\SettingsRepository;
use App\Controllers\ReportRagioneriaController;
use App\Security\JobControlCsrf;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\Support\DbTestCase;

/**
 * Fix round 2 (finding 7): il form "Reset errori Biz" del report ragioneria rimette in coda
 * righe Biz: senza token CSRF valido errore flash + redirect, nessuna riga toccata.
 */
#[Group('db')]
final class ReportRagioneriaCsrfTest extends DbTestCase
{
    protected static array $tables = ['settings', 'biz_ricevute'];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsRepository::flush();
        SettingsRepository::set('entity', 'id_dominio', '80000000001');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = ['user' => ['id' => 1, 'role' => 'admin']];
        self::pdo()->exec("INSERT INTO biz_ricevute (id_dominio, id_flusso, iur, iuv, anno, mese, data_pagamento, importo, descrizione, stato, error_msg)
            VALUES ('80000000001', 'F1', 'IUR-1', 'IUV-1', 2026, 3, '2026-03-01', 10.00, 'x', 'ERROR', 'HTTP 503')");
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        SettingsRepository::flush();
        parent::tearDown();
    }

    /** @param array<string,string> $body */
    private static function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/pagamenti/report-ragioneria/biz-reset-errors')
            ->withParsedBody($body + ['idDominio' => '80000000001']);
    }

    private static function rows(): array
    {
        return self::pdo()->query('SELECT iur, stato, error_msg FROM biz_ricevute ORDER BY iur')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testResetWithoutOrWithWrongTokenIsRefusedAndChangesNothing(): void
    {
        JobControlCsrf::token();
        $before = self::rows();
        $twig = Twig::create(dirname(__DIR__, 2) . '/backoffice/templates');

        foreach ([[], ['csrf_token' => 'sbagliato']] as $body) {
            $_SESSION['flash'] = [];
            $r = (new ReportRagioneriaController($twig))->resetBizErrors(self::post($body), new Response());
            self::assertSame(302, $r->getStatusCode());
            self::assertSame('/pagamenti/report-ragioneria', $r->getHeaderLine('Location'));
            self::assertSame([['type' => 'error', 'text' => JobControlCsrf::ERROR_MESSAGE]], $_SESSION['flash']);
        }
        self::assertSame($before, self::rows());
    }

    public function testResetWithValidTokenRuns(): void
    {
        $_SESSION['flash'] = [];
        $twig = Twig::create(dirname(__DIR__, 2) . '/backoffice/templates');
        $r = (new ReportRagioneriaController($twig))->resetBizErrors(self::post(['csrf_token' => JobControlCsrf::token()]), new Response());

        self::assertSame(302, $r->getStatusCode());
        self::assertSame('success', $_SESSION['flash'][0]['type'] ?? null);
        self::assertStringStartsWith('Reset errori Biz completato', $_SESSION['flash'][0]['text'] ?? '');
    }

    public function testResetByNonAdminIsRefusedEvenWithValidToken(): void
    {
        $token = JobControlCsrf::token();
        $before = self::rows();
        $twig = Twig::create(dirname(__DIR__, 2) . '/backoffice/templates');

        foreach (['operator', 'user', ''] as $role) {
            $_SESSION['user'] = ['id' => 2, 'role' => $role];
            $_SESSION['flash'] = [];
            $r = (new ReportRagioneriaController($twig))->resetBizErrors(self::post(['csrf_token' => $token]), new Response());
            self::assertSame(302, $r->getStatusCode());
            self::assertSame('error', $_SESSION['flash'][0]['type'] ?? null);
            self::assertSame($before, self::rows());
        }
    }

    public function testResetBySuperadminRuns(): void
    {
        $_SESSION['user'] = ['id' => 3, 'role' => 'superadmin'];
        $_SESSION['flash'] = [];
        $twig = Twig::create(dirname(__DIR__, 2) . '/backoffice/templates');
        (new ReportRagioneriaController($twig))->resetBizErrors(self::post(['csrf_token' => JobControlCsrf::token()]), new Response());
        self::assertSame('success', $_SESSION['flash'][0]['type'] ?? null);
    }
}
