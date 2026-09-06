<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Validazioni anagrafiche (CF, P.IVA, ecc.).
 * Estratto come servizio dedicato per permettere in futuro l'integrazione
 * con un servizio esterno di verifica.
 */
class ValidationService
{
    /**
     * Valida la lunghezza della causale secondo specifiche (<= 140).
     */
    public static function validateCausaleLength(string $causale): bool
    {
        return mb_strlen(trim($causale), 'UTF-8') <= 140;
    }

    /**
     * Verifica codice fiscale: formato, carattere di controllo e coerenza
     * con blocchi nome/cognome (se forniti). Non valida data/comune.
     *
     * @return array{valid:bool, format_ok:bool, check_ok:bool, name_match:bool, message:?string}
     */
    public static function validateCodiceFiscale(string $cf, ?string $nome = null, ?string $cognome = null): array
    {
        $cf = strtoupper(trim($cf));
        $result = [
            'valid' => false,
            'format_ok' => false,
            'check_ok' => false,
            'name_match' => true,
            'message' => null,
        ];

        if (!preg_match('/^[A-Z0-9]{16}$/', $cf)) {
            $result['message'] = 'Formato codice fiscale non valido';
            return $result;
        }
        $result['format_ok'] = true;

        // Check digit
        $evenMap = [
            '0'=>0,'1'=>1,'2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,
            'A'=>0,'B'=>1,'C'=>2,'D'=>3,'E'=>4,'F'=>5,'G'=>6,'H'=>7,'I'=>8,'J'=>9,
            'K'=>10,'L'=>11,'M'=>12,'N'=>13,'O'=>14,'P'=>15,'Q'=>16,'R'=>17,'S'=>18,'T'=>19,
            'U'=>20,'V'=>21,'W'=>22,'X'=>23,'Y'=>24,'Z'=>25,
        ];
        $oddMap = [
            '0'=>1,'1'=>0,'2'=>5,'3'=>7,'4'=>9,'5'=>13,'6'=>15,'7'=>17,'8'=>19,'9'=>21,
            'A'=>1,'B'=>0,'C'=>5,'D'=>7,'E'=>9,'F'=>13,'G'=>15,'H'=>17,'I'=>19,'J'=>21,
            'K'=>2,'L'=>4,'M'=>18,'N'=>20,'O'=>11,'P'=>3,'Q'=>6,'R'=>8,'S'=>12,'T'=>14,
            'U'=>16,'V'=>10,'W'=>22,'X'=>25,'Y'=>24,'Z'=>23,
        ];
        $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $ch = $cf[$i];
            if ($i % 2 === 0) { // posizioni dispari 1-based
                $sum += $oddMap[$ch] ?? 0;
            } else {
                $sum += $evenMap[$ch] ?? 0;
            }
        }
        $expected = $alpha[$sum % 26];
        $checkOk = ($cf[15] === $expected);
        $result['check_ok'] = $checkOk;
        if (!$checkOk) {
            $result['message'] = 'Carattere di controllo del codice fiscale non valido';
            return $result;
        }

        // Coerenza nome/cognome se entrambi forniti (non vuoti)
        if ($nome !== null && trim($nome) !== '' && $cognome !== null && trim($cognome) !== '') {
            $codCognome = self::cfBlockFromString((string)$cognome, false);
            $codNome = self::cfBlockFromString((string)$nome, true);
            if (substr($cf, 0, 3) !== $codCognome || substr($cf, 3, 3) !== $codNome) {
                $result['name_match'] = false;
                $result['message'] = 'Codice fiscale non coerente con nome e cognome indicati';
                return $result;
            }
        }

        $result['valid'] = true;
        $result['message'] = null;
        return $result;
    }

    /**
     * Costruisce il blocco CF da stringa nome o cognome.
     * Regola: prende consonanti, poi vocali; per il nome se >=4 consonanti
     * prende 1a, 3a, 4a.
     */
    private static function cfBlockFromString(string $str, bool $isName): string
    {
        $s = strtoupper(self::normalizeAscii($str));
        $s = preg_replace('/[^A-Z]/', '', $s) ?? '';
        $consonants = preg_replace('/[AEIOU]/', '', $s);
        $vowels = preg_replace('/[^AEIOU]/', '', $s);
        $block = '';
        if ($isName) {
            if (strlen($consonants) >= 4) {
                $block = $consonants[0] . $consonants[2] . $consonants[3];
            } else {
                $block = substr($consonants, 0, 3);
            }
        } else {
            $block = substr($consonants, 0, 3);
        }
        if (strlen($block) < 3) {
            $block .= substr($vowels, 0, 3 - strlen($block));
        }
        while (strlen($block) < 3) $block .= 'X';
        return $block;
    }

    /**
     * Normalizza in ASCII rimuovendo accenti e caratteri non standard.
     */
    private static function normalizeAscii(string $str): string
    {
        if (function_exists('iconv')) {
            $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT', $str);
            if ($tmp !== false) return $tmp;
        }
        return $str;
    }

    /**
     * Valida la Partita IVA (11 cifre con algoritmo del check digit).
     * @return array{valid:bool, message:?string}
     */
    public static function validatePartitaIva(string $piva): array
    {
        $digits = preg_replace('/[^0-9]/', '', trim($piva));
        if (strlen($digits) !== 11) {
            return ['valid' => false, 'message' => 'La partita IVA deve contenere 11 cifre'];
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $n = (int)$digits[$i];
            if ($i % 2 === 0) { // posizioni dispari 1-based
                $sum += $n;
            } else {
                $n *= 2; if ($n > 9) $n -= 9; $sum += $n;
            }
        }
        $check = (10 - ($sum % 10)) % 10;
        if ($check !== (int)$digits[10]) {
            return ['valid' => false, 'message' => 'Partita IVA non valida'];
        }
        return ['valid' => true, 'message' => null];
    }
}
