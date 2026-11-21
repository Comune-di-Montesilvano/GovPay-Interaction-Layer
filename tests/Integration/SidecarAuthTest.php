<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Support\IntegrationTestCase;

/** API sidecar /api/frontoffice/*: Bearer MASTER_TOKEN obbligatorio, fuori dal CSRF globale. */
#[Group('db')]
#[Group('integration')]
final class SidecarAuthTest extends IntegrationTestCase
{
    public function testSenzaBearerRifiutato(): void
    {
        $resp = $this->request('GET', '/api/frontoffice/tipologie', [], ['Accept' => 'application/json']);
        self::assertSame(401, $resp->getStatusCode());
        self::assertFalse(self::json($resp)['success']);
    }

    public function testBearerErratoRifiutato(): void
    {
        $resp = $this->request('GET', '/api/frontoffice/tipologie', [], self::bearer('token-sbagliato'));
        self::assertSame(401, $resp->getStatusCode());
    }

    public function testBearerValidoAccettato(): void
    {
        $resp = $this->request('GET', '/api/frontoffice/tipologie', [], self::bearer());
        self::assertSame(200, $resp->getStatusCode(), (string)$resp->getBody());
        self::assertTrue(self::json($resp)['success']);
        $this->assertNoUnmatched();
    }

    public function testPostSidecarNonSoggettoACsrf(): void
    {
        $resp = $this->request('POST', '/api/frontoffice/rate-limit/check', ['bucket' => 'test:integrazione', 'max' => 5, 'window' => 60], self::bearer());
        self::assertNotSame(403, $resp->getStatusCode());
        self::assertNotSame(303, $resp->getStatusCode());
        self::assertTrue(self::json($resp)['success'], (string)$resp->getBody());
    }
}
