<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Controllers\PendenzeController;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Slim\Views\Twig;

/**
 * PendenzeController reale con GovPay simulato al confine HTTP: tutti i client Guzzle del
 * controller (makeHttpClient = GovPayClientFactory, makePlainHttpClient = `new Client` usato da
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

    /** Se impostato, fetchPendenzaById lancia questa eccezione (ramo "eccezione durante annullamento"). */
    public ?\Throwable $fetchByIdThrows = null;

    private HandlerStack $stack;

    public function __construct()
    {
        parent::__construct(Twig::create(dirname(__DIR__, 2) . '/backoffice/templates'), null);
        $this->mock = new MockHandler([]);
        $this->stack = HandlerStack::create($this->mock);
        $this->stack->push(Middleware::history($this->requests));
    }

    protected function makeHttpClient(array $guzzleOptions = []): Client
    {
        return new Client(array_merge($guzzleOptions, ['handler' => $this->stack]));
    }

    protected function makePlainHttpClient(array $guzzleOptions = []): Client
    {
        return new Client(array_merge($guzzleOptions, ['handler' => $this->stack]));
    }

    public function fetchPendenzaById(string $idPendenza): ?array
    {
        if ($this->fetchByIdThrows !== null) {
            throw $this->fetchByIdThrows;
        }
        return parent::fetchPendenzaById($idPendenza);
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
