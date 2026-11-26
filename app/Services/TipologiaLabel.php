<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use App\Database\EntrateRepository;

/**
 * Etichetta di una tipologia pendenza da mostrare al cittadino (email, App IO): descrizione
 * effettiva (locale, altrimenti GovPay), mai il codice tassonomia. Se la tipologia non e'
 * censita, ha descrizione vuota o il DB non risponde resta il codice: una notifica non deve
 * fallire per un'etichetta.
 */
final class TipologiaLabel
{
    public static function of(string $idEntrata, ?string $idDominio = null): string
    {
        $idEntrata = trim($idEntrata);
        if ($idEntrata === '') {
            return '';
        }
        try {
            $idDominio = trim((string)($idDominio ?? SettingsRepository::get('entity', 'id_dominio', '')));
            $row = (new EntrateRepository())->findDetails($idDominio, $idEntrata);
            $descrizione = trim((string)($row['descrizione_effettiva'] ?? ''));
            return $descrizione !== '' ? $descrizione : $idEntrata;
        } catch (\Throwable) {
            return $idEntrata;
        }
    }
}
