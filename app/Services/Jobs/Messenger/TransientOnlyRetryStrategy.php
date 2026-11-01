<?php
declare(strict_types=1);

namespace App\Services\Jobs\Messenger;

use App\Services\Jobs\TransientMassiveFailure;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;

/**
 * Strategia di retry della coda `massive`: ritenta SOLO TransientMassiveFailure (rete/esito
 * incerto, GovPay 5xx), con i limiti della strategia interna (default: 3 retry, 30 s x2 ->
 * 30/60/120 s, senza jitter). Ogni altra eccezione (bug, DB, ...) non viene ritentata: la riga
 * va subito nello stato di errore.
 */
final class TransientOnlyRetryStrategy implements RetryStrategyInterface
{
    public const MAX_RETRIES = 3;
    public const DELAY_MS = 30_000;
    public const MULTIPLIER = 2.0;

    private readonly RetryStrategyInterface $inner;

    public function __construct(?RetryStrategyInterface $inner = null)
    {
        $this->inner = $inner ?? new MultiplierRetryStrategy(self::MAX_RETRIES, self::DELAY_MS, self::MULTIPLIER, 0, 0.0);
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        return self::isTransient($throwable) && $this->inner->isRetryable($message, $throwable);
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        return $this->inner->getWaitingTime($message, $throwable);
    }

    /** true se l'errore (o uno degli errori del gestore) e' una TransientMassiveFailure. */
    public static function isTransient(?\Throwable $throwable): bool
    {
        if ($throwable instanceof HandlerFailedException) {
            foreach ($throwable->getWrappedExceptions() as $nested) {
                if ($nested instanceof TransientMassiveFailure) {
                    return true;
                }
            }
            return false;
        }
        return $throwable instanceof TransientMassiveFailure;
    }

    /** Eccezione originale del gestore (senza l'involucro HandlerFailedException). */
    public static function unwrap(\Throwable $throwable): \Throwable
    {
        if ($throwable instanceof HandlerFailedException) {
            foreach ($throwable->getWrappedExceptions() as $nested) {
                return $nested;
            }
        }
        return $throwable;
    }
}
