<?php
declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\CsrfMiddleware;
use App\Security\JobControlCsrf;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class CsrfMiddlewareTest extends TestCase
{
    private int $handled = 0;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $this->handled = 0;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $handler = new class ($this->handled) implements RequestHandlerInterface {
            public function __construct(private int &$count)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->count++;
                return new Response(200);
            }
        };
        return (new CsrfMiddleware())->process($request, $handler);
    }

    /** @param array<string,string> $body */
    private static function post(string $path, array $body = [], array $headers = [], string $method = 'POST'): ServerRequestInterface
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, 'http://gil.test' . $path)->withParsedBody($body);
        foreach ($headers as $k => $v) {
            $r = $r->withHeader($k, $v);
        }
        return $r;
    }

    public function testGetPassesAndCreatesTokenWithoutRequiringOne(): void
    {
        $r = $this->dispatch((new ServerRequestFactory())->createServerRequest('GET', 'http://gil.test/pendenze'));
        self::assertSame(200, $r->getStatusCode());
        self::assertNotSame('', $_SESSION[JobControlCsrf::SESSION_KEY] ?? '');
    }

    public function testFormPostWithoutOrWrongTokenRedirectsWithFlashAndDoesNotRunHandler(): void
    {
        $token = JobControlCsrf::token();
        foreach ([[], ['csrf_token' => 'sbagliato'], ['csrf_token' => '']] as $body) {
            $_SESSION['flash'] = [];
            $r = $this->dispatch(self::post('/users/new', $body, ['Referer' => 'http://gil.test/users?x=1']));
            self::assertSame(303, $r->getStatusCode());
            self::assertSame('/users?x=1', $r->getHeaderLine('Location'));
            self::assertSame([['type' => 'error', 'text' => JobControlCsrf::ERROR_MESSAGE]], $_SESSION['flash']);
        }
        self::assertSame(0, $this->handled);
        self::assertNotSame('', $token);
    }

    public function testExternalRefererFallsBackToRoot(): void
    {
        JobControlCsrf::token();
        foreach (['http://evil.test/x', '//evil.test', 'not a url'] as $ref) {
            $r = $this->dispatch(self::post('/login', [], ['Referer' => $ref]));
            self::assertSame('/', $r->getHeaderLine('Location'), $ref);
        }
    }

    public function testJsonRequestsGet403Payload(): void
    {
        JobControlCsrf::token();
        $variants = [
            ['Accept' => 'application/json'],
            ['X-Requested-With' => 'XMLHttpRequest'],
            ['X-CSRF-Token' => 'sbagliato'],
        ];
        foreach ($variants as $h) {
            $r = $this->dispatch(self::post('/pendenze/annulla/1', [], $h));
            self::assertSame(403, $r->getStatusCode());
            self::assertSame(JobControlCsrf::errorPayload(), json_decode((string)$r->getBody(), true));
        }
        self::assertSame(0, $this->handled);
    }

    public function testValidBodyOrHeaderTokenPassesForAllStateChangingMethods(): void
    {
        $token = JobControlCsrf::token();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            self::assertSame(200, $this->dispatch(self::post('/x', ['csrf_token' => $token], [], $m))->getStatusCode(), $m);
            self::assertSame(200, $this->dispatch(self::post('/x', [], ['X-CSRF-Token' => $token], $m))->getStatusCode(), $m);
        }
        self::assertSame(8, $this->handled);
    }

    public function testPerControllerTokensStillAccepted(): void
    {
        JobControlCsrf::token();
        foreach (['impostazioni_csrf', 'backup_csrf', 'mapping_csrf', 'rendicontazione_csrf'] as $key) {
            $_SESSION[$key] = bin2hex(random_bytes(8));
            self::assertSame(200, $this->dispatch(self::post('/impostazioni/x', ['csrf_token' => $_SESSION[$key]]))->getStatusCode(), $key);
        }
        // ...ma il token legacy non vale nell'header e un valore vuoto non combacia con chiavi assenti
        self::assertSame(403, $this->dispatch(self::post('/impostazioni/x', [], ['X-CSRF-Token' => $_SESSION['mapping_csrf']]))->getStatusCode());
        unset($_SESSION['mapping_csrf']);
        self::assertSame(303, $this->dispatch(self::post('/impostazioni/x', ['csrf_token' => 'x']))->getStatusCode());
    }

    public function testNoSessionTokenMeansRefusal(): void
    {
        $_SESSION = [];
        self::assertSame(303, $this->dispatch(self::post('/login', ['csrf_token' => 'qualcosa']))->getStatusCode());
        self::assertSame(0, $this->handled);
    }

    public function testExclusions(): void
    {
        JobControlCsrf::token();
        foreach (['/api/frontoffice/pendenze', '/api/frontoffice/carrello/checkout', '/setup/step/2', '/setup', '/setup/complete'] as $p) {
            self::assertSame(200, $this->dispatch(self::post($p))->getStatusCode(), $p);
        }
        // Non escluse: /api non-frontoffice e prefissi simili
        foreach (['/api/dashboard/stats', '/api/frontofficeX', '/setupfoo', '/login', '/password-dimenticata', '/reset-password'] as $p) {
            self::assertNotSame(200, $this->dispatch(self::post($p))->getStatusCode(), $p);
        }
    }
}
