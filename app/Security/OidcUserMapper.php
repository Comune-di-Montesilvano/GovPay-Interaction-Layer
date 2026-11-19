<?php
declare(strict_types=1);

namespace App\Security;

/**
 * Claim OIDC (id_token + UserInfo di pa-sso-proxy) → utente di sessione frontoffice.
 *
 * Login persona giuridica (scope `legal_entity`, SPID Tipo 3/4 Purpose=PG): `fiscal_number`
 * è il CF della persona fisica che opera, l'impresa è identificata da `iva_code`.
 * La sessione opera a nome dell'impresa: `fiscal_number` = P.IVA, `subject_type` = G.
 */
final class OidcUserMapper
{
    private const FISCAL_KEYS = [
        'fiscal_number',
        'https://attributes.eid.gov.it/fiscal_number',
        'https://attributes.spid.gov.it/fiscalNumber',
        'fiscalNumber',
        'fiscalCode',
        'codiceFiscale',
    ];

    /**
     * @param array<string, mixed> $claims
     * @return array<string, string>|null null solo se è richiesta la persona giuridica
     *                                    e manca una P.IVA valida
     */
    public static function map(array $claims, bool $legalEntity): ?array
    {
        $user = [
            'first_name'    => self::pick($claims, ['given_name', 'first_name', 'name', 'givenName']),
            'last_name'     => self::pick($claims, ['family_name', 'last_name', 'sn', 'surname', 'familyName']),
            'email'         => self::pick($claims, ['email', 'mail', 'emailAddress']),
            'fiscal_number' => self::normalizeFiscalNumber(self::pick($claims, self::FISCAL_KEYS)),
            'spid_code'     => self::pick($claims, ['spid_code', 'spidCode']),
            'provider_id'   => 'EXTERNAL_OIDC_PROXY',
            'provider_name' => self::pick($claims, ['provider_name', 'amr']) ?: 'OIDC',
            'response_id'   => self::pick($claims, ['jti']),
            'subject_type'  => 'F',
        ];

        if (!$legalEntity) {
            return $user;
        }

        $piva = self::normalizeIvaCode($claims['iva_code'] ?? null);
        if ($piva === null) {
            return null;
        }

        $user['operator_fiscal_number'] = $user['fiscal_number'];
        $user['fiscal_number']          = $piva;
        $user['subject_type']           = 'G';
        $user['company_name']           = self::pick($claims, ['company_name']);
        $user['registered_office']      = self::pick($claims, ['registered_office']);

        return $user;
    }

    public static function normalizeFiscalNumber(string $raw): string
    {
        $value = (string)preg_replace('/\s+/', '', strtoupper(trim($raw)));
        // CIE/SPID spesso inviano il formato qualificato TINIT-<CF>.
        if (str_starts_with($value, 'TINIT-')) {
            $value = substr($value, 6);
        }
        return $value;
    }

    public static function normalizeIvaCode(mixed $raw): ?string
    {
        $value = (string)preg_replace('/\s+/', '', strtoupper(self::flat($raw)));
        foreach (['VATIT-', 'IT'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $value = substr($value, strlen($prefix));
                break;
            }
        }
        return preg_match('/^\d{11}$/', $value) === 1 ? $value : null;
    }

    /**
     * Nomi dei claim, ordinati: unica informazione sui claim ammessa nei log (mai i valori).
     *
     * @param array<array-key, mixed> $claims
     * @return list<string>
     */
    public static function claimNames(array $claims): array
    {
        $names = array_map('strval', array_keys($claims));
        sort($names);
        return $names;
    }

    /** Attributi SPID arrivano come lista a un elemento: accetta stringa o lista. */
    private static function flat(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value === [] ? '' : reset($value);
        }
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @param array<string, mixed> $claims
     * @param list<string> $keys
     */
    private static function pick(array $claims, array $keys): string
    {
        foreach ($keys as $key) {
            $value = self::flat($claims[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
