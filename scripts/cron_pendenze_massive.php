<?php
declare(strict_types=1);

/**
 * Daemon pendenze massive.
 *
 * Loop infinito: processa batch di 50 pendenze PENDING, attende 30s quando
 * la coda è vuota. Si ferma su segnale di stop (file /tmp/cron-stop-pendenze-massive)
 * o se un'altra istanza è già attiva (PID file /tmp/cron-pendenze-massive.pid).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\MassivePendenzeRepository;
use App\Controllers\PendenzeController;
use Dotenv\Dotenv;
use Slim\Views\Twig;

if (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}
if (!getenv('DB_HOST')) {
    putenv('DB_HOST=db');
}

set_time_limit(0);

$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    flush();
};

$pidFile  = '/tmp/cron-pendenze-massive.pid';
$stopFile = '/tmp/cron-stop-pendenze-massive';

// Wait for DB to be ready (max 60s — MariaDB may still be starting)
{
    $dbReady = false;
    for ($i = 0; $i < 60; $i++) {
        try {
            \App\Database\Connection::getPDO()->query('SELECT 1');
            $dbReady = true;
            break;
        } catch (\Throwable $_) {
            sleep(1);
        }
    }
    if (!$dbReady) {
        $log('DB non raggiungibile dopo 60s. Uscita.');
        exit(1);
    }
}

// Single-instance guard via PID file
if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_pendenze_massive.php') !== false) {
            $log("Istanza già attiva (PID $existingPid). Uscita.");
            exit(0);
        }
    }
}
file_put_contents($pidFile, (string)getmypid());

// Clear any leftover stop signal
@unlink($stopFile);

register_shutdown_function(static function () use ($pidFile): void {
    @unlink($pidFile);
});

// Handle SIGTERM gracefully
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(15, static function () use ($pidFile, $log): void { // 15 = SIGTERM
        $log('SIGTERM ricevuto. Uscita.');
        @unlink($pidFile);
        exit(0);
    });
}

$log('Daemon avviato (PID=' . getmypid() . ').');

$repo       = new MassivePendenzeRepository();
$twigMock   = Twig::create(dirname(__DIR__) . '/backoffice/templates');
$controller = new PendenzeController($twigMock, null);

/**
 * Estrae l'idPendenza reale (quello usato nell'URL PUT /pendenze/{idA2A}/{idPendenza}).
 * NON include numeroAvviso/iuv: non sono chiavi primarie nell'endpoint GET/PUT.
 */
$extractIdPendenza = static function (array $row): ?string {
    $idKeys = ['idPendenza', 'id_pendenza', 'idpendenza', 'UUID', 'uuid', 'id'];

    $resp = $row['response_json'] ? json_decode($row['response_json'], true) : null;
    if (is_array($resp)) {
        foreach ($idKeys as $k) {
            if (isset($resp[$k]) && is_string($resp[$k]) && trim($resp[$k]) !== '') {
                return trim($resp[$k]);
            }
            if (isset($resp['pendenza'][$k]) && is_string($resp['pendenza'][$k]) && trim($resp['pendenza'][$k]) !== '') {
                return trim($resp['pendenza'][$k]);
            }
        }
    }
    // Fallback: idPendenza esplicito nel payload originale (CSV)
    $payload = $row['payload_json'] ? json_decode($row['payload_json'], true) : null;
    if (is_array($payload)) {
        foreach (['idPendenza', 'id_pendenza', 'idpendenza'] as $k) {
            if (isset($payload[$k]) && is_string($payload[$k]) && trim($payload[$k]) !== '') {
                return trim($payload[$k]);
            }
        }
    }
    return null;
};

/**
 * Estrae il numeroAvviso/IUV dal response_json (per ricerca per IUV come fallback).
 */
$extractNumeroAvviso = static function (array $row): ?string {
    $resp = $row['response_json'] ? json_decode($row['response_json'], true) : null;
    if (is_array($resp)) {
        foreach (['numeroAvviso', 'numero_avviso', 'iuv', 'iuvPagamento'] as $k) {
            if (isset($resp[$k]) && is_string($resp[$k]) && trim($resp[$k]) !== '') {
                return trim($resp[$k]);
            }
            if (isset($resp['pendenza'][$k]) && is_string($resp['pendenza'][$k]) && trim($resp['pendenza'][$k]) !== '') {
                return trim($resp['pendenza'][$k]);
            }
        }
    }
    return null;
};

$batchSize    = 50;
$sleepSeconds = 30;

while (true) {
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $log('Segnale di stop ricevuto. Uscita pulita.');
        exit(0);
    }

    // 1) Gestione annullamenti pendenti (CANCEL_PENDING)
    $cancels = $repo->fetchCancelPending($batchSize);
    if (count($cancels) > 0) {
        $log('Elaboro batch di ' . count($cancels) . ' annullamenti...');
        foreach ($cancels as $row) {
            $id         = (int)$row['id'];
            $batchId    = $row['file_batch_id'];
            $numeroRiga = (int)$row['riga'];

            $log("  [$batchId:$numeroRiga] ID-$id Annullamento in corso...");

            try {
                $repo->setCancelProcessing($id);

                // --- Passo 1: trova idPendenza direttamente ---
                $idPendenza = $extractIdPendenza($row);

                // --- Passo 2: fallback — cerca per numeroAvviso/IUV ---
                if (!$idPendenza) {
                    $numeroAvviso = $extractNumeroAvviso($row);
                    if ($numeroAvviso) {
                        $log("  [$batchId:$numeroRiga] ID-$id idPendenza non trovato, cerco per IUV: $numeroAvviso");
                        $respData = $row['response_json'] ? json_decode($row['response_json'], true) : [];
                        $idDominio = is_array($respData) ? ($respData['idDominio'] ?? '') : '';
                        $trovata = $controller->fetchPendenzaByNumeroAvviso($numeroAvviso, $idDominio);
                        if ($trovata && isset($trovata['idPendenza'])) {
                            $idPendenza = (string)$trovata['idPendenza'];
                            $log("  [$batchId:$numeroRiga] ID-$id Trovata via IUV: idPendenza=$idPendenza");
                        }
                    }
                }

                $log("  [$batchId:$numeroRiga] ID-$id idPendenza risolto: " . ($idPendenza ?? 'NULL'));

                if ($idPendenza) {
                    $currentPendenza = $controller->fetchPendenzaById($idPendenza);
                    if ($currentPendenza) {
                        $statoGP = $currentPendenza['stato'] ?? '';
                        if ($statoGP === 'NON_ESEGUITA') {
                            $controller->fallbackFullPutUpdate($idPendenza, [], 'Annullamento');
                            $result = $controller->updatePendenzaStatus($idPendenza, 'ANNULLATA');
                            if ($result['success'] ?? false) {
                                $repo->updateRowStatus($id, 'CANCELLED', null, true);
                                $log("  [$batchId:$numeroRiga] ID-$id ANNULLATO OK");
                            } else {
                                $repo->updateRowStatus($id, 'SUCCESS', 'Errore API di Annullamento: ' . ($result['error'] ?? 'Errore sconosciuto'), true);
                                $log("  [$batchId:$numeroRiga] ID-$id ERRORE API ANNULLAMENTO: " . ($result['error'] ?? 'Errore sconosciuto'));
                            }
                        } elseif ($statoGP === 'ANNULLATA') {
                            $repo->updateRowStatus($id, 'CANCELLED', null, true);
                            $log("  [$batchId:$numeroRiga] ID-$id GIA' ANNULLATA SU GOVPAY");
                        } else {
                            $repo->updateRowStatus($id, 'SUCCESS', "Impossibile annullare: lo stato attuale su GovPay è '$statoGP'", true);
                            $log("  [$batchId:$numeroRiga] ID-$id SKIPPED (Stato GovPay: $statoGP)");
                        }
                    } else {
                        $repo->updateRowStatus($id, 'SUCCESS', "Impossibile recuperare lo stato della pendenza da GovPay", true);
                        $log("  [$batchId:$numeroRiga] ID-$id ERRORE (Impossibile recuperare stato)");
                    }
                } else {
                    $repo->updateRowStatus($id, 'SUCCESS', "ID Pendenza non trovato (né direttamente né per IUV)", true);
                    $log("  [$batchId:$numeroRiga] ID-$id ERRORE (ID Pendenza non trovato)");
                }
            } catch (\Throwable $e) {
                $repo->updateRowStatus($id, 'SUCCESS', 'Errore di Annullamento: ' . $e->getMessage(), true);
                $log("  [$batchId:$numeroRiga] ID-$id ECCEZIONE: " . $e->getMessage());
            }
        }
        // Continua il loop per verificare altri elementi senza attendere $sleepSeconds
        continue;
    }

    // 2) Gestione nuovi inserimenti (PENDING)
    $pending = $repo->fetchPending($batchSize);

    if (count($pending) === 0) {
        $log("Nessuna pendenza PENDING o CANCEL_PENDING. Attendo {$sleepSeconds}s...");
        sleep($sleepSeconds);
        continue;
    }

    $log('Elaboro batch di ' . count($pending) . ' pendenze...');

    foreach ($pending as $row) {
        $id         = (int)$row['id'];
        $batchId    = $row['file_batch_id'];
        $numeroRiga = (int)$row['riga'];
        $payload    = $row['payload_json'] ? json_decode($row['payload_json'], true) : null;

        if (!is_array($payload)) {
            $log("  [$batchId:$numeroRiga] ID-$id ERRORE PAYLOAD SCORRETTO.");
            $repo->setResult($id, false, null, 'Payload JSON non valido o mancante');
            continue;
        }

        $repo->setProcessing($id);

        $accErrors        = [];
        $accWarnings      = [];
        $accountingParams = [
            'idDominio'      => $payload['idDominio'] ?? '',
            'idTipoPendenza' => $payload['idTipoPendenza'] ?? '',
        ];
        $payload['voci'] = $controller->buildVociWithAccounting(
            $payload['voci'] ?? [],
            $accountingParams,
            null,
            $accErrors,
            $accWarnings
        );

        if (!empty($accErrors)) {
            $errorMsg = 'Errore contabilità: ' . implode('; ', $accErrors);
            $log("  [$batchId:$numeroRiga] ID-$id ERRORE ($errorMsg)");
            $repo->setResult($id, false, null, $errorMsg);
            continue;
        }

        $dDa = isset($payload['datiAllegati']) && is_string($payload['datiAllegati'])
            ? json_decode($payload['datiAllegati'], true)
            : ($payload['datiAllegati'] ?? []);
        if (!is_array($dDa)) $dDa = [];
        $dDa['sorgente']         = 'GIL-Massivo';
        $payload['datiAllegati'] = $dDa;

        $res = $controller->sendPendenzaToBackoffice($payload);

        if ($res['success'] === true) {
            $log("  [$batchId:$numeroRiga] ID-$id OK (Creato: " . ($res['idPendenza'] ?? 'sconosciuto') . ')');
            $respData = is_array($res['response'] ?? null) ? $res['response'] : [];
            if (!isset($respData['idPendenza']) && isset($res['idPendenza'])) {
                $respData['idPendenza'] = $res['idPendenza'];
            }
            $repo->setResult($id, true, $respData, null);

            // Invia notifiche email ed App IO
            $newId = $res['idPendenza'] ?? null;
            if ($newId) {
                try {
                    $responsePayload = is_array($res['response'] ?? null) ? $res['response'] : [];
                    [$iuvFromResponse, $numeroAvvisoFromResponse] = $controller->extractIuvAndNumeroAvviso($responsePayload);

                    $notifResult = $controller->sendCreationNotifications(
                        (string)$newId,
                        (string)($payload['soggettoPagatore']['email'] ?? ''),
                        (string)($payload['soggettoPagatore']['anagrafica'] ?? ''),
                        (string)($payload['soggettoPagatore']['identificativo'] ?? ''),
                        (string)($payload['soggettoPagatore']['tipo'] ?? 'F'),
                        [
                            'causale'         => $payload['causale'] ?? '',
                            'importo'         => $payload['importo'] ?? 0.0,
                            'iuv'             => $iuvFromResponse,
                            'numeroAvviso'    => $numeroAvvisoFromResponse,
                            'dataScadenza'    => $payload['dataScadenza'] ?? null,
                            'idTipoPendenza'  => $payload['idTipoPendenza'] ?? '',
                        ],
                        null
                    );
                    $log("    Notifiche - Email: " . ($notifResult['email'] ?? 'skipped') . ", App IO: " . ($notifResult['app_io'] ?? 'skipped'));
                } catch (\Throwable $e) {
                    $log("    Errore invio notifiche: " . $e->getMessage());
                }
            }
        } else {
            $errorMsg = is_array($res['errors']) ? implode('; ', $res['errors']) : 'Errore sconosciuto';
            $log("  [$batchId:$numeroRiga] ID-$id ERRORE ($errorMsg)");
            $repo->setResult($id, false, $res['response'] ?? null, $errorMsg);
        }
    }
}
