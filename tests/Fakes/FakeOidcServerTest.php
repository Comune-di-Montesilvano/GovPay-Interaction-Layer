<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace Tests\Fakes;

use PHPUnit\Framework\TestCase;

/** IdP OIDC finto (tests/Fakes/Oidc/router.php) usato dall'e2e del frontoffice. */
final class FakeOidcServerTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($sock);
        self::$port = (int)substr((string)strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $router = dirname(__DIR__) . '/Fakes/Oidc/router.php';
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router], [['file', $null, 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes, dirname($router));
        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents('http://127.0.0.1:' . self::$port . '/__fake/health') !== false) {
                return;
            }
            usleep(100_000);
        }
        self::fail('IdP finto non avviato');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private function http(string $method, string $path, array $form = [], array $headers = []): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . $path);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($form !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $body];
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Percorre la pagina di login e ritorna [code, state] dal link verso redirect_uri. */
    private function authorize(string $verifier, string $state = 'st-1'): array
    {
        $r = $this->http('GET', '/OIDC/authorization?' . http_build_query([
            'client_id' => 'gil-e2e', 'response_type' => 'code', 'scope' => 'openid profile email',
            'redirect_uri' => 'http://frontoffice/oidc/callback', 'state' => $state,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        ]));
        self::assertSame(200, $r['status'], $r['body']);
        self::assertSame(1, preg_match('#href="([^"]+)"#', $r['body'], $m));
        $url = html_entity_decode($m[1], ENT_QUOTES);
        self::assertStringStartsWith('http://frontoffice/oidc/callback?', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        return [$q['code'], $q['state']];
    }

    public function testCodeFlowConPkce(): void
    {
        [$code, $state] = $this->authorize('verifier-di-test');
        self::assertSame('st-1', $state);

        $r = $this->http('POST', '/OIDC/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'gil-e2e',
            'redirect_uri' => 'http://frontoffice/oidc/callback', 'code_verifier' => 'verifier-di-test',
        ]);
        self::assertSame(200, $r['status'], $r['body']);
        $tok = json_decode($r['body'], true);
        $claims = json_decode((string)base64_decode(strtr(explode('.', $tok['id_token'])[1], '-_', '+/')), true);
        self::assertSame('TINIT-RSSMRA80A01H501U', $claims['fiscal_number']);
        self::assertSame('gil-e2e', $claims['aud']);
        self::assertSame('http://127.0.0.1:' . self::$port, $claims['iss']);

        $u = $this->http('GET', '/OIDC/userinfo', [], ['Authorization: Bearer ' . $tok['access_token']]);
        self::assertSame(200, $u['status']);
        self::assertSame('cittadino@example.test', json_decode($u['body'], true)['email']);
    }

    public function testVerifierSbagliatoRifiutato(): void
    {
        [$code] = $this->authorize('verifier-giusto');
        $r = $this->http('POST', '/OIDC/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'gil-e2e',
            'redirect_uri' => 'http://frontoffice/oidc/callback', 'code_verifier' => 'verifier-sbagliato',
        ]);
        self::assertSame(400, $r['status']);
        self::assertSame('invalid_grant', json_decode($r['body'], true)['error']);
    }

    public function testClientIdDaBasicAuth(): void
    {
        [$code] = $this->authorize('v-basic');
        $r = $this->http('POST', '/OIDC/token', [
            'grant_type' => 'authorization_code', 'code' => $code,
            'redirect_uri' => 'http://frontoffice/oidc/callback', 'code_verifier' => 'v-basic',
        ], ['Authorization: Basic ' . base64_encode('gil-e2e:segreto-finto')]);
        self::assertSame(200, $r['status'], $r['body']);
    }

    public function testAuthorizationSenzaPkceRifiutata(): void
    {
        $r = $this->http('GET', '/OIDC/authorization?' . http_build_query([
            'client_id' => 'gil-e2e', 'response_type' => 'code', 'redirect_uri' => 'http://frontoffice/oidc/callback',
        ]));
        self::assertSame(400, $r['status']);
    }

    public function testUserinfoSenzaTokenRifiutata(): void
    {
        self::assertSame(401, $this->http('GET', '/OIDC/userinfo')['status']);
    }
}
