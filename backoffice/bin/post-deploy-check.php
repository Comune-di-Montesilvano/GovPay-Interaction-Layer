<?php
declare(strict_types=1);

/**
 * Servizio compose `post-deploy-check` (one-shot, immagine backoffice): verifica lo stack appena
 * avviato sulla rete interna (App\Services\Deploy\DeployCheck). Exit 0 = tutto ok, 1 = almeno un
 * controllo fallito (visibile in Portainer; con SENTRY_DSN anche in GlitchTip). Non ferma nulla.
 * Righe a log: una per controllo, mai token, URL completi o corpi di risposta.
 */

use App\Monitoring\SentryReporter;
use App\Services\Deploy\CheckResult;
use App\Services\Deploy\DeployCheck;

require __DIR__ . '/../vendor/autoload.php';

SentryReporter::init('post-deploy-check');

$token = (string)(getenv('MASTER_TOKEN') ?: '');
if ($token === '') {
    fwrite(STDERR, "post-deploy-check: FALLITO (MASTER_TOKEN non impostato nel servizio)\n");
    exit(1);
}

$ssl = (string)(getenv('SSL') ?: 'off');
$backofficeUrl = rtrim((string)(getenv('BACKOFFICE_INTERNAL_URL') ?: DeployCheck::internalUrl('backoffice', $ssl)), '/');
$frontofficeUrl = rtrim((string)(getenv('FRONTOFFICE_INTERNAL_URL') ?: DeployCheck::internalUrl('frontoffice', $ssl)), '/');

/** @return array{status:int, body:string} */
$httpGet = static function (string $url, string $bearer): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearer, 'Accept: application/json'],
        // Solo rete interna dello stack: con SSL=on il certificato puo' essere self-signed
        // (come nel healthcheck `curl -k` del backoffice).
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $status = $body === false ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);
    return ['status' => $status, 'body' => $body === false ? '' : (string)$body];
};

// Il frontoffice non ha healthcheck: il servizio parte quando e' avviato, qui si attende che
// risponda (Apache in avvio) fino a POST_DEPLOY_WAIT secondi.
$wait = max(0, (int)(getenv('POST_DEPLOY_WAIT') ?: 120));
for ($t = 0; $t < $wait && $httpGet($frontofficeUrl . '/health/deploy', $token)['status'] === 0; $t += 3) {
    sleep(3);
}

$check = new DeployCheck($backofficeUrl, $frontofficeUrl, $token, (string)(getenv('APP_VERSION') ?: 'latest'), $httpGet);
$results = $check->run();

foreach ($results as $r) {
    echo ($r->ok ? 'OK     ' : 'ERRORE ') . $r->name . ': ' . $r->detail . "\n";
}

$failed = array_values(array_map(static fn(CheckResult $r): string => $r->name, array_filter($results, static fn(CheckResult $r): bool => !$r->ok)));
if ($failed === []) {
    echo 'post-deploy-check: OK (' . count($results) . " controlli)\n";
    exit(0);
}

echo 'post-deploy-check: FALLITO (' . count($failed) . " errori)\n";
\Sentry\captureMessage('post-deploy-check fallito: ' . implode(', ', $failed), \Sentry\Severity::error());
\Sentry\SentrySdk::getCurrentHub()->getClient()?->flush(5);
exit(1);
