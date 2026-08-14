# Integrazione Sentry/GlitchTip + standardizzazione pause demoni — design

Data: 2026-08-14

## Contesto

GIL non ha error tracking centralizzato: eccezioni ed errori applicativi finiscono solo in `App\Logger` (file `storage/logs/app.log` + stderr, catturato da `docker logs`). Con più istanze (comuni) in produzione, diagnosi richiede accesso SSH/Portainer per leggere log grezzi.

Obiettivo: instradare eccezioni non gestite e log applicativi (`error`/`warning`) verso un'istanza GlitchTip (self-hosted, API-compatibile Sentry) via SDK ufficiale `sentry/sentry`. Ogni istanza GIL (comune) punta a un DSN configurato in `.env`, con tag di `environment` per distinguere le installazioni e tag `suite` per distinguere backoffice/frontoffice/demone.

Incluso in questo lavoro anche uno scope collaterale scoperto durante la review dei demoni: due loop hanno pausa "coda vuota" a 15 secondi (troppo aggressiva, poll continuo su DB senza motivo — gli altri demoni equivalenti sono già a 15-30 minuti). Standardizzati a 15 minuti insieme al lavoro Sentry perché toccano gli stessi file (`scripts/cron_*.php`) in cui va comunque aggiunto `SentryReporter::init()`.

## Scope: cosa NON include

- Nessun performance tracing/APM (`traces_sample_rate = 0`).
- Nessuna modifica a `SettingsRepository`/tabella `settings` — DSN e environment restano solo in `.env` (richiesta esplicita, perché servono prima di ogni bootstrap DB e variano per istanza/deployment).
- `Logger::debug()`/`info()` non toccano Sentry (rumore).
- Nessun `set_exception_handler` globale nel frontoffice (cambierebbe l'UX di errore attuale) — si usa `Sentry\captureLastError()` da shutdown function, non invasivo.

## Componente: `App\Monitoring\SentryReporter`

Nuovo file `app/Monitoring/SentryReporter.php` (namespace condiviso `App\`, stesso source root di `Logger`).

```php
SentryReporter::init(?string $suiteOverride = null): void
```

- Legge `SENTRY_DSN` da env. Vuoto/assente → non chiama `Sentry\init()`. Le funzioni `Sentry\capture*` senza client bound sono no-op sicuri (comportamento nativo SDK) — nessun guard aggiuntivo necessario nei punti di chiamata.
- Se DSN presente:
  ```php
  Sentry\init([
      'dsn' => $dsn,
      'environment' => getenv('SENTRY_ENVIRONMENT') ?: 'production',
      'traces_sample_rate' => 0,
      'send_default_pii' => false,
      'before_send' => $scrubCallback,
  ]);
  ```
  `$scrubCallback`: prima dell'invio, scansiona ricorsivamente `extra`/`contexts` dell'evento e redige (`[REDACTED]`) i valori le cui chiavi matchano case-insensitive `cf|codice_fiscale|iban|pan`. Difesa in profondità — non sostituisce la disciplina a monte su cosa finisce nel `$context` di `Logger`.
- Dopo init: `Sentry\configureScope()->setTag('suite', $suiteOverride ?? (getenv('APP_SUITE') ?: 'unknown'))`.

## Variabili `.env` (bootstrap, aggiunte a `.env.example`)

```
# ─────────────────────────────────────────────────────────────────────────────
# SENTRY / GLITCHTIP — opzionale, vuoto = error tracking disattivo
# ─────────────────────────────────────────────────────────────────────────────
SENTRY_DSN=
SENTRY_ENVIRONMENT=
```

## Punti di init

Chiamata `\App\Monitoring\SentryReporter::init(...)` aggiunta a:

- `backoffice/src/bootstrap/app.php` — inizio, prima di `AppFactory::create()`. Nessun `$suiteOverride` (usa `APP_SUITE=backoffice` da env container).
- `frontoffice/public/index.php` — inizio file, dopo autoload/dotenv. Nessun `$suiteOverride` (`APP_SUITE=frontoffice`).
- Ognuno degli 8 `scripts/cron_*.php`, dopo il blocco autoload/dotenv esistente, con `$suiteOverride` = slug del demone (`cron-ragioneria`, `cron-biz-scanner`, `cron-tefa-scanner`, `cron-mapping-pendenze`, `cron-vocab-mapping`, `cron-pendenze-massive`, `cron-govpay-debitore-scanner`, `cron-rendicontazione-govpay`).

## Hook 1 — eccezioni non gestite

- **Backoffice**: `backoffice/src/routes/web.php:1249` (`setDefaultErrorHandler`, handler 500 generico Slim) — aggiunta `\Sentry\captureException($exception);` accanto all'`error_log` esistente. Unico punto, copre tutte le route.
- **Frontoffice**: nessun handler globale oggi. Aggiunta `register_shutdown_function(fn() => \Sentry\captureLastError())` subito dopo `SentryReporter::init()` in `public/index.php` — cattura l'ultimo fatal error via `error_get_last()` internamente all'SDK, senza intercettare/cambiare il comportamento normale di visualizzazione errore.
- **Demoni cron**: vedi Hook 2 (estensione `Logger::error/warning` con `$exception`).

## Hook 2 — log applicativi

`app/Logger.php`, metodi `error()`/`warning()` estesi con parametro opzionale:

```php
public function error(string $message, array $context = [], ?\Throwable $exception = null): void
public function warning(string $message, array $context = [], ?\Throwable $exception = null): void
```

Dopo la scrittura file/stderr esistente (invariata), dentro try/catch silenzioso (il monitoring non deve mai rompere il logging primario):

```php
try {
    if ($exception !== null) {
        \Sentry\captureException($exception);
    } else {
        \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($message, $context, $level) {
            if ($context) { $scope->setExtra('context', $context); }
            \Sentry\captureMessage($message, $level === 'error' ? Severity::error() : Severity::warning());
        });
    }
} catch (\Throwable $_) {
    // monitoring non deve mai interrompere il flusso applicativo
}
```

**Call site cron**: nei loop dei demoni, i blocchi `catch (\Throwable $e)` che oggi chiamano `Logger::getInstance()->error($msg)` passano anche `$e` come terzo argomento, per preservare stack trace/grouping in Sentry invece di solo testo. Punti toccati: i catch generici a livello di iterazione principale in ciascuno degli 8 `cron_*.php` (non i retry/backoff interni già gestiti localmente, es. i 3 tentativi in `cron_biz_scanner.php:178`).

## Standardizzazione pause demoni (scope collaterale)

Verifica codice (non solo commenti CLAUDE.md, che in alcuni punti erano disallineati) — pause attuali "coda vuota / nessuna assegnazione":

| Demone | Pausa attuale | Azione |
|---|---|---|
| `cron_ragioneria.php` | 30 min (1800s, chunked 10s) | invariato |
| `cron_biz_scanner.php` | 15 min (900s, chunked 10s) | invariato |
| `cron_tefa_scanner.php` | 15 min (900s, chunked 10s) | invariato |
| `cron_govpay_debitore_scanner.php` | 15 min (`SLEEP_IDLE_S=900`, chunked 10s) | invariato |
| `cron_rendicontazione_govpay.php` | configurabile (`rendicontazione.scan_interval_minuti`, default 15) | invariato |
| `cron_mapping_pendenze.php` | **15s** (`for ($s=0;$s<15;$s++) sleep(1)`) | → 900s |
| `cron_vocab_mapping.php` | **15s** (stesso pattern) | → 900s |
| `cron_pendenze_massive.php` | 30s (`sleep($sleepSeconds)` non chunked, nessun check stop durante l'attesa) | → 900s, chunked |

Modifiche:

- `cron_mapping_pendenze.php:170-173` e `cron_vocab_mapping.php:179-182`: `for ($s=0;$s<15;$s++) { $checkStop(); sleep(1); }` → `for ($s=0;$s<900;$s+=10) { $checkStop(); sleep(10); }`, allineato al pattern chunked già usato da ragioneria/biz/tefa/debitore. Messaggio di log aggiornato (`Pausa 15s...` → `Pausa 15 minuti...`).
- `cron_pendenze_massive.php:155,241`: `$sleepSeconds = 30` → `900`; `sleep($sleepSeconds)` sostituito con loop chunked a 10s che ricontrolla `$stopFile` (oggi controllato solo a inizio ciclo, riga 158) — necessario per non rendere lo stop-file-based stop lento fino a 15 min (il segnale SIGTERM resta comunque immediato via handler esistente, non impattato).

## Testing

- Verifica manuale: `SENTRY_DSN` vuoto → nessuna chiamata di rete, nessun errore (SDK no-op).
- Verifica manuale: `SENTRY_DSN` valorizzato su istanza GlitchTip locale/test → route `/_test-error` (già esistente in debug) genera evento visibile in GlitchTip con tag `suite=backoffice`, `environment=<valore configurato>`.
- Verifica manuale: `Logger::getInstance()->error('test', [], new \RuntimeException('x'))` produce evento Sentry con stack trace.
- Verifica manuale pause demoni: avviare `cron_mapping_pendenze.php`/`cron_vocab_mapping.php`/`cron_pendenze_massive.php` con coda vuota, confermare log `Pausa 15 minuti...` e che l'invio di stop-file interrompe l'attesa entro ~10s (non fino a 15 min).
- `php -l` su tutti i file toccati (8 cron script + `web.php` + `public/index.php` + `Logger.php` + nuovo `SentryReporter.php`).

## Dipendenze

`composer require sentry/sentry` (no `sentry/sdk` bundle, no integrazioni framework-specifiche — SDK framework-agnostic, usa Guzzle già presente come transport).
