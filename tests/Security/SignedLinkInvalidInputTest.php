<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Security\SignedLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regressione: un link firmato non valido da' sempre false (pagina 403 del frontoffice), mai
 * un'eccezione (500). Gli input arrivano da $_GET: stringhe o array (`sig[]=x`).
 */
final class SignedLinkInvalidInputTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const NOW = 1700000000;

    /** @return array<string, mixed> */
    private static function firmato(): array
    {
        return SignedLink::sign(['type' => 'avviso', 'cf' => 'XXXXXX00X00X000X', 'iuv' => '000000000000000'], self::KEY, 3600, self::NOW);
    }

    public function testLinkValidoAncoraAccettato(): void
    {
        $this->assertTrue(SignedLink::verify(self::firmato(), self::KEY, self::NOW));
    }

    /** @return array<string, array{string, mixed}> */
    public static function campiNonValidiProvider(): array
    {
        return [
            'sig array' => ['sig', ['x']],
            'sig array associativo annidato' => ['sig', ['a' => ['b' => 'c']]],
            'sig array vuoto' => ['sig', []],
            'sig intero' => ['sig', 123],
            'sig null' => ['sig', null],
            'sig vuota' => ['sig', ''],
            'sig maiuscola' => ['sig', strtoupper((string)self::firmato()['sig'])],
            'sig troppo corta' => ['sig', substr((string)self::firmato()['sig'], 0, 63)],
            'sig non esadecimale' => ['sig', str_repeat('z', 64)],
            'expires array' => ['expires', ['1700003600']],
            'expires array vuoto' => ['expires', []],
            'expires intero' => ['expires', 1700003600],
            'expires null' => ['expires', null],
            'expires vuoto' => ['expires', ''],
            'expires zero' => ['expires', '0'],
            'expires negativo' => ['expires', '-1'],
            'expires esponenziale' => ['expires', '1e10'],
            'expires con spazio' => ['expires', ' 1700003600'],
            'expires con suffisso' => ['expires', '1700003600abc'],
            'expires enorme' => ['expires', str_repeat('9', 40)],
        ];
    }

    #[DataProvider('campiNonValidiProvider')]
    public function testCampoNonValidoRifiutatoSenzaEccezioni(string $campo, mixed $valore): void
    {
        $params = self::firmato();
        $params[$campo] = $valore;
        $this->assertFalse(SignedLink::verify($params, self::KEY, self::NOW));
    }

    public function testChiaveMancanteRifiutaSempre(): void
    {
        // Firmato con chiave vuota: chi non conosce la chiave lo puo' calcolare, mai valido.
        $conChiaveVuota = SignedLink::sign(['type' => 'avviso'], '', 3600, self::NOW);
        $this->assertFalse(SignedLink::verify($conChiaveVuota, '', self::NOW));
        $this->assertFalse(SignedLink::verify(self::firmato(), '', self::NOW));
    }

    public function testParametroPayloadArrayRifiutatoSenzaEccezioni(): void
    {
        $params = self::firmato();
        $params['iuv'] = ['000000000000000'];
        $params['extra'] = ['a' => ['b' => ['c']]];
        $this->assertFalse(SignedLink::verify($params, self::KEY, self::NOW));
    }

    public function testChiaviNumericheRifiutateSenzaEccezioni(): void
    {
        $params = self::firmato();
        $params[0] = 'x';
        $params[1] = ['y'];
        $this->assertFalse(SignedLink::verify($params, self::KEY, self::NOW));
    }

    public function testSoloParametriSpuriRifiutati(): void
    {
        $this->assertFalse(SignedLink::verify(['sig' => ['x'], 'expires' => ['y']], self::KEY, self::NOW));
        $this->assertFalse(SignedLink::verify(['foo' => 'bar'], self::KEY, self::NOW));
    }
}
