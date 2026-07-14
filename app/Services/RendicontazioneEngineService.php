<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use App\Database\IoServiceRepository;
use App\Database\RendicontazioneRepository;
use App\Logger;

class RendicontazioneEngineService
{
    public function __construct(
        private readonly RendicontazioneRepository $repo,
        private readonly LegacyRendicontazioneBridgeClient $bridge,
        private readonly \GuzzleHttp\Client $govPayClient
    ) {
    }

    /** @return array{processate:int, nuove:int} */
    public function processaCiclo(string $idDominio, string $idA2A, string $backofficeUrl, int $limit, string $minDataPagamento, int $geriMaxTentativi = 3): array
    {
        $righe = $this->repo->getPendingOrError($idDominio, $limit, $minDataPagamento);
        $nuove = 0;

        foreach ($righe as $riga) {
            if ($riga['rendicontazione_stato'] === 'PENDING') {
                $nuove++;
            }
            $this->processaRigaSpecifica($riga, $idDominio, $idA2A, $backofficeUrl, $geriMaxTentativi, false);
        }

        return ['processate' => count($righe), 'nuove' => $nuove];
    }

    public function processaRigaSpecifica(
        array  $riga,
        string $idDominio,
        string $idA2A,
        string $backofficeUrl,
        int    $geriMaxTentativi = 3,
        bool   $skipAppIo = false
    ): void {
        $iuvPrefixGil = (string)SettingsRepository::get('rendicontazione', 'iuv_prefix_gil', 'GIL');
        $regoleEsterne = $this->repo->getRegoleEsterneAttive($idDominio);

        $rigaId = (int)$riga['id'];
        $idPendenza = (string)($riga['id_pendenza'] ?? '');
        $iuv = (string)($riga['iuv'] ?? '');
        if ($idPendenza === '' || $iuv === '') {
            $this->repo->markErrore($rigaId, 'id_pendenza o iuv mancante sulla riga');
            return;
        }

        try {
            $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);
            $response = $this->govPayClient->request('GET', $url);
            $pendenza = json_decode((string)$response->getBody(), true);
            if (!is_array($pendenza)) {
                throw new \RuntimeException('Risposta GovPay non valida');
            }
        } catch (\Throwable $e) {
            $this->repo->markErrore($rigaId, 'Errore fetch pendenza GovPay: ' . $e->getMessage());
            return;
        }

        $idEntrata = (string)($riga['cod_entrata'] ?? '');
        if ($idEntrata === '') {
            // tipoPendenza.idTipoPendenza (struttura standard GET /pendenze/{idA2A}/{idPendenza})
            $idEntrata = (string)($pendenza['tipoPendenza']['idTipoPendenza'] ?? '');
            // Fallback: voci[].codEntrata
            if ($idEntrata === '' && isset($pendenza['voci']) && is_array($pendenza['voci'])) {
                foreach ($pendenza['voci'] as $voce) {
                    $cod = (string)($voce['codEntrata'] ?? '');
                    if ($cod !== '') {
                        $idEntrata = $cod;
                        break;
                    }
                }
            }
            if ($idEntrata !== '') {
                try {
                    $this->repo->updateCodEntrata($rigaId, $idEntrata);
                } catch (\Throwable $_) {
                    // ignore
                }
            }
        }
        $gruppo = $idEntrata !== '' ? $this->repo->getGruppoTipologia($idDominio, $idEntrata) : null;

        $decision = RendicontazioneRouter::decide($idPendenza, $iuv, $iuvPrefixGil, $gruppo, $regoleEsterne);

        if ($decision->stato === 'IN_ATTESA_CONFERMA') {
            $this->repo->markInAttesaConferma($rigaId);
            return;
        }

        if ($decision->handler === 'GERI' || $decision->handler === 'DILAZIONE') {
            if ($decision->handler === 'GERI' && (int)($riga['rendicontazione_tentativi_geri'] ?? 0) >= $geriMaxTentativi) {
                $this->repo->markErrore($rigaId, 'Cap tentativi Geri raggiunto, richiede intervento manuale');
                return;
            }

            $idAtto = (string)($pendenza['documento']['identificativo'] ?? '');
            $dataPagamento = (string)($pendenza['dataPagamento'] ?? '');
            $importo = (float)($pendenza['importo'] ?? 0);
            $rata = isset($pendenza['documento']['rata']) ? (string)$pendenza['documento']['rata'] : null;

            $esito = $this->bridge->invia($decision->handler, $iuv, $idAtto, $dataPagamento, $importo, $rata);
            if (!$esito['esito']) {
                if ($decision->handler === 'GERI') {
                    $this->repo->markErroreGeri($rigaId, "Bridge GERI: " . $esito['messaggio']);
                } else {
                    $this->repo->markErrore($rigaId, "Bridge DILAZIONE: " . $esito['messaggio']);
                }
                return;
            }
            $this->repo->markGestito($rigaId, $decision->handler, $esito['messaggio']);
        } else {
            $this->repo->markGestito($rigaId, (string)$decision->handler);
        }

        $this->controllaERegolarizzaFlussoPerRiga($idDominio, $rigaId, $backofficeUrl);

        if (!$skipAppIo) {
            $this->tentaNotificaAppIo($rigaId, $pendenza, $riga);
        } else {
            $this->repo->markAppioEsito($rigaId, 'NON_APPLICABILE');
        }
    }

    /**
     * Tenta la notifica App IO per una riga gia' passata a GESTITO al di fuori del ciclo
     * automatico (es. conferma manuale operatore in RendicontazioneController::conferma()).
     * Best-effort: fa fetch della pendenza da GovPay e riusa la stessa logica di
     * tentaNotificaAppIo() usata da processaCiclo(). Non lancia mai eccezioni verso il chiamante.
     */
    public function tentaNotificaAppIoPerRiga(int $rigaId): void
    {
        try {
            $riga = $this->repo->findById($rigaId);
            if ($riga === null) {
                return;
            }

            $idPendenza = (string)($riga['id_pendenza'] ?? '');
            $iuv = (string)($riga['iuv'] ?? '');
            if ($idPendenza === '' || $iuv === '') {
                return;
            }

            $idA2A = (string)SettingsRepository::get('entity', 'id_a2a', '');
            $backofficeUrl = (string)SettingsRepository::get('govpay', 'backoffice_url', '');
            if ($idA2A === '' || $backofficeUrl === '') {
                return;
            }

            $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);
            $response = $this->govPayClient->request('GET', $url);
            $pendenza = json_decode((string)$response->getBody(), true);
            if (!is_array($pendenza)) {
                return;
            }

            $this->tentaNotificaAppIo($rigaId, $pendenza, $riga);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Errore notifica App IO rendicontazione (conferma manuale)', [
                'riga_id' => $rigaId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Guardia di idempotenza: rilegge lo stato FRESCO da DB (non il valore in $riga, che puo'
     * essere stale se passato da un fetch precedente nella stessa richiesta) e salta l'invio se
     * la riga e' gia' stata processata con esito reale (INVIATO = notifica gia' recapitata al
     * cittadino, NON_APPLICABILE = gia' determinato che non si applica). PENDING (nuovo default,
     * vedi migrations/030) e' l'unico stato che significa "mai tentato" -> procede con l'invio.
     * ERRORE non blocca: un tentativo fallito non ha mai raggiunto il cittadino, quindi il retry
     * non e' una duplicazione.
     *
     * Necessaria perche' RendicontazioneRepository::confermaRigheScoped() puo' ri-restituire nel
     * report-back id gia' GESTITO/notificati in un batch precedente dello stesso operatore (es.
     * submit doppio/stale da tab sovrapposte) -> RendicontazioneController::conferma() richiama
     * tentaNotificaAppIoPerRiga() piu' volte per la stessa riga.
     */
    private function appioGiaProcessata(int $rigaId): bool
    {
        $fresh = $this->repo->findById($rigaId);
        $statoFresco = (string)($fresh['rendicontazione_appio_stato'] ?? '');
        return in_array($statoFresco, ['INVIATO', 'NON_APPLICABILE'], true);
    }

    private function tentaNotificaAppIo(int $rigaId, array $pendenza, array $riga): void
    {
        if ($this->appioGiaProcessata($rigaId)) {
            return;
        }

        $tipoSoggetto = (string)($pendenza['soggettoPagatore']['tipo'] ?? '');
        $cf = (string)($pendenza['soggettoPagatore']['identificativo'] ?? '');
        if ($tipoSoggetto !== 'F' || $cf === '') {
            $this->repo->markAppioEsito($rigaId, 'NON_APPLICABILE');
            return;
        }

        try {
            $ioRepo = new IoServiceRepository();
            // Nota: IoServiceRepository::getTipologiaService() richiede string non-nullable
            // (vedi backoffice/src/Controllers/PendenzeController.php:4690, stesso pattern `?? ''`).
            $ioService = $ioRepo->getTipologiaService((string)($pendenza['idTipoPendenza'] ?? '')) ?? $ioRepo->findDefault();
            if (!$ioService) {
                $this->repo->markAppioEsito($rigaId, 'NON_APPLICABILE');
                return;
            }

            $frontofficeUrl = $this->resolveFrontofficeBaseUrl();
            $iur = (string)($riga['iur'] ?? '');
            $link = ($frontofficeUrl !== '' && $iur !== '')
                ? $this->buildRicevutaLink($frontofficeUrl, (string)$riga['iuv'], $iur)
                : '';

            $causale = (string)($pendenza['causale'] ?? '');
            $tipologia = (string)($riga['descrizione_entrata'] ?? $riga['cod_entrata'] ?? '');
            $anagrafica = (string)($pendenza['soggettoPagatore']['anagrafica'] ?? '');
            $dataPagamentoRaw = (string)($pendenza['dataPagamento'] ?? '');
            $dataPagamento = $dataPagamentoRaw !== '' ? date('d/m/Y', strtotime($dataPagamentoRaw)) : '';
            $iuv = (string)($riga['iuv'] ?? '');

            $markdown = "## Pagamento registrato\n\n";
            $markdown .= "Abbiamo ricevuto e registrato il pagamento relativo alla pendenza in oggetto";
            $markdown .= $anagrafica !== '' ? ", intestata a **{$anagrafica}**.\n\n" : ".\n\n";
            if ($tipologia !== '') {
                $markdown .= "**Tipologia**: {$tipologia}\n\n";
            }
            $markdown .= "**Causale**: {$causale}\n\n";
            if ($iuv !== '') {
                $markdown .= "**IUV**: {$iuv}\n\n";
            }
            if ($dataPagamento !== '') {
                $markdown .= "**Data pagamento**: {$dataPagamento}\n\n";
            }
            $markdown .= '**Importo**: € ' . number_format((float)($pendenza['importo'] ?? 0), 2, ',', '.') . "\n\n";
            if ($link !== '') {
                $markdown .= "Può scaricare la ricevuta con tutti i dettagli da questo [link]({$link}).\n\n";
            }

            $ioSvc = new AppIoService();
            $oggetto = 'Ricevuta pagamento PagoPA' . ($tipologia !== '' ? " - {$tipologia}" : '') . ($iuv !== '' ? " - {$iuv}" : '');
            $result = $ioSvc->sendMessage(
                (string)$ioService['api_key_primaria'],
                $cf,
                substr($oggetto, 0, 120),
                $markdown,
                null,
                null
            );

            if (($result['esito'] ?? 'KO') === 'OK') {
                $this->repo->markAppioInviato($rigaId, isset($result['id']) ? (string)$result['id'] : null);
            } else {
                $this->repo->markAppioEsito($rigaId, 'ERRORE');
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Errore notifica App IO rendicontazione', ['error' => $e->getMessage()]);
            $this->repo->markAppioEsito($rigaId, 'ERRORE');
        }
    }

    private function resolveFrontofficeBaseUrl(): string
    {
        $url = trim((string)SettingsRepository::get('frontoffice', 'public_base_url', ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }
        $url = trim((string)SettingsRepository::get('backoffice', 'public_base_url', ''));
        if ($url === '') {
            return '';
        }
        $url = rtrim($url, '/');
        return str_ends_with(strtolower($url), '/backoffice') ? substr($url, 0, -strlen('/backoffice')) : $url;
    }

    private function buildRicevutaLink(string $frontofficeBaseUrl, string $iuv, string $iur): string
    {
        $signingKey = (string)(getenv('FRONTOFFICE_LINK_SIGNING_KEY') ?: '');
        if ($signingKey === '') {
            return '';
        }
        $params = ['type' => 'ricevuta', 'iuv' => $iuv, 'iur' => $iur, 'expires' => (string)(time() + 7776000)];
        ksort($params);
        $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $params['sig'] = hash_hmac('sha256', $payload, $signingKey);
        return rtrim($frontofficeBaseUrl, '/') . '/link/ricevuta?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function regolarizzaIncasso(string $idDominio, string $idFlusso, float $importo, string $trn, string $backofficeUrl): bool
    {
        $url = rtrim($backofficeUrl, '/') . '/incassi/' . rawurlencode($idDominio);
        $payload = [
            'importo'  => (float)round($importo, 2),
            'idFlusso' => $idFlusso,
            'sct'      => $trn,
        ];

        try {
            $response = $this->govPayClient->request('POST', $url, [
                'json' => $payload
            ]);
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                Logger::getInstance()->info("Regolarizzazione incasso flusso {$idFlusso} completata con successo.");
                return true;
            }
            Logger::getInstance()->error("Errore regolarizzazione incasso flusso {$idFlusso}: status code {$statusCode}");
            return false;
        } catch (\Throwable $e) {
            Logger::getInstance()->error("Eccezione durante regolarizzazione incasso flusso {$idFlusso}: " . $e->getMessage());
            return false;
        }
    }

    public function controllaERegolarizzaFlussoPerRiga(string $idDominio, int $rigaId, string $backofficeUrl): void
    {
        try {
            $riga = $this->repo->findById($rigaId);
            if (!$riga) {
                return;
            }
            $idFlusso = $riga['id_flusso'] ?? '';
            if ($idFlusso === '') {
                return;
            }

            // 1. Controlla se tutti gli elementi del flusso sono in stato GESTITO
            if (!$this->repo->isFlussoRendicontato($idDominio, $idFlusso)) {
                return;
            }

            // 2. Controlla se il flusso è già stato regolarizzato
            if ($this->repo->isFlussoRegolarizzato($idDominio, $idFlusso)) {
                return;
            }

            // 3. Recupera dati aggregati per il flusso (importo totale e TRN/SCT)
            // Proviamo ad ottenere importoTotale e TRN/SCT reali direttamente da GovPay
            $importo = 0.0;
            $trn = '';

            try {
                $flussoUrl = rtrim($backofficeUrl, '/') . '/flussiRendicontazione?idFlusso=' . rawurlencode($idFlusso) . '&idDominio=' . rawurlencode($idDominio);
                $flussoResponse = $this->govPayClient->request('GET', $flussoUrl);
                $flussoData = json_decode((string)$flussoResponse->getBody(), true);
                if (is_array($flussoData) && !empty($flussoData['risultati']) && is_array($flussoData['risultati'])) {
                    foreach ($flussoData['risultati'] as $f) {
                        if (($f['idFlusso'] ?? '') === $idFlusso) {
                            $importo = (float)($f['importoTotale'] ?? 0.0);
                            $trn = (string)($f['trn'] ?? '');
                            break;
                        }
                    }
                }
            } catch (\Throwable $ex) {
                Logger::getInstance()->warning("Impossibile recuperare dettagli flusso {$idFlusso} da GovPay, uso fallback locale: " . $ex->getMessage());
            }

            // Fallback locale se non trovato o importo nullo
            if ($importo <= 0.0) {
                $datiFlusso = $this->repo->getDatiAggregatiFlusso($idDominio, $idFlusso);
                if ($datiFlusso) {
                    $importo = (float)($datiFlusso['importo_totale'] ?? 0.0);
                    if ($trn === '') {
                        $trn = (string)($datiFlusso['trn'] ?? '');
                    }
                }
            }

            if ($importo <= 0.0) {
                Logger::getInstance()->warning("Flusso {$idFlusso} ha importo nullo o non valido, regolarizzazione saltata.");
                return;
            }

            // 4. Esegue la chiamata a GovPay
            $success = $this->regolarizzaIncasso($idDominio, $idFlusso, $importo, $trn, $backofficeUrl);
            if ($success) {
                // 5. Marca il flusso come regolarizzato nel DB locale
                $this->repo->marcaFlussoRegolarizzato($idDominio, $idFlusso);
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->error("Errore durante controllaERegolarizzaFlussoPerRiga per riga {$rigaId}: " . $e->getMessage());
        }
    }
}
