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
}
