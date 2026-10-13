<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Monitoring;

use Sentry\Event;
use Sentry\Severity;
use Sentry\State\Scope;

class SentryReporter
{
    public static function resolveEnvironment(?string $envValue): string
    {
        $trimmed = trim((string)$envValue);
        return $trimmed !== '' ? $trimmed : 'production';
    }

    public static function resolveSuite(?string $override, ?string $appSuiteEnv): string
    {
        $overrideTrimmed = trim((string)$override);
        if ($overrideTrimmed !== '') {
            return $overrideTrimmed;
        }
        $envTrimmed = trim((string)$appSuiteEnv);
        return $envTrimmed !== '' ? $envTrimmed : 'unknown';
    }

    public static function shouldInit(?string $dsn): bool
    {
        return trim((string)$dsn) !== '';
    }

    /**
     * Redige ricorsivamente i valori le cui chiavi matchano pattern PII noti
     * (cf, codice_fiscale, iban, pan). Difesa in profondità per l'evento
     * inviato a Sentry/GlitchTip — non sostituisce la disciplina a monte su
     * cosa finisce nel $context passato a Logger.
     */
    public static function scrubSensitiveKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::scrubSensitiveKeys($value);
                continue;
            }
            if (is_string($key) && preg_match('/cf|codice_fiscale|iban|pan/i', $key) === 1) {
                $result[$key] = '[REDACTED]';
                continue;
            }
            $result[$key] = is_string($value) ? self::scrubSecretsInString($value) : $value;
        }
        return $result;
    }

    /**
     * Redige dati sensibili dentro testo libero: parametri segreti `nome=valore` (tipicamente
     * URL riportati da Guzzle nel messaggio d'eccezione, es. `?subscription-key=...`) e codici
     * fiscali di persona fisica (es. echo del valore nei messaggi di validazione API esterne).
     */
    public static function scrubSecretsInString(string $value): string
    {
        $value = preg_replace(
            '/\b(subscription-key|api[_-]?key|access_token|token|password|secret)=[^&\s`"\']+/i',
            '$1=[REDACTED]',
            $value
        ) ?? $value;
        return preg_replace(
            '/\b[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]\b/i',
            '[REDACTED]',
            $value
        ) ?? $value;
    }

    /**
     * Redige la query string di un URL con le stesse regole di scrubSensitiveKeys
     * (chiavi PII) e scrubSecretsInString (segreti/CF nei valori).
     */
    public static function scrubUrl(string $url): string
    {
        $pos = strpos($url, '?');
        if ($pos === false) {
            return self::scrubSecretsInString($url);
        }
        parse_str(substr($url, $pos + 1), $queryParams);
        return self::scrubSecretsInString(substr($url, 0, $pos))
            . '?' . http_build_query(self::scrubSensitiveKeys($queryParams), '', '&', PHP_QUERY_RFC3986);
    }

    public static function init(?string $suiteOverride = null): void
    {
        $dsn = getenv('SENTRY_DSN') ?: '';
        if (!self::shouldInit($dsn)) {
            return;
        }

        \Sentry\init([
            'dsn' => $dsn,
            'environment' => self::resolveEnvironment(getenv('SENTRY_ENVIRONMENT') ?: null),
            'traces_sample_rate' => 0,
            'send_default_pii' => false,
            'before_send' => static function (Event $event): ?Event {
                $extra = $event->getExtra();
                if ($extra) {
                    $event->setExtra(self::scrubSensitiveKeys($extra));
                }
                $request = $event->getRequest();
                if ($request) {
                    $mutated = false;
                    // L'URL completo (usato anche come culprit da GlitchTip) ripete la query string:
                    // va redatto come query_string, altrimenti es. `cf=` dei link firmati esce in chiaro.
                    if (isset($request['url']) && is_string($request['url'])) {
                        $request['url'] = self::scrubUrl($request['url']);
                        $mutated = true;
                    }
                    if (isset($request['query_string']) && is_string($request['query_string'])) {
                        parse_str($request['query_string'], $queryParams);
                        $request['query_string'] = http_build_query(self::scrubSensitiveKeys($queryParams));
                        $mutated = true;
                    }
                    // Il body request (POST JSON/form) non è coperto da send_default_pii=false
                    // lato SDK — su una piattaforma pagamenti può contenere CF, email, nominativi
                    // in campi liberi (es. causale) non riconoscibili da scrubSensitiveKeys per
                    // nome-chiave. Non lo si invia proprio, invece di provare a redigerlo.
                    if (isset($request['data'])) {
                        unset($request['data']);
                        $mutated = true;
                    }
                    if ($mutated) {
                        $event->setRequest($request);
                    }
                }
                return $event;
            },
        ]);

        $suite = self::resolveSuite($suiteOverride, getenv('APP_SUITE') ?: null);
        \Sentry\configureScope(static function (Scope $scope) use ($suite): void {
            $scope->setTag('suite', $suite);
        });
    }
}
