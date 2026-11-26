<?php
declare(strict_types=1);

namespace Tests\Security;

use App\Security\OidcUserMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OidcUserMapperTest extends TestCase
{
    private const CF   = 'RSSMRA80A01H501U';
    private const PIVA = '12345678903';

    public function testPersonaFisica(): void
    {
        $user = OidcUserMapper::map([
            'given_name'    => 'Mario',
            'family_name'   => 'Rossi',
            'email'         => 'mario.rossi@example.org',
            'fiscal_number' => 'TINIT-' . self::CF,
        ], false);

        self::assertNotNull($user);
        self::assertSame(self::CF, $user['fiscal_number']);
        self::assertSame('F', $user['subject_type']);
        self::assertSame('Mario', $user['first_name']);
        self::assertSame('Rossi', $user['last_name']);
        self::assertSame('mario.rossi@example.org', $user['email']);
        self::assertSame('EXTERNAL_OIDC_PROXY', $user['provider_id']);
        self::assertArrayNotHasKey('company_name', $user);
        self::assertArrayNotHasKey('operator_fiscal_number', $user);
    }

    public function testPersonaFisicaIgnoraClaimAzienda(): void
    {
        $user = OidcUserMapper::map([
            'fiscal_number' => 'TINIT-' . self::CF,
            'iva_code'      => 'VATIT-' . self::PIVA,
            'company_name'  => 'ACME SRL',
        ], false);

        self::assertNotNull($user);
        self::assertSame('F', $user['subject_type']);
        self::assertSame(self::CF, $user['fiscal_number']);
        self::assertArrayNotHasKey('company_name', $user);
    }

    public function testPersonaGiuridica(): void
    {
        $user = OidcUserMapper::map([
            'given_name'        => ['Mario'],
            'family_name'       => ['Rossi'],
            'fiscal_number'     => ['TINIT-' . self::CF],
            'iva_code'          => ['VATIT-' . self::PIVA],
            'company_name'      => ['ACME SRL'],
            'registered_office' => ['Via Roma 1'],
        ], true);

        self::assertNotNull($user);
        self::assertSame(self::PIVA, $user['fiscal_number']);
        self::assertSame('G', $user['subject_type']);
        self::assertSame('ACME SRL', $user['company_name']);
        self::assertSame('Via Roma 1', $user['registered_office']);
        self::assertSame(self::CF, $user['operator_fiscal_number']);
        self::assertSame('Mario', $user['first_name']);
        self::assertSame('Rossi', $user['last_name']);
    }

    /** @return array<string, array{mixed}> */
    public static function ivaMancante(): array
    {
        return [
            'assente' => [null],
            'vuota'   => [''],
            'corta'   => ['VATIT-123'],
        ];
    }

    #[DataProvider('ivaMancante')]
    public function testPersonaGiuridicaSenzaIva(mixed $iva): void
    {
        $claims = ['fiscal_number' => 'TINIT-' . self::CF, 'company_name' => 'ACME SRL'];
        if ($iva !== null) {
            $claims['iva_code'] = $iva;
        }
        self::assertNull(OidcUserMapper::map($claims, true));
    }

    /** @return array<string, array{mixed, ?string}> */
    public static function ivaCases(): array
    {
        return [
            'prefisso VATIT' => ['VATIT-' . self::PIVA, self::PIVA],
            'prefisso IT'    => ['IT' . self::PIVA, self::PIVA],
            'spazi'          => [' 123 456 789 03 ', self::PIVA],
            'lista'          => [[self::PIVA], self::PIVA],
            'prefisso IT-'   => ['IT-' . self::PIVA, self::PIVA],
            'VATIT senza -'  => ['VATIT' . self::PIVA, self::PIVA],
            'vuota'          => ['', null],
            '10 cifre'       => ['1234567890', null],
            'lettere'        => ['ABCDEFGHIJK', null],
            'lista vuota'    => [[], null],
            'null'           => [null, null],
        ];
    }

    #[DataProvider('ivaCases')]
    public function testNormalizeIvaCode(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, OidcUserMapper::normalizeIvaCode($raw));
    }

    public function testNormalizeFiscalNumber(): void
    {
        self::assertSame(self::CF, OidcUserMapper::normalizeFiscalNumber(' tinit-rssmra80a01h501u '));
    }

    public function testAuditFieldsPersonaFisica(): void
    {
        $fields = OidcUserMapper::auditFields([
            'first_name' => 'Mario', 'last_name' => 'Rossi', 'fiscal_number' => self::CF,
            'email' => 'mario.rossi@example.org', 'provider_name' => 'SPID', 'subject_type' => 'F',
        ]);
        self::assertSame([
            'utente_autenticato' => true,
            'utente_nome'        => 'Mario',
            'utente_cognome'     => 'Rossi',
            'utente_cf'          => self::CF,
            'utente_email'       => 'mario.rossi@example.org',
            'utente_provider'    => 'SPID',
            'utente_tipo'        => 'F',
        ], $fields);
    }

    public function testAuditFieldsPersonaGiuridicaConservaOperatore(): void
    {
        $fields = OidcUserMapper::auditFields([
            'first_name' => 'Mario', 'last_name' => 'Rossi', 'fiscal_number' => self::PIVA,
            'subject_type' => 'G', 'company_name' => 'ACME SRL', 'operator_fiscal_number' => self::CF,
            'provider_id' => 'EXTERNAL_OIDC_PROXY',
        ]);
        self::assertSame(self::PIVA, $fields['utente_cf']);
        self::assertSame('G', $fields['utente_tipo']);
        self::assertSame('ACME SRL', $fields['utente_ragione_sociale']);
        self::assertSame(self::CF, $fields['utente_operatore_cf']);
        self::assertSame('EXTERNAL_OIDC_PROXY', $fields['utente_provider']);
    }

    public function testAuditFieldsSessioneVecchiaSenzaTipo(): void
    {
        $fields = OidcUserMapper::auditFields(['fiscal_number' => self::CF]);
        self::assertSame('F', $fields['utente_tipo']);
        self::assertArrayNotHasKey('utente_operatore_cf', $fields);
    }

    public function testClaimNames(): void
    {
        self::assertSame(['a', 'b'], OidcUserMapper::claimNames(['b' => 1, 'a' => 2]));
    }

    /** pa-sso-proxy rilascia solo i claim chiesti col parametro `claims` (contratto di /verifica). */
    public function testRequestedClaimsPersonaFisica(): void
    {
        $claims = OidcUserMapper::requestedClaims(false);

        self::assertSame(['userinfo'], array_keys($claims));
        self::assertSame([
            'fiscal_number' => ['essential' => true],
            'given_name'    => null,
            'family_name'   => null,
            'email'         => null,
        ], $claims['userinfo']);
        self::assertSame(
            '{"userinfo":{"fiscal_number":{"essential":true},"given_name":null,"family_name":null,"email":null}}',
            json_encode($claims),
        );
    }

    public function testRequestedClaimsPersonaGiuridica(): void
    {
        $userinfo = OidcUserMapper::requestedClaims(true)['userinfo'];

        self::assertSame(['essential' => true], $userinfo['fiscal_number']);
        self::assertSame(['essential' => true], $userinfo['company_name']);
        self::assertSame(['essential' => true], $userinfo['iva_code']);
        self::assertArrayHasKey('registered_office', $userinfo);
        self::assertNull($userinfo['registered_office']);
        self::assertArrayHasKey('email', $userinfo);
    }

    /** Ogni claim letto da map() per l'impresa viene anche chiesto. */
    public function testRequestedClaimsCopronoMap(): void
    {
        $userinfo = OidcUserMapper::requestedClaims(true)['userinfo'];
        $claims = array_fill_keys(array_keys($userinfo), null);
        $claims['fiscal_number'] = 'TINIT-' . self::CF;
        $claims['iva_code'] = 'VATIT-' . self::PIVA;
        $claims['company_name'] = 'ACME SRL';
        $claims['registered_office'] = 'Via Roma 1';
        $claims['given_name'] = 'Mario';
        $claims['family_name'] = 'Rossi';
        $claims['email'] = 'impresa@example.org';

        $user = OidcUserMapper::map($claims, true);

        self::assertNotNull($user);
        self::assertSame(self::PIVA, $user['fiscal_number']);
        self::assertSame(self::CF, $user['operator_fiscal_number']);
        self::assertSame('ACME SRL', $user['company_name']);
        self::assertSame('Via Roma 1', $user['registered_office']);
        self::assertSame('impresa@example.org', $user['email']);
    }
}
