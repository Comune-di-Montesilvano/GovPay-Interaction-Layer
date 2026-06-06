<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\EntrateRepository;
use App\Database\ExternalPaymentTypeRepository;
use App\Database\PendenzaTemplateRepository;
use App\Logger;
use App\Services\GovPayClientFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

/**
 * API interne usate dal frontoffice come sidecar.
 *
 * Tutti gli endpoint richiedono Bearer MASTER_TOKEN.
 * Non richiedono sessione PHP: sono API machine-to-machine tra container.
 *
 * Principio: unica fonte di verità GovPay Backoffice v1 — questo controller
 * delega a PendenzeController per tutte le operazioni GovPay, senza SDK multipli.
 */
class FrontofficeApiController
{
    public function __construct(private readonly Twig $twig)
    {
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    private function verifyMasterToken(Request $request): bool
    {
        $masterToken = $_ENV['MASTER_TOKEN'] ?? getenv('MASTER_TOKEN') ?: '';
        if ($masterToken === '') {
            return false;
        }
        $authHeader = $request->getHeaderLine('Authorization');
        return str_starts_with($authHeader, 'Bearer ')
            && hash_equals($masterToken, substr($authHeader, 7));
    }

    // ── Response helpers ─────────────────────────────────────────────────────

    private function jsonOk(string $message, array $extra = []): Response
    {
        return $this->jsonResponse(array_merge(['success' => true, 'message' => $message], $extra));
    }

    private function jsonError(string $message, int $status = 400): Response
    {
        $resp = $this->jsonResponse([
            'success'      => false,
            'message'      => $message,
            'error_status' => $status,
        ], 200);
        return $resp->withHeader('X-App-Error-Status', (string)$status);
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        $resp = new SlimResponse($status);
        $resp->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $resp->withHeader('Content-Type', 'application/json');
    }

    private function pendenzeController(): PendenzeController
    {
        return new PendenzeController($this->twig);
    }

    // ── Endpoint: tipologie ───────────────────────────────────────────────────

    /**
     * GET /api/frontoffice/tipologie
     * Restituisce le tipologie interne (entrate_tipologie) + esterne (tipologie_pagamento_esterne).
     */
    public function getTipologie(Request $request, Response $response): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            return $this->jsonError('ID_DOMINIO non configurato', 503);
        }

        try {
            $tipologie = (new EntrateRepository())->listByDominio($idDominio);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('FrontofficeApi::getTipologie errore DB entrate', ['error' => $e->getMessage()]);
            $tipologie = [];
        }

        $external = [];
        try {
            $external = (new ExternalPaymentTypeRepository())->listAll();
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::getTipologie errore DB tipologie esterne', ['error' => $e->getMessage()]);
        }

        return $this->jsonOk('OK', [
            'tipologie'         => $tipologie,
            'tipologie_esterne' => $external,
            'id_dominio'        => $idDominio,
        ]);
    }

    // ── Endpoint: pendenza templates ─────────────────────────────────────────

    /**
     * GET /api/frontoffice/pendenza-templates
     */
    public function getPendenzaTemplates(Request $request, Response $response): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            return $this->jsonError('ID_DOMINIO non configurato', 503);
        }

        try {
            $templates = (new PendenzaTemplateRepository())->findAllByDominio($idDominio);
            return $this->jsonOk('OK', ['templates' => $templates]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('FrontofficeApi::getPendenzaTemplates errore', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore interno', 500);
        }
    }

    // ── Endpoint: ricerca pendenze per CF ─────────────────────────────────────

    /**
     * GET /api/frontoffice/pendenze
     * Parametri query: cf, page, per_page, stato
     * Delega a PendenzeController::callBackofficeFindPendenze() — GovPay Backoffice v1.
     */
    public function findPendenze(Request $request, Response $response): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $params       = $request->getQueryParams();
        $cf           = trim((string)($params['cf'] ?? ''));
        $page         = max(1, (int)($params['page'] ?? 1));
        $perPage      = min(200, max(1, (int)($params['per_page'] ?? 25)));
        $stato        = trim((string)($params['stato'] ?? ''));
        $numeroAvviso = trim((string)($params['numero_avviso'] ?? ''));
        $idDominio    = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $idA2A        = (string)SettingsRepository::get('entity', 'id_a2a', '');

        if ($cf === '' && $numeroAvviso === '') {
            return $this->jsonError('Parametro cf o numero_avviso mancante', 400);
        }

        $query = array_filter([
            'pagina'             => $page,
            'risultatiPerPagina' => $perPage,
            'idDominio'          => $idDominio ?: null,
            'idA2A'              => $idA2A ?: null,
            'idDebitore'         => $cf ?: null,
            'iuv'                => $numeroAvviso ?: null,
            'stato'              => $stato ?: null,
            'metadatiPaginazione'=> true,
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            $data = $this->pendenzeController()->callBackofficeFindPendenze($query);
            return $this->jsonOk('OK', ['data' => $data]);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::findPendenze errore', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore durante la ricerca pendenze', 503);
        }
    }

    // ── Endpoint: dettaglio pendenza ─────────────────────────────────────────

    /**
     * GET /api/frontoffice/pendenze/{idPendenza}
     * Delega a PendenzeController::fetchPendenzaById() — GovPay Backoffice v1.
     * idA2A letto da SettingsRepository dentro fetchPendenzaById().
     */
    public function getPendenza(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idPendenza = trim((string)($args['idPendenza'] ?? ''));
        if ($idPendenza === '') {
            return $this->jsonError('idPendenza mancante', 400);
        }

        $pendenza = $this->pendenzeController()->fetchPendenzaById($idPendenza);
        if ($pendenza === null) {
            return $this->jsonError('Pendenza non trovata', 404);
        }
        return $this->jsonOk('OK', ['pendenza' => $pendenza]);
    }

    // ── Endpoint: pendenza per avviso ─────────────────────────────────────────

    /**
     * GET /api/frontoffice/pendenze/avviso/{idDominio}/{numeroAvviso}
     * Usa GovPay\Backoffice\Api\PendenzeApi::getPendenzaByAvviso() via factory.
     */
    public function getPendenzaByAvviso(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idDominio    = trim((string)($args['idDominio'] ?? ''));
        $numeroAvviso = trim((string)($args['numeroAvviso'] ?? ''));

        if ($idDominio === '' || $numeroAvviso === '') {
            return $this->jsonError('Parametri mancanti', 400);
        }

        if (!class_exists(\GovPay\Backoffice\Api\PendenzeApi::class)) {
            return $this->jsonError('Client GovPay Backoffice non disponibile', 503);
        }

        try {
            $api    = GovPayClientFactory::makeBackofficeSdkApi();
            $result = $api->getPendenzaByAvviso($idDominio, $numeroAvviso);
            $data   = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            return $this->jsonOk('OK', ['pendenza' => $data]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 404;
            return $this->jsonError('Avviso non trovato', $status === 404 ? 404 : 503);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::getPendenzaByAvviso errore', [
                'idDominio'    => $idDominio,
                'numeroAvviso' => $numeroAvviso,
                'error'        => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return $this->jsonError('Errore durante la ricerca avviso', 503);
        }
    }

    // ── Endpoint: crea pendenza ───────────────────────────────────────────────

    /**
     * POST /api/frontoffice/pendenze
     *
     * Body JSON: {idTipoPendenza, idDominio, causale, importo, annoRiferimento,
     *             soggettoPagatore, dataValidita?, dataScadenza?, datiAllegati?,
     *             voci?       — presenti per bollo (hashDocumento calcolato dal frontoffice),
     *                           assenti per spontaneo (backoffice costruisce da DB),
     *             _notif?     — {email, anagrafica, identificativo, tipo_soggetto} per notifiche}
     *
     * Delega a PendenzeController::sendPendenzaToBackoffice() + sendCreationNotifications().
     */
    public function createPendenza(Request $request, Response $response): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->jsonError('Body JSON non valido', 400);
        }

        $idTipo    = trim((string)($body['idTipoPendenza'] ?? ''));
        $idDominio = trim((string)($body['idDominio'] ?? ''));
        $causale   = trim((string)($body['causale'] ?? ''));
        $importo   = is_numeric($body['importo'] ?? null) ? round((float)$body['importo'], 2) : 0.0;

        if ($idTipo === '' || $idDominio === '' || $causale === '' || $importo <= 0) {
            return $this->jsonError('Campi obbligatori mancanti: idTipoPendenza, idDominio, causale, importo', 400);
        }

        // Voci: se presenti nel body le usa (bollo: hashDocumento già calcolato dal frontoffice).
        // Assenti: backoffice le costruisce da DB (spontaneo: iban, contabilità, tipo_bollo).
        if (empty($body['voci'])) {
            $body['voci'] = $this->buildVoci($idDominio, $idTipo, $causale, $importo);
        }

        // Rimuove _notif dal payload prima di inviare a GovPay
        $notifParams = is_array($body['_notif'] ?? null) ? $body['_notif'] : [];
        unset($body['_notif']);

        $pc     = $this->pendenzeController();
        $result = $pc->sendPendenzaToBackoffice($body);

        if (!$result['success']) {
            $msg = implode('; ', $result['errors'] ?? ['Errore durante la creazione della pendenza']);
            Logger::getInstance()->error('FrontofficeApi::createPendenza errore GovPay', ['error' => $msg]);
            return $this->jsonError($msg, 422);
        }

        // Notifiche opzionali
        if (!empty($notifParams['email']) || !empty($notifParams['identificativo'])) {
            $pc->sendCreationNotifications(
                (string)($result['idPendenza'] ?? ''),
                (string)($notifParams['email'] ?? ''),
                (string)($notifParams['anagrafica'] ?? ''),
                (string)($notifParams['identificativo'] ?? ''),
                (string)($notifParams['tipo_soggetto'] ?? 'F'),
                [
                    'causale'        => $causale,
                    'importo'        => $importo,
                    'dataScadenza'   => $body['dataScadenza'] ?? null,
                    'idTipoPendenza' => $idTipo,
                ],
                $request
            );
        }

        return $this->jsonOk('Pendenza creata', [
            'idPendenza' => $result['idPendenza'] ?? '',
            'response'   => $result['response'] ?? null,
        ]);
    }

    /**
     * Costruisce le voci per una pendenza spontanea leggendo IBAN/contabilità da DB.
     * Non usato per bollo — le voci bollo vengono pre-costruite dal frontoffice (con hashDocumento).
     */
    private function buildVoci(string $idDominio, string $idTipo, string $descrizione, float $importo): array
    {
        $iban = $codCont = $tipoBollo = $tipoCont = '';
        try {
            if ($idDominio !== '' && $idTipo !== '') {
                $details = (new EntrateRepository())->findDetails($idDominio, $idTipo);
                if ($details) {
                    $iban          = (string)($details['iban_accredito'] ?? '');
                    $codCont       = (string)($details['codice_contabilita'] ?? '');
                    $rawTipoBollo  = (string)($details['tipo_bollo'] ?? '');
                    $tipoBollo     = in_array($rawTipoBollo, ['01'], true) ? $rawTipoBollo : '';
                    $tipoCont      = (string)($details['tipo_contabilita'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::buildVoci impossibile recuperare dettagli contabilita', ['error' => $e->getMessage()]);
        }

        $voice = [
            'idVocePendenza' => '1',
            'descrizione'    => $descrizione,
            'importo'        => $importo,
        ];

        if ($tipoBollo !== '') {
            $voice['tipoBollo'] = $tipoBollo;
        } elseif ($iban !== '' && $tipoCont !== '' && $codCont !== '') {
            $voice['ibanAccredito']     = $iban;
            $voice['tipoContabilita']   = $tipoCont;
            $voice['codiceContabilita'] = $codCont;
        } else {
            $voice['codEntrata'] = substr(preg_replace('/[^A-Za-z0-9\-_.]/', '', $idTipo), 0, 35);
        }

        return [$voice];
    }

    // ── Endpoint: checkout carrello ──────────────────────────────────────────

    /**
     * POST /api/frontoffice/carrello/checkout
     *
     * Body JSON: {
     *   notices: [{numeroAvviso, importo, causale}],
     *   idDominio: string,
     *   returnUrls: {ok, cancel, error},
     *   emailNotice?: string
     * }
     *
     * Chiama pagoPA CheckoutEC postCarts() e ritorna la Location per il redirect.
     */
    public function checkoutCarrello(Request $request, Response $response): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        if (!class_exists(\PagoPA\CheckoutEc\Api\DefaultApi::class)) {
            return $this->jsonError('Client pagoPA Checkout non disponibile', 503);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->jsonError('Body JSON non valido', 400);
        }

        $notices     = $body['notices'] ?? [];
        $idDominio   = trim((string)($body['idDominio'] ?? ''));
        $returnUrls  = $body['returnUrls'] ?? [];
        $emailNotice = trim((string)($body['emailNotice'] ?? ''));

        if (!is_array($notices) || empty($notices)) {
            return $this->jsonError('Nessuna pendenza nel payload', 400);
        }
        if ($idDominio === '') {
            return $this->jsonError('idDominio mancante', 400);
        }
        if (empty($returnUrls['ok']) || empty($returnUrls['cancel']) || empty($returnUrls['error'])) {
            return $this->jsonError('returnUrls incompleti', 400);
        }

        $subscriptionKey = trim((string)SettingsRepository::get('pagopa', 'checkout_subscription_key', ''));
        if ($subscriptionKey === '') {
            return $this->jsonError('Checkout pagoPA non configurato (subscription key mancante)', 503);
        }

        $config = \PagoPA\CheckoutEc\Configuration::getDefaultConfiguration();
        $host   = trim((string)SettingsRepository::get('pagopa', 'checkout_ec_base_url', ''));
        if ($host !== '') {
            $config->setHost(rtrim($host, '/'));
        }
        $config->setApiKey('Ocp-Apim-Subscription-Key', $subscriptionKey);
        $config->setApiKey('subscription-key', $subscriptionKey);

        $api = new \PagoPA\CheckoutEc\Api\DefaultApi(
            new \GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 10, 'allow_redirects' => false]),
            $config
        );

        $companyName = trim((string)SettingsRepository::get('pagopa', 'checkout_company_name', ''));
        if ($companyName === '') {
            $companyName = trim((string)SettingsRepository::get('entity', 'name', 'Ente')) ?: 'Ente';
        }

        $noticeObjects = [];
        foreach (array_slice($notices, 0, 5) as $n) {
            $numeroAvviso = preg_replace('/\D+/', '', trim((string)($n['numeroAvviso'] ?? '')));
            $importoRaw   = $n['importo'] ?? null;
            $amountCents  = is_numeric($importoRaw) ? (int)round((float)$importoRaw * 100) : 0;
            $description  = trim((string)($n['causale'] ?? ''));
            if ($numeroAvviso === '' || $amountCents <= 0) {
                continue;
            }
            $notice = new \PagoPA\CheckoutEc\Model\PaymentNotice();
            $notice->setNoticeNumber($numeroAvviso);
            $notice->setFiscalCode($idDominio);
            $notice->setAmount($amountCents);
            $notice->setCompanyName($companyName);
            if ($description !== '') {
                $notice->setDescription(mb_substr($description, 0, 140));
            }
            $noticeObjects[] = $notice;
        }

        if (empty($noticeObjects)) {
            return $this->jsonError('Nessuna pendenza valida (numeroAvviso o importo mancanti)', 422);
        }

        $returnUrlsObj = new \PagoPA\CheckoutEc\Model\CartRequestReturnUrls();
        $returnUrlsObj->setReturnOkUrl((string)$returnUrls['ok']);
        $returnUrlsObj->setReturnCancelUrl((string)$returnUrls['cancel']);
        $returnUrlsObj->setReturnErrorUrl((string)$returnUrls['error']);

        $cart = new \PagoPA\CheckoutEc\Model\CartRequest();
        $cart->setPaymentNotices($noticeObjects);
        $cart->setReturnUrls($returnUrlsObj);
        if ($emailNotice !== '' && filter_var($emailNotice, FILTER_VALIDATE_EMAIL) !== false) {
            $cart->setEmailNotice($emailNotice);
        }

        try {
            [, $statusCode, $headers] = $api->postCartsWithHttpInfo($cart);

            $location = '';
            if (is_array($headers)) {
                foreach ($headers as $name => $values) {
                    if (strtolower((string)$name) !== 'location') {
                        continue;
                    }
                    $location = is_array($values) && isset($values[0]) ? trim($values[0]) : '';
                    break;
                }
            }

            if ($location === '' || $statusCode < 300 || $statusCode >= 400) {
                Logger::getInstance()->warning('FrontofficeApi::checkoutCarrello risposta inattesa pagoPA', [
                    'status' => $statusCode, 'location' => $location,
                ]);
                return $this->jsonError('Risposta inattesa da pagoPA Checkout', 503);
            }

            return $this->jsonOk('Cart creato', ['location' => $location]);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::checkoutCarrello errore pagoPA', [
                'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return $this->jsonError('Errore durante la creazione del carrello pagoPA', 503);
        }
    }

    // ── Endpoint: streaming PDF ───────────────────────────────────────────────

    /**
     * GET /api/frontoffice/ricevuta/{idDominio}/{iuv}/{ccp}
     * Delega a PendenzeController::fetchRicevutaPdfResponse() (Backoffice v1 + fallback Pendenze v2).
     */
    public function getRicevuta(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idDominio = trim((string)($args['idDominio'] ?? ''));
        $iuv       = trim((string)($args['iuv'] ?? ''));
        $ccp       = trim((string)($args['ccp'] ?? ''));

        if ($idDominio === '' || $iuv === '' || $ccp === '') {
            return $this->jsonError('Parametri mancanti', 400);
        }

        try {
            $govResp  = $this->pendenzeController()->fetchRicevutaPdfResponse($idDominio, $iuv, $ccp);
            $filename = 'rt-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $iuv . '_' . $ccp) . '.pdf';
            return $this->wrapPdfResponse($govResp, $filename);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::getRicevuta errore', [
                'idDominio' => $idDominio, 'iuv' => $iuv, 'ccp' => $ccp,
                'error'     => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return $this->jsonError('Errore durante il download della ricevuta', 503);
        }
    }

    /**
     * GET /api/frontoffice/avviso/{idDominio}/{numeroAvviso}
     * Delega a PendenzeController::fetchAvvisoPdfResponse() (Backoffice v1).
     */
    public function getAvvisoPdf(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idDominio    = trim((string)($args['idDominio'] ?? ''));
        $numeroAvviso = trim((string)($args['numeroAvviso'] ?? ''));

        if ($idDominio === '' || $numeroAvviso === '') {
            return $this->jsonError('Parametri mancanti', 400);
        }

        try {
            $govResp  = $this->pendenzeController()->fetchAvvisoPdfResponse($idDominio, $numeroAvviso);
            $filename = 'avviso-' . $idDominio . '-' . $numeroAvviso . '.pdf';
            return $this->wrapPdfResponse($govResp, $filename);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::getAvvisoPdf errore', [
                'idDominio'    => $idDominio,
                'numeroAvviso' => $numeroAvviso,
                'error'        => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return $this->jsonError('Errore durante il download dell\'avviso', 503);
        }
    }

    /**
     * GET /api/frontoffice/documento/{numeroDocumento}/avvisi
     * Delega a PendenzeController::fetchDocumentoPdfResponse() (Pendenze v2).
     */
    public function getDocumentoPdf(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $numeroDocumento = trim((string)($args['numeroDocumento'] ?? ''));
        $idDominio       = (string)SettingsRepository::get('entity', 'id_dominio', '');

        if ($numeroDocumento === '' || $idDominio === '') {
            return $this->jsonError('Parametri mancanti o ID_DOMINIO non configurato', 400);
        }

        try {
            $govResp  = $this->pendenzeController()->fetchDocumentoPdfResponse($idDominio, $numeroDocumento);
            $filename = 'avvisi-' . rawurlencode($numeroDocumento) . '.pdf';
            return $this->wrapPdfResponse($govResp, $filename);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::getDocumentoPdf errore', [
                'numeroDocumento' => $numeroDocumento,
                'error'           => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return $this->jsonError('Errore durante il download del documento', 503);
        }
    }

    private function wrapPdfResponse(\Psr\Http\Message\ResponseInterface $govResp, string $filename): Response
    {
        if ($govResp->getStatusCode() < 200 || $govResp->getStatusCode() >= 300) {
            return $this->jsonError('PDF non disponibile', 404);
        }
        $resp = new SlimResponse(200);
        $resp->getBody()->write((string)$govResp->getBody());
        return $resp
            ->withHeader('Content-Type', $govResp->getHeaderLine('Content-Type') ?: 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    // ── Endpoint: rate limiting ──────────────────────────────────────────────

    /**
     * POST /api/frontoffice/rate-limit/check
     * Body JSON: {key: string, limit: int, window_sec?: int}
     */
    public function rateLimitCheck(Request $request, Response $response): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $body = json_decode((string)$request->getBody(), true);
        if (!is_array($body)) {
            return $this->jsonError('Body JSON non valido', 400);
        }

        $key       = substr(trim((string)($body['key'] ?? '')), 0, 190);
        $limit     = (int)($body['limit'] ?? 0);
        $windowSec = max(1, (int)($body['window_sec'] ?? 60));

        if ($key === '' || $limit <= 0) {
            return $this->jsonOk('OK', ['allowed' => true]);
        }

        try {
            $pdo = Connection::getPDO();
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::rateLimitCheck DB non disponibile, fail-open', [
                'error' => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return $this->jsonOk('OK', ['allowed' => true]);
        }

        $now       = time();
        $threshold = $now - $windowSec;

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO rate_limit_buckets (bucket_key, window_start, count) VALUES (:k, :w, 1) '
                . 'ON DUPLICATE KEY UPDATE '
                . 'count = IF(window_start < :t, 1, count + 1), '
                . 'window_start = IF(window_start < :t2, :w2, window_start)'
            );
            $stmt->execute([':k' => $key, ':w' => $now, ':w2' => $now, ':t' => $threshold, ':t2' => $threshold]);

            $sel = $pdo->prepare('SELECT count FROM rate_limit_buckets WHERE bucket_key = :k LIMIT 1');
            $sel->execute([':k' => $key]);
            $row   = $sel->fetch(\PDO::FETCH_ASSOC);
            $count = is_array($row) ? (int)($row['count'] ?? 0) : 0;
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::rateLimitCheck errore bucket, fail-open', [
                'bucket' => $key,
                'error'  => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return $this->jsonOk('OK', ['allowed' => true]);
        }

        if (mt_rand(1, 100) === 1) {
            try {
                $pdo->prepare('DELETE FROM rate_limit_buckets WHERE window_start < :t')
                    ->execute([':t' => $now - max(600, $windowSec * 10)]);
            } catch (\Throwable) {
                // Best-effort garbage collection
            }
        }

        $allowed = $count <= $limit;
        return $this->jsonOk('OK', [
            'allowed'     => $allowed,
            'retry_after' => $allowed ? null : $windowSec,
        ]);
    }
}
