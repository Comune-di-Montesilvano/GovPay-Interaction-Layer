<?php
/**
 * Ponte legacy rendicontazione GovPay — riceve richieste da GIL e le smista
 * verso il connector Geri (webservice interno) o l'update diretto su
 * definizione_agevolata.rate. Da copiare A MANO su servizi.comune.montesilvano.pe.it,
 * non fa parte della pipeline Docker/CI di GIL.
 *
 * Contratto:
 *   POST body JSON: {handler: 'GERI'|'DILAZIONE', iuv, id_atto, data_pagamento, importo, rata?}
 *   Header: Authorization: Bearer <BRIDGE_TOKEN>
 *   Risposta: {"esito": bool, "messaggio": string}
 */

header('Content-Type: application/json');

const BRIDGE_TOKEN = 'CAMBIARE_CON_IL_TOKEN_CONFIGURATO_IN_GIL';

function rispondi(bool $esito, string $messaggio): void
{
    echo json_encode(['esito' => $esito, 'messaggio' => $messaggio]);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!hash_equals('Bearer ' . BRIDGE_TOKEN, $authHeader)) {
    http_response_code(401);
    rispondi(false, 'Token non valido');
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    rispondi(false, 'Body JSON non valido');
}

$handler       = (string)($body['handler'] ?? '');
$iuv           = (string)($body['iuv'] ?? '');
$idAtto        = (string)($body['id_atto'] ?? '');
$dataPagamento = (string)($body['data_pagamento'] ?? '');
$importo       = (float)($body['importo'] ?? 0);
$rata          = (string)($body['rata'] ?? '');

if ($idAtto === '' || $dataPagamento === '') {
    rispondi(false, 'id_atto o data_pagamento mancanti');
}

if ($handler === 'GERI') {
    require_once '/var/www/servizi.comune.montesilvano.pe.it/lib/backoffice/connector.php';
    try {
        $arc = new BackOffice();
        // Nota: il connector legacy non ha un contratto di ritorno affidabile
        // (vedi geri.php storico) — si considera riuscita l'assenza di eccezioni.
        $arc->registra_versamento_geri($idAtto, $dataPagamento, number_format($importo, 2, '.', ''), $iuv);
        rispondi(true, 'Registrato su Geri (best-effort, nessuna verifica esito disponibile)');
    } catch (\Throwable $e) {
        rispondi(false, 'Eccezione chiamata Geri: ' . $e->getMessage());
    }
}

if ($handler === 'DILAZIONE') {
    require_once '/var/www/servizi.comune.montesilvano.pe.it/config/config.php';
    require_once '/var/www/servizi.comune.montesilvano.pe.it/lib/system/database.php';
    require_once '/var/www/JOBS/scripts/params/params.php';

    try {
        connect_db($PARAMS['DATABASE']['SERVER'], $PARAMS['DATABASE']['USERNAME'], $PARAMS['DATABASE']['PASSWORD'], 'pagopa', true);
        $rataRow = exec_sql("SELECT * FROM definizione_agevolata.rate WHERE iuv='" . addslashes($iuv) . "'", true);
        if (!isset($rataRow['id_rata'])) {
            rispondi(false, 'Rata non trovata per IUV ' . $iuv);
        }
        $righeAggiornate = exec_sql(
            "UPDATE definizione_agevolata.rate SET pagato=1, data_incasso='" . addslashes($dataPagamento) . "'
             WHERE id_rata='" . (int)$rataRow['id_rata'] . "' AND iuv='" . addslashes($iuv) . "'"
        );
        if ((int)$righeAggiornate > 0) {
            rispondi(true, 'Rata ' . $rataRow['id_rata'] . ' marcata pagata');
        }
        rispondi(false, 'Nessuna riga aggiornata per id_rata ' . $rataRow['id_rata']);
    } catch (\Throwable $e) {
        rispondi(false, 'Eccezione update Dilazione: ' . $e->getMessage());
    }
}

http_response_code(400);
rispondi(false, 'Handler sconosciuto: ' . $handler);
