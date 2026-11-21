<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Support\IntegrationTestCase;

/** AuthMiddleware single-session: il login piu' recente dello stesso utente invalida i precedenti. */
#[Group('db')]
#[Group('integration')]
final class SingleSessionTest extends IntegrationTestCase
{
    public function testSecondoLoginInvalidaIlPrimo(): void
    {
        $this->creaOperatore('admin');

        $this->useSession('postazione-a');
        $this->loginOperatore();
        self::assertSame(200, $this->request('GET', '/')->getStatusCode());

        $this->useSession('postazione-b');
        $this->loginOperatore();

        $this->useSession('postazione-a');
        $resp = $this->request('GET', '/');
        self::assertSame(302, $resp->getStatusCode());
        self::assertSame('/login', $resp->getHeaderLine('Location'));

        $this->useSession('postazione-b');
        self::assertSame(200, $this->request('GET', '/')->getStatusCode());
    }
}
