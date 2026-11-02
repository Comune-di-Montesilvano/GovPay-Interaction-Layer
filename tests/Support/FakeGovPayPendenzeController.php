<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Controllers\PendenzeController;
use App\Services\GovPayClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Slim\Views\Twig;

/**
 * PendenzeController reale con GovPay simulato al confine HTTP: tutti i client Guzzle del
 * controller (makeHttpClient = GovPayClientFactory reale, usato anche da
 * sendPendenzaToBackoffice) puntano allo stesso MockHandler e ogni richiesta finisce in $requests.
 *
 * Le notifiche di creazione (email/App IO) sono un effetto esterno separato: qui vengono solo
 * registrate in $notifications (nessuna mail / App IO reale).
 */
final class FakeGovPayPendenzeController extends PendenzeController
{
    public MockHandler $mock;

    /** @var list<array{request:RequestInterface}> */
    public array $requests = [];

    /** @var list<array<string,mixed>> argomenti di ogni chiamata a sendCreationNotifications */
    public array $notifications = [];

    public ?\Throwable $notificationThrows = null;

    /**
     * Se true il circuit breaker della factory non si apre mai (per i test che contano i tentativi
     * su piu' di 3 guasti di rete consecutivi; il circuito aperto ha test dedicati).
     */
    public bool $circuitBreakerNeverOpens = false;

    /** Se impostato, fetchPendenzaById lancia questa eccezione (ramo "eccezione durante annullamento"). */
    public ?\Throwable $fetchByIdThrows = null;

    /** Se impostato, allocateIdPendenza lancia questa eccezione (errore imprevisto, non ritentabile). */
    public ?\Throwable $allocateThrows = null;

    public function __construct()
    {
        parent::__construct(Twig::create(dirname(__DIR__, 2) . '/backoffice/templates'), null);
        $this->mock = new MockHandler([]);
        // stato del circuit breaker condiviso su file: ogni test parte da circuito chiuso
        @unlink(self::circuitBreakerFile());
    }

    /**
     * Client della vera GovPayClientFactory con $mock come handler finale (grezzo, sotto tutti i
     * middleware della factory) e ogni tentativo registrato in $history. Azzera il circuit breaker.
     *
     * @param array<int, mixed> $history
     */
    public static function factoryClient(MockHandler $mock, array &$history, array $options = []): Client
    {
        @unlink(self::circuitBreakerFile());
        return GovPayClientFactory::makeBackofficeClient(array_merge($options, ['handler' => Middleware::history($history)($mock)]));
    }

    public static function circuitBreakerFile(): string
    {
        return dirname(__DIR__, 2) . '/backoffice/storage/tmp/govpay_circuit_breaker.json';
    }

    protected function makeHttpClient(array $guzzleOptions = []): Client
    {
        // Sempre la vera GovPayClientFactory (http_errors, Connection: close, retry di rete,
        // circuit breaker): handler = MockHandler GREZZO (mai HandlerStack::create(), che
        // aggiungerebbe da solo http_errors e nasconderebbe uno stack della factory incompleto).
        // history avvolge direttamente il MockHandler (piu' interno di tutto) cosi' registra OGNI
        // tentativo, anche quelli ripetuti dal retry della factory.
        $handler = Middleware::history($this->requests)($this->mock);
        if ($this->circuitBreakerNeverOpens) {
            $inner = $handler;
            // gira SOTTO il circuit breaker: azzerarne il file prima di ogni invio fa ripartire il
            // conteggio dei guasti da 0 a ogni tentativo, quindi il circuito non si apre mai.
            $handler = static function (RequestInterface $request, array $options) use ($inner) {
                @unlink(self::circuitBreakerFile());
                return $inner($request, $options);
            };
        }
        return GovPayClientFactory::makeBackofficeClient(array_merge($guzzleOptions, ['handler' => $handler]));
    }

    public function fetchPendenzaById(string $idPendenza): ?array
    {
        if ($this->fetchByIdThrows !== null) {
            throw $this->fetchByIdThrows;
        }
        return parent::fetchPendenzaById($idPendenza);
    }

    public function allocateIdPendenza(array $payload): array
    {
        if ($this->allocateThrows !== null) {
            throw $this->allocateThrows;
        }
        return parent::allocateIdPendenza($payload);
    }

    public function sendCreationNotifications(
        string $idPendenza,
        string $email,
        string $anagrafica,
        string $identificativo,
        string $tipoSog,
        array $pendenzaData,
        ?\Psr\Http\Message\ServerRequestInterface $request = null
    ): array {
        $this->notifications[] = compact('idPendenza', 'email', 'anagrafica', 'identificativo', 'tipoSog', 'pendenzaData');
        if ($this->notificationThrows !== null) {
            throw $this->notificationThrows;
        }
        return ['email' => 'skipped', 'app_io' => 'skipped'];
    }

    /** @return list<string> "METHOD path[?query]" di ogni richiesta, in ordine */
    public function calls(): array
    {
        return array_map(static function (array $h): string {
            $uri = $h['request']->getUri();
            $q = $uri->getQuery();
            return $h['request']->getMethod() . ' ' . $uri->getPath() . ($q !== '' ? '?' . $q : '');
        }, $this->requests);
    }

    /** @return array<mixed> corpo JSON della richiesta $i */
    public function body(int $i): array
    {
        $decoded = json_decode((string)$this->requests[$i]['request']->getBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
