<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Config\SettingsRepository;
use App\Controllers\PendenzeController;
use App\Database\MassivePendenzeRepository;

/**
 * Elaborazione della coda pendenze massive.
 *
 * Worker (Task 11): processClaimedRow() per ogni messaggio ProcessMassiveRow (righe prese in carico
 * da MassiveEnqueueJob con claim atomico), failClaimedRow() per l'esito finale dei messaggi falliti.
 * Percorso legacy (cron_pendenze_massive.php, rimosso con il passaggio al worker): runOnce().
 * In entrambi l'idPendenza di una nuova pendenza viene assegnato e salvato sulla riga PRIMA della
 * PUT di creazione, e resta salvato anche dopo un rifiuto: ogni nuovo tentativo aggiorna la
 * stessa pendenza (PUT idempotente), mai una seconda creazione con un id diverso.
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
        // Ogni iterazione rilegge i settings: in un processo longevo la cache statica
        // resterebbe ferma ai valori dell'avvio (modifiche da UI ignorate fino al riavvio).
        SettingsRepository::flush();

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
            ? $this->processCancel($row, $log, false)
            : $this->processCreate($row, $log);
    }

    /**
     * Elaborazione di una riga presa dal worker (messaggio ProcessMassiveRow, claim atomico di
     * MassiveEnqueueJob): rinfresca claimed_at, rilegge la riga e la elabora solo se e' ancora
     * in lavorazione (PROCESSING / CANCEL_PROCESSING) con lo STESSO claim_token del messaggio.
     * Altrimenti (messaggio duplicato, riconsegnato dopo un reset del claim, riga gia' conclusa,
     * messa in pausa, eliminata) e' un no-op senza chiamate GovPay.
     *
     * Creazione con errore transitorio (rete/esito incerto, GovPay 5xx): la riga resta PROCESSING
     * con errore e id salvati, e viene lanciata TransientMassiveFailure (nuovo tentativo del
     * messaggio). Errori definitivi (4xx, validazione, configurazione): ERROR subito, come oggi.
     * Annullamento: invariato (mai ritentato, P4 fuori ambito).
     *
     * @throws TransientMassiveFailure
     */
    public function processClaimedRow(int $rowId, string $claimToken, ?JobContext $ctx = null): JobResult
    {
        $log = static function (string $msg) use ($ctx): void {
            $ctx?->debug($msg);
        };

        $this->repo->touchClaim($rowId, $claimToken);
        $row = $this->repo->findById($rowId);
        $stato = is_array($row) ? (string)$row['stato'] : null;
        if ($row === null
            || ($stato !== 'PROCESSING' && $stato !== 'CANCEL_PROCESSING')
            || !hash_equals((string)($row['claim_token'] ?? ''), $claimToken)
        ) {
            $log("  ID-$rowId messaggio obsoleto o duplicato (stato: " . ($stato ?? 'inesistente') . '), ignorato.');
            return new JobResult(0, 0, 1, 0, false);
        }

        return $stato === 'CANCEL_PROCESSING'
            ? $this->processCancel($row, $log, true)
            : $this->processCreate($row, $log, $claimToken);
    }

    /** Pausa: rilascia il claim senza chiamare GovPay (vedi MassivePendenzeRepository::releaseClaim()). */
    public function releaseClaimedRow(int $rowId, string $claimToken): bool
    {
        return $this->repo->releaseClaim($rowId, $claimToken);
    }

    /**
     * Esito finale di un messaggio fallito (tentativi esauriti o errore non ritentabile): vedi
     * MassivePendenzeRepository::failClaim(). $errore null = resta quello dell'ultimo tentativo.
     */
    public function failClaimedRow(int $rowId, string $claimToken, ?string $errore): bool
    {
        return $this->repo->failClaim($rowId, $claimToken, $errore);
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
     * @param bool $claimed riga gia' CANCEL_PROCESSING con claim del worker (nessuna marcatura)
     */
    private function processCancel(array $row, callable $log, bool $claimed): JobResult
    {
        $id         = (int)$row['id'];
        $batchId    = $row['file_batch_id'];
        $numeroRiga = (int)$row['riga'];
        $ref        = (string)$id;

        $log("  [$batchId:$numeroRiga] ID-$id Annullamento in corso...");

        try {
            if (!$claimed && !$this->repo->setCancelProcessing($id)) {
                $log("  [$batchId:$numeroRiga] ID-$id non piu' CANCEL_PENDING (presa da un altro processo), salto.");
                return new JobResult(0, 0, 1, 0, false);
            }

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
                        $putRes = $this->controller->fallbackFullPutUpdate($idPendenza, [], 'Annullamento');
                        if (($putRes['success'] ?? false) !== true) {
                            // Storico non registrato: niente PATCH (la pendenza resta NON_ESEGUITA su GovPay).
                            $status = isset($putRes['httpStatus']) ? (int)$putRes['httpStatus'] : null;
                            $suffix = $status !== null ? " (HTTP $status)" : '';
                            $this->repo->updateRowStatus($id, 'SUCCESS', 'Errore di Annullamento: aggiornamento storico rifiutato' . $suffix, true);
                            $log("  [$batchId:$numeroRiga] ID-$id ERRORE AGGIORNAMENTO STORICO$suffix");
                            return self::error($ref, 'Annullamento rifiutato' . ($status !== null ? ": HTTP $status" : ''));
                        }
                        $result = $this->controller->updatePendenzaStatus($idPendenza, 'ANNULLATA');
                        if ($result['success'] ?? false) {
                            $this->repo->updateRowStatus($id, 'CANCELLED', null, true);
                            $log("  [$batchId:$numeroRiga] ID-$id ANNULLATO OK");
                            return self::ok();
                        }
                        // P6: solo lo status HTTP, mai il corpo upstream che il controller accoda.
                        $httpCode = preg_match('/HTTP (\d{3})/', (string)($result['error'] ?? ''), $m) === 1 ? $m[1] : null;
                        $errText = $httpCode !== null ? 'HTTP ' . $httpCode : 'Errore sconosciuto';
                        $this->repo->updateRowStatus($id, 'SUCCESS', 'Errore API di Annullamento: ' . $errText, true);
                        $log("  [$batchId:$numeroRiga] ID-$id ERRORE API ANNULLAMENTO: " . $errText);
                        $reason = $httpCode !== null
                            ? 'Annullamento rifiutato: HTTP ' . $httpCode
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
            // P6: solo la classe dell'eccezione (il messaggio puo' contenere URL/corpi upstream).
            $short = (new \ReflectionClass($e))->getShortName();
            $this->repo->updateRowStatus($id, 'SUCCESS', 'Errore di Annullamento: ' . $short, true);
            $log("  [$batchId:$numeroRiga] ID-$id ECCEZIONE: " . $e->getMessage());
            return self::error($ref, 'Eccezione ' . get_class($e));
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param callable(string):void $log
     * @param string|null $claimToken null = percorso legacy (runOnce dello script), altrimenti
     *                                claim del worker (riga gia' PROCESSING)
     * @throws TransientMassiveFailure solo con $claimToken
     */
    private function processCreate(array $row, callable $log, ?string $claimToken = null): JobResult
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

        if ($claimToken === null && !$this->repo->setProcessing($id)) {
            $log("  [$batchId:$numeroRiga] ID-$id non piu' PENDING (presa da un altro processo), salto.");
            return new JobResult(0, 0, 1, 0, false);
        }

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

        // Riprova dopo un esito incerto / processo interrotto: riusa l'idPendenza (e il
        // numeroAvviso IUV vincolato) salvati sulla riga, cosi' la PUT aggiorna la stessa
        // pendenza invece di crearne un'altra.
        /** @var array<string,string> $ids idPendenza (+ numeroAvviso) usati per la PUT, salvati sulla riga */
        $ids = [];
        $stored = $row['response_json'] ? json_decode((string)$row['response_json'], true) : null;
        if (is_array($stored) && isset($stored['idPendenza']) && is_string($stored['idPendenza']) && trim($stored['idPendenza']) !== '') {
            $retryId = trim($stored['idPendenza']);
            $ids['idPendenza'] = $retryId;
            if (isset($stored['numeroAvviso']) && is_string($stored['numeroAvviso']) && $stored['numeroAvviso'] !== '') {
                $payload['numeroAvviso'] = $stored['numeroAvviso'];
                $ids['numeroAvviso'] = $stored['numeroAvviso'];
            }
            $log("  [$batchId:$numeroRiga] ID-$id riprova sullo stesso idPendenza: $retryId");
        } else {
            // Prima creazione: l'id viene assegnato e SALVATO sulla riga PRIMA della PUT. Se il
            // processo muore durante la PUT (la pendenza puo' essere gia' su GovPay), il nuovo
            // tentativo dopo il reset del claim riusa lo stesso id: mai due id per una riga.
            $alloc = $this->controller->allocateIdPendenza($payload);
            if ($alloc['success'] !== true) {
                $errorMsg = self::createErrore($alloc);
                $log("  [$batchId:$numeroRiga] ID-$id ERRORE ($errorMsg)");
                $this->repo->setResult($id, false, null, $errorMsg);
                return self::error($ref, self::createFailureReason($alloc));
            }
            $retryId = $alloc['idPendenza'];
            $ids['idPendenza'] = $retryId;
            if ($alloc['numeroAvviso'] !== null) {
                $payload['numeroAvviso'] = $alloc['numeroAvviso'];
                $ids['numeroAvviso'] = $alloc['numeroAvviso'];
            }
            $this->repo->storeAllocatedId($id, $ids);
        }

        $res = $this->controller->sendPendenzaToBackoffice($payload, $retryId);

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

        $errorMsg = self::createErrore($res);
        // response_json: solo i campi ProblemJson (categoria/codice/descrizione), mai il corpo
        // intero, piu' l'idPendenza/numeroAvviso usati: anche dopo un rifiuto la "riprova"
        // aggiorna la stessa pendenza (puo' esistere su GovPay se un tentativo precedente era
        // arrivato), mai un id nuovo.
        $problem = array_key_exists('response', $res) && is_array($res['problem'] ?? null) ? $res['problem'] : [];
        $response = array_merge($problem, $ids) ?: null;
        $reason = self::createFailureReason($res);
        if ($claimToken !== null && self::isTransient($res)) {
            $log("  [$batchId:$numeroRiga] ID-$id ERRORE TRANSITORIO ($errorMsg): nuovo tentativo");
            $this->repo->recordTransientFailure($id, $claimToken, $response, $errorMsg);
            throw new TransientMassiveFailure($reason);
        }
        $log("  [$batchId:$numeroRiga] ID-$id ERRORE ($errorMsg)");
        $this->repo->setResult($id, false, $response, $errorMsg);
        return self::error($ref, $reason);
    }

    /**
     * Errore ritentabile della PUT di creazione: trasporto senza risposta HTTP (esito incerto:
     * timeout, connessione rifiutata/interrotta, circuit breaker aperto) o GovPay 5xx. Tutto il
     * resto (4xx, validazione, configurazione, altre eccezioni) e' definitivo.
     *
     * @param array<string,mixed> $res
     */
    private static function isTransient(array $res): bool
    {
        if (!empty($res['esitoIncerto'])) {
            return true;
        }
        return isset($res['httpStatus']) && (int)$res['httpStatus'] >= 500;
    }

    /**
     * Testo per la colonna `errore` di una creazione fallita, senza dati personali: status HTTP +
     * codice/descrizione del ProblemJson GovPay; errore di rete -> sola classe dell'eccezione (il
     * messaggio contiene URL/corpo); errori di configurazione interni -> testo fisso del controller.
     * Troncato a 255 caratteri.
     *
     * @param array<string,mixed> $res
     */
    private static function createErrore(array $res): string
    {
        if (!empty($res['circuitBreakerOpen'])) {
            return "GovPay non raggiungibile (circuit breaker aperto) \u{2014} nessun invio effettuato";
        }
        if (!empty($res['esitoIncerto'])) {
            $errno = isset($res['curlErrno']) ? (string)(int)$res['curlErrno'] : 'n/d';
            return "Esito incerto: timeout/errore di rete verso GovPay (cURL $errno) \u{2014} verificare prima di ripetere";
        }
        if (isset($res['httpStatus'])) {
            $msg = 'HTTP ' . (int)$res['httpStatus'];
            $problem = is_array($res['problem'] ?? null) ? $res['problem'] : [];
            $codice = (string)($problem['codice'] ?? '');
            $descrizione = (string)($problem['descrizione'] ?? '');
            if ($codice !== '' || $descrizione !== '') {
                $msg .= ' - ' . $codice . ($codice !== '' && $descrizione !== '' ? ': ' : '') . $descrizione;
            }
        } elseif (isset($res['exceptionClass'])) {
            $class = (string)$res['exceptionClass'];
            $pos = strrpos($class, '\\');
            $msg = 'Invio a GovPay fallito (' . ($pos === false ? $class : substr($class, $pos + 1)) . ')';
        } else {
            $msg = is_array($res['errors'] ?? null) ? implode('; ', $res['errors']) : 'Errore sconosciuto';
        }
        return mb_substr($msg, 0, 255);
    }

    /**
     * Motivo sintetico del fallimento di creazione (errorDetails), senza corpi upstream:
     * risposta 4xx JSON (ClientException) -> "HTTP 4xx"; 4xx non JSON / 5xx -> "HTTP NNN";
     * altrimenti testo fisso.
     *
     * @param array<string,mixed> $res
     */
    private static function createFailureReason(array $res): string
    {
        if (!empty($res['circuitBreakerOpen'])) {
            return 'GovPay non raggiungibile (circuit breaker aperto)';
        }
        if (!empty($res['esitoIncerto'])) {
            return 'Esito incerto: errore di rete (cURL ' . (isset($res['curlErrno']) ? (int)$res['curlErrno'] : 'n/d') . ')';
        }
        if (array_key_exists('response', $res) && (is_array($res['response']) || $res['response'] === null)) {
            return 'HTTP 4xx';
        }
        if (isset($res['httpStatus'])) {
            return 'HTTP ' . (int)$res['httpStatus'];
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
