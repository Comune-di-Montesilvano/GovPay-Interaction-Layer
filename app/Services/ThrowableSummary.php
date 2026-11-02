<?php
declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Exception\RequestException;

/**
 * Riassunto di un'eccezione adatto a essere persistito (note/errore su DB): solo il nome breve
 * della classe e, se c'e' una risposta HTTP, lo status. Mai il messaggio: per Guzzle contiene
 * URL (con query) e un estratto del corpo upstream, che puo' riecheggiare dati personali.
 */
final class ThrowableSummary
{
    public static function of(\Throwable $e): string
    {
        $class = get_class($e);
        $pos = strrpos($class, '\\');
        $out = $pos === false ? $class : substr($class, $pos + 1);
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            $out .= ' (HTTP ' . $e->getResponse()->getStatusCode() . ')';
        }
        return $out;
    }

    /**
     * Testo d'errore da mostrare (operatore, endpoint di stato): 'HTTP <code>' piu' title/status
     * di un eventuale ProblemJson, altrimenti of(). Copre RequestException Guzzle e le
     * ApiException dei client generati (codice = status HTTP, getResponseBody()). Il messaggio
     * di circuito aperto (costante, senza dati) resta leggibile.
     */
    public static function forDisplay(\Throwable $e): string
    {
        if (str_contains($e->getMessage(), GovPayClientFactory::CIRCUIT_OPEN_MESSAGE)) {
            return GovPayClientFactory::CIRCUIT_OPEN_MESSAGE;
        }
        $status = 0;
        $body = null;
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            $status = $e->getResponse()->getStatusCode();
            $body = (string)$e->getResponse()->getBody();
        } elseif (method_exists($e, 'getResponseBody') && (int)$e->getCode() >= 100) {
            $status = (int)$e->getCode();
            $body = $e->getResponseBody();
        }
        if ($status === 0) {
            return self::of($e);
        }
        $out = 'HTTP ' . $status;
        $problem = is_string($body) ? json_decode($body, true) : json_decode((string)json_encode($body), true);
        if (is_array($problem)) {
            $title = $problem['title'] ?? null;
            if (is_string($title) && trim($title) !== '') {
                $out .= ' - ' . mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title)), 0, 100);
            }
            if (is_int($problem['status'] ?? null)) {
                $out .= ' (status ' . $problem['status'] . ')';
            }
        }
        return $out;
    }
}
