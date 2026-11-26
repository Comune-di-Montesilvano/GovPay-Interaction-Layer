<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

/**
 * IdP OIDC finto per test e2e (`php -S 0.0.0.0:8080 router.php`), stessi path di pa-sso-proxy
 * usati dal frontoffice: {issuer}/OIDC/authorization, /OIDC/token, /OIDC/userinfo.
 * Issuer = origine della richiesta (es. http://oidc:8080). Nessuno stato: il codice porta con se'
 * challenge PKCE, client_id e redirect_uri; il token endpoint verifica il code_verifier (S256).
 * Utente sintetico fisso (CF di test, mai dati reali).
 * Come pa-sso-proxy, rilascia solo i claim chiesti col parametro `claims` (userinfo/id_token):
 * senza, id_token e UserInfo hanno solo `sub` e i claim di protocollo.
 */

const FAKE_OIDC_USER = [
    'sub' => 'fake-oidc-user-1',
    'given_name' => 'Mario',
    'family_name' => 'Rossi',
    'email' => 'cittadino@example.test',
    'fiscal_number' => 'TINIT-RSSMRA80A01H501U',
    'provider_name' => 'IdP finto',
];

function oidc_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function oidc_b64url_decode(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/'), true);
}

/**
 * Nomi dei claim chiesti nel parametro `claims` (JSON con chiavi userinfo/id_token).
 *
 * @return list<string>
 */
function oidc_requested_claims(string $raw): array
{
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        return [];
    }
    $names = [];
    foreach (['userinfo', 'id_token'] as $target) {
        if (isset($req[$target]) && is_array($req[$target])) {
            $names = array_merge($names, array_map('strval', array_keys($req[$target])));
        }
    }
    return array_values(array_unique($names));
}

/** Utente filtrato sui claim chiesti; `sub` sempre presente. */
function oidc_released_user(array $requested): array
{
    return ['sub' => FAKE_OIDC_USER['sub']] + array_intersect_key(FAKE_OIDC_USER, array_flip($requested));
}

function oidc_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$issuer = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'oidc:8080');

if ($path === '/__fake/health') {
    oidc_json(200, ['ok' => true]);
    return true;
}

// Pagina di login: un link "Accedi" che torna al client con code e state.
if ($method === 'GET' && $path === '/OIDC/authorization') {
    $q = $_GET;
    $redirect = (string)($q['redirect_uri'] ?? '');
    if (($q['response_type'] ?? '') !== 'code' || $redirect === '' || ($q['client_id'] ?? '') === ''
        || ($q['code_challenge_method'] ?? '') !== 'S256' || ($q['code_challenge'] ?? '') === '') {
        oidc_json(400, ['error' => 'invalid_request']);
        return true;
    }
    $code = oidc_b64url(json_encode([
        'client_id' => $q['client_id'],
        'redirect_uri' => $redirect,
        'challenge' => $q['code_challenge'],
        'scope' => (string)($q['scope'] ?? ''),
        'claims' => oidc_requested_claims((string)($q['claims'] ?? '')),
    ]));
    $back = $redirect . (str_contains($redirect, '?') ? '&' : '?')
        . http_build_query(['code' => $code, 'state' => (string)($q['state'] ?? '')]);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><title>IdP finto</title><h1>IdP finto</h1>'
        . '<a href="' . htmlspecialchars($back, ENT_QUOTES) . '">Accedi come Mario Rossi</a>';
    return true;
}

if ($method === 'POST' && $path === '/OIDC/token') {
    $p = $_POST;
    $clientId = (string)($p['client_id'] ?? '');
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($clientId === '' && str_starts_with($auth, 'Basic ')) {
        $clientId = explode(':', (string)base64_decode(substr($auth, 6)), 2)[0];
    }
    $code = json_decode(oidc_b64url_decode((string)($p['code'] ?? '')), true);
    $verifier = (string)($p['code_verifier'] ?? '');
    if (($p['grant_type'] ?? '') !== 'authorization_code' || !is_array($code)) {
        oidc_json(400, ['error' => 'invalid_grant']);
        return true;
    }
    if ($clientId !== $code['client_id'] || ($p['redirect_uri'] ?? '') !== $code['redirect_uri']
        || oidc_b64url(hash('sha256', $verifier, true)) !== $code['challenge']) {
        oidc_json(400, ['error' => 'invalid_grant', 'error_description' => 'client, redirect_uri o PKCE non validi']);
        return true;
    }
    $now = time();
    $requested = is_array($code['claims'] ?? null) ? $code['claims'] : [];
    $claims = oidc_released_user($requested) + ['iss' => $issuer, 'aud' => $clientId, 'iat' => $now, 'exp' => $now + 300, 'jti' => bin2hex(random_bytes(8))];
    $idToken = oidc_b64url(json_encode(['alg' => 'none', 'typ' => 'JWT'])) . '.' . oidc_b64url(json_encode($claims)) . '.';
    oidc_json(200, [
        'access_token' => 'fake-at-' . oidc_b64url(json_encode(['sub' => FAKE_OIDC_USER['sub'], 'claims' => $requested])),
        'token_type' => 'Bearer',
        'expires_in' => 300,
        'id_token' => $idToken,
    ]);
    return true;
}

if ($method === 'GET' && $path === '/OIDC/userinfo') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($auth, 'Bearer fake-at-')) {
        oidc_json(401, ['error' => 'invalid_token']);
        return true;
    }
    $at = json_decode(oidc_b64url_decode(substr($auth, strlen('Bearer fake-at-'))), true);
    oidc_json(200, oidc_released_user(is_array($at['claims'] ?? null) ? $at['claims'] : []));
    return true;
}

oidc_json(404, ['error' => 'not_found', 'path' => $path]);
return true;
