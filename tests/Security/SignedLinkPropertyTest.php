<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Security\SignedLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test property-based (generatore deterministico, seed fisso) sui link firmati HMAC del
 * frontoffice: un link firmato si verifica con la stessa chiave prima della scadenza; ogni
 * manomissione (valore cambiato, parametro aggiunto, chiave diversa) o la scadenza superata
 * lo invalidano; l'ordine dei parametri ricevuti e' irrilevante.
 */
final class SignedLinkPropertyTest extends TestCase
{
    private const SEED = 8675309;
    private const CASES = 200;

    private const ALFABETO_VALORI = [
        'a', 'b', 'Z', '0', '9', ' ', '&', '=', '?', '/', '+', '%', '#', ';', '"', "'", '.', '-', '_', '~',
        'à', 'è', 'ù', '€', 'ß',
    ];

    /** @return array<string, array{int, array<string, string>, string, int, int}> */
    public static function linkProvider(): array
    {
        mt_srand(self::SEED);
        $cases = [];
        for ($i = 0; $i < self::CASES; $i++) {
            $params = [];
            $n = mt_rand(1, 6);
            while (count($params) < $n) {
                $params[self::chiaveCasuale()] = self::valoreCasuale();
            }
            $key = self::chiaveSegretaCasuale();
            $ttl = mt_rand(1, 63072000);
            $now = mt_rand(1600000000, 1900000000);
            $cases['caso ' . $i] = [$i, $params, $key, $ttl, $now];
        }
        return $cases;
    }

    /** @param array<string, string> $params */
    #[DataProvider('linkProvider')]
    public function testFirmaVerificataEManomissioniRifiutate(int $i, array $params, string $key, int $ttl, int $now): void
    {
        $msg = sprintf('seed=%d caso=%d params=%s', self::SEED, $i, json_encode($params, JSON_UNESCAPED_UNICODE));
        $signed = SignedLink::sign($params, $key, $ttl, $now);

        $this->assertSame((string)($now + $ttl), $signed['expires'], $msg);
        $this->assertArrayHasKey('sig', $signed, $msg);
        $this->assertTrue(SignedLink::verify($signed, $key, $now), $msg);
        $this->assertTrue(SignedLink::verify($signed, $key, $now + $ttl), $msg . ' (istante di scadenza incluso)');

        // Ordine delle chiavi irrilevante.
        $shuffled = self::mescola($signed, $i);
        $this->assertTrue(SignedLink::verify($shuffled, $key, $now), $msg . ' (ordine mescolato)');

        // Valore qualunque cambiato (payload, expires o sig).
        $chiavi = array_keys($signed);
        $target = $chiavi[$i % count($chiavi)];
        $manomesso = $signed;
        $manomesso[$target] = $signed[$target] . 'x';
        $this->assertFalse(SignedLink::verify($manomesso, $key, $now), $msg . ' (cambiato ' . $target . ')');

        // Parametro aggiunto.
        $aggiunto = $signed;
        $aggiunto['extra' . $i] = 'v';
        $this->assertFalse(SignedLink::verify($aggiunto, $key, $now), $msg . ' (param aggiunto)');

        // Chiave diversa.
        $this->assertFalse(SignedLink::verify($signed, $key . 'k', $now), $msg . ' (chiave diversa)');

        // Scaduto.
        $this->assertFalse(SignedLink::verify($signed, $key, $now + $ttl + 1), $msg . ' (scaduto)');
    }

    public function testParametriMancantiRifiutati(): void
    {
        $signed = SignedLink::sign(['type' => 'avviso'], 'k', 60, 1700000000);
        $senzaSig = $signed;
        unset($senzaSig['sig']);
        $senzaExpires = $signed;
        unset($senzaExpires['expires']);

        $this->assertFalse(SignedLink::verify($senzaSig, 'k', 1700000000));
        $this->assertFalse(SignedLink::verify($senzaExpires, 'k', 1700000000));
        $this->assertFalse(SignedLink::verify([], 'k', 1700000000));
    }

    private static function chiaveCasuale(): string
    {
        $chiave = '';
        $len = mt_rand(1, 8);
        for ($k = 0; $k < $len; $k++) {
            $chiave .= 'abcdefghijklmnopqrstuvwxyz_'[mt_rand(0, 26)];
        }
        // 'expires' e 'sig' sono riservati alla firma.
        return in_array($chiave, ['expires', 'sig'], true) ? $chiave . '_' : $chiave;
    }

    private static function valoreCasuale(): string
    {
        $valore = '';
        $len = mt_rand(0, 12);
        for ($k = 0; $k < $len; $k++) {
            $valore .= self::ALFABETO_VALORI[mt_rand(0, count(self::ALFABETO_VALORI) - 1)];
        }
        return $valore;
    }

    private static function chiaveSegretaCasuale(): string
    {
        $hex = '';
        for ($k = 0; $k < 64; $k++) {
            $hex .= '0123456789abcdef'[mt_rand(0, 15)];
        }
        return $hex;
    }

    /**
     * @param array<string, string> $a
     * @return array<string, string>
     */
    private static function mescola(array $a, int $i): array
    {
        $chiavi = array_keys($a);
        // Rotazione + inversione deterministiche: ordine diverso da quello ordinato di firma.
        $rot = $i % count($chiavi);
        $chiavi = array_reverse(array_merge(array_slice($chiavi, $rot), array_slice($chiavi, 0, $rot)));
        $out = [];
        foreach ($chiavi as $k) {
            $out[$k] = $a[$k];
        }
        return $out;
    }
}
