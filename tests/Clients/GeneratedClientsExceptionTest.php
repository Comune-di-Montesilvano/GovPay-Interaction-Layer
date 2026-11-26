<?php
declare(strict_types=1);

namespace Tests\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contratto dei client generati (govpay-clients/, pagopa-clients/) con Guzzle 8: ogni metodo
 * sincrono di ogni Api trasforma in ApiException del client sia una risposta 4xx/5xx sia un
 * guasto senza risposta (RequestException semplice, NetworkException, timeout). Senza il
 * post-processing di generate.sh/generate.ps1 il primo caso andava in fatal
 * (RequestException::getResponse() non esiste piu') e gli altri uscivano come eccezioni Guzzle.
 *
 * Metodi con parametri non scalari (modelli) o rifiutati dalla validazione lato client con
 * valori fittizi vengono saltati; ogni client deve comunque avere almeno un metodo esercitato.
 */
final class GeneratedClientsExceptionTest extends TestCase
{
    /** Namespace PSR-4 dei client generati (composer.json di ogni client). */
    private const CLIENTS = [
        'GovPay\\Backoffice', 'GovPay\\Pagamenti', 'GovPay\\Pendenze', 'GovPay\\Ragioneria',
        'PagoPA\\BizEvents', 'PagoPA\\CheckoutEc', 'PagoPA\\GPD',
    ];

    /** @return array<string, array{string}> */
    public static function clientProvider(): array
    {
        $out = [];
        foreach (self::CLIENTS as $ns) {
            $out[$ns] = [$ns];
        }
        return $out;
    }

    #[DataProvider('clientProvider')]
    public function testOgniMetodoTrasformaErroriInApiException(string $ns): void
    {
        $apiException = $ns . '\\ApiException';
        $exercised = 0;
        foreach (self::apiClasses($ns) as $apiClass) {
            foreach (self::syncMethods($apiClass) as $method) {
                $args = self::dummyArgs($method);
                if ($args === null) {
                    continue;
                }
                $cases = [
                    'HTTP 503' => [new Response(503, ['X-Test' => 'si'], '{"title":"Down"}'), 503, '{"title":"Down"}'],
                    'HTTP 404' => [new Response(404, [], '{"title":"Not Found"}'), 404, '{"title":"Not Found"}'],
                    'RequestException' => [static fn(Request $r) => new RequestException('cURL error 3: simulated', $r), 0, null],
                    'NetworkException' => [static fn(Request $r) => new NetworkException('cURL error 56: simulated', $r), 0, null],
                    'NetworkTimeoutException' => [static fn(Request $r) => new NetworkTimeoutException('cURL error 28: simulated', $r), 0, null],
                ];
                $skipped = false;
                foreach ($cases as $label => [$reply, $code, $body]) {
                    $handler = is_callable($reply)
                        ? static fn(Request $r) => \GuzzleHttp\Promise\Create::rejectionFor($reply($r))
                        : $reply;
                    $api = new $apiClass(new Client(['handler' => HandlerStack::create(new MockHandler([$handler]))]));
                    try {
                        $method->invokeArgs($api, $args);
                        self::fail("$apiClass::{$method->getName()} [$label]: nessuna eccezione");
                    } catch (\InvalidArgumentException) {
                        // Validazione lato client dei valori fittizi: metodo non esercitabile qui.
                        $skipped = true;
                        break;
                    } catch (\Throwable $e) {
                        $where = "$apiClass::{$method->getName()} [$label]";
                        self::assertInstanceOf($apiException, $e, $where . ': ' . get_class($e) . ' ' . $e->getMessage());
                        self::assertSame($code, $e->getCode(), $where);
                        self::assertSame($body, $e->getResponseBody(), $where);
                    }
                }
                if (!$skipped) {
                    $exercised++;
                }
            }
        }
        self::assertGreaterThan(0, $exercised, "$ns: nessun metodo esercitato");
    }

    /** L'output generato non contiene piu' i costrutti incompatibili con Guzzle 8. */
    public function testNessunCostruttoGuzzle7NeiClientGenerati(): void
    {
        $root = dirname(__DIR__, 2);
        $forbidden = ['$e->getResponse() ?', 'catch (ConnectException $e)', 'GuzzleHttp\\Utils::jsonEncode('];
        $hits = [];
        foreach (['govpay-clients/generated-clients', 'pagopa-clients/generated-clients'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = (string)$file;
                if (!str_ends_with($path, '.php') || !str_contains(str_replace('\\', '/', $path), '/lib/')) {
                    continue;
                }
                $src = (string)file_get_contents($path);
                foreach ($forbidden as $needle) {
                    if (str_contains($src, $needle)) {
                        $hits[] = substr($path, strlen($root) + 1) . ': ' . $needle;
                    }
                }
            }
        }
        self::assertSame([], $hits);
    }

    /** @return list<class-string> */
    private static function apiClasses(string $ns): array
    {
        $config = new \ReflectionClass($ns . '\\Configuration');
        $classes = [];
        foreach (glob(dirname((string)$config->getFileName()) . '/Api/*Api.php') ?: [] as $file) {
            $classes[] = $ns . '\\Api\\' . basename($file, '.php');
        }
        sort($classes);
        return $classes;
    }

    /** Metodi pubblici "base" (non *WithHttpInfo / *Async / *Request, non accessori). @return list<\ReflectionMethod> */
    private static function syncMethods(string $apiClass): array
    {
        $out = [];
        foreach ((new \ReflectionClass($apiClass))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $name = $m->getName();
            if ($m->isStatic() || $m->getDeclaringClass()->getName() !== $apiClass
                || str_starts_with($name, '__') || in_array($name, ['getConfig', 'getHostIndex', 'setHostIndex'], true)
                || preg_match('/(WithHttpInfo|Async|AsyncWithHttpInfo|Request)$/', $name)) {
                continue;
            }
            $out[] = $m;
        }
        return $out;
    }

    /** Argomenti fittizi per i parametri obbligatori scalari; null se servono modelli/oggetti. @return list<mixed>|null */
    private static function dummyArgs(\ReflectionMethod $m): ?array
    {
        $args = [];
        foreach ($m->getParameters() as $p) {
            if ($p->isOptional()) {
                break;
            }
            $type = $p->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
            $args[] = match ($name) {
                'string', 'mixed' => 'X1',
                'int' => 1,
                'float' => 1.0,
                'bool' => true,
                'array' => [],
                default => null,
            };
            if (end($args) === null) {
                return null;
            }
        }
        return $args;
    }
}
