<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use App\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface as HttpRequest;

/**
 * Factory centralizzata per client HTTP GovPay.
 * Unico punto dove si configurano TLS, retry e credenziali per tutte le
 * chiamate verso GovPay Backoffice v1 — backoffice, frontoffice sidecar, impostazioni.
 */
class GovPayClientFactory
{
    /** Messaggio della ConnectException con cui il circuit breaker aperto rifiuta (nessun invio). */
    public const CIRCUIT_OPEN_MESSAGE = 'GovPay is offline (Circuit Breaker active)';

    /**
     * Client HTTP per chiamate raw verso GovPay Backoffice v1.
     *
     * Include: TLS v1.2 forzato, Connection:close, retry automatico su cURL 35/28/56, SOLO per metodi
     * idempotenti (GET/HEAD/PUT/DELETE/OPTIONS; mai POST/PATCH). Backoff esponenziale con jitter
     * (0–2000 ms), max 5 retry (28 timeout: max 2, solo se timeout <= 30 s). 28 (timeout) e
     * 56 (connection reset) sono transitori tipici al riavvio dei container (GovPay
     * o rete non ancora pronti), oltre al TLS handshake instabile di 35.
     * Stack di default Guzzle incluso (http_errors): un 4xx/5xx GovPay lancia
     * ClientException/ServerException come un `new Client()` (salvo 'http_errors' => false
     * per richiesta). $extra['handler']: HandlerStack usato com'e', altro callable avvolto.
     * Redirect mai seguiti: un 3xx torna come risposta (non-2xx), allow_redirects ignorato.
     *
     * @param array $extra Opzioni Guzzle aggiuntive (es. auth, headers specifici)
     */
    public static function makeBackofficeClient(array $extra = []): Client
    {
        $guzzleOptions = array_merge([
            'connect_timeout' => 5.0,
            'timeout'         => 15.0,
        ], $extra);

        $authMethod = strtolower((string)SettingsRepository::get('govpay', 'authentication_method', ''));
        if (in_array($authMethod, ['ssl', 'sslheader'], true)) {
            $cert    = (string)SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key     = (string)SettingsRepository::get('govpay', 'tls_key_path', '');
            $keyPass = SettingsRepository::get('govpay', 'tls_key_password') ?: null;
            if ($cert !== '' && $key !== '') {
                $guzzleOptions['cert']    = $cert;
                $guzzleOptions['ssl_key'] = ($keyPass !== null && $keyPass !== '') ? [$key, $keyPass] : $key;
            }
        }

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $guzzleOptions['crypto_method'] = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        $defaultCurlOptions = [];
        if (defined('CURL_HTTP_VERSION_1_1')) {
            $guzzleOptions['version'] = '1.1';
        }
        // CURLOPT_SSL_SESSIONID_CACHE rimosso: deprecato da Guzzle 7.12+ e non
        // necessario dato che CURLOPT_FORBID_REUSE già impedisce il riuso della connessione.
        if (defined('CURLOPT_FORBID_REUSE')) {
            $defaultCurlOptions[CURLOPT_FORBID_REUSE] = true;
        }
        if (!empty($defaultCurlOptions)) {
            $guzzleOptions['curl'] = ($guzzleOptions['curl'] ?? []) + $defaultCurlOptions;
        }

        // Stack di default Guzzle (http_errors, cookies, prepare_body; redirect tolto sotto) come `new Client()`:
        // senza http_errors un 4xx/5xx GovPay NON lancerebbe e i chiamanti (che si aspettano
        // ClientException/ServerException, come prima della factory) lo tratterebbero come risposta
        // valida. I middleware della factory vanno dopo: http_errors resta il piu' esterno, quindi
        // retry e circuit breaker vedono ancora la risposta grezza (5xx = risposta, non guasto di rete).
        // Un handler non-HandlerStack (es. MockHandler nei test) viene avvolto nello stesso stack.
        $handler = $guzzleOptions['handler'] ?? null;
        unset($guzzleOptions['handler']);
        if ($handler instanceof \GuzzleHttp\HandlerStack) {
            $handlerStack = $handler;
        } else {
            $handlerStack = \GuzzleHttp\HandlerStack::create($handler ?? new \GuzzleHttp\Handler\CurlHandler());
        }
        // Redirect MAI seguiti (come lo stack nudo di prima): Guzzle trasformerebbe POST/PUT in un
        // GET "riuscito" su 301/302/303 (incasso, aggiornamento pendenza persi) e presenterebbe il
        // certificato mTLS a un host diverso. Middleware tolto (non riattivabile con allow_redirects
        // del chiamante, nemmeno per richiesta) e opzione a false: un 3xx torna come risposta non-2xx.
        $handlerStack->remove('allow_redirects');
        $guzzleOptions['allow_redirects'] = false;

        // Circuit breaker PIU' ESTERNO del retry (push = piu' esterno): vede una sola volta l'esito
        // finale, quindi i retry di rete non gonfiano il conteggio guasti (3 = apertura).
        $handlerStack->push(self::circuitBreakerMiddleware(), 'circuit_breaker');

        $handlerStack->push(Middleware::mapRequest(
            static function (HttpRequest $request): HttpRequest {
                return $request->withHeader('Connection', 'close');
            }
        ));

        // Test hook: callable(int $retries): int ms. Default = backoff esponenziale con jitter.
        $delayFn = $guzzleOptions['retry_delay'] ?? null;
        unset($guzzleOptions['retry_delay']);

        // Tetti di retry per errno. 35 (TLS handshake) e 56 (reset): fino a 5, il guasto e' tipico
        // al riavvio dei container. 28 (timeout): max 2 retry E solo se il timeout
        // effettivo e' <= 30 s. Le richieste lunghe (PUT di creazione a 90 s) NON si ritentano su 28: il worker
        // (sequenziale) resterebbe bloccato minuti e il frontoffice scade a 30 s; un timeout puo' inoltre
        // scattare dopo l'invio (esito incerto). Subentrano retry Messenger / 'riprova' sullo stesso idPendenza.
        $maxNetworkRetries = 5;
        $maxTimeoutRetries = 2;
        $maxTimeoutForRetry = 30.0; // s: cURL 28 ritentato solo se il timeout effettivo della richiesta e' <= 30
        // Solo metodi idempotenti: POST (es. /incassi, checkout) e PATCH mai ritentati, perche' una
        // seconda esecuzione duplicherebbe l'effetto. Le PUT GovPay in uso (create/update pendenza
        // PUT /pendenze/{a2a}/{id}) sono idempotenti.
        $idempotentMethods = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];
        // Il decider di Middleware::retry non riceve $options: RetryMiddleware si costruisce per
        // richiesta con un decider che chiude sul timeout effettivo (client default gia' fuso in $options).
        $retryDelay = $delayFn ?? static function (int $retries): int {
            if ($retries <= 0) {
                return 0;
            }
            $baseDelay = 200 * (1 << ($retries - 1));
            $jitter    = random_int(0, 100);
            return (int)min(2000, $baseDelay + $jitter);
        };
        $handlerStack->push(static function (callable $next) use ($maxNetworkRetries, $maxTimeoutRetries, $maxTimeoutForRetry, $idempotentMethods, $retryDelay) {
            return static function (HttpRequest $request, array $options) use ($next, $maxNetworkRetries, $maxTimeoutRetries, $maxTimeoutForRetry, $idempotentMethods, $retryDelay) {
                $timeout = (float)($options['timeout'] ?? 0);
                $decider = static function (int $retries, $request, $response = null, $exception = null) use ($maxNetworkRetries, $maxTimeoutRetries, $maxTimeoutForRetry, $idempotentMethods, $timeout): bool {
                // Guzzle 7: cURL 35/28 arrivano come ConnectException (NON RequestException); 56 come RequestException.
                if (!$exception instanceof ConnectException && !$exception instanceof RequestException) {
                    return false;
                }
                if (!in_array(strtoupper($request->getMethod()), $idempotentMethods, true)) {
                    return false;
                }
                $context = method_exists($exception, 'getHandlerContext') ? $exception->getHandlerContext() : [];
                $errno   = (int)($context['errno'] ?? 0);
                $message = strtolower($exception->getMessage());
                if ($errno === 0 && preg_match('/curl error (\d+)/', $message, $m)) {
                    $errno = (int)$m[1];
                }
                // 35 = TLS handshake, 28 = timeout, 56 = connection reset by peer.
                if ($errno === 56) {
                    if (!$exception instanceof RequestException) {
                        return false;
                    }
                } elseif ($errno !== 35 && $errno !== 28) {
                    return false;
                }
                if ($errno === 28 && ($timeout <= 0.0 || $timeout > $maxTimeoutForRetry)) {
                    return false;
                }
                $cap = $errno === 28 ? $maxTimeoutRetries : $maxNetworkRetries;
                if ($retries >= $cap) {
                    Logger::getInstance()->error(sprintf(
                        'GovPay network error after %d retries (errno %s): %s',
                        $cap, $errno, $exception->getMessage()
                    ));
                    return false;
                }
                Logger::getInstance()->warning(sprintf(
                    'Retry GovPay call after network error (attempt %d/%d, errno %s)',
                    $retries + 1, $cap, $errno
                ));
                return true;
                };
                return (new \GuzzleHttp\RetryMiddleware($decider, $next, $retryDelay))($request, $options);
            };
        });

        $guzzleOptions['handler'] = $handlerStack;

        return new Client($guzzleOptions);
    }

    /**
     * Path del file di stato del Circuit Breaker. Deliberatamente NON in
     * sys_get_temp_dir() (/tmp ha lo sticky bit): backoffice (Apache, www-data) e worker
     * scrivono entrambi qui, e un file creato da un altro utente non sarebbe eliminabile
     * (Operation not permitted, vedi GOVPAY-GIL-K e duplicati). storage/tmp ha invece il
     * bit setgid (2775, impostato in Dockerfile) cosi' ogni nuovo file eredita il gruppo
     * www-data indipendentemente da chi scrive.
     */
    private static function circuitBreakerFilePath(): string
    {
        $dir = dirname(__DIR__, 2) . '/backoffice/storage/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/govpay_circuit_breaker.json';
    }

    /**
     * Scrive lo stato del Circuit Breaker via file temporaneo + rename().
     * A differenza di file_put_contents() in-place, rename() richiede solo
     * il permesso di scrittura sulla directory (come unlink) — non su un
     * eventuale file esistente scritto da un altro utente con mode 0644
     * (owner-only write). Elimina il caso limite in cui due utenti si
     * alternano nello scrivere lo stesso file e uno dei due resta bloccato.
     */
    /**
     * Esegue $fn sotto lock esclusivo (flock su <file>.lock): il conteggio dei fallimenti e' un
     * leggi-modifica-scrivi e il file e' condiviso da piu' processi (Apache, e nel worker un
     * processo per lavoro). Senza lock due fallimenti concorrenti ne contano uno solo. Lock non
     * ottenibile (permessi/FS): $fn eseguita comunque (il rename resta atomico per i lettori).
     */
    private static function withCircuitBreakerLock(string $cbFile, callable $fn): void
    {
        $h = @fopen($cbFile . '.lock', 'c');
        if ($h === false) {
            $fn();
            return;
        }
        @chmod($cbFile . '.lock', 0664);
        try {
            @flock($h, LOCK_EX);
            $fn();
        } finally {
            @flock($h, LOCK_UN);
            fclose($h);
        }
    }

    private static function writeCircuitBreakerFile(string $cbFile, array $data): void
    {
        $tmp = $cbFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data)) !== false) {
            @chmod($tmp, 0664);
            if (!@rename($tmp, $cbFile)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Middleware per Circuit Breaker su GovPay.
     */
    public static function circuitBreakerMiddleware(): \Closure
    {
        return static function (callable $handler) {
            return static function (HttpRequest $request, array $options) use ($handler) {
                $cbFile = self::circuitBreakerFilePath();
                $cooloff = 30; // secondi
                $maxFailures = 3;

                if (file_exists($cbFile)) {
                    $cbData = json_decode((string)@file_get_contents($cbFile), true);
                    if (is_array($cbData) && ($cbData['status'] ?? '') === 'OPEN') {
                        $lastFailure = (int)($cbData['last_failure_time'] ?? 0);
                        if (time() - $lastFailure < $cooloff) {
                            return \GuzzleHttp\Promise\Create::rejectionFor(
                                new \GuzzleHttp\Exception\ConnectException(
                                    self::CIRCUIT_OPEN_MESSAGE,
                                    $request
                                )
                            );
                        }
                        $cbData['status'] = 'HALF-OPEN';
                        self::writeCircuitBreakerFile($cbFile, $cbData);
                    }
                }

                return $handler($request, $options)->then(
                    static function ($response) use ($cbFile) {
                        if (file_exists($cbFile)) {
                            @unlink($cbFile);
                        }
                        return $response;
                    },
                    static function ($reason) use ($cbFile, $maxFailures) {
                        $isNetworkError = false;
                        if ($reason instanceof \GuzzleHttp\Exception\ConnectException) {
                            $isNetworkError = true;
                        } elseif ($reason instanceof RequestException && !$reason->hasResponse()) {
                            $isNetworkError = true;
                        }

                        if ($isNetworkError) {
                            $opened = 0;
                            self::withCircuitBreakerLock($cbFile, static function () use ($cbFile, $maxFailures, &$opened): void {
                                $cbData = ['status' => 'CLOSED', 'failures' => 0, 'last_failure_time' => 0];
                                if (file_exists($cbFile)) {
                                    $existing = json_decode((string)@file_get_contents($cbFile), true);
                                    if (is_array($existing)) {
                                        $cbData = $existing;
                                    }
                                }
                                $cbData['failures'] = ($cbData['failures'] ?? 0) + 1;
                                $cbData['last_failure_time'] = time();

                                if ($cbData['failures'] >= $maxFailures) {
                                    $cbData['status'] = 'OPEN';
                                    $opened = (int)$cbData['failures'];
                                }
                                self::writeCircuitBreakerFile($cbFile, $cbData);
                            });
                            if ($opened > 0) {
                                Logger::getInstance()->error(sprintf(
                                    'Connessione a GovPay fallita per %d volte. Circuit Breaker APERTO.',
                                    $opened
                                ));
                            }
                        }

                        return \GuzzleHttp\Promise\Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    /**
     * Client GovPay Backoffice v1 SDK (`GovPay\Backoffice\Api\PendenzeApi`) pronto all'uso.
     * Unico punto di istanziazione del client SDK Backoffice.
     */
    public static function makeBackofficeSdkApi(): \GovPay\Backoffice\Api\PendenzeApi
    {
        $url = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');
        $config = new \GovPay\Backoffice\Configuration();
        $config->setHost($url);
        self::applyCredentials($config);
        return new \GovPay\Backoffice\Api\PendenzeApi(self::makeBackofficeClient(), $config);
    }

    /**
     * Applica username/password GovPay a qualsiasi oggetto Configuration SDK generato.
     * Estratto da ImpostazioniController::applyGovpayCredentials() (linea 2953).
     */
    public static function applyCredentials(object $config): void
    {
        $user = (string)SettingsRepository::get('govpay', 'user', '');
        $pass = (string)SettingsRepository::get('govpay', 'password', '');
        if ($user !== '' && $pass !== '') {
            $config->setUsername($user);
            $config->setPassword($pass);
        }
    }

    /**
     * Client Backoffice con Basic Auth da impostazioni (se configurata) piu' $extra; mTLS e stack
     * middleware come makeBackofficeClient(). Per i lavori del worker e i servizi senza Slim.
     */
    public static function makeBackofficeBasicAuthClient(array $extra = []): Client
    {
        return self::makeBackofficeClient($extra + self::basicAuthOptions());
    }

    /** Client dei lavori ragioneria / rendicontazione: JSON, connect 10 s, timeout 30 s. */
    public static function makeBackofficeJobClient(): Client
    {
        return self::makeBackofficeBasicAuthClient([
            'headers' => ['Accept' => 'application/json'],
            'connect_timeout' => 10,
            'timeout' => 30,
        ]);
    }

    /**
     * Restituisce le opzioni Guzzle per Basic Auth GovPay (o array vuoto se non configurata).
     */
    public static function basicAuthOptions(): array
    {
        $user = (string)SettingsRepository::get('govpay', 'user', '');
        $pass = (string)SettingsRepository::get('govpay', 'password', '');
        return ($user !== '' && $pass !== '') ? ['auth' => [$user, $pass]] : [];
    }

    /**
     * Esegue una verifica veloce della connettività con GovPay.
     * Ritorna true se online, false altrimenti.
     */
    public static function checkGovPayStatus(): bool
    {
        $res = self::checkGovPayStatusDetails();
        return $res['online'];
    }

    /**
     * Esegue una verifica veloce della connettività con GovPay con dettagli dell'errore.
     */
    public static function checkGovPayStatusDetails(): array
    {
        $url = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');
        if ($url === '') {
            return ['online' => false, 'error' => 'GovPay Backoffice URL non configurato nel database.'];
        }

        try {
            $config = new \GovPay\Backoffice\Configuration();
            $config->setHost($url);
            self::applyCredentials($config);

            // Usiamo timeout molto bassi per non bloccare
            $client = self::makeBackofficeClient([
                'connect_timeout' => 2.0,
                'timeout'         => 3.0,
            ]);

            $api = new \GovPay\Backoffice\Api\InfoApi($client, $config);
            $api->getInfo();
            return ['online' => true, 'error' => null];
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            Logger::getInstance()->error('GovPay check status failed: ' . $msg);
            // Servito da /api/govpay/status e /api/frontoffice/govpay-status: mai URL ne' corpo upstream.
            return ['online' => false, 'error' => ThrowableSummary::forDisplay($e)];
        }
    }

    /**
     * Esegue la verifica connettività a GovPay cacheando il risultato per N secondi.
     * Ritorna un array ['online' => bool, 'error' => ?string]
     */
    public static function checkGovPayStatusCached(int $ttlSeconds = 30): array
    {
        $cacheFile = sys_get_temp_dir() . '/govpay_status_cache.json';
        if (file_exists($cacheFile)) {
            $data = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($data) && isset($data['status'], $data['time'])) {
                if (time() - $data['time'] < $ttlSeconds) {
                    return [
                        'online' => $data['status'] === 'online',
                        'error'  => $data['error'] ?? null,
                    ];
                }
            }
        }

        $res = self::checkGovPayStatusDetails();
        @file_put_contents($cacheFile, json_encode([
            'status' => $res['online'] ? 'online' : 'offline',
            'error'  => $res['error'],
            'time'   => time(),
        ]));

        return $res;
    }
}

