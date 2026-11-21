<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Security\JobControlCsrf;
use PHPUnit\Framework\Attributes\Group;
use Tests\Integration\Support\IntegrationTestCase;

/** CSRF globale (CsrfMiddleware) sullo stack reale: form → 303, JSON/XHR → 403, token valido passa. */
#[Group('db')]
#[Group('integration')]
final class CsrfIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->creaOperatore('superadmin');
        $this->loginOperatore();
    }

    public function testFormSenzaTokenRedirect303(): void
    {
        $resp = $this->request('POST', '/impostazioni/generale/save', ['app_entity_name' => 'Comune di Esempio'], [
            'Referer' => 'https://sito-esterno.example.test/pagina',
        ]);
        self::assertSame(303, $resp->getStatusCode());
        // Referer di un altro host: mai open redirect.
        self::assertSame('/', $resp->getHeaderLine('Location'));
        self::assertContains(JobControlCsrf::ERROR_MESSAGE, self::flashTexts());
    }

    public function testFormSenzaTokenTornaAlRefererSameOrigin(): void
    {
        $resp = $this->request('POST', '/impostazioni/generale/save', ['app_entity_name' => 'Comune di Esempio'], [
            'Referer' => 'http://backoffice/impostazioni?tab=generale',
        ]);
        self::assertSame(303, $resp->getStatusCode());
        self::assertSame('/impostazioni?tab=generale', $resp->getHeaderLine('Location'));
    }

    public function testXhrSenzaToken403Json(): void
    {
        $resp = $this->request('POST', '/impostazioni/generale/save', '{}', [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
        self::assertSame(403, $resp->getStatusCode());
        self::assertFalse(self::json($resp)['success']);
    }

    public function testTokenValidoNonBloccato(): void
    {
        $token = $this->csrfToken('/impostazioni');
        $resp = $this->request('POST', '/impostazioni/generale/save', ['csrf_token' => $token, 'app_entity_name' => 'Comune di Esempio']);
        self::assertNotSame(403, $resp->getStatusCode());
        // Un eventuale redirect e' del controller: nessun messaggio di token CSRF non valido.
        self::assertNotContains(JobControlCsrf::ERROR_MESSAGE, self::flashTexts());
    }

    /** @return list<string> testi flash lasciati in sessione dall'ultima richiesta */
    private static function flashTexts(): array
    {
        return array_map(static fn(array $f): string => (string)($f['text'] ?? ''), $_SESSION['flash'] ?? []);
    }
}
