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
    $claims = FAKE_OIDC_USER + ['iss' => $issuer, 'aud' => $clientId, 'iat' => $now, 'exp' => $now + 300, 'jti' => bin2hex(random_bytes(8))];
    $idToken = oidc_b64url(json_encode(['alg' => 'none', 'typ' => 'JWT'])) . '.' . oidc_b64url(json_encode($claims)) . '.';
    oidc_json(200, [
        'access_token' => 'fake-at-' . oidc_b64url(json_encode(['sub' => FAKE_OIDC_USER['sub']])),
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
    oidc_json(200, FAKE_OIDC_USER);
    return true;
}

oidc_json(404, ['error' => 'not_found', 'path' => $path]);
return true;
