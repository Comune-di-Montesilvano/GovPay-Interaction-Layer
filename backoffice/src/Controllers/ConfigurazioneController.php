<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\UserRepository;
use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\EntrateRepository;
use App\Database\ExternalPaymentTypeRepository;
use App\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ConfigurazioneController
{
    private UserRepository $userRepository;

    public function __construct(private readonly Twig $twig)
    {
        $this->userRepository = new UserRepository();
    }

    public function index(Request $request, Response $response): Response
    {
        $currentUser = $_SESSION['user'] ?? null;
        $role = $currentUser['role'] ?? null;
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato: permessi insufficienti'];
            return $response->withHeader('Location', '/')->withStatus(302);
        }

        if ($currentUser) {
            $this->twig->getEnvironment()->addGlobal('current_user', $currentUser);
        }

        $canEditConfig = $role === 'superadmin';
        $canManageUsers = in_array($role, ['admin', 'superadmin'], true);

        $errors = [];
        $cfgJson = null;
        $cfgArr = null;
        $appsJson = null;
        $appsArr = null;
        $appJson = null;
        $appArr = null;
        $profiloJson = null;
        $entrateJson = null;
        $entrateArr = null;
        $entrateSource = null;
        $pagamentiProfiloJson = null;
        $infoJson = null;
        $infoArr = null;
        $dominioJson = null;
        $dominioArr = null;
        $ruoliApiJson = null;
        $ruoliApiStatus = null;
        $ruoliApiError = null;
        $ruoliApiCount = null;
        $operatoriJson = null;
        $operatoriArr = null;
        $idDominio = null;

        $params = $request->getQueryParams();
        $tab = $params['tab'] ?? 'principali';
        $tassonomieTree = [];
        $tassonomieStats = null;
        $tassonomieError = null;
        $tassonomieRaw = null;
        $tassonomieFilteredStats = null;
        $tassonomieUrl = SettingsRepository::get('pagopa', 'tassonomie_url') ?: null;
        $tassonomieSearch = trim((string)($params['tq'] ?? ''));

        $normalizeTipologiaCode = static function (?string $code): ?string {
            if ($code === null) {
                return null;
            }
            $code = trim($code);
            if ($code === '') {
                return null;
            }
            if (str_contains($code, '/')) {
                $parts = array_values(array_filter(explode('/', $code), static fn($segment) => $segment !== null && $segment !== ''));
                if (!empty($parts)) {
                    $code = (string)end($parts);
                }
            }
            $sanitized = preg_replace('/[^A-Za-z0-9\-_.]/', '', $code);
            if (!is_string($sanitized) || $sanitized === '') {
                return null;
            }
            return mb_strtoupper($sanitized, 'UTF-8');
        };

        $tipologieCodici = [];
        $rawUpperCode = static function (?string $code): ?string {
            if ($code === null) {
                return null;
            }
            $code = trim($code);
            if ($code === '') {
                return null;
            }
            return mb_strtoupper($code, 'UTF-8');
        };

        $registerTipologiaCode = static function (?string $code) use (&$tipologieCodici, $normalizeTipologiaCode, $rawUpperCode): void {
            $normalized = $normalizeTipologiaCode($code);
            if ($normalized !== null) {
                $tipologieCodici[$normalized] = true;
            }

            $raw = $rawUpperCode($code);
            if ($raw !== null) {
                $tipologieCodici[$raw] = true;
            }
        };

        $extractValue = static function (?array $source, array $candidateKeys): ?string {
            if (!is_array($source)) {
                return null;
            }
            foreach ($candidateKeys as $candidate) {
                if (array_key_exists($candidate, $source) && $source[$candidate] !== null && $source[$candidate] !== '') {
                    return (string)$source[$candidate];
                }
            }
            return null;
        };

        $operatoriPage = isset($params['operatori_pagina']) ? (int)$params['operatori_pagina'] : 1;
        if ($operatoriPage < 1) {
            $operatoriPage = 1;
        }

        $operatoriPerPage = isset($params['operatori_rpp']) ? (int)$params['operatori_rpp'] : 25;
        if ($operatoriPerPage < 1) {
            $operatoriPerPage = 25;
        } elseif ($operatoriPerPage > 200) {
            $operatoriPerPage = 200;
        }

        $operatoriPagination = [
            'page' => $operatoriPage,
            'perPage' => $operatoriPerPage,
            'totalPages' => null,
            'totalResults' => null,
            'hasPrev' => false,
            'hasNext' => false,
            'prevUrl' => null,
            'nextUrl' => null,
        ];
        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');

        if (class_exists('\\GovPay\\Backoffice\\Api\\ConfigurazioniApi')) {
            if (!empty($backofficeUrl)) {
                try {
                    $config = new \GovPay\Backoffice\Configuration();
                    $config->setHost(rtrim($backofficeUrl, '/'));

                    $username = SettingsRepository::get('govpay', 'user', '');
                    $password = SettingsRepository::get('govpay', 'password', '');
                    if ($username !== '' && $password !== '') {
                        $config->setUsername($username);
                        $config->setPassword($password);
                    }

                    $guzzleOptions = [];
                    $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
                    if (in_array(strtolower((string)$authMethod), ['ssl', 'sslheader'], true)) {
                        $cert = SettingsRepository::get('govpay', 'tls_cert_path', '');
                        $key = SettingsRepository::get('govpay', 'tls_key_path', '');
                        $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
                        
                        if (!empty($cert) && !empty($key)) {
                            $guzzleOptions['cert'] = $cert;
                            $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                        } else {
                            $errors[] = 'mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati';
                        }
                    }

                    $httpClient = new \GuzzleHttp\Client($guzzleOptions);

                    $api = new \GovPay\Backoffice\Api\ConfigurazioniApi($httpClient, $config);
                    $result = $api->getConfigurazioni();
                    $data = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($result);
                    $cfgJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $cfgArr = $data;

                    try {
                        $appApi = new \GovPay\Backoffice\Api\ApplicazioniApi($httpClient, $config);
                        $apps = $appApi->findApplicazioni(1, 100, '+idA2A', null, null, null, null, true, true);
                        $appsData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($apps);
                        $appsArr = is_array($appsData)
                            ? $appsData
                            : (json_decode(json_encode($appsData, JSON_UNESCAPED_SLASHES), true) ?: []);

                        $idA2A = SettingsRepository::get('entity', 'id_a2a', '');

                        $appsJson = json_encode($appsArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                        if ($idA2A !== '') {
                            try {
                                $appDet = $appApi->getApplicazione($idA2A);
                                $appDetData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($appDet);
                                $appJson = json_encode($appDetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                $appArr = json_decode($appJson, true);
                                if (!is_array($appArr)) {
                                    $appArr = is_array($appDetData) ? $appDetData : [];
                                }
                            } catch (\Throwable $e) {
                                $errors[] = 'Errore lettura applicazione ' . $idA2A . ': ' . $e->getMessage();
                            }
                        }
                    } catch (\Throwable $e) {
                        $errors[] = 'Errore lettura applicazioni: ' . $e->getMessage();
                    }

                    try {
                        if (class_exists('GovPay\\Backoffice\\Api\\RuoliApi')) {
                            $ruoliApi = new \GovPay\Backoffice\Api\RuoliApi($httpClient, $config);
                            $ruoliResponse = $ruoliApi->findRuoli(1, 200, true);
                            $ruoliData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($ruoliResponse);
                            $ruoliApiJson = json_encode($ruoliData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            if (is_array($ruoliData)) {
                                $results = $ruoliData['risultati'] ?? null;
                                if (is_array($results)) {
                                    $ruoliApiCount = count($results);
                                } elseif (!empty($ruoliData)) {
                                    $ruoliApiCount = count($ruoliData);
                                }
                            }
                            $ruoliApiStatus = 'ok';
                        } else {
                            $ruoliApiStatus = 'missing-client';
                            $ruoliApiError = 'Client Backoffice Ruoli non disponibile';
                        }
                        // Operatori (lista)
                        if (class_exists('GovPay\\Backoffice\\Api\\OperatoriApi')) {
                                $operatoriApi = new \GovPay\Backoffice\Api\OperatoriApi($httpClient, $config);
                                $operRes = $operatoriApi->findOperatori($operatoriPagination['page'], $operatoriPagination['perPage'], '+ragioneSociale', null, null, true, true);
                                $operData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($operRes);
                                $operatoriJson = json_encode($operData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                $operatoriArr = is_array($operData)
                                    ? $operData
                                    : (json_decode(json_encode($operData, JSON_UNESCAPED_SLASHES), true) ?: []);

                                if (is_array($operatoriArr)) {
                                    $extractInt = static function (array $src, array $paths): ?int {
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

                                    $pageFromResponse = $extractInt($operatoriArr, ['pagina', 'page']);
                                    if ($pageFromResponse !== null && $pageFromResponse > 0) {
                                        $operatoriPagination['page'] = $pageFromResponse;
                                    }

                                    $perPageFromResponse = $extractInt($operatoriArr, ['risultatiPerPagina', 'risultati_per_pagina']);
                                    if ($perPageFromResponse !== null && $perPageFromResponse > 0) {
                                        $operatoriPagination['perPage'] = $perPageFromResponse;
                                    }

                                    $operatoriPagination['totalPages'] = $extractInt($operatoriArr, [
                                        'numPagine',
                                        'num_pagine',
                                        'metadatiPaginazione.numPagine',
                                        'metadatiPaginazione.num_pagine',
                                    ]);
                                    $operatoriPagination['totalResults'] = $extractInt($operatoriArr, [
                                        'numRisultati',
                                        'num_risultati',
                                        'metadatiPaginazione.numRisultati',
                                        'metadatiPaginazione.num_risultati',
                                    ]);
                                    if ($operatoriPagination['totalPages'] === null
                                        && $operatoriPagination['totalResults'] !== null
                                        && $operatoriPagination['perPage'] > 0) {
                                        $operatoriPagination['totalPages'] = (int)ceil($operatoriPagination['totalResults'] / $operatoriPagination['perPage']);
                                    }

                                    $hasPrev = $operatoriPagination['page'] > 1;
                                    $hasNext = false;
                                    if ($operatoriPagination['totalPages'] !== null) {
                                        $hasNext = $operatoriPagination['page'] < $operatoriPagination['totalPages'];
                                    } elseif ($operatoriPagination['perPage'] > 0 && isset($operatoriArr['risultati']) && is_array($operatoriArr['risultati'])) {
                                        $hasNext = count($operatoriArr['risultati']) >= $operatoriPagination['perPage'];
                                    }

                                    $buildOperatoriUrl = static function (Request $req, array $overrides): string {
                                        $qs = $req->getQueryParams();
                                        $qs['tab'] = 'operatori';
                                        foreach ($overrides as $key => $value) {
                                            if ($value === null) {
                                                unset($qs[$key]);
                                            } else {
                                                $qs[$key] = $value;
                                            }
                                        }
                                        return $req->getUri()->getPath() . '?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
                                    };

                                    if ($hasPrev) {
                                        $operatoriPagination['prevUrl'] = $buildOperatoriUrl($request, [
                                            'operatori_pagina' => $operatoriPagination['page'] - 1,
                                            'operatori_rpp' => $operatoriPagination['perPage'],
                                        ]);
                                    }
                                    if ($hasNext) {
                                        $operatoriPagination['nextUrl'] = $buildOperatoriUrl($request, [
                                            'operatori_pagina' => $operatoriPagination['page'] + 1,
                                            'operatori_rpp' => $operatoriPagination['perPage'],
                                        ]);
                                    }

                                    $operatoriPagination['hasPrev'] = $hasPrev;
                                    $operatoriPagination['hasNext'] = $hasNext;
                                }
                        }
                    } catch (\Throwable $e) {
                        $ruoliApiStatus = 'error';
                        $ruoliApiError = $e->getMessage();
                    }

                    try {
                        if (class_exists('GovPay\\Backoffice\\Api\\EntiCreditoriApi')) {
                            $entrApi = new \GovPay\Backoffice\Api\EntiCreditoriApi($httpClient, $config);
                            $idDominioEnv = SettingsRepository::get('entity', 'id_dominio', '');
                            $entrateSource = '/entrate';
                            if ($idDominioEnv !== '') {
                                $idDominio = trim((string)$idDominioEnv);
                                $entrRes = $entrApi->findEntrateDominio($idDominio, 1, 200, '+idEntrata', null, null, null, true, true);
                                $entrateSource = '/domini/' . $idDominio . '/entrate';
                            } else {
                                $entrRes = $entrApi->findEntrate(1, 200, '+idEntrata', null, true, true);
                                $entrateSource = '/entrate';
                            }
                            $entrData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($entrRes);
                            $entrateJson = json_encode($entrData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            $entrateArr = json_decode($entrateJson, true);
                            if (!is_array($entrateArr)) {
                                $entrateArr = [];
                            }

                            $entrateRows = $entrateArr['risultati'] ?? [];
                            if (!is_array($entrateRows)) {
                                $entrateRows = [];
                            }
                            foreach ($entrateRows as $row) {
                                if (!is_array($row)) {
                                    continue;
                                }
                                $registerTipologiaCode($extractValue($row, ['codiceContabilita', 'codice_contabilita']));
                                $registerTipologiaCode($extractValue($row, ['idEntrata', 'id_entrata']));

                                $tipoEntrataPayload = null;
                                if (isset($row['tipoEntrata']) && is_array($row['tipoEntrata'])) {
                                    $tipoEntrataPayload = $row['tipoEntrata'];
                                } elseif (isset($row['tipo_entrata']) && is_array($row['tipo_entrata'])) {
                                    $tipoEntrataPayload = $row['tipo_entrata'];
                                }

                                if ($tipoEntrataPayload !== null) {
                                    $registerTipologiaCode($extractValue($tipoEntrataPayload, ['codiceContabilita', 'codice_contabilita']));
                                    $registerTipologiaCode($extractValue($tipoEntrataPayload, ['idEntrata', 'id_entrata']));
                                }
                            }

                            if (!empty($idDominioEnv ?? '')) {
                                try {
                                    $repoEntr = new EntrateRepository();
                                    $rows = $entrateRows;
                                    if (isset($idDominio)) {
                                        foreach ($rows as $row) {
                                            $repoEntr->upsertFromBackoffice($idDominio, $row);
                                        }
                                        $entrateEff = $repoEntr->listByDominio($idDominio);
                                        $boMap = [];
                                        $ovrMap = [];
                                        $urlMap = [];
                                        $descrMap = [];
                                        $descrEstesaMap = [];
                                        $descrEffMap = [];
                                        foreach ($entrateEff as $r) {
                                            $idE = $r['id_entrata'];
                                            $boMap[$idE] = (int)$r['abilitato_backoffice'] === 1;
                                            $ovrMap[$idE] = isset($r['override_locale']) ? ((int)$r['override_locale'] === 1 ? 1 : 0) : null;
                                            $urlMap[$idE] = $r['external_url'] ?? null;
                                            $descrMap[$idE] = $r['descrizione_locale'] ?? null;
                                            $descrEstesaMap[$idE] = $r['descrizione_estesa'] ?? null;
                                            $descrEffMap[$idE] = $r['descrizione_effettiva'] ?? ($r['descrizione'] ?? null);
                                            $registerTipologiaCode($extractValue($r, ['codice_contabilita', 'codiceContabilita']));
                                            $registerTipologiaCode($extractValue($r, ['id_entrata', 'idEntrata']));
                                        }
                                        $entrateArr['_bo_map'] = $boMap;
                                        $entrateArr['_override_map'] = $ovrMap;
                                        $entrateArr['_exturl_map'] = $urlMap;
                                        $entrateArr['_descr_map'] = $descrMap;
                                        $entrateArr['_descr_estesa_map'] = $descrEstesaMap;
                                        $entrateArr['_descr_eff_map'] = $descrEffMap;
                                    }
                                } catch (\Throwable $e) {
                                    $errors[] = 'Sync DB entrate fallito: ' . $e->getMessage();
                                }
                            }
                        } else {
                            $errors[] = 'Client Backoffice EntiCreditori non disponibile';
                        }
                    } catch (\Throwable $e) {
                        $errors[] = 'Errore lettura entrate: ' . $e->getMessage();
                    }

                    try {
                        if (class_exists('GovPay\\Pendenze\\Api\\ProfiloApi')) {
                            $pendHost = SettingsRepository::get('govpay', 'pendenze_url', '');
                            if (!empty($pendHost)) {
                                $pendCfg = new \GovPay\Pendenze\Configuration();
                                $pendCfg->setHost(rtrim($pendHost, '/'));
                                if ($username !== '' && $password !== '') {
                                    $pendCfg->setUsername($username);
                                    $pendCfg->setPassword($password);
                                }
                                $pendClient = new \GuzzleHttp\Client($guzzleOptions);
                                $profApi = new \GovPay\Pendenze\Api\ProfiloApi($pendClient, $pendCfg);
                                $profRes = $profApi->getProfilo();
                                $profData = \GovPay\Pendenze\ObjectSerializer::sanitizeForSerialization($profRes);
                                $profiloJson = json_encode($profData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            } else {
                                $errors[] = 'Variabile GOVPAY_PENDENZE_URL non impostata';
                            }
                        } else {
                            $errors[] = 'Client Pendenze non disponibile (namespace GovPay\\Pendenze)';
                        }
                    } catch (\Throwable $e) {
                        $errors[] = 'Errore lettura profilo Pendenze: ' . $e->getMessage();
                    }

                    try {
                        $pagHost = SettingsRepository::get('govpay', 'pagamenti_url', '');
                        if (!empty($pagHost)) {
                            $headers = ['Accept' => 'application/json'];
                            if ($username !== '' && $password !== '') {
                                $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
                            }
                            $http = new \GuzzleHttp\Client($guzzleOptions);
                            $resp = $http->request('GET', rtrim($pagHost, '/') . '/profilo', ['headers' => $headers]);
                            $pagamentiProfiloJson = (string)$resp->getBody();
                        } else {
                            $errors[] = 'Variabile GOVPAY_PAGAMENTI_URL non impostata';
                        }
                    } catch (\Throwable $e) {
                        $errors[] = 'Errore lettura profilo Pagamenti: ' . $e->getMessage();
                    }

                    try {
                        if (class_exists('GovPay\\Backoffice\\Api\\InfoApi')) {
                            $infoApi = new \GovPay\Backoffice\Api\InfoApi($httpClient, $config);
                            $infoRes = $infoApi->getInfo();
                            $infoData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($infoRes);
                            $infoJson = json_encode($infoData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            $infoArr = $infoData;
                        }
                    } catch (\Throwable $e) {
                        $errors[] = 'Errore lettura Info: ' . $e->getMessage();
                    }

                    try {
                        if (class_exists('GovPay\\Backoffice\\Api\\EntiCreditoriApi')) {
                            $idDom = SettingsRepository::get('entity', 'id_dominio', '');
                            if ($idDom !== '') {
                                $entiApi = new \GovPay\Backoffice\Api\EntiCreditoriApi($httpClient, $config);
                                $domRes = $entiApi->getDominio($idDom);
                                $domData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($domRes);
                                $dominioJson = json_encode($domData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                $dominioArr = $domData;
                            } else {
                                $errors[] = 'Variabile ID_DOMINIO non impostata per lettura dominio';
                            }
                        }
                    } catch (\Throwable $e) {
                        $errors[] = 'Errore lettura dominio beneficiario: ' . $e->getMessage();
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore chiamata Backoffice: ' . $e->getMessage();
                }
            } else {
                $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
            }
        } else {
            $errors[] = 'Client Backoffice non disponibile (namespace GovPay\\Backoffice)';
        }

        if ($tab === 'tassonomie') {
            if (!empty($tassonomieUrl)) {
                try {
                    $http = new \GuzzleHttp\Client(['timeout' => 15]);
                    $resp = $http->request('GET', $tassonomieUrl, [
                        'headers' => ['Accept' => 'application/json'],
                        'http_errors' => false,
                    ]);
                    $status = $resp->getStatusCode();
                    if ($status >= 200 && $status < 300) {
                        $tassonomieRaw = (string)$resp->getBody();
                        $rows = json_decode($tassonomieRaw, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($rows)) {
                            throw new \RuntimeException('Formato tassonomie inatteso: JSON non è un array');
                        }

                        $tree = [];
                        $versions = [];
                        foreach ($rows as $entry) {
                            if (!is_array($entry)) {
                                continue;
                            }
                            $enteCode = trim((string)($entry['CODICE TIPO ENTE CREDITORE'] ?? ''));
                            $enteLabel = trim((string)($entry['TIPO ENTE CREDITORE'] ?? ''));
                            if ($enteLabel === '' && $enteCode !== '') {
                                $enteLabel = 'Ente ' . $enteCode;
                            }
                            if ($enteLabel === '') {
                                $enteLabel = 'Altro ente creditore';
                            }
                            $enteKey = ($enteCode !== '' ? $enteCode : 'NA') . '|' . $enteLabel;
                            if (!isset($tree[$enteKey])) {
                                $tree[$enteKey] = [
                                    'codice' => $enteCode,
                                    'label' => $enteLabel,
                                    'macro_aree' => [],
                                    'servizi_count' => 0,
                                ];
                            }

                            $macroProg = trim((string)($entry['PROGRESSIVO MACRO AREA PER ENTE CREDITORE'] ?? ''));
                            $macroNome = trim((string)($entry['NOME MACRO AREA'] ?? 'Macro area'));
                            $macroDesc = trim((string)($entry['DESCRIZIONE MACRO AREA'] ?? ''));
                            $macroKey = ($macroProg !== '' ? $macroProg : 'NA') . '|' . $macroNome;
                            if (!isset($tree[$enteKey]['macro_aree'][$macroKey])) {
                                $tree[$enteKey]['macro_aree'][$macroKey] = [
                                    'progressivo' => $macroProg,
                                    'nome' => $macroNome,
                                    'descrizione' => $macroDesc,
                                    'servizi' => [],
                                ];
                            }

                            $service = [
                                'codice_tipologia' => trim((string)($entry['CODICE TIPOLOGIA SERVIZIO'] ?? '')),
                                'tipo_servizio' => trim((string)($entry['TIPO SERVIZIO'] ?? '')),
                                'motivo_giuridico' => trim((string)($entry['MOTIVO GIURIDICO DELLA RISCOSSIONE'] ?? '')),
                                'descrizione_servizio' => trim((string)($entry['DESCRIZIONE TIPO SERVIZIO'] ?? '')),
                                'dati_specifici_incasso' => trim((string)($entry['DATI SPECIFICI INCASSO'] ?? '')),
                                'data_inizio_validita' => trim((string)($entry['DATA INIZIO VALIDITA'] ?? '')),
                                'data_fine_validita' => trim((string)($entry['DATA FINE VALIDITA'] ?? '')),
                                'versione' => trim((string)($entry['VERSIONE TASSONOMIA'] ?? '')),
                            ];

                            $primaryCatalogCode = $service['dati_specifici_incasso'] !== ''
                                ? $service['dati_specifici_incasso']
                                : null;

                            $normalizedCatalogCode = $primaryCatalogCode !== null ? $normalizeTipologiaCode($primaryCatalogCode) : null;
                            $rawCatalogCode = $primaryCatalogCode !== null ? $rawUpperCode($primaryCatalogCode) : null;

                            $service['codice_tipologia_norm'] = $normalizedCatalogCode ?? $rawCatalogCode;
                            $service['codice_tipologia_raw'] = $rawCatalogCode;

                            $matchSource = null;
                            if ($normalizedCatalogCode !== null && isset($tipologieCodici[$normalizedCatalogCode])) {
                                $matchSource = 'normalized';
                            } elseif ($rawCatalogCode !== null && isset($tipologieCodici[$rawCatalogCode])) {
                                $matchSource = 'raw';
                            }

                            $service['match_source'] = $matchSource;
                            $service['presente_tipologie'] = $matchSource !== null;

                            $tree[$enteKey]['macro_aree'][$macroKey]['servizi'][] = $service;
                            $tree[$enteKey]['servizi_count']++;

                            if ($service['versione'] !== '') {
                                $versions[$service['versione']] = true;
                            }
                        }

                        $macroTotal = 0;
                        foreach ($tree as &$enteRow) {
                            $macroEntries = array_values($enteRow['macro_aree']);
                            usort($macroEntries, static function (array $a, array $b): int {
                                $aLabel = trim(($a['progressivo'] !== '' ? $a['progressivo'] . ' · ' : '') . $a['nome']);
                                $bLabel = trim(($b['progressivo'] !== '' ? $b['progressivo'] . ' · ' : '') . $b['nome']);
                                return strnatcasecmp($aLabel, $bLabel);
                            });
                            foreach ($macroEntries as &$macroEntry) {
                                usort($macroEntry['servizi'], static function (array $a, array $b): int {
                                    $aLabel = trim(($a['codice_tipologia'] !== '' ? $a['codice_tipologia'] . ' · ' : '') . $a['tipo_servizio']);
                                    $bLabel = trim(($b['codice_tipologia'] !== '' ? $b['codice_tipologia'] . ' · ' : '') . $b['tipo_servizio']);
                                    return strnatcasecmp($aLabel, $bLabel);
                                });
                            }
                            unset($macroEntry);
                            $macroTotal += count($macroEntries);
                            $enteRow['macro_aree'] = $macroEntries;
                        }
                        unset($enteRow);

                        $treeList = array_values($tree);
                        usort($treeList, static function (array $a, array $b): int {
                            return strnatcasecmp($a['label'], $b['label']);
                        });

                        $totalServizi = array_sum(array_map(static function (array $row): int {
                            return (int)$row['servizi_count'];
                        }, $treeList));

                        $versions = array_keys($versions);
                        sort($versions, SORT_NATURAL);
                        $tassonomieStats = [
                            'total_servizi' => $totalServizi,
                            'total_macro' => $macroTotal,
                            'enti' => count($treeList),
                            'versions' => $versions,
                            'per_ente' => array_map(static function (array $row): array {
                                return ['label' => $row['label'], 'servizi' => $row['servizi_count']];
                            }, $treeList),
                        ];

                        $selectedTree = $treeList;
                        $filteredStats = [
                            'servizi' => $totalServizi,
                            'macro' => $macroTotal,
                            'enti' => count($treeList),
                        ];

                        if ($tassonomieSearch !== '') {
                            $term = mb_strtolower($tassonomieSearch);
                            $matches = static function (string $text, string $needle): bool {
                                if ($needle === '') {
                                    return true;
                                }
                                return mb_stripos($text, $needle) !== false;
                            };

                            $selectedTree = [];
                            foreach ($treeList as $enteRow) {
                                $enteMatch = $matches(mb_strtolower($enteRow['label']), $term);
                                $macroFiltered = [];
                                foreach ($enteRow['macro_aree'] as $macroRow) {
                                    $macroMatch = $enteMatch || $matches(mb_strtolower($macroRow['nome'] . ' ' . $macroRow['descrizione']), $term);
                                    $servicesFiltered = [];
                                    foreach ($macroRow['servizi'] as $serviceRow) {
                                        $serviceText = mb_strtolower(
                                            $serviceRow['tipo_servizio'] . ' ' .
                                            $serviceRow['descrizione_servizio'] . ' ' .
                                            $serviceRow['codice_tipologia'] . ' ' .
                                            $serviceRow['dati_specifici_incasso'] . ' ' .
                                            $serviceRow['motivo_giuridico']
                                        );
                                        if ($macroMatch || $matches($serviceText, $term)) {
                                            $servicesFiltered[] = $serviceRow;
                                        }
                                    }
                                    if (!empty($servicesFiltered)) {
                                        $macroCopy = $macroRow;
                                        $macroCopy['servizi'] = $servicesFiltered;
                                        $macroFiltered[] = $macroCopy;
                                    }
                                }
                                if (!empty($macroFiltered)) {
                                    $enteCopy = $enteRow;
                                    $enteCopy['macro_aree'] = $macroFiltered;
                                    $enteCopy['servizi_count'] = array_sum(array_map(static function (array $macroRow): int {
                                        return count($macroRow['servizi']);
                                    }, $macroFiltered));
                                    $selectedTree[] = $enteCopy;
                                }
                            }

                            $filteredStats = [
                                'servizi' => array_sum(array_map(static function (array $row): int {
                                    return (int)$row['servizi_count'];
                                }, $selectedTree)),
                                'macro' => array_sum(array_map(static function (array $row): int {
                                    return count($row['macro_aree']);
                                }, $selectedTree)),
                                'enti' => count($selectedTree),
                            ];
                        }

                        $tassonomieTree = $selectedTree;
                        $tassonomieFilteredStats = $filteredStats;
                    } else {
                        $tassonomieError = 'Errore HTTP ' . $status . ' dal catalogo tassonomie';
                    }
                } catch (\JsonException $e) {
                    $tassonomieError = 'Errore decoding tassonomie: ' . $e->getMessage();
                } catch (\Throwable $e) {
                    $tassonomieError = 'Errore lettura tassonomie PagoPA: ' . $e->getMessage();
                }
            } else {
                $tassonomieError = 'Variabile TASSONOMIE_PAGOPA non impostata';
            }
        }

        $externalTypes = [];
        try {
            $extRepo = new ExternalPaymentTypeRepository();
            $externalTypes = $extRepo->listAll();
        } catch (\Throwable $e) {
            $errors[] = 'Errore lettura tipologie esterne: ' . $e->getMessage();
        }

        // Read last N log lines from application log (safe guard: read only up to 20MB tail)
        $logsLines = [];
        $maxLines = 1000;
        $logPath = Logger::getLogFilePath();
        if (is_file($logPath) && is_readable($logPath)) {
            $size = filesize($logPath);
            if ($size > 0) {
                try {
                    if ($size <= 20 * 1024 * 1024) { // 20 MB
                        $all = @file($logPath, FILE_IGNORE_NEW_LINES);
                        if ($all !== false) {
                            $slice = array_slice($all, -$maxLines);
                            $logsLines = array_reverse($slice);
                        }
                    } else {
                        $fp = @fopen($logPath, 'r');
                        if ($fp) {
                            $chunk = 20 * 1024 * 1024;
                            fseek($fp, -$chunk, SEEK_END);
                            $data = stream_get_contents($fp);
                            fclose($fp);
                            $all = explode("\n", $data);
                            $slice = array_slice($all, -$maxLines);
                            $logsLines = array_reverse($slice);
                        }
                    }
                } catch (\Throwable $_) {
                    // swallow errors reading logs; just leave logsLines empty
                    $logsLines = [];
                }
            }
        }

        $usersList = [];
        $countSuperadmins = 0;
        if ($tab === 'utenti' && $canManageUsers) {
            try {
                $usersList = $this->userRepository->listAll();
                $countSuperadmins = $this->userRepository->countByRole('superadmin', false);
            } catch (\Throwable $e) {
                $errors[] = 'Errore caricamento utenti: ' . $e->getMessage();
            }
        }

        // Carica i servizi App IO
        $ioServices = [];
        $ioServicesTipologie = [];
        if ($canEditConfig) {
            try {
                $ioRepo = new \App\Database\IoServiceRepository();
                $ioServices = $ioRepo->listAll();
                $ioServicesTipologie = $ioRepo->getAllTipologiaServices();
            } catch (\Throwable $e) {
                $errors[] = 'Errore caricamento servizi App IO: ' . $e->getMessage();
            }
        }

        $pendenzaTemplates = [];
        $tipologiePendenze = [];
        if ($tab === 'templates' && $canManageUsers) {
            try {
                $idDominioEnv = SettingsRepository::get('entity', 'id_dominio', '');
                if ($idDominioEnv !== '') {
                    $templateRepo = new \App\Database\PendenzaTemplateRepository();
                    $pendenzaTemplates = $templateRepo->findAllByDominio($idDominioEnv);
                    foreach ($pendenzaTemplates as &$pt) {
                        $pt['users'] = $templateRepo->getAssignedUserIds((int)$pt['id']);
                    }
                    unset($pt);

                    $entrateRepo = new EntrateRepository();
                    $tipologiePendenze = $entrateRepo->listAbilitateByDominio($idDominioEnv);
                } else {
                    $errors[] = 'ID_DOMINIO non impostato: impossibile caricare i template';
                }

                if (empty($usersList)) {
                    $usersList = $this->userRepository->listAll();
                }
            } catch (\Throwable $e) {
                $errors[] = 'Errore caricamento template pendenze: ' . $e->getMessage();
            }
        }

        return $this->twig->render($response, 'configurazione.html.twig', [
            'errors' => $errors,
            'cfg_json' => $cfgJson,
            'cfg' => $cfgArr,
            'apps_json' => $appsJson,
            'apps' => $appsArr,
            'app_json' => $appJson,
            'app' => $appArr,
            'ruoli_api_json' => $ruoliApiJson,
            'ruoli_api_status' => $ruoliApiStatus,
            'ruoli_api_error' => $ruoliApiError,
            'ruoli_api_count' => $ruoliApiCount,
            'idA2A' => SettingsRepository::get('entity', 'id_a2a') ?: null,
            'profilo_json' => $profiloJson,
            'entrate_json' => $entrateJson,
            'entrate' => $entrateArr,
            'entrate_source' => $entrateSource ?? '/entrate',
            'pagamenti_profilo_json' => $pagamentiProfiloJson,
            'info' => $infoArr,
            'info_json' => $infoJson,
            'dominio' => $dominioArr,
            'operatori_json' => $operatoriJson,
            'operatori' => $operatoriArr,
            'operatori_pagination' => $operatoriPagination,
            'dominio_json' => $dominioJson,
            'tipologie_esterne' => $externalTypes,
            'io_services' => $ioServices,
            'io_services_tipologie' => $ioServicesTipologie,
            'backoffice_base' => rtrim($backofficeUrl, '/'),
            'tab' => $tab,
            'logs_lines' => $logsLines,
            'query_params' => $params,
            'users' => $usersList,
            'pendenza_templates' => $pendenzaTemplates,
            'tipologie_pendenze' => $tipologiePendenze,
            'count_superadmins' => $countSuperadmins,
            'config_readonly' => !$canEditConfig,
            'can_manage_users' => $canManageUsers,
            'tassonomie_tree' => $tassonomieTree,
            'tassonomie_stats' => $tassonomieStats,
            'tassonomie_filtered_stats' => $tassonomieFilteredStats,
            'tassonomie_error' => $tassonomieError,
            'tassonomie_url' => $tassonomieUrl,
            'tassonomie_search' => $tassonomieSearch,
            'tassonomie_raw' => $tassonomieRaw,
        ]);
    }

    /**
     * Carica i dati per un singolo tab di configurazione. Usato da ImpostazioniController
     * per la vista unificata /impostazioni?tab=X. Non esegue controlli auth (presuppone
     * che il chiamante li abbia già eseguiti).
     */
    public function getTabData(string $tab, Request $request): array
    {
        $currentUser    = $_SESSION['user'] ?? null;
        $role           = $currentUser['role'] ?? null;
        $canEditConfig  = $role === 'superadmin';
        $canManageUsers = in_array($role, ['admin', 'superadmin'], true);

        $params = $request->getQueryParams();
        $errors = [];

        // ── GovPay API client setup ───────────────────────────────────────
        $httpClient    = null;
        $config        = null;
        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        $username      = SettingsRepository::get('govpay', 'user', '');
        $password      = SettingsRepository::get('govpay', 'password', '');
        $guzzleOptions = [];

        if (!empty($backofficeUrl) && class_exists('\\GovPay\\Backoffice\\Api\\ConfigurazioniApi')) {
            try {
                $config = new \GovPay\Backoffice\Configuration();
                $config->setHost(rtrim($backofficeUrl, '/'));
                if ($username !== '' && $password !== '') {
                    $config->setUsername($username);
                    $config->setPassword($password);
                }
                $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
                if (in_array(strtolower((string)$authMethod), ['ssl', 'sslheader'], true)) {
                    $cert    = SettingsRepository::get('govpay', 'tls_cert_path', '');
                    $key     = SettingsRepository::get('govpay', 'tls_key_path', '');
                    $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
                    if (!empty($cert) && !empty($key)) {
                        $guzzleOptions['cert']    = $cert;
                        $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                    }
                }
                $httpClient = new \GuzzleHttp\Client($guzzleOptions);
            } catch (\Throwable $e) {
                $errors[] = 'Errore inizializzazione client API: ' . $e->getMessage();
            }
        } elseif (empty($backofficeUrl)) {
            $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
        } elseif (!class_exists('\\GovPay\\Backoffice\\Api\\ConfigurazioniApi')) {
            $errors[] = 'Client Backoffice non disponibile (namespace GovPay\\Backoffice)';
        }

        // ── Helpers ──────────────────────────────────────────────────────
        $extractValue = static function (?array $source, array $candidateKeys): ?string {
            if (!is_array($source)) { return null; }
            foreach ($candidateKeys as $candidate) {
                if (array_key_exists($candidate, $source) && $source[$candidate] !== null && $source[$candidate] !== '') {
                    return (string)$source[$candidate];
                }
            }
            return null;
        };

        $normalizeTipologiaCode = static function (?string $code): ?string {
            if ($code === null) { return null; }
            $code = trim($code);
            if ($code === '') { return null; }
            if (str_contains($code, '/')) {
                $parts = array_values(array_filter(explode('/', $code), static fn($s) => $s !== null && $s !== ''));
                if (!empty($parts)) { $code = (string)end($parts); }
            }
            $sanitized = preg_replace('/[^A-Za-z0-9\-_.]/', '', $code);
            if (!is_string($sanitized) || $sanitized === '') { return null; }
            return mb_strtoupper($sanitized, 'UTF-8');
        };

        $rawUpperCode = static function (?string $code): ?string {
            if ($code === null) { return null; }
            $code = trim($code);
            return $code === '' ? null : mb_strtoupper($code, 'UTF-8');
        };

        $tipologieCodici = [];
        $registerTipologiaCode = static function (?string $code) use (&$tipologieCodici, $normalizeTipologiaCode, $rawUpperCode): void {
            $n = $normalizeTipologiaCode($code);
            if ($n !== null) { $tipologieCodici[$n] = true; }
            $r = $rawUpperCode($code);
            if ($r !== null) { $tipologieCodici[$r] = true; }
        };

        // ── Variable defaults ─────────────────────────────────────────────
        $cfgJson = null; $cfgArr = null;
        $appsJson = null; $appsArr = null; $appJson = null; $appArr = null;
        $ruoliApiJson = null; $ruoliApiStatus = null; $ruoliApiError = null; $ruoliApiCount = null;
        $operatoriJson = null; $operatoriArr = null;
        $entrateJson = null; $entrateArr = null; $entrateSource = '/entrate';
        $profiloJson = null; $pagamentiProfiloJson = null;
        $infoJson = null; $infoArr = null;
        $dominioJson = null; $dominioArr = null;
        $idDominio = null;

        $operatoriPage    = isset($params['operatori_pagina']) ? max(1, (int)$params['operatori_pagina']) : 1;
        $operatoriPerPage = isset($params['operatori_rpp']) ? min(200, max(1, (int)$params['operatori_rpp'])) : 25;
        $operatoriPagination = [
            'page' => $operatoriPage, 'perPage' => $operatoriPerPage,
            'totalPages' => null, 'totalResults' => null,
            'hasPrev' => false, 'hasNext' => false, 'prevUrl' => null, 'nextUrl' => null,
        ];

        if ($httpClient !== null && $config !== null) {
            // confapi: configurazioni + ruoli
            if ($tab === 'confapi') {
                try {
                    $api     = new \GovPay\Backoffice\Api\ConfigurazioniApi($httpClient, $config);
                    $result  = $api->getConfigurazioni();
                    $cfgData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($result);
                    $cfgJson = json_encode($cfgData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $cfgArr  = $cfgData;
                } catch (\Throwable $e) {
                    $errors[] = 'Errore lettura configurazioni: ' . $e->getMessage();
                }
                try {
                    if (class_exists('GovPay\\Backoffice\\Api\\RuoliApi')) {
                        $ruoliApi      = new \GovPay\Backoffice\Api\RuoliApi($httpClient, $config);
                        $ruoliResponse = $ruoliApi->findRuoli(1, 200, true);
                        $ruoliData     = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($ruoliResponse);
                        $ruoliApiJson  = json_encode($ruoliData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        if (is_array($ruoliData)) {
                            $rr = $ruoliData['risultati'] ?? null;
                            $ruoliApiCount = is_array($rr) ? count($rr) : count($ruoliData);
                        }
                        $ruoliApiStatus = 'ok';
                    } else {
                        $ruoliApiStatus = 'missing-client';
                        $ruoliApiError  = 'Client Backoffice Ruoli non disponibile';
                    }
                } catch (\Throwable $e) {
                    $ruoliApiStatus = 'error';
                    $ruoliApiError  = $e->getMessage();
                }
            }

            // applicazioni / gestionali: apps + app
            if (in_array($tab, ['gestionali', 'applicazioni'], true)) {
                try {
                    $appApi  = new \GovPay\Backoffice\Api\ApplicazioniApi($httpClient, $config);
                    $apps    = $appApi->findApplicazioni(1, 100, '+idA2A', null, null, null, null, true, true);
                    $appsData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($apps);
                    $appsArr  = is_array($appsData) ? $appsData : (json_decode(json_encode($appsData, JSON_UNESCAPED_SLASHES), true) ?: []);
                    $appsJson = json_encode($appsArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    $idA2A   = SettingsRepository::get('entity', 'id_a2a', '');
                    if ($idA2A !== '') {
                        try {
                            $appDet     = $appApi->getApplicazione($idA2A);
                            $appDetData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($appDet);
                            $appJson    = json_encode($appDetData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            $appArr     = json_decode($appJson, true);
                            if (!is_array($appArr)) { $appArr = is_array($appDetData) ? $appDetData : []; }
                        } catch (\Throwable $e) {
                            $errors[] = 'Errore lettura applicazione: ' . $e->getMessage();
                        }
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore lettura applicazioni: ' . $e->getMessage();
                }
            }

            // operatori
            if ($tab === 'operatori') {
                try {
                    if (class_exists('GovPay\\Backoffice\\Api\\OperatoriApi')) {
                        $operatoriApi = new \GovPay\Backoffice\Api\OperatoriApi($httpClient, $config);
                        $operRes      = $operatoriApi->findOperatori($operatoriPage, $operatoriPerPage, '+ragioneSociale', null, null, true, true);
                        $operData     = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($operRes);
                        $operatoriJson = json_encode($operData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        $operatoriArr  = is_array($operData) ? $operData : (json_decode(json_encode($operData, JSON_UNESCAPED_SLASHES), true) ?: []);
                        if (is_array($operatoriArr)) {
                            $extractInt = static function (array $src, array $paths): ?int {
                                foreach ($paths as $path) {
                                    $cursor = $src;
                                    foreach (explode('.', $path) as $segment) {
                                        if (!is_array($cursor) || !array_key_exists($segment, $cursor)) { continue 2; }
                                        $cursor = $cursor[$segment];
                                    }
                                    if ($cursor !== null && $cursor !== '') { return (int)$cursor; }
                                }
                                return null;
                            };
                            $pageR    = $extractInt($operatoriArr, ['pagina', 'page']);
                            $perPageR = $extractInt($operatoriArr, ['risultatiPerPagina', 'risultati_per_pagina']);
                            if ($pageR !== null && $pageR > 0)    { $operatoriPagination['page']    = $pageR; }
                            if ($perPageR !== null && $perPageR > 0) { $operatoriPagination['perPage'] = $perPageR; }
                            $operatoriPagination['totalPages']   = $extractInt($operatoriArr, ['numPagine','num_pagine','metadatiPaginazione.numPagine','metadatiPaginazione.num_pagine']);
                            $operatoriPagination['totalResults'] = $extractInt($operatoriArr, ['numRisultati','num_risultati','metadatiPaginazione.numRisultati','metadatiPaginazione.num_risultati']);
                            if ($operatoriPagination['totalPages'] === null && $operatoriPagination['totalResults'] !== null && $operatoriPagination['perPage'] > 0) {
                                $operatoriPagination['totalPages'] = (int)ceil($operatoriPagination['totalResults'] / $operatoriPagination['perPage']);
                            }
                            $hasPrev = $operatoriPagination['page'] > 1;
                            $hasNext = $operatoriPagination['totalPages'] !== null
                                ? $operatoriPagination['page'] < $operatoriPagination['totalPages']
                                : (isset($operatoriArr['risultati']) && is_array($operatoriArr['risultati']) && count($operatoriArr['risultati']) >= $operatoriPagination['perPage']);
                            $buildOperatoriUrl = static function (Request $req, array $overrides): string {
                                $qs = $req->getQueryParams();
                                $qs['tab'] = 'operatori';
                                foreach ($overrides as $k => $v) {
                                    if ($v === null) { unset($qs[$k]); } else { $qs[$k] = $v; }
                                }
                                return $req->getUri()->getPath() . '?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
                            };
                            if ($hasPrev) { $operatoriPagination['prevUrl'] = $buildOperatoriUrl($request, ['operatori_pagina' => $operatoriPagination['page'] - 1, 'operatori_rpp' => $operatoriPagination['perPage']]); }
                            if ($hasNext) { $operatoriPagination['nextUrl'] = $buildOperatoriUrl($request, ['operatori_pagina' => $operatoriPagination['page'] + 1, 'operatori_rpp' => $operatoriPagination['perPage']]); }
                            $operatoriPagination['hasPrev'] = $hasPrev;
                            $operatoriPagination['hasNext'] = $hasNext;
                        }
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore lettura operatori: ' . $e->getMessage();
                }
            }

            // entrate: tipologie, tipologie_esterne, tassonomie (needs tipologieCodici)
            if (in_array($tab, ['tipologie', 'tipologie_esterne', 'tassonomie'], true)) {
                try {
                    if (class_exists('GovPay\\Backoffice\\Api\\EntiCreditoriApi')) {
                        $entrApi      = new \GovPay\Backoffice\Api\EntiCreditoriApi($httpClient, $config);
                        $idDominioEnv = SettingsRepository::get('entity', 'id_dominio', '');
                        if ($idDominioEnv !== '') {
                            $idDominio    = trim((string)$idDominioEnv);
                            $entrRes      = $entrApi->findEntrateDominio($idDominio, 1, 200, '+idEntrata', null, null, null, true, true);
                            $entrateSource = '/domini/' . $idDominio . '/entrate';
                        } else {
                            $entrRes = $entrApi->findEntrate(1, 200, '+idEntrata', null, true, true);
                        }
                        $entrData    = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($entrRes);
                        $entrateJson = json_encode($entrData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        $entrateArr  = json_decode($entrateJson, true) ?: [];
                        $entrateRows = is_array($entrateArr['risultati'] ?? null) ? $entrateArr['risultati'] : [];
                        foreach ($entrateRows as $row) {
                            if (!is_array($row)) { continue; }
                            $registerTipologiaCode($extractValue($row, ['codiceContabilita', 'codice_contabilita']));
                            $registerTipologiaCode($extractValue($row, ['idEntrata', 'id_entrata']));
                            $tipoEntrataPayload = $row['tipoEntrata'] ?? ($row['tipo_entrata'] ?? null);
                            if (is_array($tipoEntrataPayload)) {
                                $registerTipologiaCode($extractValue($tipoEntrataPayload, ['codiceContabilita', 'codice_contabilita']));
                                $registerTipologiaCode($extractValue($tipoEntrataPayload, ['idEntrata', 'id_entrata']));
                            }
                        }
                        if ($idDominioEnv !== '' && isset($idDominio)) {
                            try {
                                $repoEntr   = new EntrateRepository();
                                foreach ($entrateRows as $row) { $repoEntr->upsertFromBackoffice($idDominio, $row); }
                                $entrateEff = $repoEntr->listByDominio($idDominio);
                                $boMap = []; $ovrMap = []; $urlMap = []; $descrMap = []; $descrEstesaMap = []; $descrEffMap = [];
                                foreach ($entrateEff as $r) {
                                    $idE = $r['id_entrata'];
                                    $boMap[$idE]          = (int)$r['abilitato_backoffice'] === 1;
                                    $ovrMap[$idE]         = isset($r['override_locale']) ? ((int)$r['override_locale'] === 1 ? 1 : 0) : null;
                                    $urlMap[$idE]         = $r['external_url'] ?? null;
                                    $descrMap[$idE]       = $r['descrizione_locale'] ?? null;
                                    $descrEstesaMap[$idE] = $r['descrizione_estesa'] ?? null;
                                    $descrEffMap[$idE]    = $r['descrizione_effettiva'] ?? ($r['descrizione'] ?? null);
                                    $registerTipologiaCode($extractValue($r, ['codice_contabilita', 'codiceContabilita']));
                                    $registerTipologiaCode($extractValue($r, ['id_entrata', 'idEntrata']));
                                }
                                $entrateArr['_bo_map']           = $boMap;
                                $entrateArr['_override_map']     = $ovrMap;
                                $entrateArr['_exturl_map']       = $urlMap;
                                $entrateArr['_descr_map']        = $descrMap;
                                $entrateArr['_descr_estesa_map'] = $descrEstesaMap;
                                $entrateArr['_descr_eff_map']    = $descrEffMap;
                            } catch (\Throwable $e) {
                                $errors[] = 'Sync DB entrate fallito: ' . $e->getMessage();
                            }
                        }
                    } else {
                        $errors[] = 'Client Backoffice EntiCreditori non disponibile';
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore lettura entrate: ' . $e->getMessage();
                }
            }

            // profilo pendenze + pagamenti (per dominio e gestionali)
            if (in_array($tab, ['dominio', 'gestionali'], true)) {
                try {
                    if (class_exists('GovPay\\Pendenze\\Api\\ProfiloApi')) {
                        $pendHost = SettingsRepository::get('govpay', 'pendenze_url', '');
                        if (!empty($pendHost)) {
                            $pendCfg = new \GovPay\Pendenze\Configuration();
                            $pendCfg->setHost(rtrim($pendHost, '/'));
                            if ($username !== '' && $password !== '') {
                                $pendCfg->setUsername($username);
                                $pendCfg->setPassword($password);
                            }
                            $profRes  = (new \GovPay\Pendenze\Api\ProfiloApi(new \GuzzleHttp\Client($guzzleOptions), $pendCfg))->getProfilo();
                            $profData = \GovPay\Pendenze\ObjectSerializer::sanitizeForSerialization($profRes);
                            $profiloJson = json_encode($profData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        }
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore lettura profilo Pendenze: ' . $e->getMessage();
                }
                try {
                    $pagHost = SettingsRepository::get('govpay', 'pagamenti_url', '');
                    if (!empty($pagHost)) {
                        $headers = ['Accept' => 'application/json'];
                        if ($username !== '' && $password !== '') {
                            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
                        }
                        $pagamentiProfiloJson = (string)(new \GuzzleHttp\Client($guzzleOptions))->request('GET', rtrim($pagHost, '/') . '/profilo', ['headers' => $headers])->getBody();
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore lettura profilo Pagamenti: ' . $e->getMessage();
                }
            }

            // info + dominio
            if (in_array($tab, ['info', 'dominio'], true)) {
                try {
                    if (class_exists('GovPay\\Backoffice\\Api\\InfoApi')) {
                        $infoRes  = (new \GovPay\Backoffice\Api\InfoApi($httpClient, $config))->getInfo();
                        $infoData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($infoRes);
                        $infoJson = json_encode($infoData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                        $infoArr  = $infoData;
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore lettura Info: ' . $e->getMessage();
                }
                try {
                    if (class_exists('GovPay\\Backoffice\\Api\\EntiCreditoriApi')) {
                        $idDom = SettingsRepository::get('entity', 'id_dominio', '');
                        if ($idDom !== '') {
                            $domRes      = (new \GovPay\Backoffice\Api\EntiCreditoriApi($httpClient, $config))->getDominio($idDom);
                            $domData     = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($domRes);
                            $dominioJson = json_encode($domData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            $dominioArr  = $domData;
                        }
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore lettura dominio beneficiario: ' . $e->getMessage();
                }
            }
        }

        // ── Tassonomie (richiede chiamata HTTP esterna, indipendente da GovPay) ──
        $tassonomieUrl          = SettingsRepository::get('pagopa', 'tassonomie_url') ?: null;
        $tassonomieSearch       = trim((string)($params['tq'] ?? ''));
        $tassonomieTree         = [];
        $tassonomieStats        = null;
        $tassonomieError        = null;
        $tassonomieRaw          = null;
        $tassonomieFilteredStats = null;

        if ($tab === 'tassonomie') {
            if (!empty($tassonomieUrl)) {
                try {
                    $http   = new \GuzzleHttp\Client(['timeout' => 15]);
                    $resp   = $http->request('GET', $tassonomieUrl, ['headers' => ['Accept' => 'application/json'], 'http_errors' => false]);
                    $status = $resp->getStatusCode();
                    if ($status >= 200 && $status < 300) {
                        $tassonomieRaw = (string)$resp->getBody();
                        $rows = json_decode($tassonomieRaw, true, 512, JSON_THROW_ON_ERROR);
                        if (!is_array($rows)) { throw new \RuntimeException('Formato tassonomie inatteso'); }
                        $tree = []; $versions = [];
                        foreach ($rows as $entry) {
                            if (!is_array($entry)) { continue; }
                            $enteCode  = trim((string)($entry['CODICE TIPO ENTE CREDITORE'] ?? ''));
                            $enteLabel = trim((string)($entry['TIPO ENTE CREDITORE'] ?? ''));
                            if ($enteLabel === '' && $enteCode !== '') { $enteLabel = 'Ente ' . $enteCode; }
                            if ($enteLabel === '') { $enteLabel = 'Altro ente creditore'; }
                            $enteKey   = ($enteCode !== '' ? $enteCode : 'NA') . '|' . $enteLabel;
                            if (!isset($tree[$enteKey])) {
                                $tree[$enteKey] = ['codice' => $enteCode, 'label' => $enteLabel, 'macro_aree' => [], 'servizi_count' => 0];
                            }
                            $macroProg = trim((string)($entry['PROGRESSIVO MACRO AREA PER ENTE CREDITORE'] ?? ''));
                            $macroNome = trim((string)($entry['NOME MACRO AREA'] ?? 'Macro area'));
                            $macroDesc = trim((string)($entry['DESCRIZIONE MACRO AREA'] ?? ''));
                            $macroKey  = ($macroProg !== '' ? $macroProg : 'NA') . '|' . $macroNome;
                            if (!isset($tree[$enteKey]['macro_aree'][$macroKey])) {
                                $tree[$enteKey]['macro_aree'][$macroKey] = ['progressivo' => $macroProg, 'nome' => $macroNome, 'descrizione' => $macroDesc, 'servizi' => []];
                            }
                            $service = [
                                'codice_tipologia'     => trim((string)($entry['CODICE TIPOLOGIA SERVIZIO'] ?? '')),
                                'tipo_servizio'        => trim((string)($entry['TIPO SERVIZIO'] ?? '')),
                                'motivo_giuridico'     => trim((string)($entry['MOTIVO GIURIDICO DELLA RISCOSSIONE'] ?? '')),
                                'descrizione_servizio' => trim((string)($entry['DESCRIZIONE TIPO SERVIZIO'] ?? '')),
                                'dati_specifici_incasso' => trim((string)($entry['DATI SPECIFICI INCASSO'] ?? '')),
                                'data_inizio_validita' => trim((string)($entry['DATA INIZIO VALIDITA'] ?? '')),
                                'data_fine_validita'   => trim((string)($entry['DATA FINE VALIDITA'] ?? '')),
                                'versione'             => trim((string)($entry['VERSIONE TASSONOMIA'] ?? '')),
                            ];
                            $primaryCatalogCode    = $service['dati_specifici_incasso'] !== '' ? $service['dati_specifici_incasso'] : null;
                            $normalizedCatalogCode = $primaryCatalogCode !== null ? $normalizeTipologiaCode($primaryCatalogCode) : null;
                            $rawCatalogCode        = $primaryCatalogCode !== null ? $rawUpperCode($primaryCatalogCode) : null;
                            $service['codice_tipologia_norm'] = $normalizedCatalogCode ?? $rawCatalogCode;
                            $service['codice_tipologia_raw']  = $rawCatalogCode;
                            $matchSource = null;
                            if ($normalizedCatalogCode !== null && isset($tipologieCodici[$normalizedCatalogCode])) { $matchSource = 'normalized'; }
                            elseif ($rawCatalogCode !== null && isset($tipologieCodici[$rawCatalogCode])) { $matchSource = 'raw'; }
                            $service['match_source']       = $matchSource;
                            $service['presente_tipologie'] = $matchSource !== null;
                            $tree[$enteKey]['macro_aree'][$macroKey]['servizi'][] = $service;
                            $tree[$enteKey]['servizi_count']++;
                            if ($service['versione'] !== '') { $versions[$service['versione']] = true; }
                        }
                        $macroTotal = 0;
                        foreach ($tree as &$enteRow) {
                            $macroEntries = array_values($enteRow['macro_aree']);
                            usort($macroEntries, static fn(array $a, array $b): int => strnatcasecmp(($a['progressivo'] !== '' ? $a['progressivo'] . ' · ' : '') . $a['nome'], ($b['progressivo'] !== '' ? $b['progressivo'] . ' · ' : '') . $b['nome']));
                            foreach ($macroEntries as &$macroEntry) {
                                usort($macroEntry['servizi'], static fn(array $a, array $b): int => strnatcasecmp(($a['codice_tipologia'] !== '' ? $a['codice_tipologia'] . ' · ' : '') . $a['tipo_servizio'], ($b['codice_tipologia'] !== '' ? $b['codice_tipologia'] . ' · ' : '') . $b['tipo_servizio']));
                            }
                            unset($macroEntry);
                            $macroTotal += count($macroEntries);
                            $enteRow['macro_aree'] = $macroEntries;
                        }
                        unset($enteRow);
                        $treeList = array_values($tree);
                        usort($treeList, static fn(array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
                        $totalServizi   = array_sum(array_map(static fn(array $r): int => (int)$r['servizi_count'], $treeList));
                        $versions       = array_keys($versions);
                        sort($versions, SORT_NATURAL);
                        $tassonomieStats = [
                            'total_servizi' => $totalServizi, 'total_macro' => $macroTotal,
                            'enti' => count($treeList), 'versions' => $versions,
                            'per_ente' => array_map(static fn(array $r): array => ['label' => $r['label'], 'servizi' => $r['servizi_count']], $treeList),
                        ];
                        $selectedTree  = $treeList;
                        $filteredStats = ['servizi' => $totalServizi, 'macro' => $macroTotal, 'enti' => count($treeList)];
                        if ($tassonomieSearch !== '') {
                            $term     = mb_strtolower($tassonomieSearch);
                            $matches  = static fn(string $text, string $needle): bool => $needle === '' || mb_stripos($text, $needle) !== false;
                            $selectedTree = [];
                            foreach ($treeList as $enteRow) {
                                $enteMatch     = $matches(mb_strtolower($enteRow['label']), $term);
                                $macroFiltered = [];
                                foreach ($enteRow['macro_aree'] as $macroRow) {
                                    $macroMatch       = $enteMatch || $matches(mb_strtolower($macroRow['nome'] . ' ' . $macroRow['descrizione']), $term);
                                    $servicesFiltered = [];
                                    foreach ($macroRow['servizi'] as $serviceRow) {
                                        $serviceText = mb_strtolower($serviceRow['tipo_servizio'] . ' ' . $serviceRow['descrizione_servizio'] . ' ' . $serviceRow['codice_tipologia'] . ' ' . $serviceRow['dati_specifici_incasso'] . ' ' . $serviceRow['motivo_giuridico']);
                                        if ($macroMatch || $matches($serviceText, $term)) { $servicesFiltered[] = $serviceRow; }
                                    }
                                    if (!empty($servicesFiltered)) {
                                        $macroCopy = $macroRow; $macroCopy['servizi'] = $servicesFiltered; $macroFiltered[] = $macroCopy;
                                    }
                                }
                                if (!empty($macroFiltered)) {
                                    $enteCopy = $enteRow; $enteCopy['macro_aree'] = $macroFiltered;
                                    $enteCopy['servizi_count'] = array_sum(array_map(static fn(array $m): int => count($m['servizi']), $macroFiltered));
                                    $selectedTree[] = $enteCopy;
                                }
                            }
                            $filteredStats = [
                                'servizi' => array_sum(array_map(static fn(array $r): int => (int)$r['servizi_count'], $selectedTree)),
                                'macro'   => array_sum(array_map(static fn(array $r): int => count($r['macro_aree']), $selectedTree)),
                                'enti'    => count($selectedTree),
                            ];
                        }
                        $tassonomieTree          = $selectedTree;
                        $tassonomieFilteredStats = $filteredStats;
                    } else {
                        $tassonomieError = 'Errore HTTP ' . $status . ' dal catalogo tassonomie';
                    }
                } catch (\JsonException $e) {
                    $tassonomieError = 'Errore decoding tassonomie: ' . $e->getMessage();
                } catch (\Throwable $e) {
                    $tassonomieError = 'Errore lettura tassonomie PagoPA: ' . $e->getMessage();
                }
            } else {
                $tassonomieError = 'Variabile TASSONOMIE_PAGOPA non impostata';
            }
        }

        // ── External types ────────────────────────────────────────────────
        $externalTypes = [];
        if (in_array($tab, ['tipologie', 'tipologie_esterne'], true)) {
            try {
                $extRepo       = new ExternalPaymentTypeRepository();
                $externalTypes = $extRepo->listAll();
            } catch (\Throwable $e) {
                $errors[] = 'Errore lettura tipologie esterne: ' . $e->getMessage();
            }
        }

        // ── Logs ──────────────────────────────────────────────────────────
        $logsLines = [];
        if ($tab === 'logs') {
            $maxLines = 1000;
            $logPath  = Logger::getLogFilePath();
            if (is_file($logPath) && is_readable($logPath)) {
                $size = filesize($logPath);
                if ($size > 0) {
                    try {
                        if ($size <= 20 * 1024 * 1024) {
                            $all = @file($logPath, FILE_IGNORE_NEW_LINES);
                            if ($all !== false) { $logsLines = array_reverse(array_slice($all, -$maxLines)); }
                        } else {
                            $fp = @fopen($logPath, 'r');
                            if ($fp) {
                                fseek($fp, -(20 * 1024 * 1024), SEEK_END);
                                $all = explode("\n", stream_get_contents($fp));
                                fclose($fp);
                                $logsLines = array_reverse(array_slice($all, -$maxLines));
                            }
                        }
                    } catch (\Throwable $_) {
                        $logsLines = [];
                    }
                }
            }
        }

        // ── Utenti ────────────────────────────────────────────────────────
        $usersList = []; $countSuperadmins = 0;
        if ($tab === 'utenti' && $canManageUsers) {
            try {
                $usersList        = $this->userRepository->listAll();
                $countSuperadmins = $this->userRepository->countByRole('superadmin', false);
            } catch (\Throwable $e) {
                $errors[] = 'Errore caricamento utenti: ' . $e->getMessage();
            }
        }

        // ── IO Services ───────────────────────────────────────────────────
        $ioServices = []; $ioServicesTipologie = [];
        if ($canEditConfig && $tab === 'servizi_io') {
            try {
                $ioRepo              = new \App\Database\IoServiceRepository();
                $ioServices          = $ioRepo->listAll();
                $ioServicesTipologie = $ioRepo->getAllTipologiaServices();
            } catch (\Throwable $e) {
                $errors[] = 'Errore caricamento servizi App IO: ' . $e->getMessage();
            }
        }

        // ── Template pendenze ─────────────────────────────────────────────
        $pendenzaTemplates = []; $tipologiePendenze = [];
        if (in_array($tab, ['templates', 'gruppi-utenti'], true) && $canManageUsers) {
            try {
                $idDominioEnv = SettingsRepository::get('entity', 'id_dominio', '');
                if ($idDominioEnv !== '') {
                    $templateRepo      = new \App\Database\PendenzaTemplateRepository();
                    $pendenzaTemplates = $templateRepo->findAllByDominio($idDominioEnv);
                    foreach ($pendenzaTemplates as &$pt) { $pt['users'] = $templateRepo->getAssignedUserIds((int)$pt['id']); }
                    unset($pt);
                    $tipologiePendenze = (new EntrateRepository())->listAbilitateByDominio($idDominioEnv);
                } else {
                    $errors[] = 'ID_DOMINIO non impostato: impossibile caricare i template';
                }
                if (empty($usersList)) { $usersList = $this->userRepository->listAll(); }
            } catch (\Throwable $e) {
                $errors[] = 'Errore caricamento template pendenze: ' . $e->getMessage();
            }
        }

        // ── Gruppi Utenti ─────────────────────────────────────────────────
        $userGroups = []; $allUsersForGroups = [];
        if ($tab === 'gruppi-utenti' && $canManageUsers) {
            try {
                $groupRepo  = new \App\Database\UserGroupRepository();
                $userGroups = $groupRepo->listAll();
                $idDominioEnv = SettingsRepository::get('entity', 'id_dominio', '');
                foreach ($userGroups as &$grp) {
                    $grp['member_ids']    = $groupRepo->getMemberIds((int)$grp['id']);
                    $grp['tipologie_ids'] = $idDominioEnv ? $groupRepo->getTipologie((int)$grp['id'], $idDominioEnv) : [];
                    $grp['template_ids']  = $groupRepo->getTemplateIds((int)$grp['id']);
                }
                unset($grp);
                $allUsersForGroups = $this->userRepository->listAll();
            } catch (\Throwable $e) {
                $errors[] = 'Errore caricamento gruppi: ' . $e->getMessage();
            }
        }

        return [
            'errors'                    => $errors,
            'cfg_json'                  => $cfgJson,
            'cfg'                       => $cfgArr,
            'apps_json'                 => $appsJson,
            'apps'                      => $appsArr,
            'app_json'                  => $appJson,
            'app'                       => $appArr,
            'ruoli_api_json'            => $ruoliApiJson,
            'ruoli_api_status'          => $ruoliApiStatus,
            'ruoli_api_error'           => $ruoliApiError,
            'ruoli_api_count'           => $ruoliApiCount,
            'idA2A'                     => SettingsRepository::get('entity', 'id_a2a') ?: null,
            'profilo_json'              => $profiloJson,
            'entrate_json'              => $entrateJson,
            'entrate'                   => $entrateArr,
            'entrate_source'            => $entrateSource,
            'pagamenti_profilo_json'    => $pagamentiProfiloJson,
            'info'                      => $infoArr,
            'info_json'                 => $infoJson,
            'dominio'                   => $dominioArr,
            'operatori_json'            => $operatoriJson,
            'operatori'                 => $operatoriArr,
            'operatori_pagination'      => $operatoriPagination,
            'dominio_json'              => $dominioJson,
            'tipologie_esterne'         => $externalTypes,
            'io_services'               => $ioServices,
            'io_services_tipologie'     => $ioServicesTipologie,
            'backoffice_base'           => rtrim($backofficeUrl, '/'),
            'tab'                       => $tab,
            'logs_lines'                => $logsLines,
            'query_params'              => $params,
            'users'                     => $usersList,
            'pendenza_templates'        => $pendenzaTemplates,
            'tipologie_pendenze'        => $tipologiePendenze,
            'count_superadmins'         => $countSuperadmins,
            'config_readonly'           => !$canEditConfig,
            'can_manage_users'          => $canManageUsers,
            'tassonomie_tree'           => $tassonomieTree,
            'tassonomie_stats'          => $tassonomieStats,
            'tassonomie_filtered_stats' => $tassonomieFilteredStats,
            'tassonomie_error'          => $tassonomieError,
            'tassonomie_url'            => $tassonomieUrl,
            'tassonomie_search'         => $tassonomieSearch,
            'tassonomie_raw'            => $tassonomieRaw,
            'user_groups'               => $userGroups,
            'all_users_for_groups'      => $allUsersForGroups,
        ];
    }

    public function createExternalPaymentType(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $descrizione = trim((string)($data['descrizione'] ?? ''));
        $descrizioneEstesa = trim((string)($data['descrizione_estesa'] ?? ''));
        $url = trim((string)($data['url'] ?? ''));

        if ($descrizione === '' || $url === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Compila descrizione e URL'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'URL non valido'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }

        try {
            $repo = new ExternalPaymentTypeRepository();
            $repo->create($descrizione, $descrizioneEstesa !== '' ? $descrizioneEstesa : null, $url);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Tipologia esterna salvata'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore salvataggio tipologia esterna: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'tipologie_esterne');
    }

    public function deleteExternalPaymentType(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }

        $id = isset($args['id']) ? (int)$args['id'] : 0;
        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID tipologia non valido'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }

        try {
            $repo = new ExternalPaymentTypeRepository();
            $repo->delete($id);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Tipologia esterna rimossa'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore eliminazione tipologia esterna: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'tipologie_esterne');
    }

    public function updateExternalPaymentType(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }

        $id = isset($args['id']) ? (int)$args['id'] : 0;
        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID tipologia non valido'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $descrizione = trim((string)($data['descrizione'] ?? ''));
        $descrizioneEstesa = trim((string)($data['descrizione_estesa'] ?? ''));
        $url = trim((string)($data['url'] ?? ''));

        if ($descrizione === '' || $url === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Compila descrizione e URL'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'URL non valido'];
            return $this->redirectToTab($response, 'tipologie_esterne');
        }

        try {
            $repo = new ExternalPaymentTypeRepository();
            $repo->update($id, $descrizione, $descrizioneEstesa !== '' ? $descrizioneEstesa : null, $url);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Tipologia esterna aggiornata'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento tipologia esterna: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'tipologie_esterne');
    }

    public function updateDominio(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'dominio');
        }

        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        $idDom = SettingsRepository::get('entity', 'id_dominio', '');
        if ($backofficeUrl === '' || $idDom === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Variabili GOVPAY_BACKOFFICE_URL o ID_DOMINIO non impostate'];
            return $this->redirectToTab($response, 'dominio');
        }

        // Setup HTTP client (basic or mTLS) like other Backoffice calls
        $username = SettingsRepository::get('govpay', 'user', '');
        $password = SettingsRepository::get('govpay', 'password', '');
        $guzzleOptions = ['headers' => ['Accept' => 'application/json']];
        $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
        if (in_array(strtolower((string)$authMethod), ['ssl', 'sslheader'], true)) {
            $cert = SettingsRepository::get('govpay', 'tls_cert_path', '');
            $key = SettingsRepository::get('govpay', 'tls_key_path', '');
            $keyPass = SettingsRepository::get('govpay', 'tls_key_password') ?: null;
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert'] = $cert;
                $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            } else {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'mTLS abilitato ma certificati non impostati'];
                return $this->redirectToTab($response, 'dominio');
            }
        }

        if (!class_exists('GovPay\\Backoffice\\Api\\EntiCreditoriApi')) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Client Backoffice non disponibile'];
            return $this->redirectToTab($response, 'dominio');
        }

        $config = new \GovPay\Backoffice\Configuration();
        $config->setHost(rtrim($backofficeUrl, '/'));
        if ($username !== '' && $password !== '') {
            $config->setUsername($username);
            $config->setPassword($password);
        }

        $httpClient = new \GuzzleHttp\Client($guzzleOptions);
        $entiApi = new \GovPay\Backoffice\Api\EntiCreditoriApi($httpClient, $config);

        $data = (array)($request->getParsedBody() ?? []);

        try {
            // Fetch current domain model and read via getters
            $curr = $entiApi->getDominio($idDom);

            // Start from current values, then override only provided fields
            $payload = [
                'ragione_sociale' => (string)($curr->getRagioneSociale() ?? ''),
                'indirizzo' => (string)($curr->getIndirizzo() ?? ''),
                'civico' => (string)($curr->getCivico() ?? ''),
                'cap' => (string)($curr->getCap() ?? ''),
                'localita' => (string)($curr->getLocalita() ?? ''),
                'provincia' => (string)($curr->getProvincia() ?? ''),
                'nazione' => (string)($curr->getNazione() ?? ''),
                'email' => (string)($curr->getEmail() ?? ''),
                'pec' => (string)($curr->getPec() ?? ''),
                'tel' => (string)($curr->getTel() ?? ''),
                'fax' => (string)($curr->getFax() ?? ''),
                'web' => (string)($curr->getWeb() ?? ''),
                'gln' => (string)($curr->getGln() ?? ''),
                'cbill' => (string)($curr->getCbill() ?? ''),
                'iuv_prefix' => (string)($curr->getIuvPrefix() ?? ''),
                'stazione' => (string)($curr->getStazione() ?? ''),
                'aux_digit' => (string)($curr->getAuxDigit() ?? ''),
                'segregation_code' => (string)($curr->getSegregationCode() ?? ''),
                'logo' => (string)($curr->getLogo() ?? ''),
                'abilitato' => (bool)($curr->getAbilitato() ?? false),
                'intermediato' => (bool)($curr->getIntermediato() ?? false),
            ];

            // Overlay user-provided values (trim strings)
            $map = [
                'ragione_sociale','indirizzo','civico','cap','localita','provincia','nazione','email','pec','tel','fax','web','gln','cbill','iuv_prefix','stazione','aux_digit','segregation_code','logo'
            ];
            foreach ($map as $k) {
                if (array_key_exists($k, $data)) {
                    $payload[$k] = trim((string)$data[$k]);
                }
            }
            // Checkboxes
            if (array_key_exists('abilitato', $data)) {
                $payload['abilitato'] = ((string)$data['abilitato'] === '1');
            }
            if (array_key_exists('intermediato', $data)) {
                $payload['intermediato'] = ((string)$data['intermediato'] === '1');
            }

            // Required by model: ragione_sociale, gln, stazione, abilitato
            if ($payload['ragione_sociale'] === '' || $payload['gln'] === '' || $payload['stazione'] === '') {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Compila i campi obbligatori: Ragione sociale, GLN e Stazione'];
                return $this->redirectToTab($response, 'dominio');
            }

            // Build request without forcing null on optional fields (omit empties)
            $req = [];
            $setIfNotEmpty = function(string $key) use (&$req, $payload) {
                if (isset($payload[$key]) && $payload[$key] !== '') {
                    $req[$key] = $payload[$key];
                }
            };
            $req['ragione_sociale'] = $payload['ragione_sociale'];
            $req['gln'] = $payload['gln'];
            $req['stazione'] = $payload['stazione'];
            $req['abilitato'] = (bool)$payload['abilitato'];
            foreach (['indirizzo','civico','cap','localita','provincia','nazione','email','pec','tel','fax','web','cbill','iuv_prefix','aux_digit','segregation_code','logo'] as $opt) {
                $setIfNotEmpty($opt);
            }
            // intermediato is boolean optional in model, include if present in form or differs from current
            if (array_key_exists('intermediato', $data)) {
                $req['intermediato'] = (bool)$payload['intermediato'];
            }

            $dominioPost = new \GovPay\Backoffice\Model\DominioPost($req);

            $entiApi->addDominio($idDom, $dominioPost);

            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Dati dominio aggiornati con successo'];
            Logger::getInstance()->info('Dominio aggiornato', ['id_dominio' => $idDom, 'user_id' => $_SESSION['user']['id'] ?? null]);
        } catch (\GovPay\Backoffice\ApiException $e) {
            $code = $e->getCode();
            $body = method_exists($e, 'getResponseBody') ? $e->getResponseBody() : null;
            $msg = 'Errore Backoffice (' . $code . ') aggiornamento dominio';
            if ($body) {
                $msg .= ': ' . (is_string($body) ? $body : json_encode($body));
            } else {
                $msg .= ': ' . $e->getMessage();
            }
            $_SESSION['flash'][] = ['type' => 'error', 'text' => $msg];
            Logger::getInstance()->error('Errore Backoffice aggiornamento dominio', ['id_dominio' => $idDom, 'code' => $code, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento dominio: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore aggiornamento dominio', ['id_dominio' => $idDom, 'error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'dominio');
    }

    public function overrideTipologia(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $idEntrata = $args['idEntrata'] ?? '';
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $override = null;
        if (isset($data['action']) && $data['action'] === 'reset') {
            $override = null;
        } elseif (isset($data['enable'])) {
            $override = (string)$data['enable'] === '1';
        }

        try {
            $repo = new EntrateRepository();
            $repo->setOverride($idDominio, $idEntrata, $override);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Impostazione salvata'];
            Logger::getInstance()->info('Tipologia override updated', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'override' => $override]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore salvataggio: ' . $e->getMessage()];
            Logger::getInstance()->error('Error updating tipologia override', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    public function updateTipologiaUrl(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $idEntrata = $args['idEntrata'] ?? '';
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $url = trim((string)($data['external_url'] ?? ''));
        if ($url === '') {
            $url = null;
        }

        try {
            $repo = new EntrateRepository();
            $repo->setExternalUrl($idDominio, $idEntrata, $url);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'URL esterna salvata'];
            Logger::getInstance()->info('Tipologia external_url updated', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'url' => $url]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore salvataggio URL: ' . $e->getMessage()];
            Logger::getInstance()->error('Error updating tipologia external_url', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    public function updateTipologiaGovpay(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $idEntrata = $args['idEntrata'] ?? '';
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $enable = isset($data['enable']) ? ((string)$data['enable'] === '1') : null;
        if ($enable === null) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametro enable mancante'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        if (empty($backofficeUrl)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'GOVPAY_BACKOFFICE_URL non impostata'];
            return $this->redirectToTab($response, 'tipologie');
        }

        try {
            $username = SettingsRepository::get('govpay', 'user', '');
            $password = SettingsRepository::get('govpay', 'password', '');
            $guzzleOptions = ['headers' => ['Accept' => 'application/json']];
            $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
            if (in_array(strtolower((string)$authMethod), ['ssl', 'sslheader'], true)) {
                $cert = SettingsRepository::get('govpay', 'tls_cert_path', '');
                $key = SettingsRepository::get('govpay', 'tls_key_path', '');
                $keyPass = SettingsRepository::get('govpay', 'tls_key_password') ?: null;
                if (!empty($cert) && !empty($key)) {
                    $guzzleOptions['cert'] = $cert;
                    $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                } else {
                    $_SESSION['flash'][] = ['type' => 'error', 'text' => 'mTLS abilitato ma certificati non impostati'];
                    return $this->redirectToTab($response, 'tipologie');
                }
            }

            if (!class_exists('GovPay\\Backoffice\\Api\\EntiCreditoriApi')) {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Client Backoffice non disponibile'];
                return $this->redirectToTab($response, 'tipologie');
            }

            $config = new \GovPay\Backoffice\Configuration();
            $config->setHost(rtrim($backofficeUrl, '/'));
            if ($username !== '' && $password !== '') {
                $config->setUsername($username);
                $config->setPassword($password);
            }

            $httpClient = new \GuzzleHttp\Client($guzzleOptions);
            $entiApi = new \GovPay\Backoffice\Api\EntiCreditoriApi($httpClient, $config);

            $curr = $entiApi->getEntrataDominio($idDominio, $idEntrata);
            $ibanAccredito = null;
            $codiceCont = null;
            if (is_object($curr)) {
                if (method_exists($curr, 'getIbanAccredito')) {
                    $ibanAccredito = $curr->getIbanAccredito();
                }
                if (method_exists($curr, 'getCodiceContabilita')) {
                    $codiceCont = $curr->getCodiceContabilita();
                }
                if ($ibanAccredito === null || $codiceCont === null) {
                    $currData = json_decode(json_encode($curr), true);
                    if (is_array($currData)) {
                        if ($ibanAccredito === null) {
                            $ibanAccredito = $currData['ibanAccredito'] ?? null;
                        }
                        if ($codiceCont === null) {
                            $codiceCont = $currData['codiceContabilita'] ?? null;
                        }
                    }
                }
            } else {
                $currData = \GovPay\Backoffice\ObjectSerializer::sanitizeForSerialization($curr);
                $currArr = json_decode(json_encode($currData), true);
                if (is_array($currArr)) {
                    $ibanAccredito = $currArr['ibanAccredito'] ?? null;
                    $codiceCont = $currArr['codiceContabilita'] ?? null;
                }
            }

            if (empty($ibanAccredito)) {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'IBAN mancante sulla tipologia: impossibile aggiornare'];
                return $this->redirectToTab($response, 'tipologie');
            }

            $body = new \GovPay\Backoffice\Model\EntrataPost([
                'iban_accredito' => $ibanAccredito,
                'abilitato' => $enable,
            ]);

            if (!empty($codiceCont)) {
                $body->setCodiceContabilita($codiceCont);
            }

            $entiApi->addEntrataDominio($idDominio, $idEntrata, $body);

            $_SESSION['flash'][] = ['type' => 'success', 'text' => ($enable ? 'Abilitata' : 'Disabilitata') . ' su GovPay'];
            Logger::getInstance()->info('Tipologia govpay enabled toggled', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'enabled' => $enable]);
        } catch (\GuzzleHttp\Exception\ClientException $ce) {
            $code = $ce->getResponse() ? $ce->getResponse()->getStatusCode() : 0;
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore GovPay (' . $code . '): ' . $ce->getMessage()];
            Logger::getInstance()->error('Error toggling tipologia govpay', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'code' => $code, 'error' => $ce->getMessage()]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento GovPay: ' . $e->getMessage()];
            Logger::getInstance()->error('Error toggling tipologia govpay', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    public function addOperatore(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'operatori');
        }

        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($backofficeUrl === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'GOVPAY_BACKOFFICE_URL non impostata'];
            return $this->redirectToTab($response, 'operatori');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $principal = trim((string)($data['principal'] ?? ''));
        $ragione = trim((string)($data['ragione_sociale'] ?? ''));
        $abilitato = isset($data['abilitato']) ? ((string)$data['abilitato'] === '1') : true;

        if ($principal === '' || $ragione === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Compila principal e ragione sociale'];
            return $this->redirectToTab($response, 'operatori');
        }

        try {
            $config = new \GovPay\Backoffice\Configuration();
            $config->setHost(rtrim($backofficeUrl, '/'));
            $username = SettingsRepository::get('govpay', 'user', '');
            $password = SettingsRepository::get('govpay', 'password', '');
            if ($username !== '' && $password !== '') {
                $config->setUsername($username);
                $config->setPassword($password);
            }
            $httpClient = new \GuzzleHttp\Client();

            $api = new \GovPay\Backoffice\Api\OperatoriApi($httpClient, $config);
            $opPost = new \GovPay\Backoffice\Model\OperatorePost([
                'ragione_sociale' => $ragione,
                'abilitato' => (bool)$abilitato
            ]);
            $api->addOperatore($principal, $opPost);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Operatore aggiunto/aggiornato'];
        } catch (\GovPay\Backoffice\ApiException $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore Backoffice: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'operatori');
    }

    public function toggleOperatoreAbilitato(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'operatori');
        }

        $principal = $args['principal'] ?? '';
        if ($principal === '') return $this->redirectToTab($response, 'operatori');

        $data = (array)($request->getParsedBody() ?? []);
        $enable = isset($data['enable']) ? ((string)$data['enable'] === '1') : null;
        if ($enable === null) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametro enable mancante'];
            return $this->redirectToTab($response, 'operatori');
        }

        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        if ($backofficeUrl === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'GOVPAY_BACKOFFICE_URL non impostata'];
            return $this->redirectToTab($response, 'operatori');
        }

        try {
            $config = new \GovPay\Backoffice\Configuration();
            $config->setHost(rtrim($backofficeUrl, '/'));
            $username = SettingsRepository::get('govpay', 'user', '');
            $password = SettingsRepository::get('govpay', 'password', '');
            if ($username !== '' && $password !== '') {
                $config->setUsername($username);
                $config->setPassword($password);
            }
            $httpClient = new \GuzzleHttp\Client();

            $operApi = new \GovPay\Backoffice\Api\OperatoriApi($httpClient, $config);
            $opPost = new \GovPay\Backoffice\Model\OperatorePost(['abilitato' => (bool)$enable]);
            $operApi->addOperatore($principal, $opPost);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => ($enable ? 'Operatore abilitato' : 'Operatore disabilitato')];
        } catch (\GovPay\Backoffice\ApiException $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore Backoffice: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'operatori');
    }

    public function resetTipologia(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $idEntrata = $args['idEntrata'] ?? '';
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }

        try {
            $repo = new EntrateRepository();
            $row = $repo->findOne($idDominio, $idEntrata);
            $repo->setExternalUrl($idDominio, $idEntrata, null);
            if ($row && ((int)$row['abilitato_backoffice'] === 1)) {
                $repo->setOverride($idDominio, $idEntrata, null);
            }
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Reset eseguito'];
            Logger::getInstance()->info('Tipologia reset', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore reset: ' . $e->getMessage()];
            Logger::getInstance()->error('Error resetting tipologia', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    public function updateTipologiaDescrizione(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }
        $idEntrata = $args['idEntrata'] ?? '';
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }
        $data = (array)($request->getParsedBody() ?? []);
        $descr = trim((string)($data['descrizione'] ?? ''));
        if ($descr === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Descrizione non valida'];
            return $this->redirectToTab($response, 'tipologie');
        }
        if (mb_strlen($descr) > 255) {
            $descr = mb_substr($descr, 0, 255);
        }
        try {
            $repo = new EntrateRepository();
            $repo->updateDescrizione($idDominio, $idEntrata, $descr);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Descrizione aggiornata'];
            Logger::getInstance()->info('Tipologia descrizione aggiornata', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento descrizione: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore aggiornamento descrizione tipologia', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'error' => $e->getMessage()]);
        }
        return $this->redirectToTab($response, 'tipologie');
    }

    public function restoreTipologiaDescrizione(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }
        $idEntrata = $args['idEntrata'] ?? '';
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }
        try {
            $repo = new EntrateRepository();
            $affected = $repo->clearDescrizioneLocale($idDominio, $idEntrata);
            if ($affected > 0) {
                $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Descrizione ripristinata da GovPay'];
                Logger::getInstance()->info('Tipologia descrizione ripristinata', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null]);
            } else {
                $_SESSION['flash'][] = ['type' => 'info', 'text' => 'Nessuna descrizione locale trovata da cancellare'];
                Logger::getInstance()->warning('Restore descrizione nessuna riga aggiornata', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null]);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore ripristino descrizione: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore ripristino descrizione tipologia', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'error' => $e->getMessage()]);
        }
        return $this->redirectToTab($response, 'tipologie');
    }

    public function updateTipologiaDescrizioneEstesa(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }
        $idEntrata = $args['idEntrata'] ?? '';
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $descr = trim((string)($data['descrizione_estesa'] ?? ''));

        if ($descr !== '' && mb_strlen($descr) > 4000) {
            $descr = mb_substr($descr, 0, 4000);
        }

        try {
            $repo = new EntrateRepository();
            $repo->updateDescrizioneEstesa($idDominio, $idEntrata, $descr !== '' ? $descr : null);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Descrizione estesa aggiornata'];
            Logger::getInstance()->info('Tipologia descrizione_estesa aggiornata', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento descrizione estesa: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore aggiornamento descrizione_estesa tipologia', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    public function restoreTipologiaDescrizioneEstesa(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }
        $idEntrata = $args['idEntrata'] ?? '';
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }

        try {
            $repo = new EntrateRepository();
            $affected = $repo->clearDescrizioneEstesa($idDominio, $idEntrata);
            if ($affected > 0) {
                $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Descrizione estesa rimossa'];
                Logger::getInstance()->info('Tipologia descrizione_estesa rimossa', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null]);
            } else {
                $_SESSION['flash'][] = ['type' => 'info', 'text' => 'Nessuna descrizione estesa trovata da cancellare'];
                Logger::getInstance()->warning('Restore descrizione_estesa nessuna riga aggiornata', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null]);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore rimozione descrizione estesa: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore rimozione descrizione_estesa tipologia', ['id_dominio' => $idDominio, 'id_entrata' => $idEntrata, 'user_id' => $_SESSION['user']['id'] ?? null, 'error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    public function copyTipologieDescrizioneEstesaFromTassonomie(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        $tassonomieUrl = SettingsRepository::get('pagopa', 'tassonomie_url', '');
        if ($idDominio === '' || $tassonomieUrl === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID_DOMINIO o TASSONOMIE_PAGOPA non impostati'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $normalizeTipologiaCode = static function (?string $code): ?string {
            if ($code === null) {
                return null;
            }
            $code = trim($code);
            if ($code === '') {
                return null;
            }
            if (str_contains($code, '/')) {
                $parts = array_values(array_filter(explode('/', $code), static fn($segment) => $segment !== null && $segment !== ''));
                if (!empty($parts)) {
                    $code = (string)end($parts);
                }
            }
            $sanitized = preg_replace('/[^A-Za-z0-9\-_.]/', '', $code);
            if (!is_string($sanitized) || $sanitized === '') {
                return null;
            }
            return mb_strtoupper($sanitized, 'UTF-8');
        };

        $rawUpperCode = static function (?string $code): ?string {
            if ($code === null) {
                return null;
            }
            $code = trim($code);
            if ($code === '') {
                return null;
            }
            return mb_strtoupper($code, 'UTF-8');
        };

        try {
            $http = new \GuzzleHttp\Client(['timeout' => 15]);
            $resp = $http->request('GET', $tassonomieUrl, [
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => false,
            ]);

            $status = $resp->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore HTTP ' . $status . ' dal catalogo tassonomie'];
                return $this->redirectToTab($response, 'tipologie');
            }

            $raw = (string)$resp->getBody();
            $rows = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($rows)) {
                throw new \RuntimeException('Formato tassonomie inatteso: JSON non è un array');
            }

            $descrByCode = [];
            foreach ($rows as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $catalogCode = trim((string)($entry['DATI SPECIFICI INCASSO'] ?? ''));
                $descrizioneServizio = trim((string)($entry['DESCRIZIONE TIPO SERVIZIO'] ?? ''));
                if ($catalogCode === '' || $descrizioneServizio === '') {
                    continue;
                }
                $norm = $normalizeTipologiaCode($catalogCode);
                if ($norm !== null && !isset($descrByCode[$norm])) {
                    $descrByCode[$norm] = $descrizioneServizio;
                }
                $rawUpper = $rawUpperCode($catalogCode);
                if ($rawUpper !== null && !isset($descrByCode[$rawUpper])) {
                    $descrByCode[$rawUpper] = $descrizioneServizio;
                }
            }

            if (empty($descrByCode)) {
                $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Nessuna descrizione trovata nella tassonomia (DESCRIZIONE TIPO SERVIZIO)'];
                return $this->redirectToTab($response, 'tipologie');
            }

            $repo = new EntrateRepository();
            $tipologie = $repo->listByDominio($idDominio);

            $toUpdate = [];
            $skippedWithValue = 0;
            $notMatched = 0;

            foreach ($tipologie as $t) {
                if (!is_array($t)) {
                    continue;
                }

                $idEntrata = (string)($t['id_entrata'] ?? '');
                if ($idEntrata === '') {
                    continue;
                }

                $current = (string)($t['descrizione_estesa'] ?? '');
                if (trim($current) !== '') {
                    $skippedWithValue++;
                    continue;
                }

                $candidates = [];
                $codiceContabilita = (string)($t['codice_contabilita'] ?? '');

                foreach ([$idEntrata, $codiceContabilita] as $candidateCode) {
                    if ($candidateCode === '') {
                        continue;
                    }
                    $n = $normalizeTipologiaCode($candidateCode);
                    if ($n !== null) {
                        $candidates[] = $n;
                    }
                    $r = $rawUpperCode($candidateCode);
                    if ($r !== null) {
                        $candidates[] = $r;
                    }
                }

                $found = null;
                foreach ($candidates as $key) {
                    if (isset($descrByCode[$key])) {
                        $found = $descrByCode[$key];
                        break;
                    }
                }

                if ($found === null) {
                    $notMatched++;
                    continue;
                }

                if (mb_strlen($found) > 4000) {
                    $found = mb_substr($found, 0, 4000);
                }

                $toUpdate[$idEntrata] = $found;
            }

            $updated = $repo->bulkFillDescrizioneEstesaIfEmpty($idDominio, $toUpdate);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Descrizioni estese compilate: ' . $updated . ' (già valorizzate: ' . $skippedWithValue . ', senza match: ' . $notMatched . ')'];
            Logger::getInstance()->info('Bulk fill descrizione_estesa da tassonomie', [
                'id_dominio' => $idDominio,
                'updated' => $updated,
                'skipped_with_value' => $skippedWithValue,
                'not_matched' => $notMatched,
                'user_id' => $_SESSION['user']['id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore copia descrizioni da tassonomia: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore bulk fill descrizione_estesa da tassonomie', [
                'id_dominio' => $idDominio,
                'user_id' => $_SESSION['user']['id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    // =========================================================================
    // App IO Services Methods
    // =========================================================================

    public function createIoService(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'servizi_io');
        }

        $params = (array)($request->getParsedBody() ?? []);
        
        try {
            $nome = trim((string)($params['nome'] ?? ''));
            $descrizione = trim((string)($params['descrizione'] ?? ''));
            $id_service = trim((string)($params['id_service'] ?? ''));
            $api_key_primaria = trim((string)($params['api_key_primaria'] ?? ''));
            $api_key_secondaria = trim((string)($params['api_key_secondaria'] ?? ''));
            $codice_catalogo = trim((string)($params['codice_catalogo'] ?? ''));
            $is_default = isset($params['is_default']) && $params['is_default'] === '1';

            if ($nome === '' || $id_service === '' || $api_key_primaria === '') {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Campi obbligatori mancanti'];
                return $this->redirectToTab($response, 'servizi_io');
            }

            $ioRepo = new \App\Database\IoServiceRepository();
            $ioRepo->create(
                $nome,
                $descrizione !== '' ? $descrizione : null,
                $id_service,
                $api_key_primaria,
                $api_key_secondaria !== '' ? $api_key_secondaria : null,
                $codice_catalogo !== '' ? $codice_catalogo : null,
                $is_default
            );

            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Servizio IO creato con successo'];
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore creazione servizio IO', [
                'error' => $e->getMessage(),
                'user_id' => $_SESSION['user']['id'] ?? null,
            ]);
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore creazione servizio: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'servizi_io');
    }

    public function updateIoService(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'servizi_io');
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID servizio non valido'];
            return $this->redirectToTab($response, 'servizi_io');
        }

        $params = (array)($request->getParsedBody() ?? []);
        
        try {
            $nome = trim((string)($params['nome'] ?? ''));
            $descrizione = trim((string)($params['descrizione'] ?? ''));
            $id_service = trim((string)($params['id_service'] ?? ''));
            $api_key_primaria = trim((string)($params['api_key_primaria'] ?? ''));
            $api_key_secondaria = trim((string)($params['api_key_secondaria'] ?? ''));
            $codice_catalogo = trim((string)($params['codice_catalogo'] ?? ''));
            $is_default = isset($params['is_default']) && $params['is_default'] === '1';

            if ($nome === '' || $id_service === '' || $api_key_primaria === '') {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Campi obbligatori mancanti'];
                return $this->redirectToTab($response, 'servizi_io');
            }

            $ioRepo = new \App\Database\IoServiceRepository();
            $ioRepo->update(
                $id,
                $nome,
                $descrizione !== '' ? $descrizione : null,
                $id_service,
                $api_key_primaria,
                $api_key_secondaria !== '' ? $api_key_secondaria : null,
                $codice_catalogo !== '' ? $codice_catalogo : null,
                $is_default
            );

            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Servizio IO aggiornato con successo'];
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore aggiornamento servizio IO', [
                'id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $_SESSION['user']['id'] ?? null,
            ]);
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'servizi_io');
    }

    public function deleteIoService(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'servizi_io');
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID servizio non valido'];
            return $this->redirectToTab($response, 'servizi_io');
        }

        try {
            $ioRepo = new \App\Database\IoServiceRepository();
            $ioRepo->delete($id);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Servizio IO eliminato con successo'];
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore eliminazione servizio IO', [
                'id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $_SESSION['user']['id'] ?? null,
            ]);
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore eliminazione: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'servizi_io');
    }

    public function setDefaultIoService(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'servizi_io');
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID servizio non valido'];
            return $this->redirectToTab($response, 'servizi_io');
        }

        try {
            $ioRepo = new \App\Database\IoServiceRepository();
            $ioRepo->setDefault($id);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Servizio IO impostato come predefinito'];
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore impostazione servizio IO predefinito', [
                'id' => $id,
                'error' => $e->getMessage(),
                'user_id' => $_SESSION['user']['id'] ?? null,
            ]);
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'servizi_io');
    }

    public function setTipologiaIoService(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $idEntrata = (string)($args['idEntrata'] ?? '');
        if ($idEntrata === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID tipologia non valido'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $params = (array)($request->getParsedBody() ?? []);
        $ioServiceId = isset($params['io_service_id']) && $params['io_service_id'] !== '' 
            ? (int)$params['io_service_id'] 
            : null;

        try {
            $ioRepo = new \App\Database\IoServiceRepository();
            $ioRepo->setTipologiaService($idEntrata, $ioServiceId);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Servizio IO associato alla tipologia'];
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore associazione servizio IO a tipologia', [
                'id_entrata' => $idEntrata,
                'error' => $e->getMessage(),
                'user_id' => $_SESSION['user']['id'] ?? null,
            ]);
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    /**
     * Salvataggio massivo delle associazioni tipologia-servizio IO.
     * Riceve un array io_service_id[idEntrata] => id_servizio_io|''
     */
    public function bulkSetTipologieIoService(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $params = (array)($request->getParsedBody() ?? []);
        $ioServiceMap = isset($params['io_service_id']) && is_array($params['io_service_id'])
            ? $params['io_service_id']
            : [];

        $ok = 0;
        $ko = 0;

        try {
            $ioRepo = new \App\Database\IoServiceRepository();
            $pdo = \App\Database\Connection::getPDO();
            $pdo->beginTransaction();
            try {
                foreach ($ioServiceMap as $idEntrata => $ioServiceId) {
                    $idEntrata = (string)$idEntrata;
                    if ($idEntrata === '') {
                        continue;
                    }
                    $serviceId = ($ioServiceId !== '' && $ioServiceId !== null)
                        ? (int)$ioServiceId
                        : null;
                    $ioRepo->setTipologiaService($idEntrata, $serviceId);
                    $ok++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            if ($ok > 0) {
                $_SESSION['flash'][] = ['type' => 'success', 'text' => "Configurazioni salvate: $ok tipologie aggiornate"];
            } else {
                $_SESSION['flash'][] = ['type' => 'info', 'text' => 'Nessuna configurazione da salvare'];
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore salvataggio massivo associazioni IO tipologie', [
                'error' => $e->getMessage(),
                'user_id' => $_SESSION['user']['id'] ?? null,
            ]);
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore salvataggio: ' . $e->getMessage()];
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    /**
     * Salva tutti i parametri editabili di una tipologia in un colpo solo:
     * descrizione, descrizione estesa, servizio IO, URL esterna.
     */
    public function saveTipologia(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $idEntrata = (string)($args['idEntrata'] ?? '');
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idEntrata === '' || $idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Parametri mancanti'];
            return $this->redirectToTab($response, 'tipologie');
        }

        $data = (array)($request->getParsedBody() ?? []);

        try {
            $entrateRepo = new EntrateRepository();
            $ioRepo = new \App\Database\IoServiceRepository();

            // Descrizione personalizzata (se non vuota)
            $descr = trim((string)($data['descrizione'] ?? ''));
            if ($descr !== '') {
                if (mb_strlen($descr) > 255) {
                    $descr = mb_substr($descr, 0, 255);
                }
                $entrateRepo->updateDescrizione($idDominio, $idEntrata, $descr);
            }

            // Descrizione estesa (vuota = rimuovi)
            $descrEstesa = trim((string)($data['descrizione_estesa'] ?? ''));
            $entrateRepo->updateDescrizioneEstesa($idDominio, $idEntrata, $descrEstesa !== '' ? $descrEstesa : null);

            // Servizio App IO (vuoto = nessuno / usa predefinito)
            $ioServiceId = isset($data['io_service_id']) && (string)$data['io_service_id'] !== ''
                ? (int)$data['io_service_id']
                : null;
            $ioRepo->setTipologiaService($idEntrata, $ioServiceId);

            // URL esterna (vuota = rimuovi)
            $url = trim((string)($data['external_url'] ?? ''));
            $entrateRepo->setExternalUrl($idDominio, $idEntrata, $url !== '' ? $url : null);

            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Tipologia salvata'];
            Logger::getInstance()->info('Tipologia salvata (save-all)', [
                'id_dominio' => $idDominio,
                'id_entrata' => $idEntrata,
                'user_id'    => $_SESSION['user']['id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore salvataggio: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore salvataggio tipologia (save-all)', [
                'id_dominio' => $idDominio,
                'id_entrata' => $idEntrata,
                'user_id'    => $_SESSION['user']['id'] ?? null,
                'error'      => $e->getMessage(),
            ]);
        }

        return $this->redirectToTab($response, 'tipologie');
    }

    private function redirectToTab(Response $response, string $tab): Response
    {
        return $response->withHeader('Location', '/impostazioni?tab=' . $tab)->withStatus(302);
    }

    private function isSuperadmin(): bool
    {
        $u = $_SESSION['user'] ?? null;
        return $u && ($u['role'] ?? '') === 'superadmin';
    }

    private function isAdminOrAbove(): bool
    {
        $role = $_SESSION['user']['role'] ?? '';
        return in_array($role, ['admin', 'superadmin'], true);
    }

    public function addPendenzaTemplate(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin() && !in_array($_SESSION['user']['role'] ?? '', ['admin'], true)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'templates');
        }

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID_DOMINIO non impostato'];
            return $this->redirectToTab($response, 'templates');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $titolo = trim((string)($data['titolo'] ?? ''));
        $idTipoPendenza = trim((string)($data['id_tipo_pendenza'] ?? ''));
        $causale = trim((string)($data['causale'] ?? ''));
        $importo = (float)($data['importo'] ?? 0);

        if ($titolo === '' || $idTipoPendenza === '' || $causale === '' || $importo <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Compila tutti i campi obbligatori del template'];
            return $this->redirectToTab($response, 'templates');
        }

        try {
            $repo = new \App\Database\PendenzaTemplateRepository();
            $repo->create([
                'id_dominio' => $idDominio,
                'titolo' => $titolo,
                'id_tipo_pendenza' => $idTipoPendenza,
                'causale' => $causale,
                'importo' => $importo
            ]);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Template creato con successo'];
            Logger::getInstance()->info('PendenzaTemplate created', ['titolo' => $titolo, 'user' => $_SESSION['user']['id'] ?? '']);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore creazione template: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore creazione template', ['error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'templates');
    }

    public function updatePendenzaTemplate(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin() && !in_array($_SESSION['user']['role'] ?? '', ['admin'], true)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'templates');
        }

        $id = isset($args['id']) ? (int)$args['id'] : 0;
        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID template non valido'];
            return $this->redirectToTab($response, 'templates');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $titolo = trim((string)($data['titolo'] ?? ''));
        $idTipoPendenza = trim((string)($data['id_tipo_pendenza'] ?? ''));
        $causale = trim((string)($data['causale'] ?? ''));
        $importo = (float)($data['importo'] ?? 0);

        if ($titolo === '' || $idTipoPendenza === '' || $causale === '' || $importo <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Compila tutti i campi obbligatori del template'];
            return $this->redirectToTab($response, 'templates');
        }

        try {
            $repo = new \App\Database\PendenzaTemplateRepository();
            $repo->update($id, [
                'titolo' => $titolo,
                'id_tipo_pendenza' => $idTipoPendenza,
                'causale' => $causale,
                'importo' => $importo
            ]);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Template aggiornato con successo'];
            Logger::getInstance()->info('PendenzaTemplate updated', ['id' => $id, 'user' => $_SESSION['user']['id'] ?? '']);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento template: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore aggiornamento template', ['error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'templates');
    }

    public function deletePendenzaTemplate(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin() && !in_array($_SESSION['user']['role'] ?? '', ['admin'], true)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'templates');
        }

        $id = isset($args['id']) ? (int)$args['id'] : 0;
        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID template non valido'];
            return $this->redirectToTab($response, 'templates');
        }

        try {
            $repo = new \App\Database\PendenzaTemplateRepository();
            $repo->delete($id);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Template eliminato'];
            Logger::getInstance()->info('PendenzaTemplate deleted', ['id' => $id, 'user' => $_SESSION['user']['id'] ?? '']);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore eliminazione template: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore eliminazione template', ['error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'templates');
    }

    public function assignUsersToPendenzaTemplate(Request $request, Response $response, array $args): Response
    {
        if (!$this->isSuperadmin() && !in_array($_SESSION['user']['role'] ?? '', ['admin'], true)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'templates');
        }

        $id = isset($args['id']) ? (int)$args['id'] : 0;
        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID template non valido'];
            return $this->redirectToTab($response, 'templates');
        }

        $data = (array)($request->getParsedBody() ?? []);
        $userIds = isset($data['user_ids']) && is_array($data['user_ids']) ? $data['user_ids'] : [];

        // Cast to int array
        $userIds = array_map('intval', $userIds);

        try {
            $repo = new \App\Database\PendenzaTemplateRepository();
            $repo->assignUsers($id, $userIds);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Assegnazioni salvate'];
            Logger::getInstance()->info('PendenzaTemplate users assigned', ['id' => $id, 'users_count' => count($userIds)]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore assegnazione utenti: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore assegnazione utenti template', ['error' => $e->getMessage()]);
        }

        return $this->redirectToTab($response, 'templates');
    }

    // ── Gruppi Utenti ────────────────────────────────────────────────────────

    public function addUserGroup(Request $request, Response $response): Response
    {
        if (!$this->isAdminOrAbove()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'gruppi-utenti');
        }
        $data = (array)($request->getParsedBody() ?? []);
        $nome = trim((string)($data['nome'] ?? ''));
        if ($nome === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Nome gruppo obbligatorio'];
            return $this->redirectToTab($response, 'gruppi-utenti');
        }
        try {
            $repo = new \App\Database\UserGroupRepository();
            $repo->create($nome, $data['descrizione'] ?? null, ($data['default_id_entrata'] ?? '') ?: null);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => "Gruppo \"{$nome}\" creato"];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore creazione gruppo: ' . $e->getMessage()];
        }
        return $this->redirectToTab($response, 'gruppi-utenti');
    }

    public function updateUserGroup(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdminOrAbove()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'gruppi-utenti');
        }
        $id   = (int)($args['id'] ?? 0);
        $data = (array)($request->getParsedBody() ?? []);
        $nome = trim((string)($data['nome'] ?? ''));
        if ($nome === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Nome gruppo obbligatorio'];
            return $this->redirectToTab($response, 'gruppi-utenti');
        }
        try {
            $repo = new \App\Database\UserGroupRepository();
            $repo->update($id, $nome, $data['descrizione'] ?? null, ($data['default_id_entrata'] ?? '') ?: null);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Gruppo aggiornato'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento: ' . $e->getMessage()];
        }
        return $this->redirectToTab($response, 'gruppi-utenti');
    }

    public function deleteUserGroup(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdminOrAbove()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'gruppi-utenti');
        }
        $id = (int)($args['id'] ?? 0);
        try {
            $repo = new \App\Database\UserGroupRepository();
            $repo->delete($id);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Gruppo eliminato'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore eliminazione: ' . $e->getMessage()];
        }
        return $this->redirectToTab($response, 'gruppi-utenti');
    }

    public function setGroupMembers(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdminOrAbove()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'gruppi-utenti');
        }
        $id      = (int)($args['id'] ?? 0);
        $data    = (array)($request->getParsedBody() ?? []);
        $userIds = (array)($data['user_ids'] ?? []);
        try {
            (new \App\Database\UserGroupRepository())->setMembers($id, $userIds);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Membri aggiornati'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
        }
        return $this->redirectToTab($response, 'gruppi-utenti');
    }

    public function setGroupTipologie(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdminOrAbove()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'gruppi-utenti');
        }
        $id        = (int)($args['id'] ?? 0);
        $data      = (array)($request->getParsedBody() ?? []);
        $entrate   = (array)($data['tipologie_ids'] ?? []);
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        try {
            (new \App\Database\UserGroupRepository())->setTipologie($id, $idDominio, $entrate);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Tipologie gruppo aggiornate'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
        }
        return $this->redirectToTab($response, 'gruppi-utenti');
    }

    public function setGroupTemplates(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdminOrAbove()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToTab($response, 'gruppi-utenti');
        }
        $id          = (int)($args['id'] ?? 0);
        $data        = (array)($request->getParsedBody() ?? []);
        $templateIds = (array)($data['template_ids'] ?? []);
        try {
            (new \App\Database\UserGroupRepository())->setTemplates($id, $templateIds);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Template gruppo aggiornati'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
        }
        return $this->redirectToTab($response, 'gruppi-utenti');
    }
}
