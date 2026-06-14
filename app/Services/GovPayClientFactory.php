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
    /**
     * Client HTTP per chiamate raw verso GovPay Backoffice v1.
     *
     * Include: TLS v1.2 forzato, Connection:close, retry automatico su cURL 35
     * (backoff esponenziale con jitter, max 5 tentativi, 0–2000 ms).
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

        $defaultCurlOptions = [];
        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $defaultCurlOptions[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }
        if (defined('CURL_HTTP_VERSION_1_1')) {
            $guzzleOptions['version'] = '1.1';
        }
        if (defined('CURLOPT_SSL_SESSIONID_CACHE')) {
            $defaultCurlOptions[CURLOPT_SSL_SESSIONID_CACHE] = false;
        }
        if (defined('CURLOPT_FORBID_REUSE')) {
            $defaultCurlOptions[CURLOPT_FORBID_REUSE] = true;
        }
        if (!empty($defaultCurlOptions)) {
            $guzzleOptions['curl'] = ($guzzleOptions['curl'] ?? []) + $defaultCurlOptions;
        }

        $handlerStack = $guzzleOptions['handler'] ?? null;
        if ($handlerStack === null) {
            $handlerStack = new \GuzzleHttp\HandlerStack();
            $handlerStack->setHandler(new \GuzzleHttp\Handler\CurlHandler());
        } else {
            unset($guzzleOptions['handler']);
        }

        $handlerStack->push(Middleware::mapRequest(
            static function (HttpRequest $request): HttpRequest {
                return $request->withHeader('Connection', 'close');
            }
        ));

        $maxTlsRetries = 5;
        $handlerStack->push(Middleware::retry(
            function (int $retries, $request, $response = null, $exception = null) use ($maxTlsRetries): bool {
                if (!$exception instanceof RequestException) {
                    return false;
                }
                $context = $exception->getHandlerContext();
                $errno   = (int)($context['errno'] ?? 0);
                $message = strtolower($exception->getMessage());
                $isTlsError = $errno === 35 || str_contains($message, 'curl error 35');
                if (!$isTlsError) {
                    return false;
                }
                if ($retries >= $maxTlsRetries) {
                    Logger::error(sprintf(
                        'GovPay TLS error after %d retries (errno %s): %s',
                        $maxTlsRetries, $errno ?: 'n/a', $exception->getMessage()
                    ));
                    return false;
                }
                Logger::warning(sprintf(
                    'Retry GovPay call after TLS error (attempt %d/%d, errno %s)',
                    $retries + 1, $maxTlsRetries, $errno ?: 'n/a'
                ));
                return true;
            },
            static function (int $retries): int {
                if ($retries <= 0) {
                    return 0;
                }
                $baseDelay = 200 * (1 << ($retries - 1));
                $jitter    = random_int(0, 100);
                return (int)min(2000, $baseDelay + $jitter);
            }
        ));

        $handlerStack->push(self::circuitBreakerMiddleware(), 'circuit_breaker');

        $guzzleOptions['handler'] = $handlerStack;

        return new Client($guzzleOptions);
    }

    /**
     * Middleware per Circuit Breaker su GovPay.
     */
    public static function circuitBreakerMiddleware(): \Closure
    {
        return static function (callable $handler) {
            return static function (HttpRequest $request, array $options) use ($handler) {
                $cbFile = sys_get_temp_dir() . '/govpay_circuit_breaker.json';
                $cooloff = 30; // secondi
                $maxFailures = 3;

                if (file_exists($cbFile)) {
                    $cbData = json_decode((string)@file_get_contents($cbFile), true);
                    if (is_array($cbData) && ($cbData['status'] ?? '') === 'OPEN') {
                        $lastFailure = (int)($cbData['last_failure_time'] ?? 0);
                        if (time() - $lastFailure < $cooloff) {
                            return \GuzzleHttp\Promise\Create::rejectionFor(
                                new \GuzzleHttp\Exception\ConnectException(
                                    'GovPay is offline (Circuit Breaker active)',
                                    $request
                                )
                            );
                        }
                        $cbData['status'] = 'HALF-OPEN';
                        @file_put_contents($cbFile, json_encode($cbData));
                    }
                }

                return $handler($request, $options)->then(
                    static function ($response) use ($cbFile) {
                        if (file_exists($cbFile)) {
                            @unlink($cbFile);
                        }
                        return $response;
                    },
                    static function ($reason) use ($cbFile, $request, $maxFailures) {
                        $isNetworkError = false;
                        if ($reason instanceof \GuzzleHttp\Exception\ConnectException) {
                            $isNetworkError = true;
                        } elseif ($reason instanceof RequestException && !$reason->hasResponse()) {
                            $isNetworkError = true;
                        }

                        if ($isNetworkError) {
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
                                Logger::error(sprintf(
                                    'Connessione a GovPay fallita per %d volte. Circuit Breaker APERTO.',
                                    $cbData['failures']
                                ));
                            }
                            @file_put_contents($cbFile, json_encode($cbData));
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
     * Restituisce le opzioni Guzzle per Basic Auth GovPay (o array vuoto se non configurata).
     */
    public static function basicAuthOptions(): array
    {
        $user = (string)SettingsRepository::get('govpay', 'user', '');
        $pass = (string)SettingsRepository::get('govpay', 'password', '');
        return ($user !== '' && $pass !== '') ? ['auth' => [$user, $pass]] : [];
    }
}
