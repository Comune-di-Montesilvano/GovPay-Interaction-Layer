<?php
declare(strict_types=1);

/**
 * GovPay / pagoPA finto per test di integrazione ed e2e (router di `php -S`).
 *
 * Un solo server per tutte le basi URL dei settings, distinte dal primo segmento del path:
 *   /backoffice (govpay.backoffice_url), /pendenze (govpay.pendenze_url),
 *   /pagamenti (govpay.pagamenti_url), /checkout (pagopa.checkout_ec_base_url).
 * Endpoint: GET /info; con stato: PUT/GET pendenza, lista per debitore, byAvviso, avviso PDF, RT PDF (solo
 * dopo /__fake/paid), POST /carts (302 verso la pagina /__checkout/<id> del finto, mai pagoPA).
 * Controllo: /__fake/health, reset, requests, unmatched, paid, fail-next.
 * Route non gestite: con PRISM_URL inoltro a Prism, altrimenti 404; sempre registrate in
 * `unmatched` (i test falliscono se non e' vuoto: un endpoint nuovo va aggiunto qui).
 * Solo dati sintetici: nessun valore reale.
 */

require __DIR__ . '/FakeState.php';

$state = FakeState::fromEnv();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = (string)parse_url($uri, PHP_URL_PATH);
parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
$rawBody = (string)file_get_contents('php://input');
$body = json_decode($rawBody, true);

/** @param array<string,mixed>|list<mixed> $data */
function fake_json(int $status, array $data, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $k => $v) {
        header($k . ': ' . $v);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function fake_pdf(string $name): void
{
    header('Content-Type: application/pdf');
    readfile(__DIR__ . '/fixtures/' . $name);
}

function fake_base_url(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? ('127.0.0.1:' . ($_SERVER['SERVER_PORT'] ?? '80'));
    return 'http://' . $host;
}

// ── Controllo ────────────────────────────────────────────────────────────────────────────
if (str_starts_with($path, '/__fake/')) {
    $cmd = substr($path, strlen('/__fake/'));
    switch ("$method $cmd") {
        case 'GET health':
            fake_json(200, ['ok' => true]);
            return true;
        case 'POST reset':
            $state->reset();
            fake_json(200, ['ok' => true]);
            return true;
        case 'GET requests':
            fake_json(200, $state->read()['requests']);
            return true;
        case 'GET unmatched':
            fake_json(200, $state->read()['unmatched']);
            return true;
        case 'GET pendenze':
            fake_json(200, array_values($state->read()['pendenze']));
            return true;
        case 'POST paid':
            $key = ($body['idDominio'] ?? '') . '/' . ($body['iuv'] ?? '');
            $state->update(static function (array $s) use ($key, $body): array {
                $s['paid'][$key] = true;
                foreach ($s['pendenze'] as $k => $p) {
                    if (($p['idDominio'] ?? '') === ($body['idDominio'] ?? '') && ($p['iuvAvviso'] ?? '') === ($body['iuv'] ?? '')) {
                        $s['pendenze'][$k]['stato'] = 'ESEGUITA';
                        // Come GovPay dopo l'RT: ogni voce porta la riscossione con il path rpp
                        // (letto da PendenzeController::buildReceiptPathLookup per la ricevuta).
                        $rpp = '/rpp/' . rawurlencode($p['idDominio']) . '/' . rawurlencode($p['iuvAvviso']) . '/CCP-FAKE';
                        foreach (($p['voci'] ?? []) as $i => $voce) {
                            $s['pendenze'][$k]['voci'][$i]['riscossioni'] = [[
                                'iur' => 'IUR-FAKE-' . $p['iuvAvviso'] . '-' . $i,
                                'rpp' => $rpp,
                                'importo' => $voce['importo'] ?? null,
                                'stato' => 'RISCOSSA',
                                'tipo' => 'ENTRATA',
                            ]];
                        }
                    }
                }
                return [$s, null];
            });
            fake_json(200, ['ok' => true]);
            return true;
        case 'POST fail-next':
            $state->update(static function (array $s) use ($body): array {
                $s['failNext'][] = [
                    'method' => (string)($body['method'] ?? ''),
                    'pathRegex' => (string)($body['pathRegex'] ?? ''),
                    'status' => (int)($body['status'] ?? 500),
                    'body' => is_array($body['body'] ?? null) ? $body['body'] : [],
                ];
                return [$s, null];
            });
            fake_json(200, ['ok' => true]);
            return true;
    }
    fake_json(404, ['fake' => 'controllo sconosciuto']);
    return true;
}

// ── Pagina di checkout finta (destinazione del 302 di /carts) ─────────────────────────────
if ($method === 'GET' && preg_match('#^/__checkout/([a-z0-9]+)$#', $path, $m)) {
    $cart = $state->read()['carts'][$m[1]] ?? null;
    if ($cart === null) {
        http_response_code(404);
        echo 'carrello sconosciuto';
        return true;
    }
    $urls = is_array($cart['returnUrls'] ?? null) ? $cart['returnUrls'] : [];
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><title>Checkout finto</title><h1>Checkout finto</h1>\n";
    foreach (['returnOkUrl' => 'Esito OK', 'returnCancelUrl' => 'Annulla', 'returnErrorUrl' => 'Errore'] as $k => $label) {
        if (!empty($urls[$k])) {
            echo '<p><a href="' . htmlspecialchars((string)$urls[$k], ENT_QUOTES) . '">' . $label . "</a></p>\n";
        }
    }
    return true;
}

// ── API finte ────────────────────────────────────────────────────────────────────────────
if (!preg_match('#^/(backoffice|pendenze|pagamenti|checkout)(/.*)?$#', $path, $pm)) {
    $rest = $path;
} else {
    $rest = $pm[2] ?? '/';
}

$state->update(static function (array $s) use ($method, $path, $body, $rawBody): array {
    $s['requests'][] = ['method' => $method, 'path' => $path, 'body' => $body ?? ($rawBody === '' ? null : $rawBody)];
    return [$s, null];
});

// Errore programmato (una volta) da /__fake/fail-next.
$fail = $state->update(static function (array $s) use ($method, $path): array {
    foreach ($s['failNext'] as $i => $f) {
        if ($f['method'] === $method && @preg_match($f['pathRegex'], $path) === 1) {
            array_splice($s['failNext'], $i, 1);
            return [$s, $f];
        }
    }
    return [$s, null];
});
if (is_array($fail)) {
    fake_json($fail['status'], $fail['body']);
    return true;
}

$seg = '[^/]+';

// GET /info: stato/versione (controllo "GovPay raggiungibile" della dashboard).
if ($method === 'GET' && $rest === '/info') {
    fake_json(200, ['appName' => 'GovPay (finto)', 'versione' => '0.0.0-fake', 'build' => 'e2e']);
    return true;
}

// PUT /pendenze/{idA2A}/{idPendenza}: crea/aggiorna, assegna numero avviso sintetico.
if ($method === 'PUT' && preg_match("#^/pendenze/($seg)/($seg)$#", $rest, $m)) {
    $idA2A = rawurldecode($m[1]);
    $idPendenza = rawurldecode($m[2]);
    $created = $state->update(static function (array $s) use ($idA2A, $idPendenza, $body): array {
        $key = "$idA2A/$idPendenza";
        $existing = $s['pendenze'][$key] ?? null;
        if ($existing === null) {
            $s['seq']++;
            $iuv = str_pad((string)$s['seq'], 17, '0', STR_PAD_LEFT);
        } else {
            $iuv = $existing['iuvAvviso'];
        }
        $p = (is_array($body) ? $body : []) + [];
        $p['idA2A'] = $idA2A;
        $p['idPendenza'] = $idPendenza;
        $p['idDominio'] = (string)($p['idDominio'] ?? '00000000000');
        $p['iuvAvviso'] = $iuv;
        $p['numeroAvviso'] = '3' . $iuv;
        $p['stato'] = $existing['stato'] ?? 'NON_ESEGUITA';
        $s['pendenze'][$key] = $p;
        return [$s, ['idDominio' => $p['idDominio'], 'numeroAvviso' => $p['numeroAvviso'], 'UUID' => 'fake-' . $iuv]];
    });
    fake_json(201, $created);
    return true;
}

// GET /pendenze/byAvviso/{idDominio}/{numeroAvviso}
if ($method === 'GET' && preg_match("#^/pendenze/byAvviso/($seg)/($seg)$#", $rest, $m)) {
    foreach ($state->read()['pendenze'] as $p) {
        if ($p['idDominio'] === rawurldecode($m[1]) && $p['numeroAvviso'] === rawurldecode($m[2])) {
            fake_json(200, $p);
            return true;
        }
    }
    fake_json(404, ['categoria' => 'OPERAZIONE', 'codice' => 'NOT_FOUND', 'descrizione' => 'Pendenza inesistente']);
    return true;
}

// GET /pendenze/{idA2A}/{idPendenza}
if ($method === 'GET' && preg_match("#^/pendenze/($seg)/($seg)$#", $rest, $m)) {
    $p = $state->read()['pendenze'][rawurldecode($m[1]) . '/' . rawurldecode($m[2])] ?? null;
    if ($p === null) {
        fake_json(404, ['categoria' => 'OPERAZIONE', 'codice' => 'NOT_FOUND', 'descrizione' => 'Pendenza inesistente']);
    } else {
        fake_json(200, $p);
    }
    return true;
}

// GET /pendenze (lista, filtro idDebitore)
if ($method === 'GET' && $rest === '/pendenze') {
    $deb = isset($query['idDebitore']) ? (string)$query['idDebitore'] : null;
    $list = array_values(array_filter(
        $state->read()['pendenze'],
        static fn(array $p): bool => $deb === null || ($p['soggettoPagatore']['identificativo'] ?? null) === $deb
    ));
    fake_json(200, [
        'numRisultati' => count($list),
        'numPagine' => 1,
        'risultatiPerPagina' => max(25, count($list)),
        'pagina' => 1,
        'risultati' => $list,
    ]);
    return true;
}

// GET /avvisi/{idDominio}/{numeroAvviso} (PDF)
if ($method === 'GET' && preg_match("#^/avvisi/($seg)/($seg)$#", $rest)) {
    fake_pdf('avviso.pdf');
    return true;
}

// GET /rpp/{idDominio}/{iuv}/{ccp}/rt (PDF, solo pagate)
if ($method === 'GET' && preg_match("#^/rpp/($seg)/($seg)/($seg)/rt$#", $rest, $m)) {
    $paid = $state->read()['paid'][rawurldecode($m[1]) . '/' . rawurldecode($m[2])] ?? false;
    if ($paid) {
        fake_pdf('ricevuta.pdf');
    } else {
        fake_json(404, ['categoria' => 'OPERAZIONE', 'codice' => 'NOT_FOUND', 'descrizione' => 'Ricevuta inesistente']);
    }
    return true;
}

// POST /carts (pagoPA CheckoutEC): 302 verso la pagina di checkout del finto.
if ($method === 'POST' && $rest === '/carts') {
    $id = $state->update(static function (array $s) use ($body): array {
        $id = substr(sha1((string)microtime(true) . count($s['carts'])), 0, 12);
        $s['carts'][$id] = is_array($body) ? $body : [];
        return [$s, $id];
    });
    http_response_code(302);
    header('Location: ' . fake_base_url() . '/__checkout/' . $id);
    return true;
}

// ── Non gestita: Prism se configurato, altrimenti 404 ─────────────────────────────────────
$state->update(static function (array $s) use ($method, $path): array {
    $s['unmatched'][] = "$method $path";
    return [$s, null];
});

$prism = getenv('PRISM_URL');
if (is_string($prism) && $prism !== '') {
    $ch = curl_init(rtrim($prism, '/') . $rest . (empty($query) ? '' : '?' . http_build_query($query)));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? 'application/json')],
        CURLOPT_POSTFIELDS => $rawBody === '' ? null : $rawBody,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 10,
    ]);
    $out = curl_exec($ch);
    http_response_code((int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502);
    header('Content-Type: ' . (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json'));
    unset($ch);
    echo is_string($out) ? $out : '';
    return true;
}

fake_json(404, ['fake' => 'unmatched', 'route' => "$method $path"]);
return true;
