<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\SessionMiddleware;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Parametri del cookie di sessione backoffice impostati PRIMA di qualunque session_start():
 * CurrentPathMiddleware gira prima di SessionMiddleware (LIFO di Slim) e apriva la sessione con
 * i default PHP (PHPSESSID senza HttpOnly/SameSite, rilevato da ZAP).
 */
final class SessionCookieParamsTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testHttpOnlyELaxSuHttp(): void
    {
        SessionMiddleware::configureCookieParams(['HTTPS' => 'off']);
        $p = session_get_cookie_params();
        $this->assertTrue($p['httponly']);
        $this->assertSame('Lax', $p['samesite']);
        $this->assertFalse($p['secure']);
        $this->assertSame('/', $p['path']);
        $this->assertSame(0, $p['lifetime']);
    }

    #[RunInSeparateProcess]
    public function testSecureDietroProxyHttps(): void
    {
        SessionMiddleware::configureCookieParams(['HTTP_X_FORWARDED_PROTO' => 'https']);
        $this->assertTrue(session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    public function testSecureConHttpsDiretto(): void
    {
        SessionMiddleware::configureCookieParams(['HTTPS' => 'on']);
        $this->assertTrue(session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    public function testNessunEffettoConSessioneGiaAttiva(): void
    {
        session_start();
        SessionMiddleware::configureCookieParams(['HTTPS' => 'on']);
        $this->assertFalse(session_get_cookie_params()['secure']);
        session_write_close();
    }

    public function testBootstrapLiImpostaPrimaDeiMiddleware(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/backoffice/src/bootstrap/app.php');
        $call = strpos($src, 'SessionMiddleware::configureCookieParams(');
        $this->assertNotFalse($call, 'bootstrap senza configureCookieParams()');
        $firstAdd = strpos($src, '$app->add(');
        $this->assertNotFalse($firstAdd);
        $this->assertLessThan($firstAdd, $call, 'configureCookieParams() va chiamato prima dei middleware');
    }
}
