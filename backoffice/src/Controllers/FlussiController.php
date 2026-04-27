<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Controller per la ricerca dei flussi di rendicontazione.
 * Utilizza l'endpoint Backoffice /flussiRendicontazione per ottenere l'elenco paginato.
 */
class FlussiController
{
    public function __construct(private readonly Twig $twig) {}

    public function search(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();

        $params = (array)($request->getQueryParams() ?? []);
        $errors = [];
        $highlightId = isset($params['highlight']) ? (string)$params['highlight'] : null;
        $filters = [
            'q' => isset($params['q']) ? (string)$params['q'] : null,
            'pagina' => max(1, (int)($params['pagina'] ?? 1)),
            'risultatiPerPagina' => min(200, max(1, (int)($params['risultatiPerPagina'] ?? 25))),
            'ordinamento' => (string)($params['ordinamento'] ?? '-data'),
            'idDominio' => (string)($params['idDominio'] ?? (SettingsRepository::get('entity', 'id_dominio', ''))),
            'idA2A' => (string)($params['idA2A'] ?? (SettingsRepository::get('entity', 'id_a2a', ''))),
            'idFlusso' => (string)($params['idFlusso'] ?? ''),
            'statoFlusso' => (string)($params['statoFlusso'] ?? ''),
            'dataDa' => (string)($params['dataDa'] ?? ''),
            'dataA' => (string)($params['dataA'] ?? ''),
            'iuv' => (string)($params['iuv'] ?? ''),
            'incassato' => (string)($params['incassato'] ?? ''),
            'escludiObsoleti' => (string)($params['escludiObsoleti'] ?? ''),
        ];
        $orderValue = $filters['ordinamento'];
        if (!in_array($orderValue, ['+data', '-data'], true)) {
            $orderValue = '-data';
        }
        $filters['ordinamento'] = $orderValue;
        $orderField = 'data';
        $orderDirection = $orderValue[0] === '-' ? 'desc' : 'asc';

        $results = null;
        $numPagine = null;
        $numRisultati = null;
        $queryMade = false;
        $prevUrl = null;
        $nextUrl = null;

        if (($filters['q'] ?? null) !== null) {
            $queryMade = true;
            $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
            if ($backofficeUrl === '') {
                $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
            } else {
                try {
                    $http = $this->makeHttpClient();
                    $pagina = $filters['pagina'];
                    $rpp = $filters['risultatiPerPagina'];
                    $orderParam = $filters['ordinamento'] === '-data' ? null : $filters['ordinamento'];
                    $query = [
                        'pagina' => $pagina,
                        'risultatiPerPagina' => $rpp,
                        'ordinamento' => $orderParam,
                        'idDominio' => $filters['idDominio'] ?: null,
                        'idA2A' => $filters['idA2A'] ?: null,
                        'idFlusso' => $filters['idFlusso'] ?: null,
                        'dataDa' => $filters['dataDa'] ?: null,
                        'dataA' => $filters['dataA'] ?: null,
                        'statoFlussoRendicontazione' => $filters['statoFlusso'] ?: null,
                        'iuv' => $filters['iuv'] ?: null,
                        'incassato' => $filters['incassato'] ?: null,
                        'escludiObsoleti' => $filters['escludiObsoleti'] ?: null,
                        'metadatiPaginazione' => 'true',
                        'maxRisultati' => 'true',
                    ];
                    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');

                    $username = SettingsRepository::get('govpay', 'user', '');
                    $password = SettingsRepository::get('govpay', 'password', '');

                    $options = [
                        'headers' => ['Accept' => 'application/json'],
                        'query' => $query,
                    ];
                    if ($username && $password) {
                        $options['auth'] = [$username, $password];
                    }

                    $url = rtrim($backofficeUrl, '/') . '/flussiRendicontazione';
                    if (\App\Config\SettingsRepository::get('app', 'debug', 'false') === 'true' && $filters['q']) {
                        error_log('[FlussiController] GET ' . $url . '?' . http_build_query($query));
                        if ($filters['statoFlusso']) {
                            error_log('[FlussiController] statoFlussoRendicontazione filter = ' . $filters['statoFlusso']);
                        }
                    }

                    $resp = $http->request('GET', $url, $options);
                    $json = (string)$resp->getBody();
                    $dataArr = json_decode($json, true);
                    if (!is_array($dataArr)) {
                        throw new \RuntimeException('Parsing JSON fallito');
                    }

                    $extractInt = static function(array $src, array $paths): ?int {
                        foreach ($paths as $path) {
                            $cursor = $src;
                            foreach (explode('.', $path) as $segment) {
                                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                                    continue 2;
                                }
                                $cursor = $cursor[$segment];
                            }
                            if ($cursor !== null && $cursor !== '') {
                                return (int)$cursor;
                            }
                        }
                        return null;
                    };

                    $numPagine = $extractInt($dataArr, ['numPagine', 'num_pagine', 'metadatiPaginazione.numPagine', 'metadatiPaginazione.num_pagine']);
                    $numRisultati = $extractInt($dataArr, ['numRisultati', 'num_risultati', 'metadatiPaginazione.numRisultati', 'metadatiPaginazione.num_risultati']);
                    if ($numPagine === null && $numRisultati !== null && $rpp > 0) {
                        $numPagine = (int)ceil($numRisultati / $rpp);
                    }

                    $results = $dataArr;

                    if (isset($results['risultati']) && is_array($results['risultati'])) {
                        $directionMul = $orderDirection === 'desc' ? -1 : 1;
                        $extractDate = static function (array $item): ?int {
                            foreach (['data', 'dataFlusso', 'data_flusso', 'dataRegolamento', 'data_regolamento'] as $key) {
                                if (isset($item[$key]) && $item[$key] !== '') {
                                    $ts = strtotime((string)$item[$key]);
                                    if ($ts !== false) {
                                        return $ts;
                                    }
                                }
                            }
                            return null;
                        };
                        usort($results['risultati'], static function ($left, $right) use ($directionMul, $extractDate) {
                            $a = is_array($left) ? $extractDate($left) : null;
                            $b = is_array($right) ? $extractDate($right) : null;
                            if ($a === $b) {
                                return 0;
                            }
                            if ($a === null) {
                                return 1;
                            }
                            if ($b === null) {
                                return -1;
                            }
                            return ($a <=> $b) * $directionMul;
                        });
                    }

                    $basePath = $request->getUri()->getPath();
                    $qsBase = $params;
                    $qsBase['q'] = '1';
                    $qsBase['ordinamento'] = $filters['ordinamento'];
                    $qsBase['pagina'] = $filters['pagina'];
                    $qsBase['risultatiPerPagina'] = $filters['risultatiPerPagina'];
                    unset($qsBase['highlight']);
                    $buildUrl = static fn(array $payload) => $basePath . '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986);

                    if ($filters['pagina'] > 1) {
                        $prev = $qsBase;
                        $prev['pagina'] = $filters['pagina'] - 1;
                        $prevUrl = $buildUrl($prev);
                    }
                    if ($numPagine !== null && $filters['pagina'] < $numPagine) {
                        $next = $qsBase;
                        $next['pagina'] = $filters['pagina'] + 1;
                        $nextUrl = $buildUrl($next);
                    }
                } catch (ClientException $ce) {
                    $errors[] = 'Errore chiamata Flussi: ' . $ce->getMessage();
                    $detailBody = $ce->getResponse() ? (string)$ce->getResponse()->getBody() : '';
                    if ($detailBody !== '') {
                        $errors[] = 'Dettaglio API: ' . $detailBody;
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore chiamata Flussi: ' . $e->getMessage();
                }
            }
        }

        $qsClean = $params;
        unset($qsClean['highlight']);
        $qsCurrent = http_build_query($qsClean, '', '&', PHP_QUERY_RFC3986);
        $returnUrl = '/pagamenti/ricerca-flussi' . ($qsCurrent ? ('?' . $qsCurrent) : '');

        if ($queryMade) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $_SESSION['flussi_last_search'] = [
                'return_url' => $returnUrl,
                'query_params' => $qsClean,
                'updated_at' => time(),
            ];
        }

        return $this->twig->render($response, 'pagamenti/ricerca_flussi.html.twig', [
            'filters' => $filters,
            'errors' => $errors,
            'results' => $results,
            'num_pagine' => $numPagine,
            'num_risultati' => $numRisultati,
            'query_made' => $queryMade,
            'prev_url' => $prevUrl,
            'next_url' => $nextUrl,
            'return_url' => $returnUrl,
            'highlight_id' => $highlightId,
            'order_field' => $orderField,
            'order_direction' => $orderDirection,
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $this->exposeCurrentUser();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $lastSearch = $_SESSION['flussi_last_search'] ?? null;

        $errors = [];
        $idFlusso = isset($args['idFlusso']) ? (string)$args['idFlusso'] : '';
        if ($idFlusso === '') {
            $errors[] = "ID flusso mancante.";
        }

        $flow = null;
        if (!$errors) {
            $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
            if ($backofficeUrl === '') {
                $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
            } else {
                try {
                    $http = $this->makeHttpClient();
                    $username = SettingsRepository::get('govpay', 'user', '');
                    $password = SettingsRepository::get('govpay', 'password', '');

                    $options = [
                        'headers' => ['Accept' => 'application/json'],
                    ];
                    if ($username && $password) {
                        $options['auth'] = [$username, $password];
                    }

                    $endpoint = sprintf('%s/flussiRendicontazione/%s', rtrim($backofficeUrl, '/'), rawurlencode($idFlusso));
                    if (\App\Config\SettingsRepository::get('app', 'debug', 'false') === 'true') {
                        error_log('[FlussiController] GET ' . $endpoint);
                    }

                    $resp = $http->request('GET', $endpoint, $options);
                    $status = $resp->getStatusCode();
                    if (!in_array($status, [200, 201], true)) {
                        throw new \RuntimeException('Risposta inattesa dal servizio: ' . $status);
                    }

                    $json = (string)$resp->getBody();
                    $dataArr = json_decode($json, true);
                    if (!is_array($dataArr)) {
                        throw new \RuntimeException('Parsing JSON fallito');
                    }

                    $flow = $dataArr;

                    // Mark orphan payments (no local voce data) so the template
                    // can offer an on-demand "load receipt" button via AJAX.
                    $paymentsKey = isset($flow['rendicontazioni']) ? 'rendicontazioni' : (isset($flow['pagamenti']) ? 'pagamenti' : null);
                    if ($paymentsKey && is_array($flow[$paymentsKey])) {
                        $fiscalCode = $flow['idDominio']
                            ?? ($flow['dominio']['idDominio'] ?? null)
                            ?? ($flow['dominio']['id'] ?? null)
                            ?? '';
                        foreach ($flow[$paymentsKey] as $index => $payment) {
                            $risc = $payment['riscossione'] ?? null;
                            $hasVoce = !empty($payment['voce'])
                                || !empty($payment['vocePendenza'])
                                || (!empty($risc) && !empty($risc['vocePendenza']));
                            $iuv = $payment['iuv'] ?? ($risc['iuv'] ?? '');
                            $iur = $payment['iur'] ?? ($risc['iur'] ?? '');
                            if (!$hasVoce && $iuv !== '' && $iur !== '') {
                                $flow[$paymentsKey][$index]['is_orphan'] = true;
                                $flow[$paymentsKey][$index]['_fc'] = $fiscalCode;
                            }
                        }
                    }
                } catch (ClientException $ce) {
                    $errors[] = 'Errore recupero dettaglio flusso: ' . $ce->getMessage();
                    $detailBody = $ce->getResponse() ? (string)$ce->getResponse()->getBody() : '';
                    if ($detailBody !== '') {
                        $errors[] = 'Dettaglio API: ' . $detailBody;
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore recupero dettaglio flusso: ' . $e->getMessage();
                }
            }
        }

        $allowedReturnPrefixes = ['/pagamenti/ricerca-flussi'];
        $sanitizeReturn = static function ($candidate) use ($allowedReturnPrefixes): ?string {
            if (!is_string($candidate) || $candidate === '') {
                return null;
            }
            $decoded = rawurldecode($candidate);
            if ($decoded === '' || $decoded[0] !== '/') {
                return null;
            }
            foreach ($allowedReturnPrefixes as $prefix) {
                if (strncmp($decoded, $prefix, strlen($prefix)) === 0) {
                    return $decoded;
                }
            }
            return null;
        };

        $returnCandidate = $request->getQueryParams()['return'] ?? null;
        $return = $sanitizeReturn($returnCandidate);
        if ($return === null && is_array($lastSearch) && isset($lastSearch['return_url'])) {
            $return = $sanitizeReturn((string)$lastSearch['return_url']);
        }
        if ($return === null) {
            $return = '/pagamenti/ricerca-flussi';
        }

        return $this->twig->render($response, 'pagamenti/dettaglio_flusso.html.twig', [
            'errors' => $errors,
            'flusso' => $flow,
            'id_flusso' => $idFlusso,
            'return_url' => $return,
        ]);
    }

    /**
     * AJAX endpoint: fetch a single receipt from Biz Events API on-demand.
     * GET /api/biz-event?fc={fiscalCode}&iur={iur}&iuv={iuv}
     */
    public function fetchBizEvent(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $fc  = $params['fc']  ?? '';
        $iur = $params['iur'] ?? '';
        $iuv = $params['iuv'] ?? '';

        if ($fc === '' || $iur === '' || $iuv === '') {
            return $this->jsonResponse($response, ['error' => 'Parametri mancanti (fc, iur, iuv)'], 400);
        }

        $bizHost = rtrim(SettingsRepository::get('pagopa', 'biz_events_host', ''), '/');
        $bizApiKey = SettingsRepository::get('pagopa', 'biz_events_api_key', '');

        if (!$bizHost || !$bizApiKey) {
            return $this->jsonResponse($response, ['error' => 'BIZ_EVENTS_HOST o BIZ_EVENTS_API_KEY non configurati'], 500);
        }

        try {
            // Configuration
            $config = \PagoPA\BizEvents\Configuration::getDefaultConfiguration()
                ->setHost($bizHost)
                ->setApiKey('Ocp-Apim-Subscription-Key', $bizApiKey);

            // Instantiate API Client
            $apiInstance = new \PagoPA\BizEvents\Api\PaymentReceiptsRESTAPIsApi(
                new \GuzzleHttp\Client(['connect_timeout' => 5, 'timeout' => 15]),
                $config
            );

            // Call API
            try {
                $receipt = $apiInstance->getOrganizationReceiptIuvIur($fc, $iur, $iuv);
            } catch (\PagoPA\BizEvents\ApiException $e) {
                $statusCode = $e->getCode();
                if ($statusCode === 404) {
                    return $this->jsonResponse($response, ['error' => 'Ricevuta non trovata per questo IUV/IUR.'], 404);
                }
                if ($statusCode === 429) {
                    return $this->jsonResponse($response, ['error' => 'Rate limit superato. Riprova tra qualche secondo.', 'retry' => true], 429);
                }
                return $this->jsonResponse($response, ['error' => "Errore API: HTTP $statusCode", 'body' => $e->getResponseBody()], $statusCode ?: 500);
            }

            // Extract data from model
            $debtor = $receipt->getDebtor();
            $payer = $receipt->getPayer();
            $transferList = $receipt->getTransferList() ?? [];

            // Process transfers
            $transfers = [];
            $totalAmount = 0;
            foreach ($transferList as $tr) {
                /** @var \PagoPA\BizEvents\Model\TransferPA $tr */
                $trAmount = $tr->getTransferAmount() ?? 0.0;
                $totalAmount += $trAmount;
                $transfers[] = [
                    'amount'      => $trAmount,
                    'fiscal_code' => $tr->getFiscalCodePa() ?? '',
                    'iban'        => $tr->getIban() ?? '',
                    'description' => $tr->getRemittanceInformation() ?? '',
                    'category'    => $tr->getTransferCategory() ?? '',
                    'company'     => '', // companyName not directly in TransferPA, usually extracted from other sources or mapped
                ];
            }

            // Fallback total amount
            if ($totalAmount == 0) {
                $totalAmount = $receipt->getPaymentAmount() ?? 0.0;
            }

            $result = [
                'description'        => $receipt->getDescription() ?? '',
                'amount'             => $receipt->getPaymentAmount() ?? 0.0,
                'total_amount'       => $totalAmount,
                'company_name'       => $receipt->getCompanyName() ?? '',
                'office_name'        => $receipt->getOfficeName() ?? '',
                'debtor_name'        => $debtor ? $debtor->getFullName() : '',
                'debtor_fiscal_code' => $debtor ? $debtor->getEntityUniqueIdentifierValue() : '',
                'debtor_type'        => $debtor ? $debtor->getEntityUniqueIdentifierType() : '',
                'payer_name'         => $payer ? $payer->getFullName() : '',
                'payer_fiscal_code'  => $payer ? $payer->getEntityUniqueIdentifierValue() : '',
                'psp_id'             => $receipt->getIdPsp() ?? '',
                'psp_name'           => $receipt->getPspCompanyName() ?? '',
                'channel'            => $receipt->getChannelDescription() ?? $receipt->getIdChannel() ?? '',
                'payment_method'     => $receipt->getPaymentMethod() ?? '',
                'payment_date'       => $receipt->getPaymentDateTime() ? $receipt->getPaymentDateTime()->format('Y-m-d H:i:s') : '',
                'outcome'            => $receipt->getOutcome() ?? '',
                'receipt_id'         => $receipt->getReceiptId() ?? '',
                'notice_number'      => $receipt->getNoticeNumber() ?? '',
                'transfers'          => $transfers,
            ];

            return $this->jsonResponse($response, $result);

        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['error' => 'Errore: ' . $e->getMessage()], 500);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response = $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }

    private function exposeCurrentUser(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (isset($_SESSION['user'])) {
            $this->twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }
    }

    private function makeHttpClient(): Client
    {
        $guzzleOptions = [];
        $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
        if (in_array(strtolower($authMethod), ['ssl', 'sslheader'], true)) {
            $cert    = SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key     = SettingsRepository::get('govpay', 'tls_key_path', '');
            $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert'] = $cert;
                $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            }
        }
        return new Client($guzzleOptions);
    }
}
