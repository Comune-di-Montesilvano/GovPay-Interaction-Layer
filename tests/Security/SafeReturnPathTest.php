<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace Tests\Security;

use App\Security\SafeReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeReturnPathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function percorsiLocali(): iterable
    {
        yield 'radice' => ['/'];
        yield 'pagina' => ['/pendenze'];
        yield 'dettaglio con query' => ['/pendenze/A2A/GIL-1?tab=ricevute'];
        yield 'frammento' => ['/profile#dati'];
    }

    #[DataProvider('percorsiLocali')]
    public function testPercorsoLocaleMantenuto(string $path): void
    {
        self::assertSame($path, SafeReturnPath::normalize($path));
    }

    /** @return iterable<string, array{string}> */
    public static function destinazioniEsterne(): iterable
    {
        yield 'vuoto' => [''];
        yield 'URL assoluto' => ['https://attacker.example/x'];
        yield 'protocol-relative' => ['//attacker.example/x'];
        yield 'backslash dopo slash' => ['/\\attacker.example/x'];
        yield 'solo backslash' => ['\\\\attacker.example'];
        yield 'relativo senza slash' => ['pendenze'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'CRLF' => ["/pendenze\r\nLocation: https://attacker.example"];
        yield 'tab tra gli slash' => ["/\t/attacker.example"];
        yield 'backslash interno' => ['/pendenze\\..\\x'];
    }

    #[DataProvider('destinazioniEsterne')]
    public function testDestinazioneNonLocaleTornaAllaRadice(string $raw): void
    {
        self::assertSame('/', SafeReturnPath::normalize($raw));
    }
}
