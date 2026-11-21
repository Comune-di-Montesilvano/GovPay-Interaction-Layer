<?php
declare(strict_types=1);

namespace Tests\Integration\Support;

use App\Config\SettingsRepository;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\DbTestCase;
use Tests\Support\FakeGovPayServer;

/**
 * Base dei test di integrazione (gruppo `integration`, solo in merge queue: job
 * integration-tests di ci.yml). Backoffice Slim in-process (bootstrap/app.php + routes/web.php,
 * stesso stack di middleware del runtime) su MariaDB usa-e-getta (DbTestCase: porta 3307,
 * schema da first_run + migrazioni) e GovPay/pagoPA finto (FakeGovPayServer).
 *
 * Ogni sottoclasse concreta: #[Group('db')] e #[Group('integration')] (PHPUnit 13 non eredita
 * i gruppi). Sessione PHP mantenuta tra richieste senza cookie (session.use_cookies=0): ogni
 * "postazione" e' un nome in $sessions, useSession() cambia postazione.
 * Solo dati sintetici: CF RSSMRA80A01H501U, dominio 00000000000, utenti @example.test.
 */
abstract class IntegrationTestCase extends DbTestCase
{
    public const DOMINIO = '00000000000';
    public const ID_A2A = 'A2A-TEST';
    public const CF = 'RSSMRA80A01H501U';
    public const OPERATORE_EMAIL = 'operatore@example.test';
    public const OPERATORE_PASSWORD = 'Password-di-test-1!';

    protected static array $tables = ['settings', 'users', 'entrate_tipologie', 'rate_limit_buckets'];

    protected static FakeGovPayServer $fake;
    protected static string $masterToken;

    /** @var array<string,string> postazione => session id */
    private array $sessions = [];
    private string $currentSession = 'default';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$fake = FakeGovPayServer::start();
        self::$masterToken = bin2hex(random_bytes(24));
        putenv('MASTER_TOKEN=' . self::$masterToken);
        $_ENV['MASTER_TOKEN'] = self::$masterToken;
        putenv('APP_ENCRYPTION_KEY=' . bin2hex(random_bytes(16)));
        putenv('FRONTOFFICE_LINK_SIGNING_KEY=' . bin2hex(random_bytes(32)));
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '0');
        ini_set('session.use_strict_mode', '0');
        ini_set('session.cache_limiter', '');
        ini_set('session.save_path', sys_get_temp_dir());
    }

    public static function tearDownAfterClass(): void
    {
        self::$fake->stop();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$fake->reset();
        SettingsRepository::flush();
        $base = self::$fake->baseUrl();
        foreach ([
            ['setup', 'complete', '1'],
            ['entity', 'id_dominio', self::DOMINIO],
            ['entity', 'id_a2a', self::ID_A2A],
            ['govpay', 'authentication_method', 'basic'],
            ['govpay', 'user', 'utente-finto'],
            ['govpay', 'password', 'password-finta'],
            ['govpay', 'backoffice_url', $base . '/backoffice'],
            ['govpay', 'pendenze_url', $base . '/pendenze'],
            ['govpay', 'pagamenti_url', $base . '/pagamenti'],
            ['pagopa', 'checkout_ec_base_url', $base . '/checkout'],
            ['pagopa', 'checkout_subscription_key', 'chiave-finta'],
        ] as [$section, $key, $value]) {
            SettingsRepository::set($section, $key, $value);
        }
        SettingsRepository::flush();
        $this->sessions = [];
        $this->currentSession = 'default';
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        parent::tearDown();
    }

    /** Cambia postazione (sessione PHP separata), creandola alla prima richiesta. */
    protected function useSession(string $name): void
    {
        $this->currentSession = $name;
    }

    /**
     * Esegue una richiesta sull'app backoffice reale.
     *
     * @param array<string,mixed>|string $body array = form (o JSON se Content-Type json), string = corpo grezzo
     * @param array<string,string> $headers
     */
    protected function request(string $method, string $path, array|string $body = [], array $headers = []): ResponseInterface
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $sid = $this->sessions[$this->currentSession] ?? null;
        session_id($sid ?? bin2hex(random_bytes(16)));

        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'backoffice',
            'SERVER_NAME' => 'backoffice',
        ];
        $_SERVER = array_merge($_SERVER, $server);
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://backoffice' . $path, $server);
        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $q);
            $request = $request->withQueryParams($q);
        }
        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }
        $isJson = str_contains(strtolower($headers['Content-Type'] ?? ''), 'json');
        if (is_string($body)) {
            $request = $request->withBody((new StreamFactory())->createStream($body));
        } elseif ($isJson) {
            $request = $request->withBody((new StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        } elseif ($body !== []) {
            $request = $request->withParsedBody($body)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withBody((new StreamFactory())->createStream(http_build_query($body)));
        }

        $root = dirname(__DIR__, 3) . '/backoffice/src';
        [$app, $twig] = require $root . '/bootstrap/app.php';
        // app.php registra handler globali per il runtime web: nei test restano quelli di PHPUnit.
        restore_error_handler();
        restore_exception_handler();
        $routes = require $root . '/routes/web.php';
        $routes($app, $twig);

        $response = $app->handle($request);

        $this->sessions[$this->currentSession] = session_id() ?: ($sid ?? '');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $response->getBody()->rewind();
        return $response;
    }

    /** Token CSRF della sessione corrente (meta csrf-token di una pagina HTML). */
    protected function csrfToken(string $page = '/login'): string
    {
        $html = (string)$this->request('GET', $page)->getBody();
        if (!preg_match('/<meta name="csrf-token" content="([^"]+)"/', $html, $m)) {
            self::fail("meta csrf-token assente in $page");
        }
        return html_entity_decode($m[1]);
    }

    protected function creaOperatore(string $ruolo = 'admin', string $email = self::OPERATORE_EMAIL): int
    {
        $now = date('Y-m-d H:i:s');
        $st = self::pdo()->prepare('INSERT INTO users (email, password_hash, role, first_name, last_name, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$email, password_hash(self::OPERATORE_PASSWORD, PASSWORD_DEFAULT), $ruolo, 'Operatore', 'Test', $now, $now]);
        return (int)self::pdo()->lastInsertId();
    }

    /** Login dal form reale (CSRF compreso) nella postazione corrente. */
    protected function loginOperatore(string $email = self::OPERATORE_EMAIL): void
    {
        $token = $this->csrfToken('/login');
        $resp = $this->request('POST', '/login', [
            'csrf_token' => $token,
            'email' => $email,
            'password' => self::OPERATORE_PASSWORD,
        ]);
        self::assertSame(302, $resp->getStatusCode(), 'login fallito: ' . substr((string)$resp->getBody(), 0, 300));
        self::assertSame('/', $resp->getHeaderLine('Location'));
    }

    /** @return array<string,mixed> */
    protected static function json(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data, 'risposta non JSON: ' . substr((string)$response->getBody(), 0, 300));
        return $data;
    }

    /** @return array<string,string> */
    protected static function bearer(?string $token = null): array
    {
        return ['Authorization' => 'Bearer ' . ($token ?? self::$masterToken), 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    protected function assertNoUnmatched(): void
    {
        self::assertSame([], self::$fake->unmatched(), 'chiamate GovPay/pagoPA non previste dal finto: aggiungerle a tests/Fakes/GovPay/router.php');
    }
}
