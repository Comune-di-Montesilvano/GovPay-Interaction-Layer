<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Controllers\PendenzeController;
use App\Database\MassivePendenzeRepository;

/**
 * Elaborazione della coda pendenze massive (corpo del loop di cron_pendenze_massive.php).
 *
 * - claimBatch(): selezione di oggi. Prima le righe CANCEL_PENDING (id ASC, max $limit); solo se
 *   non ce ne sono, le righe PENDING (id ASC, max $limit). NON marca nulla: oggi la marcatura
 *   "in corso" avviene riga per riga dentro l'elaborazione (setCancelProcessing per gli
 *   annullamenti, setProcessing per le creazioni dopo la validazione del payload), quindi una
 *   riga selezionata e non ancora elaborata resta CANCEL_PENDING/PENDING. Sicuro solo con un
 *   unico consumatore (oggi garantito dal PID file).
 * - processRow(): rilegge la riga e la elabora secondo il suo stato attuale: CANCEL_PENDING ->
 *   annullamento su GovPay, PENDING -> creazione su GovPay; ogni altro stato (o id inesistente)
 *   e' un no-op (skipped). Diversamente dallo script, che lavorava sullo snapshot del batch, una
 *   riga cambiata di stato tra selezione ed elaborazione (es. batch messo in PAUSED) non viene
 *   piu' toccata.
 * - runOnce(): claimBatch + processRow per ogni id. Come oggi lo stop non viene verificato tra
 *   una riga e l'altra (lo script lo controlla solo tra un ciclo e l'altro). hadWork = c'erano
 *   righe in coda: se false lo script attende 15 minuti.
 *
 * Le chiamate GovPay passano dal PendenzeController (stessi metodi usati dallo script). Le
 * eccezioni del ramo creazione NON sono catturate (come oggi: arrivano al gestore globale dello
 * script); quelle del ramo annullamento sono catturate per riga.
 *
 * errorDetails: ref = id della riga; reason = testo fisso / status HTTP, mai corpi upstream.
 */
final class MassivePendenzeProcessor
{
    public const BATCH_SIZE = 50;

    public const IDLE_SECONDS = 900;

    public function __construct(
        private readonly PendenzeController $controller,
        private readonly MassivePendenzeRepository $repo
    ) {}

    /** @return list<int> */
    public function claimBatch(int $limit = self::BATCH_SIZE): array
    {
        return $this->selectBatch($limit)[1];
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        [$kind, $ids] = $this->selectBatch(self::BATCH_SIZE);

        if ($ids === []) {
            $ctx->debug('Nessuna pendenza PENDING o CANCEL_PENDING. Attendo ' . self::IDLE_SECONDS . 's...');
            return new JobResult(0, 0, 0, 0, false);
        }

        $ctx->debug('Elaboro batch di ' . count($ids) . ($kind === 'cancel' ? ' annullamenti...' : ' pendenze...'));

        $processed = 0;
        $ok = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];
        foreach ($ids as $id) {
            $r = $this->processRow($id, $ctx);
            $processed += $r->processed;
            $ok += $r->ok;
            $skipped += $r->skipped;
            $errors += $r->errors;
            array_push($details, ...$r->errorDetails);
        }

        return new JobResult($processed, $ok, $skipped, $errors, true, $details);
    }

    public function processRow(int $rowId, ?JobContext $ctx = null): JobResult
    {
        $log = static function (string $msg) use ($ctx): void {
            $ctx?->debug($msg);
        };

        $row = $this->repo->findById($rowId);
        $stato = is_array($row) ? (string)$row['stato'] : null;
        if ($row === null || ($stato !== 'PENDING' && $stato !== 'CANCEL_PENDING')) {
            $log("  ID-$rowId non elaborabile (stato: " . ($stato ?? 'inesistente') . '), salto.');
            return new JobResult(0, 0, 1, 0, false);
        }

        return $stato === 'CANCEL_PENDING'
            ? $this->processCancel($row, $log)
            : $this->processCreate($row, $log);
    }

    /**
     * @return array{0:'cancel'|'create',1:list<int>}
     */
    private function selectBatch(int $limit): array
    {
        $cancels = $this->repo->fetchCancelPending($limit);
        if (count($cancels) > 0) {
            return ['cancel', array_values(array_map(static fn(array $r): int => (int)$r['id'], $cancels))];
        }
        $pending = $this->repo->fetchPending($limit);
        return ['create', array_values(array_map(static fn(array $r): int => (int)$r['id'], $pending))];
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(string):void $log
     */
    private function processCancel(array $row, callable $log): JobResult
    {
        $id         = (int)$row['id'];
        $batchId    = $row['file_batch_id'];
        $numeroRiga = (int)$row['riga'];
        $ref        = (string)$id;

        $log("  [$batchId:$numeroRiga] ID-$id Annullamento in corso...");

        try {
            $this->repo->setCancelProcessing($id);

            // --- Passo 1: trova idPendenza direttamente ---
            $idPendenza = self::extractIdPendenza($row);

            // --- Passo 2: fallback — cerca per numeroAvviso/IUV ---
            if (!$idPendenza) {
                $numeroAvviso = self::extractNumeroAvviso($row);
                if ($numeroAvviso) {
                    $log("  [$batchId:$numeroRiga] ID-$id idPendenza non trovato, cerco per IUV: $numeroAvviso");
                    $respData = $row['response_json'] ? json_decode($row['response_json'], true) : [];
                    $idDominio = is_array($respData) ? ($respData['idDominio'] ?? '') : '';
                    $trovata = $this->controller->fetchPendenzaByNumeroAvviso($numeroAvviso, $idDominio);
                    if ($trovata && isset($trovata['idPendenza'])) {
                        $idPendenza = (string)$trovata['idPendenza'];
                        $log("  [$batchId:$numeroRiga] ID-$id Trovata via IUV: idPendenza=$idPendenza");
                    }
                }
            }

            $log("  [$batchId:$numeroRiga] ID-$id idPendenza risolto: " . ($idPendenza ?? 'NULL'));

            if ($idPendenza) {
                $currentPendenza = $this->controller->fetchPendenzaById($idPendenza);
                if ($currentPendenza) {
                    $statoGP = $currentPendenza['stato'] ?? '';
                    if ($statoGP === 'NON_ESEGUITA') {
                        $this->controller->fallbackFullPutUpdate($idPendenza, [], 'Annullamento');
                        $result = $this->controller->updatePendenzaStatus($idPendenza, 'ANNULLATA');
                        if ($result['success'] ?? false) {
                            $this->repo->updateRowStatus($id, 'CANCELLED', null, true);
                            $log("  [$batchId:$numeroRiga] ID-$id ANNULLATO OK");
                            return self::ok();
                        }
                        $this->repo->updateRowStatus($id, 'SUCCESS', 'Errore API di Annullamento: ' . ($result['error'] ?? 'Errore sconosciuto'), true);
                        $log("  [$batchId:$numeroRiga] ID-$id ERRORE API ANNULLAMENTO: " . ($result['error'] ?? 'Errore sconosciuto'));
                        $reason = preg_match('/HTTP (\d{3})/', (string)($result['error'] ?? ''), $m) === 1
                            ? 'Annullamento rifiutato: HTTP ' . $m[1]
                            : 'Annullamento rifiutato';
                        return self::error($ref, $reason);
                    } elseif ($statoGP === 'ANNULLATA') {
                        $this->repo->updateRowStatus($id, 'CANCELLED', null, true);
                        $log("  [$batchId:$numeroRiga] ID-$id GIA' ANNULLATA SU GOVPAY");
                        return self::ok();
                    }
                    $this->repo->updateRowStatus($id, 'SUCCESS', "Impossibile annullare: lo stato attuale su GovPay è '$statoGP'", true);
                    $log("  [$batchId:$numeroRiga] ID-$id SKIPPED (Stato GovPay: $statoGP)");
                    return self::error($ref, 'Stato GovPay non annullabile: ' . $statoGP);
                }
                $this->repo->updateRowStatus($id, 'SUCCESS', "Impossibile recuperare lo stato della pendenza da GovPay", true);
                $log("  [$batchId:$numeroRiga] ID-$id ERRORE (Impossibile recuperare stato)");
                return self::error($ref, 'Pendenza non recuperabile da GovPay');
            }
            $this->repo->updateRowStatus($id, 'SUCCESS', "ID Pendenza non trovato (né direttamente né per IUV)", true);
            $log("  [$batchId:$numeroRiga] ID-$id ERRORE (ID Pendenza non trovato)");
            return self::error($ref, 'ID pendenza non risolto');
        } catch (\Throwable $e) {
            $this->repo->updateRowStatus($id, 'SUCCESS', 'Errore di Annullamento: ' . $e->getMessage(), true);
            $log("  [$batchId:$numeroRiga] ID-$id ECCEZIONE: " . $e->getMessage());
            return self::error($ref, 'Eccezione ' . get_class($e));
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(string):void $log
     */
    private function processCreate(array $row, callable $log): JobResult
    {
        $id         = (int)$row['id'];
        $batchId    = $row['file_batch_id'];
        $numeroRiga = (int)$row['riga'];
        $ref        = (string)$id;
        $payload    = $row['payload_json'] ? json_decode($row['payload_json'], true) : null;

        if (!is_array($payload)) {
            $log("  [$batchId:$numeroRiga] ID-$id ERRORE PAYLOAD SCORRETTO.");
            $this->repo->setResult($id, false, null, 'Payload JSON non valido o mancante');
            return self::error($ref, 'Payload JSON non valido o mancante');
        }

        $this->repo->setProcessing($id);

        $accErrors        = [];
        $accWarnings      = [];
        $accountingParams = [
            'idDominio'      => $payload['idDominio'] ?? '',
            'idTipoPendenza' => $payload['idTipoPendenza'] ?? '',
        ];
        $payload['voci'] = $this->controller->buildVociWithAccounting(
            $payload['voci'] ?? [],
            $accountingParams,
            null,
            $accErrors,
            $accWarnings
        );

        if (!empty($accErrors)) {
            $errorMsg = 'Errore contabilità: ' . implode('; ', $accErrors);
            $log("  [$batchId:$numeroRiga] ID-$id ERRORE ($errorMsg)");
            $this->repo->setResult($id, false, null, $errorMsg);
            return self::error($ref, 'Errore contabilità');
        }

        $dDa = isset($payload['datiAllegati']) && is_string($payload['datiAllegati'])
            ? json_decode($payload['datiAllegati'], true)
            : ($payload['datiAllegati'] ?? []);
        if (!is_array($dDa)) $dDa = [];
        $dDa['sorgente']         = 'GIL-Massivo';
        $payload['datiAllegati'] = $dDa;

        $res = $this->controller->sendPendenzaToBackoffice($payload);

        if ($res['success'] === true) {
            $log("  [$batchId:$numeroRiga] ID-$id OK (Creato: " . ($res['idPendenza'] ?? 'sconosciuto') . ')');
            $respData = is_array($res['response'] ?? null) ? $res['response'] : [];
            if (!isset($respData['idPendenza']) && isset($res['idPendenza'])) {
                $respData['idPendenza'] = $res['idPendenza'];
            }
            $this->repo->setResult($id, true, $respData, null);

            // Invia notifiche email ed App IO
            $newId = $res['idPendenza'] ?? null;
            if ($newId) {
                try {
                    $responsePayload = is_array($res['response'] ?? null) ? $res['response'] : [];
                    [$iuvFromResponse, $numeroAvvisoFromResponse] = $this->controller->extractIuvAndNumeroAvviso($responsePayload);

                    $notifResult = $this->controller->sendCreationNotifications(
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
            return self::ok();
        }

        $errorMsg = is_array($res['errors']) ? implode('; ', $res['errors']) : 'Errore sconosciuto';
        $response = $res['response'] ?? null;
        if ($response !== null && !is_array($response)) {
            // Corpo 4xx non JSON (es. pagina HTML di un proxy): solo lo status, mai il corpo.
            $errorMsg = isset($res['httpStatus']) ? 'HTTP ' . (int)$res['httpStatus'] : 'HTTP 4xx';
            $response = null;
        }
        $log("  [$batchId:$numeroRiga] ID-$id ERRORE ($errorMsg)");
        $this->repo->setResult($id, false, $response, $errorMsg);
        return self::error($ref, self::createFailureReason($res, $errorMsg));
    }

    /**
     * Motivo sintetico del fallimento di creazione, senza corpi upstream:
     * risposta 4xx (ClientException: il controller restituisce la chiave 'response') -> "HTTP 4xx";
     * messaggio Guzzle con status (es. 5xx) -> "HTTP NNN"; altrimenti testo fisso.
     *
     * @param array<string,mixed> $res
     */
    private static function createFailureReason(array $res, string $errorMsg): string
    {
        if (array_key_exists('response', $res)) {
            return isset($res['httpStatus']) && !is_array($res['response'] ?? null) && $res['response'] !== null
                ? 'HTTP ' . (int)$res['httpStatus']
                : 'HTTP 4xx';
        }
        if (preg_match('/resulted in a `(\d{3})/', $errorMsg, $m) === 1) {
            return 'HTTP ' . $m[1];
        }
        return 'Invio a GovPay fallito';
    }

    /**
     * Estrae l'idPendenza reale (quello usato nell'URL PUT /pendenze/{idA2A}/{idPendenza}).
     *
     * Priorità:
     *  1. response_json['idPendenza']  — iniettato dal cron al salvataggio
     *  2. response_json['numeroAvviso'] — per pendenze IUV-vincolato (es. AFFITTO_SALE):
     *     il numeroAvviso È l'idPendenza usato nella PUT
     *  3. payload_json['idPendenza']   — idPendenza esplicito nel CSV originale
     *
     * UUID escluso: è un identificativo interno GovPay diverso dall'idPendenza.
     *
     * @param array<string,mixed> $row
     */
    private static function extractIdPendenza(array $row): ?string
    {
        $resp = $row['response_json'] ? json_decode($row['response_json'], true) : null;
        if (is_array($resp)) {
            // Prima: idPendenza esplicito
            foreach (['idPendenza', 'id_pendenza', 'idpendenza'] as $k) {
                if (isset($resp[$k]) && is_string($resp[$k]) && trim($resp[$k]) !== '') {
                    return trim($resp[$k]);
                }
                if (isset($resp['pendenza'][$k]) && is_string($resp['pendenza'][$k]) && trim($resp['pendenza'][$k]) !== '') {
                    return trim($resp['pendenza'][$k]);
                }
            }
            // Poi: numeroAvviso (= idPendenza per IUV vincolato)
            foreach (['numeroAvviso', 'numero_avviso'] as $k) {
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
    }

    /**
     * Estrae il numeroAvviso/IUV dal response_json (per ricerca per IUV come fallback).
     *
     * @param array<string,mixed> $row
     */
    private static function extractNumeroAvviso(array $row): ?string
    {
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
    }

    private static function ok(): JobResult
    {
        return new JobResult(1, 1, 0, 0, true);
    }

    private static function error(string $ref, string $reason): JobResult
    {
        return new JobResult(1, 0, 0, 1, true, [['ref' => $ref, 'reason' => $reason]]);
    }
}
