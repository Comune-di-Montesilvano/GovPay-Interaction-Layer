<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Security\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrustedProxiesTest extends TestCase
{
    /** @return array<string, array{string, string, bool}> */
    public static function cases(): array
    {
        return [
            // Regressione: con TRUSTED_PROXIES=subnet il CIDR veniva confrontato come stringa
            // esatta e Traefik (IP variabile sulla rete proxy) non era mai fidato.
            'ipv4 dentro cidr /16'      => ['172.19.0.5', '172.19.0.0/16', true],
            'ipv4 fuori cidr /16'       => ['172.18.0.1', '172.19.0.0/16', false],
            'ipv4 cidr non allineato'   => ['10.16.19.255', '10.16.16.0/22', true],
            'ipv4 appena fuori /22'     => ['10.16.20.0', '10.16.16.0/22', false],
            'ipv4 cidr /32'             => ['192.168.1.80', '192.168.1.80/32', true],
            'ipv4 cidr /0'              => ['8.8.8.8', '0.0.0.0/0', true],
            'ipv4 esatto'               => ['172.18.0.1', '172.18.0.1', true],
            'ipv4 esatto diverso'       => ['172.18.0.2', '172.18.0.1', false],
            'lista con spazi'           => ['172.19.3.4', ' 10.0.0.1 , 172.19.0.0/16 ', true],
            'wildcard'                  => ['203.0.113.7', '*', true],
            'ipv6 dentro cidr'          => ['fd00::1234', 'fd00::/8', true],
            'ipv6 fuori cidr'           => ['fe80::1', 'fd00::/8', false],
            'ipv6 esatto forma diversa' => ['::1', '0:0:0:0:0:0:0:1', true],
            'famiglie diverse'          => ['172.19.0.5', '::/0', false],
            'setting vuoto'             => ['172.19.0.5', '', false],
            'remote addr vuoto'         => ['', '172.19.0.0/16', false],
            'remote addr non valido'    => ['not-an-ip', '*', false],
            'cidr prefisso troppo lungo' => ['172.19.0.5', '172.19.0.0/33', false],
            'cidr prefisso non numerico' => ['172.19.0.5', '172.19.0.0/abc', false],
            'cidr rete non valida'      => ['172.19.0.5', 'rete/16', false],
        ];
    }

    #[DataProvider('cases')]
    public function testIsTrusted(string $ip, string $setting, bool $expected): void
    {
        $this->assertSame($expected, TrustedProxies::isTrusted($ip, $setting));
    }
}
