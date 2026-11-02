<?php
declare(strict_types=1);

namespace Tests\Controllers;

use App\Controllers\MassivePendenzeController;
use App\Security\JobControlCsrf;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\Support\DbTestCase;

/**
 * Ogni POST di MassivePendenzeController che cambia stato richiede il token CSRF condiviso
 * (sessione `cron_csrf`, campo `csrf_token`). Form: flash + redirect; JSON (export avvisi): 403.
 */
#[Group('db')]
final class MassivePendenzeControllerCsrfTest extends DbTestCase
{
    protected static array $tables = ['pendenze_massive'];

    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = ['user' => ['id' => 1, 'role' => 'operator']];
        self::pdo()->exec("INSERT INTO pendenze_massive (file_batch_id, riga, stato, payload_json) VALUES ('B1', 1, 'PENDING', '{}')");
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function controller(): MassivePendenzeController
    {
        return new MassivePendenzeController(Twig::create(dirname(__DIR__, 2) . '/backoffice/templates'));
    }

    /** @param array<string,string> $body */
    private static function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/pendenze/massivo/x')->withParsedBody($body);
    }

    private static function states(): array
    {
        return self::pdo()->query('SELECT file_batch_id, riga, stato FROM pendenze_massive ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return array<string, \Closure(MassivePendenzeController, array<string,string>): ResponseInterface> */
    private function formActions(): array
    {
        return [
            'upload'   => fn(MassivePendenzeController $c, array $b) => $c->upload(self::post($b), new Response()),
            'conferma' => fn(MassivePendenzeController $c, array $b) => $c->conferma(self::post($b + ['batch_id' => 'B2', 'idTipoPendenza' => 'T']), new Response()),
            'pausa'    => fn(MassivePendenzeController $c, array $b) => $c->azioneBatch(self::post($b), new Response(), 'B1', 'PAUSE'),
            'riprendi' => fn(MassivePendenzeController $c, array $b) => $c->azioneBatch(self::post($b), new Response(), 'B1', 'RESUME'),
            'elimina'  => fn(MassivePendenzeController $c, array $b) => $c->azioneBatch(self::post($b), new Response(), 'B1', 'DELETE'),
            'annulla'  => fn(MassivePendenzeController $c, array $b) => $c->azioneBatch(self::post($b), new Response(), 'B1', 'CANCEL'),
        ];
    }

    public function testFormActionsWithoutOrWithWrongTokenAreRefusedAndChangeNothing(): void
    {
        JobControlCsrf::token();
        $_SESSION['massive_valid_rows']['B2'] = [['_row' => 1, 'importo' => '1']];
        $before = self::states();

        foreach ($this->formActions() as $name => $action) {
            foreach ([[], ['csrf_token' => 'sbagliato']] as $body) {
                $_SESSION['flash'] = [];
                $r = $action($this->controller(), $body);
                self::assertSame(303, $r->getStatusCode(), $name);
                self::assertStringStartsWith('/pendenze/massivo/', $r->getHeaderLine('Location'), $name);
                self::assertSame([['type' => 'error', 'text' => JobControlCsrf::ERROR_MESSAGE]], $_SESSION['flash'], $name);
            }
        }
        self::assertSame($before, self::states());
    }

    public function testBatchActionsWithValidTokenWork(): void
    {
        $token = JobControlCsrf::token();
        $r = $this->formActions()['pausa']($this->controller(), ['csrf_token' => $token]);
        self::assertSame(302, $r->getStatusCode());
        self::assertSame('PAUSED', self::states()[0]['stato']);

        $this->formActions()['riprendi']($this->controller(), ['csrf_token' => $token]);
        self::assertSame('PENDING', self::states()[0]['stato']);

        $this->formActions()['elimina']($this->controller(), ['csrf_token' => $token]);
        self::assertSame([], self::states());
    }

    public function testConfermaWithValidTokenInsertsRows(): void
    {
        $_SESSION['massive_valid_rows']['B2'] = [['_row' => 1, 'tipo' => 'F', 'importo' => '1.00', 'causale' => 'x', 'annoRiferimento' => 2026, 'identificativo' => 'RSSMRA80A01H501U', 'anagrafica' => 'Rossi', 'nome' => 'Mario', 'voci' => [['importo' => 1.0]], 'dataScadenza' => '2030-01-01']];
        $r = $this->formActions()['conferma']($this->controller(), ['csrf_token' => JobControlCsrf::token()]);
        self::assertSame(302, $r->getStatusCode());
        self::assertStringContainsString('batch=B2', $r->getHeaderLine('Location'));
        $n = (int)self::pdo()->query("SELECT COUNT(*) FROM pendenze_massive WHERE file_batch_id = 'B2'")->fetchColumn();
        self::assertGreaterThan(0, $n);
    }

    public function testExportAvvisiWithoutOrWithWrongTokenIs403(): void
    {
        JobControlCsrf::token();
        foreach ([[], ['csrf_token' => 'sbagliato']] as $body) {
            $r = $this->controller()->avviaExportAvvisi(self::post($body), new Response(), 'B1');
            self::assertSame(403, $r->getStatusCode());
            $d = json_decode((string)$r->getBody(), true);
            self::assertFalse($d['ok']);
            self::assertSame(JobControlCsrf::ERROR_MESSAGE, $d['error']);
        }
    }

    public function testExportAvvisiWithValidTokenPassesCsrfGate(): void
    {
        // Nessuna riga SUCCESS: il controllo CSRF e' superato e si arriva al 409 di dominio (nessun worker avviato).
        $r = $this->controller()->avviaExportAvvisi(self::post(['csrf_token' => JobControlCsrf::token()]), new Response(), 'B1');
        self::assertSame(409, $r->getStatusCode());
    }
}
