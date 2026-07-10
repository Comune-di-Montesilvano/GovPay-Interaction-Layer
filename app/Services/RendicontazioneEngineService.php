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
        $iuvPrefixGil = (string)SettingsRepository::get('rendicontazione', 'iuv_prefix_gil', 'GIL');
        $regoleEsterne = $this->repo->getRegoleEsterneAttive($idDominio);

        $righe = $this->repo->getPendingOrError($idDominio, $limit, $minDataPagamento);
        $nuove = 0;

        foreach ($righe as $riga) {
            if ($riga['rendicontazione_stato'] === 'PENDING') {
                $nuove++;
            }

            $idPendenza = (string)($riga['id_pendenza'] ?? '');
            $iuv = (string)($riga['iuv'] ?? '');
            if ($idPendenza === '' || $iuv === '') {
                $this->repo->markErrore((int)$riga['id'], 'id_pendenza o iuv mancante sulla riga');
                continue;
            }

            try {
                $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);
                $response = $this->govPayClient->request('GET', $url);
                $pendenza = json_decode((string)$response->getBody(), true);
                if (!is_array($pendenza)) {
                    throw new \RuntimeException('Risposta GovPay non valida');
                }
            } catch (\Throwable $e) {
                $this->repo->markErrore((int)$riga['id'], 'Errore fetch pendenza GovPay: ' . $e->getMessage());
                continue;
            }

            $idEntrata = (string)($riga['cod_entrata'] ?? '');
            $gruppo = $idEntrata !== '' ? $this->repo->getGruppoTipologia($idDominio, $idEntrata) : null;

            $decision = RendicontazioneRouter::decide($iuv, $iuvPrefixGil, $gruppo, $regoleEsterne);

            if ($decision->stato === 'IN_ATTESA_CONFERMA') {
                $this->repo->markInAttesaConferma((int)$riga['id']);
                continue;
            }

            if ($decision->handler === 'GERI' || $decision->handler === 'DILAZIONE') {
                if ($decision->handler === 'GERI' && (int)($riga['rendicontazione_tentativi_geri'] ?? 0) >= $geriMaxTentativi) {
                    $this->repo->markErrore((int)$riga['id'], 'Cap tentativi Geri raggiunto, richiede intervento manuale');
                    continue;
                }

                $idAtto = (string)($pendenza['documento']['identificativo'] ?? '');
                $dataPagamento = (string)($pendenza['dataPagamento'] ?? '');
                $importo = (float)($pendenza['importo'] ?? 0);
                $rata = isset($pendenza['documento']['rata']) ? (string)$pendenza['documento']['rata'] : null;

                $esito = $this->bridge->invia($decision->handler, $iuv, $idAtto, $dataPagamento, $importo, $rata);
                if (!$esito['esito']) {
                    if ($decision->handler === 'GERI') {
                        $this->repo->markErroreGeri((int)$riga['id'], "Bridge GERI: " . $esito['messaggio']);
                    } else {
                        $this->repo->markErrore((int)$riga['id'], "Bridge DILAZIONE: " . $esito['messaggio']);
                    }
                    continue;
                }
                $this->repo->markGestito((int)$riga['id'], $decision->handler, $esito['messaggio']);
            } else {
                $this->repo->markGestito((int)$riga['id'], (string)$decision->handler);
            }

            $this->tentaNotificaAppIo((int)$riga['id'], $pendenza, $riga);
        }

        return ['processate' => count($righe), 'nuove' => $nuove];
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

            $markdown = "## Pagamento registrato\n\n";
            $markdown .= '**Causale**: ' . ($pendenza['causale'] ?? '') . "\n\n";
            $markdown .= '**Importo**: € ' . number_format((float)($pendenza['importo'] ?? 0), 2, ',', '.') . "\n\n";
            if ($link !== '') {
                $markdown .= "[Scarica la ricevuta]({$link})\n\n";
            }

            $ioSvc = new AppIoService();
            $result = $ioSvc->sendMessage(
                (string)$ioService['api_key_primaria'],
                $cf,
                'Ricevuta pagamento PagoPA - ' . substr((string)($pendenza['causale'] ?? ''), 0, 100),
                $markdown,
                null,
                null
            );

            $this->repo->markAppioEsito($rigaId, ($result['esito'] ?? 'KO') === 'OK' ? 'INVIATO' : 'ERRORE');
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
}
