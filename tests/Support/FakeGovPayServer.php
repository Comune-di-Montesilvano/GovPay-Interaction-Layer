<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Avvia il GovPay/pagoPA finto (tests/Fakes/GovPay/router.php) con `php -S` su una porta
 * libera di 127.0.0.1 e ne espone gli endpoint di controllo. Stato in un file temporaneo per
 * istanza. Usato dai test del finto e dai test di integrazione.
 */
final class FakeGovPayServer
{
    /** @var resource */
    private $proc;
    private bool $stopped = false;

    private function __construct($proc, private readonly int $port, private readonly string $stateFile)
    {
        $this->proc = $proc;
    }

    public static function start(): self
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        if ($sock === false) {
            throw new \RuntimeException('nessuna porta libera');
        }
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);

        $router = dirname(__DIR__) . '/Fakes/GovPay/router.php';
        $stateFile = sys_get_temp_dir() . '/gil-fake-' . $port . '-' . bin2hex(random_bytes(4)) . '.json';
        $env = getenv();
        $env['FAKE_GOVPAY_STATE'] = $stateFile;
        unset($env['PRISM_URL']);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            dirname($router),
            $env
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('avvio php -S fallito');
        }
        $server = new self($proc, $port, $stateFile);
        register_shutdown_function([$server, 'stop']);

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
            if (@file_get_contents($server->baseUrl() . '/__fake/health', false, $ctx) !== false) {
                return $server;
            }
            usleep(100_000);
        }
        $server->stop();
        throw new \RuntimeException('GovPay finto non pronto su 127.0.0.1:' . $port);
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function reset(): void
    {
        $this->call('POST', '/__fake/reset');
    }

    /** @return list<array{method:string,path:string,body:mixed}> */
    public function requests(): array
    {
        return $this->call('GET', '/__fake/requests');
    }

    /** @return list<string> */
    public function unmatched(): array
    {
        return $this->call('GET', '/__fake/unmatched');
    }

    public function setPaid(string $idDominio, string $iuv): void
    {
        $this->call('POST', '/__fake/paid', ['idDominio' => $idDominio, 'iuv' => $iuv]);
    }

    /** @param array<string,mixed> $body */
    public function failNext(string $method, string $pathRegex, int $status, array $body = []): void
    {
        $this->call('POST', '/__fake/fail-next', compact('method', 'pathRegex', 'status', 'body'));
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $status = proc_get_status($this->proc);
        if ($status['running']) {
            // php -S su Windows gira come figlio diretto: terminate basta; su Linux idem (argv, niente shell).
            proc_terminate($this->proc);
        }
        proc_close($this->proc);
        foreach ([$this->stateFile, $this->stateFile . '.lock'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @return mixed
     */
    private function call(string $method, string $path, ?array $body = null): mixed
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'content' => $body === null ? '' : json_encode($body),
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $out = file_get_contents($this->baseUrl() . $path, false, $ctx);
        if ($out === false) {
            throw new \RuntimeException("GovPay finto: $method $path fallita");
        }
        return json_decode($out, true);
    }
}
