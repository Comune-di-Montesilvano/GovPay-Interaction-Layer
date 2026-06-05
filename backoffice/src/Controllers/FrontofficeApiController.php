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
use GovPay\Pagamenti\Api\PendenzeApi as PagamentiPendenzeApi;
use GovPay\Pagamenti\Configuration as PagamentiConfiguration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

/**
 * API interne usate dal frontoffice come sidecar.
 *
 * Tutti gli endpoint richiedono Bearer MASTER_TOKEN.
 * Non richiedono sessione PHP: sono API machine-to-machine tra container.
 */
class FrontofficeApiController
{
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

    // ── GovPay client helpers ─────────────────────────────────────────────────

    private function govpayGuzzleOptions(): array
    {
        $options = [];
        $authMethod = strtolower(SettingsRepository::get('govpay', 'authentication_method', ''));
        if (in_array($authMethod, ['ssl', 'sslheader'], true)) {
            $cert    = SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key     = SettingsRepository::get('govpay', 'tls_key_path', '');
            $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
            if ($cert !== '' && $key !== '') {
                $options['cert']    = $cert;
                $options['ssl_key'] = ($keyPass !== null && $keyPass !== '') ? [$key, $keyPass] : $key;
            }
        }
        return $options;
    }

    private function govpayAuth(): ?array
    {
        $user = SettingsRepository::get('govpay', 'user', '');
        $pass = SettingsRepository::get('govpay', 'password', '');
        return ($user !== '' && $pass !== '') ? [$user, $pass] : null;
    }

    private function makePagamentiClient(): ?PagamentiPendenzeApi
    {
        if (!class_exists(PagamentiPendenzeApi::class)) {
            return null;
        }
        $url = rtrim((string)SettingsRepository::get('govpay', 'pagamenti_url', ''), '/');
        if ($url === '') {
            return null;
        }
        $config = new PagamentiConfiguration();
        $config->setHost($url);
        if ($auth = $this->govpayAuth()) {
            $config->setUsername($auth[0]);
            $config->setPassword($auth[1]);
        }
        return new PagamentiPendenzeApi(new Client($this->govpayGuzzleOptions()), $config);
    }

    private function makePendenzeClient(): ?\GovPay\Pendenze\Api\PendenzeApi
    {
        if (!class_exists(\GovPay\Pendenze\Api\PendenzeApi::class)) {
            return null;
        }
        $url = rtrim((string)SettingsRepository::get('govpay', 'pendenze_url', ''), '/');
        if ($url === '') {
            return null;
        }
        $config = new \GovPay\Pendenze\Configuration();
        $config->setHost($url);
        $guzzleOptions = $this->govpayGuzzleOptions();
        if ($auth = $this->govpayAuth()) {
            $guzzleOptions['auth'] = $auth;
        }
        return new \GovPay\Pendenze\Api\PendenzeApi(new Client($guzzleOptions), $config);
    }

    private function makeTransazioniClient(): ?\GovPay\Pendenze\Api\TransazioniApi
    {
        if (!class_exists(\GovPay\Pendenze\Api\TransazioniApi::class)) {
            return null;
        }
        $url = rtrim((string)SettingsRepository::get('govpay', 'pendenze_url', ''), '/');
        if ($url === '') {
            return null;
        }
        $config = new \GovPay\Pendenze\Configuration();
        $config->setHost($url);
        $guzzleOptions = $this->govpayGuzzleOptions();
        if ($auth = $this->govpayAuth()) {
            $guzzleOptions['auth'] = $auth;
        }
        return new \GovPay\Pendenze\Api\TransazioniApi(new Client($guzzleOptions), $config);
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
            'tipologie'           => $tipologie,
            'tipologie_esterne'   => $external,
            'id_dominio'          => $idDominio,
        ]);
    }

    // ── Endpoint: pendenza templates ─────────────────────────────────────────

    /**
     * GET /api/frontoffice/pendenza-templates
     * Restituisce i template pendenze del dominio.
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
            $repo = new PendenzaTemplateRepository();
            $templates = $repo->findAllByDominio($idDominio);
            return $this->jsonOk('OK', ['templates' => $templates]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('FrontofficeApi::getPendenzaTemplates errore', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore interno', 500);
        }
    }

    // ── Endpoint: trova pendenze per CF ──────────────────────────────────────

    /**
     * GET /api/frontoffice/pendenze
     * Parametri query: cf, page, per_page, stato
     */
    public function findPendenze(Request $request, Response $response): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $params       = $request->getQueryParams();
        $cf           = trim((string)($params['cf'] ?? ''));
        $page         = max(1, (int)($params['page'] ?? 1));
        $perPage      = min(100, max(1, (int)($params['per_page'] ?? 25)));
        $stato        = trim((string)($params['stato'] ?? ''));
        $numeroAvviso = trim((string)($params['numero_avviso'] ?? ''));
        $idDominio    = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $idA2A        = (string)SettingsRepository::get('entity', 'id_a2a', '');

        if ($cf === '' && $numeroAvviso === '') {
            return $this->jsonError('Parametro cf o numero_avviso mancante', 400);
        }

        $api = $this->makePagamentiClient();
        if ($api === null) {
            return $this->jsonError('Client GovPay Pagamenti non disponibile', 503);
        }

        $allowedStates = ['ESEGUITA', 'NON_ESEGUITA', 'ESEGUITA_PARZIALE', 'ANNULLATA', 'SCADUTA', 'ANOMALA'];
        $statoParam = in_array(strtoupper($stato), $allowedStates, true) ? strtoupper($stato) : null;

        try {
            $result = $api->findPendenze(
                $page,
                $perPage,
                null,
                $idDominio !== '' ? $idDominio : null,
                null,
                null,
                $numeroAvviso !== '' ? $numeroAvviso : null,
                $idA2A !== '' ? $idA2A : null,
                null,
                $cf !== '' ? $cf : null,
                $statoParam,
                null,
                null,
                null,
                false,
                true,
                true
            );
            $data = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            return $this->jsonOk('OK', ['data' => $data]);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::findPendenze errore', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore durante la ricerca pendenze', 503);
        }
    }

    // ── Endpoint: dettaglio pendenza ─────────────────────────────────────────

    /**
     * GET /api/frontoffice/pendenze/{idA2A}/{idPendenza}
     */
    public function getPendenza(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idA2A      = trim((string)($args['idA2A'] ?? ''));
        $idPendenza = trim((string)($args['idPendenza'] ?? ''));

        if ($idA2A === '' || $idPendenza === '') {
            return $this->jsonError('Parametri mancanti', 400);
        }

        $api = $this->makePagamentiClient();
        if ($api === null) {
            return $this->jsonError('Client GovPay Pagamenti non disponibile', 503);
        }

        try {
            $result = $api->getPendenza($idA2A, $idPendenza);
            $data   = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            return $this->jsonOk('OK', ['pendenza' => $data]);
        } catch (\InvalidArgumentException $e) {
            // Fallback raw su errore deserializzazione (tipoBollo con label invece di codice).
            $raw = $this->getPendenzaRaw($idA2A, $idPendenza);
            if ($raw !== null) {
                return $this->jsonOk('OK', ['pendenza' => $raw]);
            }
            return $this->jsonError('Pendenza non trovata', 404);
        } catch (ClientException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 404;
            return $this->jsonError('Pendenza non trovata', $status === 404 ? 404 : 503);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::getPendenza errore', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore durante il recupero pendenza', 503);
        }
    }

    private function getPendenzaRaw(string $idA2A, string $idPendenza): ?array
    {
        $url = rtrim((string)SettingsRepository::get('govpay', 'pagamenti_url', ''), '/');
        if ($url === '') {
            return null;
        }
        try {
            $guzzleOptions = $this->govpayGuzzleOptions();
            $guzzleOptions['headers']     = ['Accept' => 'application/json'];
            $guzzleOptions['http_errors'] = false;
            if ($auth = $this->govpayAuth()) {
                $guzzleOptions['auth'] = $auth;
            }
            $client   = new Client($guzzleOptions);
            $endpoint = $url . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);
            $resp     = $client->get($endpoint);
            if ($resp->getStatusCode() !== 200) {
                return null;
            }
            $data = json_decode((string)$resp->getBody(), true);
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Endpoint: pendenza per numero avviso ─────────────────────────────────

    /**
     * GET /api/frontoffice/pendenze/avviso/{idDominio}/{numeroAvviso}
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

        $api = $this->makePendenzeClient();
        if ($api === null) {
            return $this->jsonError('Client GovPay Pendenze non disponibile', 503);
        }

        try {
            $result = $api->getPendenzaByAvviso($idDominio, $numeroAvviso);
            $data   = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            return $this->jsonOk('OK', ['pendenza' => $data]);
        } catch (ClientException $e) {
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

    // ── Endpoint: crea pendenza spontanea ────────────────────────────────────

    /**
     * POST /api/frontoffice/pendenze
     *
     * Body JSON: {idTipoPendenza, idDominio, causale, importo, annoRiferimento,
     *             soggettoPagatore, dataValidita, dataScadenza, datiAllegati?}
     *
     * Il controller risolve voci (IBAN/contabilita da DB), genera idPendenza
     * (con iuv_prefix se configurato) e PUT su GovPay Backoffice.
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

        $backofficeUrl = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');
        $idA2A         = (string)SettingsRepository::get('entity', 'id_a2a', '');

        if ($backofficeUrl === '' || $idA2A === '') {
            return $this->jsonError('GovPay Backoffice URL o ID_A2A non configurati', 503);
        }

        // Usa voci pre-costruite (es. bollo) oppure risolvile da DB
        $voci = (isset($body['voci']) && is_array($body['voci']) && !empty($body['voci']))
            ? $body['voci']
            : $this->buildVoci($idDominio, $idTipo, $causale, $importo);

        // Risolvi iuv_prefix per generazione ID pendenza
        $iuvPrefix = null;
        try {
            $details   = (new EntrateRepository())->findDetails($idDominio, $idTipo);
            $iuvPrefix = ($details['iuv_prefix'] ?? null) ?: null;
        } catch (\Throwable) {
            // fallback a ID generico GIL-
        }

        // Genera idPendenza
        $idPendenza = $this->generatePendenzaId($iuvPrefix, $backofficeUrl, $idA2A);
        if ($idPendenza === null) {
            return $this->jsonError('Impossibile generare ID pendenza univoco', 503);
        }

        $payload = [
            'idTipoPendenza'  => $idTipo,
            'idDominio'       => $idDominio,
            'causale'         => $causale,
            'importo'         => $importo,
            'annoRiferimento' => isset($body['annoRiferimento']) ? (int)$body['annoRiferimento'] : (int)date('Y'),
            'soggettoPagatore'=> $body['soggettoPagatore'] ?? [],
            'voci'            => $voci,
            'dataValidita'    => $body['dataValidita'] ?? date('Y-m-d'),
            'dataScadenza'    => $body['dataScadenza'] ?? (new \DateTimeImmutable('today'))->modify('+15 days')->format('Y-m-d'),
        ];

        if (!empty($body['datiAllegati']) && is_array($body['datiAllegati'])) {
            $payload['datiAllegati'] = $body['datiAllegati'];
        }

        if ($iuvPrefix !== null) {
            $payload['numeroAvviso'] = $idPendenza;
        }

        $guzzleOptions = $this->govpayGuzzleOptions();
        $guzzleOptions['headers'] = ['Accept' => 'application/json', 'Connection' => 'close'];
        $guzzleOptions['json']    = $payload;
        if ($auth = $this->govpayAuth()) {
            $guzzleOptions['auth'] = $auth;
        }

        try {
            $client = new Client($guzzleOptions);
            $url    = $backofficeUrl . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);
            $resp   = $client->request('PUT', $url, $guzzleOptions);
            $data   = json_decode((string)$resp->getBody(), true);
            return $this->jsonOk('Pendenza creata', ['idPendenza' => $idPendenza, 'response' => $data]);
        } catch (ClientException $e) {
            $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            $msg  = $body !== '' ? $body : $e->getMessage();
            if ($body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && isset($decoded['dettaglio'])) {
                    $msg = $decoded['dettaglio'];
                }
            }
            Logger::getInstance()->error('FrontofficeApi::createPendenza errore GovPay', ['error' => $msg]);
            return $this->jsonError(Logger::sanitizeErrorForDisplay($msg), 422);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('FrontofficeApi::createPendenza errore inatteso', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore inatteso durante la creazione della pendenza', 503);
        }
    }

    private function buildVoci(string $idDominio, string $idTipo, string $descrizione, float $importo): array
    {
        $iban = $codCont = $tipoBollo = $tipoCont = '';
        try {
            if ($idDominio !== '' && $idTipo !== '') {
                $details = (new EntrateRepository())->findDetails($idDominio, $idTipo);
                if ($details) {
                    $iban     = (string)($details['iban_accredito'] ?? '');
                    $codCont  = (string)($details['codice_contabilita'] ?? '');
                    $rawTipoBollo = (string)($details['tipo_bollo'] ?? '');
                    $tipoBollo    = in_array($rawTipoBollo, ['01'], true) ? $rawTipoBollo : '';
                    $tipoCont = (string)($details['tipo_contabilita'] ?? '');
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
            $voice['ibanAccredito']    = $iban;
            $voice['tipoContabilita']  = $tipoCont;
            $voice['codiceContabilita']= $codCont;
        } else {
            $voice['codEntrata'] = substr(preg_replace('/[^A-Za-z0-9\-_.]/', '', $idTipo), 0, 35);
        }

        return [$voice];
    }

    private function generatePendenzaId(?string $iuvPrefix, string $backofficeUrl, string $idA2A): ?string
    {
        if ($iuvPrefix !== null) {
            $guzzleOptions = $this->govpayGuzzleOptions();
            $guzzleOptions['headers']     = ['Accept' => 'application/json'];
            $guzzleOptions['http_errors'] = false;
            if ($auth = $this->govpayAuth()) {
                $guzzleOptions['auth'] = $auth;
            }
            $client      = new Client($guzzleOptions);
            $maxAttempts = 10;
            for ($i = 0; $i < $maxAttempts; $i++) {
                $totalLen  = 18;
                $suffixLen = max(1, $totalLen - strlen($iuvPrefix));
                $datePart  = sprintf('%s%03d%s%s', date('y'), (int)date('z') + 1, date('H'), date('i'));
                if ($suffixLen >= 9) {
                    $rand      = '';
                    for ($j = 0; $j < $suffixLen - 9; $j++) {
                        $rand .= (string)random_int(0, 9);
                    }
                    $candidate = $iuvPrefix . $datePart . $rand;
                } else {
                    $suffix    = '';
                    for ($j = 0; $j < $suffixLen; $j++) {
                        $suffix .= (string)random_int(0, 9);
                    }
                    $candidate = $iuvPrefix . $suffix;
                }
                $chkUrl = $backofficeUrl . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($candidate);
                try {
                    $chkResp = $client->request('GET', $chkUrl, $guzzleOptions);
                    if ($chkResp->getStatusCode() !== 200) {
                        return $candidate;
                    }
                } catch (\Throwable) {
                    return $candidate;
                }
            }
            return null;
        }

        try {
            $rand      = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $rand = md5((string)microtime(true));
        }
        $candidate = 'GIL-' . substr($rand, 0, 16);
        return substr(preg_replace('/[^A-Za-z0-9\-_]/', '-', $candidate), 0, 35);
    }

    // ── Endpoint: transazioni pendenza ───────────────────────────────────────

    /**
     * GET /api/frontoffice/pendenze/{idA2A}/{idPendenza}/transazioni
     */
    public function getTransazioni(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idA2A      = trim((string)($args['idA2A'] ?? ''));
        $idPendenza = trim((string)($args['idPendenza'] ?? ''));
        $idDominio  = (string)SettingsRepository::get('entity', 'id_dominio', '');

        if ($idA2A === '' || $idPendenza === '') {
            return $this->jsonError('Parametri mancanti', 400);
        }

        $api = $this->makeTransazioniClient();
        if ($api === null) {
            return $this->jsonError('Client GovPay Transazioni non disponibile', 503);
        }

        try {
            $esito  = class_exists('\GovPay\Pendenze\Model\EsitoRpp')
                ? \GovPay\Pendenze\Model\EsitoRpp::ESEGUITO
                : 'ESEGUITO';
            $result = $api->findRpp(
                1, 25, null, null,
                $idDominio !== '' ? $idDominio : null,
                null, null,
                $idA2A !== '' ? $idA2A : null,
                $idPendenza,
                null, $esito
            );
            $data = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            return $this->jsonOk('OK', ['data' => $data]);
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::getTransazioni errore', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore durante il recupero transazioni', 503);
        }
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
     * Il frontoffice ha già risolto i dettagli pendenze e validato whitelist/stato.
     * Questo endpoint chiama solo pagoPA CheckoutEC e ritorna la location.
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

        $httpClient = new Client(['timeout' => 15, 'connect_timeout' => 10, 'allow_redirects' => false]);
        $api        = new \PagoPA\CheckoutEc\Api\DefaultApi($httpClient, $config);

        $companyName = trim((string)SettingsRepository::get('pagopa', 'checkout_company_name', ''));
        if ($companyName === '') {
            $companyName = trim((string)SettingsRepository::get('entity', 'name', 'Ente'));
            if ($companyName === '') {
                $companyName = 'Ente';
            }
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
                    'status'   => $statusCode,
                    'location' => $location,
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

    // ── Endpoint: streaming ricevuta PDF ─────────────────────────────────────

    /**
     * GET /api/frontoffice/ricevuta/{idDominio}/{iuv}/{idRicevuta}
     * Proxy binario verso GovPay Pagamenti — unico endpoint che non usa jsonResponse().
     */
    public function getRicevuta(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idDominio  = trim((string)($args['idDominio'] ?? ''));
        $iuv        = trim((string)($args['iuv'] ?? ''));
        $idRicevuta = trim((string)($args['idRicevuta'] ?? ''));
        $baseUrl    = rtrim((string)SettingsRepository::get('govpay', 'pagamenti_url', ''), '/');

        if ($baseUrl === '' || $idDominio === '' || $iuv === '' || $idRicevuta === '') {
            return $this->jsonError('Parametri mancanti', 400);
        }

        $guzzleOptions = $this->govpayGuzzleOptions();
        $guzzleOptions['headers'] = ['Accept' => 'application/pdf'];
        if ($auth = $this->govpayAuth()) {
            $guzzleOptions['auth'] = $auth;
        }

        try {
            $client   = new Client($guzzleOptions);
            $url      = $baseUrl . '/ricevute/' . rawurlencode($idDominio) . '/' . rawurlencode($iuv) . '/' . rawurlencode($idRicevuta);
            $govResp  = $client->request('GET', $url, $guzzleOptions);
            $status   = $govResp->getStatusCode();

            if ($status < 200 || $status >= 300) {
                return $this->jsonError('Ricevuta non disponibile', 404);
            }

            $contentType = strtolower(implode(' ', $govResp->getHeader('Content-Type')));
            if ($contentType !== '' && strpos($contentType, 'application/pdf') === false) {
                return $this->jsonError('Ricevuta non disponibile in formato PDF', 503);
            }

            $filename = 'ricevuta_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $iuv . '_' . $idRicevuta) . '.pdf';

            $resp = new SlimResponse(200);
            $resp->getBody()->write((string)$govResp->getBody());
            return $resp
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::getRicevuta errore', [
                'idDominio'  => $idDominio,
                'iuv'        => $iuv,
                'idRicevuta' => $idRicevuta,
                'error'      => Logger::sanitizeErrorForDisplay($e->getMessage()),
            ]);
            return $this->jsonError('Errore durante il download della ricevuta', 503);
        }
    }

    // ── Endpoint: avviso PDF ─────────────────────────────────────────────────

    /**
     * GET /api/frontoffice/avviso/{idDominio}/{numeroAvviso}
     * Proxy PDF avviso da GovPay Backoffice.
     */
    public function getAvvisoPdf(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $idDominio    = trim((string)($args['idDominio'] ?? ''));
        $numeroAvviso = trim((string)($args['numeroAvviso'] ?? ''));
        $backofficeUrl = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');

        if ($backofficeUrl === '' || $idDominio === '' || $numeroAvviso === '') {
            return $this->jsonError('Parametri o GOVPAY_BACKOFFICE_URL mancanti', 400);
        }

        return $this->proxyPdfFromGovpay(
            $backofficeUrl . '/avvisi/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroAvviso),
            'avviso-' . $idDominio . '-' . $numeroAvviso . '.pdf'
        );
    }

    // ── Endpoint: documento PDF ───────────────────────────────────────────────

    /**
     * GET /api/frontoffice/documento/{numeroDocumento}/avvisi
     * Proxy PDF documento (multi-rata) da GovPay Pendenze v2.
     */
    public function getDocumentoPdf(Request $request, Response $response, array $args): Response
    {
        if (!$this->verifyMasterToken($request)) {
            return $this->jsonError('Non autorizzato', 401);
        }

        $numeroDocumento = trim((string)($args['numeroDocumento'] ?? ''));
        $idDominio       = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $pendenzeUrl     = rtrim((string)SettingsRepository::get('govpay', 'pendenze_url', ''), '/');

        if ($pendenzeUrl === '' || $idDominio === '' || $numeroDocumento === '') {
            return $this->jsonError('Parametri o GOVPAY_PENDENZE_URL mancanti', 400);
        }

        return $this->proxyPdfFromGovpay(
            $pendenzeUrl . '/documenti/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroDocumento) . '/avvisi',
            'avvisi-' . rawurlencode($numeroDocumento) . '.pdf'
        );
    }

    private function proxyPdfFromGovpay(string $url, string $filename): Response
    {
        $guzzleOptions = $this->govpayGuzzleOptions();
        $guzzleOptions['headers'] = ['Accept' => 'application/pdf', 'Connection' => 'close'];
        if ($auth = $this->govpayAuth()) {
            $guzzleOptions['auth'] = $auth;
        }

        try {
            $govResp = (new Client($guzzleOptions))->request('GET', $url, $guzzleOptions);
            $status  = $govResp->getStatusCode();
            if ($status < 200 || $status >= 300) {
                return $this->jsonError('PDF non disponibile', $status === 404 ? 404 : 503);
            }
            $resp = new SlimResponse(200);
            $resp->getBody()->write((string)$govResp->getBody());
            return $resp
                ->withHeader('Content-Type', $govResp->getHeaderLine('Content-Type') ?: 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('FrontofficeApi::proxyPdfFromGovpay errore', ['url' => $url, 'error' => Logger::sanitizeErrorForDisplay($e->getMessage())]);
            return $this->jsonError('Errore durante il download del PDF', 503);
        }
    }

    // ── Endpoint: rate limiting ──────────────────────────────────────────────

    /**
     * POST /api/frontoffice/rate-limit/check
     *
     * Body JSON: {key: string, limit: int, window_sec?: int}
     * Il frontoffice passa X-Real-IP via header; la chiave bucket è opaca.
     *
     * Risponde: {success, allowed: bool, retry_after?: int}
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
            // Fail-open su parametri non validi
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

        // Garbage collection probabilistica
        if (mt_rand(1, 100) === 1) {
            try {
                $gc = $pdo->prepare('DELETE FROM rate_limit_buckets WHERE window_start < :t');
                $gc->execute([':t' => $now - max(600, $windowSec * 10)]);
            } catch (\Throwable) {
                // Best-effort
            }
        }

        $allowed = $count <= $limit;
        return $this->jsonOk('OK', [
            'allowed'     => $allowed,
            'retry_after' => $allowed ? null : $windowSec,
        ]);
    }
}
