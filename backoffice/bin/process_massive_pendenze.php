<?php
/**
 * CLI processor for massive pendenze staging table.
 * Scans for PENDING rows, sends them to Backoffice (reuse PendenzeController logic),
 * updates stato to PROCESSING then SUCCESS/ERROR with response payload.
 *
 * Usage (inside container): php bin/process_massive_pendenze.php [--limit=100] [--once]
 * If --once is provided it will process at most <limit> rows and exit.
 * Without --once it will loop until no rows remain (with small sleep).
 */
declare(strict_types=1);

use App\Config\Config;
use App\Config\SettingsRepository;
use App\Database\MassivePendenzeRepository;
use App\Logger;
use GuzzleHttp\Client;

require __DIR__ . '/../vendor/autoload.php';

$limit = 50;
$loop = true;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) { $limit = (int)substr($arg, 8); }
    if ($arg === '--once') { $loop = false; }
}
$limit = max(1, $limit);

$repo = new MassivePendenzeRepository();
$processedTotal = 0;

/**
 * Minimal standalone sender (derived from PendenzeController::sendPendenzaToBackoffice)
 */
function sendOne(array $payload): array {
    $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
    $idA2A = SettingsRepository::get('entity', 'id_a2a', '');
    $errors = [];
    if (empty($backofficeUrl)) { return ['success' => false, 'errors' => ['URL GovPay Backoffice non configurato']]; }
    if ($idA2A === '') { return ['success' => false, 'errors' => ['ID A2A non configurato']]; }

    $guzzleOptions = [];
    $authMethod = Config::get('AUTHENTICATION_GOVPAY');
    if (!empty($authMethod) && in_array(strtolower((string)$authMethod), ['ssl', 'sslheader'], true)) {
        $cert = Config::get('GOVPAY_TLS_CERT');
        $key = Config::get('GOVPAY_TLS_KEY');
        $keyPass = Config::get('GOVPAY_TLS_KEY_PASSWORD') ?: null;
        if (!empty($cert) && !empty($key)) {
            $guzzleOptions['cert'] = $cert;
            $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
        } else {
            return ['success' => false, 'errors' => ['mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non configurati']];
        }
    }

    try {
        $httpClient = new Client($guzzleOptions);
        $idP = trim((string)($payload['idPendenza'] ?? ''));
        if ($idP === '') {
            try { $rand = bin2hex(random_bytes(8)); } catch (\Throwable $_) { $rand = preg_replace('/[^A-Za-z0-9]/', '', uniqid()); }
            $idPCand = 'GIL-' . substr($rand, 0, 16);
            $idP = preg_replace('/[^A-Za-z0-9\-_]/', '-', substr($idPCand, 0, 35));
        }

        if (array_key_exists('idPendenza', $payload)) { unset($payload['idPendenza']); }
        if (isset($payload['proprieta']) && is_array($payload['proprieta'])) {
            $allowedPropKeys = ['descrizioneImporto','lineaTestoRicevuta1','lineaTestoRicevuta2','linguaSecondaria','linguaSecondariaCausale'];
            $payload['proprieta'] = array_intersect_key($payload['proprieta'], array_flip($allowedPropKeys));
            if (empty($payload['proprieta'])) { unset($payload['proprieta']); }
        }
        foreach (['cartellaPagamento','direzione','divisione'] as $sf) {
            if (isset($payload[$sf]) && (!is_scalar($payload[$sf]) || trim((string)$payload[$sf]) === '')) { unset($payload[$sf]); }
        }

        $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idP);
        $username = SettingsRepository::get('govpay', 'user', '');
        $password = SettingsRepository::get('govpay', 'password', '');
        $requestOptions = [ 'headers' => ['Accept' => 'application/json'], 'json' => $payload ];
        if ($username !== '' && $password !== '') {
            $requestOptions['auth'] = [$username, $password];
        }

        $resp = $httpClient->request('PUT', $url, $requestOptions);
        $code = $resp->getStatusCode();
        $body = (string)$resp->getBody();
        $data = json_decode($body, true);
        return ['success' => $code >= 200 && $code < 300, 'idPendenza' => $idP, 'errors' => [], 'response' => $data];
    } catch (\GuzzleHttp\Exception\ClientException $ce) {
        $detail = '';
        $resp = $ce->getResponse();
        if ($resp) {
            try { $detail = (string)$resp->getBody(); } catch (\Throwable $_) { $detail = ''; }
        }
        $parsed = null; if ($detail !== '') { $tmp = json_decode($detail, true); $parsed = (json_last_error() === JSON_ERROR_NONE) ? $tmp : $detail; }
        return ['success' => false, 'errors' => [$detail ?: $ce->getMessage()], 'response' => $parsed];
    } catch (\Throwable $e) {
        return ['success' => false, 'errors' => [$e->getMessage()]];
    }
}

Logger::getInstance()->info('Massive pendenze processor avviato', ['limit' => $limit, 'loop' => $loop]);

while (true) {
    $rows = $repo->fetchPending($limit);
    if (!$rows) {
        Logger::getInstance()->info('Nessuna pendenza PENDING trovata');
        if ($loop) { sleep(5); continue; } else { break; }
    }
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $payloadJson = $r['payload_json'] ?? null;
        if (!$payloadJson) { $repo->setResult($id, false, null, 'payload_json mancante'); continue; }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) { $repo->setResult($id, false, null, 'payload_json non valido'); continue; }
        try {
            $repo->setProcessing($id);
            $res = sendOne($payload);
            if ($res['success'] ?? false) {
                $repo->setResult($id, true, $res['response'] ?? null, null);
            } else {
                $repo->setResult($id, false, $res['response'] ?? null, implode('; ', $res['errors'] ?? []));
            }
        } catch (Throwable $e) {
            $repo->setResult($id, false, null, $e->getMessage());
        }
        $processedTotal++;
    }
    if (!$loop) { break; }
}

Logger::getInstance()->info('Processor terminato', ['processed' => $processedTotal]);

echo "Processo concluso. Pendenze elaborate: {$processedTotal}\n";
