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
            $result[$key] = $value;
        }
        return $result;
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
