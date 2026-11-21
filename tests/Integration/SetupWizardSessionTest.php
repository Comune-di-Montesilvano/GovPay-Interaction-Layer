<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Config\SettingsRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Integration\Support\IntegrationTestCase;

/**
 * Wizard di primo avvio su installazione nuova: il token CSRF del wizard nasce in una GET e
 * deve sopravvivere fino alla POST dello step. ReleaseSessionLockMiddleware chiude la sessione
 * sulle GET prima del controller: senza eccezione per /setup il token non veniva mai salvato e
 * ogni step 1 tornava con ?error=csrf (trovato dall'e2e).
 * Processo separato: ConfigLoader::isSetupComplete() memorizza l'esito per processo e gli
 * altri test dell'integrazione partono da setup completato.
 */
#[Group('db')]
#[Group('integration')]
final class SetupWizardSessionTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::pdo()->exec("DELETE FROM settings WHERE section = 'setup' AND key_name = 'complete'");
        SettingsRepository::flush();
    }

    private function wizardToken(string $path): string
    {
        $html = (string)$this->request('GET', $path)->getBody();
        self::assertMatchesRegularExpression('/name="csrf_token" value="[0-9a-f]+"/', $html, "nessun token in $path");
        preg_match('/name="csrf_token" value="([0-9a-f]+)"/', $html, $m);
        return $m[1];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTokenDelWizardStabileTraLeGet(): void
    {
        self::assertSame($this->wizardToken('/setup/step/1'), $this->wizardToken('/setup/step/1'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStep1AccettatoConIlTokenDellaPagina(): void
    {
        $token = $this->wizardToken('/setup/step/1');
        $resp = $this->request('POST', '/setup/step/1', [
            'csrf_token' => $token,
            'entity_name' => 'Comune di Esempio',
            'id_dominio' => self::DOMINIO,
            'id_a2a' => self::ID_A2A,
        ]);
        self::assertSame(302, $resp->getStatusCode());
        self::assertSame('/setup/step/2', $resp->getHeaderLine('Location'));
    }
}
