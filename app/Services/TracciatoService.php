<?php
declare(strict_types=1);

namespace App\Services;

use App\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use GovPay\Backoffice\Api\PendenzeApi as BackofficePendenzeApi;
use GovPay\Backoffice\Configuration as BackofficeConfiguration;
use GovPay\Backoffice\ObjectSerializer as BackofficeSerializer;
use App\Config\SettingsRepository;
use App\Database\EntrateRepository;

/**
 * Service per creare e inviare Tracciati di pendenze al Backoffice
 */
class TracciatoService
{
    public function __construct(private ?object $api = null)
    {
    }

    // ...existing code...

    /**
     * Costruisce ricorsivamente istanze dei Model generati a partire da un array
     * con chiavi in snake_case (come costruito sopra).
     * Restituisce un'istanza di \GovPay\Backoffice\Model\TracciatoPendenzePost
     * completamente popolata con oggetti annidati quando possibile.
     *
     * @param array $payloadSnake
     * @return object
     */
    private function buildTracciatoModelFromArray(array $payloadSnake): object
    {
        $rootClass = '\\GovPay\\Backoffice\\Model\\TracciatoPendenzePost';
        if (!class_exists(ltrim($rootClass, '\\'))) {
            throw new \RuntimeException('Model Backoffice non disponibile: ' . $rootClass);
        }

        // Prepariamo gli inserimenti come array di NuovaPendenzaTracciato (model)
        $inserimenti = [];
        $insItems = $payloadSnake['inserimenti'] ?? [];
        foreach ($insItems as $ins) {
            // tipo per l'inserimento
            $insClass = '\\GovPay\\Backoffice\\Model\\NuovaPendenzaTracciato';
            $insPrepared = [];
            // Copia i campi scalari così come sono (l'openapi generator si occuperà della mappatura)
            foreach ($ins as $k => $v) {
                $insPrepared[$k] = $v;
            }

            // Costruisci voci come NuovaVocePendenza[] quando possibile
            if (!empty($ins['voci']) && is_array($ins['voci'])) {
                $voceClass = '\\GovPay\\Backoffice\\Model\\NuovaVocePendenza';
                $voceObjs = [];
                foreach ($ins['voci'] as $v) {
                    if (is_array($v) && class_exists(ltrim($voceClass, '\\'))) {
                        $voceObjs[] = new \GovPay\Backoffice\Model\NuovaVocePendenza($v);
                    } else {
                        $voceObjs[] = $v;
                    }
                }
                $insPrepared['voci'] = $voceObjs;
            }

            // Documento
            if (!empty($ins['documento']) && is_array($ins['documento']) && class_exists('GovPay\\Backoffice\\Model\\Documento')) {
                $insPrepared['documento'] = new \GovPay\Backoffice\Model\Documento($ins['documento']);
            }

            // Soggetto pagatore
            if (!empty($ins['soggetto_pagatore']) && is_array($ins['soggetto_pagatore']) && class_exists('GovPay\\Backoffice\\Model\\Soggetto')) {
                $insPrepared['soggetto_pagatore'] = new \GovPay\Backoffice\Model\Soggetto($ins['soggetto_pagatore']);
            }

            // Proprieta pendenza
            if (!empty($ins['proprieta']) && is_array($ins['proprieta']) && class_exists('GovPay\\Backoffice\\Model\\ProprietaPendenza')) {
                $insPrepared['proprieta'] = new \GovPay\Backoffice\Model\ProprietaPendenza($ins['proprieta']);
            }

            // Ora creiamo l'istanza di NuovaPendenzaTracciato
            if (class_exists(ltrim($insClass, '\\'))) {
                $inserimenti[] = new \GovPay\Backoffice\Model\NuovaPendenzaTracciato($insPrepared);
            } else {
                $inserimenti[] = $insPrepared;
            }
        }

        // Costruiamo il payload per il model root (usando chiavi locali in snake_case)
        $rootPayload = [
            'id_tracciato' => $payloadSnake['id_tracciato'] ?? null,
            'id_dominio' => $payloadSnake['id_dominio'] ?? null,
            'inserimenti' => $inserimenti,
        ];

        // Se sono stati passati annullamenti (ad es. per operazioni di annullo), li
        // convertiamo ai model corretti e li includiamo solo se validi e non vuoti.
        if (isset($payloadSnake['annullamenti']) && is_array($payloadSnake['annullamenti'])) {
            $annObjs = [];
            foreach ($payloadSnake['annullamenti'] as $aIdx => $a) {
                if (is_array($a) && class_exists('GovPay\\Backoffice\\Model\\AnnullamentoPendenza')) {
                    $annObj = new \GovPay\Backoffice\Model\AnnullamentoPendenza($a);
                    $annObjs[] = $annObj;
                } elseif (is_object($a)) {
                    $annObjs[] = $a;
                } else {
                    // Malformazione dell'elemento annullamento
                    throw new \InvalidArgumentException('Elemento annullamenti malformato in indice ' . $aIdx);
                }
            }
            if (!empty($annObjs)) {
                $rootPayload['annullamenti'] = $annObjs;
            }
        }

        return new \GovPay\Backoffice\Model\TracciatoPendenzePost($rootPayload);
    }

    /**
     * Convert single camelCase key to snake_case
     */
    private function camelToSnake(string $key): string
    {
        if (strpos($key, '_') !== false) return $key;
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
        $snake = preg_replace('/([A-Z])([A-Z][a-z])/', '$1_$2', $snake);
        return strtolower($snake);
    }

    /**
     * Convert recursively array keys from camelCase to snake_case
     */
    private function convertArrayKeysToSnake(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $nk = is_string($k) ? $this->camelToSnake($k) : $k;
            if (is_array($v)) {
                $out[$nk] = $this->convertArrayKeysToSnake($v);
            } else {
                $out[$nk] = $v;
            }
        }
        return $out;
    }

    /**
     * Convert single snake_case key to camelCase
     */
    private function snakeToCamel(string $key, bool $capitalizeFirst = false): string
    {
        $str = str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        if (!$capitalizeFirst) {
            $str = lcfirst($str);
        }
        return $str;
    }

    /**
     * Convert recursively array keys from snake_case to camelCase
     */
    private function convertKeysSnakeToCamel(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $nk = is_string($k) ? $this->snakeToCamel($k) : $k;
            if (is_array($v)) {
                $out[$nk] = $this->convertKeysSnakeToCamel($v);
            } else {
                $out[$nk] = $v;
            }
        }
        return $out;
    }

    /**
     * Normalizza il soggetto pagatore per evitare campi non riconosciuti dal Backoffice
     */
    private function sanitizeSoggettoPagatore(array $s): array
    {
        $tipo = strtoupper((string)($s['tipo'] ?? 'F'));
        $ident = trim((string)($s['identificativo'] ?? ''));
        $anag = trim((string)($s['anagrafica'] ?? ''));
        $nome = trim((string)($s['nome'] ?? ''));
        if ($tipo === 'F') {
            $full = trim(($nome !== '' ? $nome . ' ' : '') . $anag);
            $s['anagrafica'] = $full !== '' ? $full : $anag;
            if (isset($s['nome'])) unset($s['nome']);
        }
        $s['tipo'] = $tipo;
        $s['identificativo'] = $ident;
        // Se il campo email è vuoto o non è un valore scalar, rimuovilo per
        // evitare che il Backoffice interpreti un valore non valido (es. "" o []).
        if (array_key_exists('email', $s)) {
            $raw = $s['email'];
            if (!is_scalar($raw) || trim((string)$raw) === '') {
                unset($s['email']);
            } else {
                $email = trim((string)$raw);
                // Accettiamo solo email valide secondo FILTER_VALIDATE_EMAIL.
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    unset($s['email']);
                } else {
                    $s['email'] = $email;
                }
            }
        }
        // Se il cellulare è vuoto o non è scalar, rimuovilo.
        if (array_key_exists('cellulare', $s)) {
            $rawCell = $s['cellulare'];
            if (!is_scalar($rawCell) || trim((string)$rawCell) === '') {
                unset($s['cellulare']);
            } else {
                $s['cellulare'] = trim((string)$rawCell);
            }
        }
        return $s;
    }

    /**
     * Costruisce le voci per ogni inserimento applicando la logica DB-first
     * Restituisce array di voci normalizzate (con chiavi API => snake_case conversione effettuata in seguito)
     */
    private function buildVociForInsertion(array $merged, array $voci, string $idDominio, string $idTipoPendenza, array &$errors = []): array
    {
        $out = [];
        $repo = null;
        try {
            $repo = new EntrateRepository();
        } catch (\Throwable $_) {
            $repo = null;
        }
        foreach ($voci as $v) {
            $vv = $v;
            // Normalize numeric types
            if (isset($vv['importo'])) {
                $vv['importo'] = is_numeric(str_replace(',', '.', (string)$vv['importo'])) ? (float)str_replace(',', '.', (string)$vv['importo']) : 0.0;
            } else {
                $vv['importo'] = 0.0;
            }
            // copy/normalize idVocePendenza
            $vv['idVocePendenza'] = trim((string)($vv['idVocePendenza'] ?? ($vv['id_voce_pendenza'] ?? '')));
            // DB-first: se abbiamo un repository proviamo a leggere la tipologia per integrare i campi
            $details = null;
            if ($repo !== null && $idDominio !== '' && $idTipoPendenza !== '') {
                try {
                    $details = $repo->findDetails($idDominio, $idTipoPendenza);
                } catch (\Throwable $_) {
                    $details = null;
                }
            }
            // Build final per-voce accounting values (DB-first then form). For consistency
            $finalIban = '';
            $finalCodEntrata = '';
            $finalTipoBollo = '';
            $finalTipoContabilita = '';
            if ($details) {
                if (!empty($details['iban_accredito'])) $finalIban = $details['iban_accredito'];
                if (!empty($details['codice_contabilita'])) $finalCodEntrata = $details['codice_contabilita'];
                if (!empty($details['tipo_bollo'])) $finalTipoBollo = $details['tipo_bollo'];
                if (!empty($details['tipo_contabilita'])) $finalTipoContabilita = $details['tipo_contabilita'];
            }
            // override dal merged/form
            if (empty($finalIban) && !empty($merged['ibanAccredito'])) $finalIban = $merged['ibanAccredito'];
            // accetta sia il nome storico 'codEntrata' che 'codiceContabilita' dal form
            if (empty($finalCodEntrata) && (!empty($merged['codiceContabilita']) || !empty($merged['codEntrata']))) $finalCodEntrata = $merged['codiceContabilita'] ?? $merged['codEntrata'] ?? '';
            // Coerenza: se abbiamo idTipoPendenza, usiamolo come codice entrata preferito
            if ($idTipoPendenza !== '') {
                $finalCodEntrata = (string)$idTipoPendenza;
            }
            if (empty($finalTipoBollo) && !empty($merged['tipoBollo'])) $finalTipoBollo = $merged['tipoBollo'];
            if (empty($finalTipoContabilita) && !empty($merged['tipoContabilita'])) $finalTipoContabilita = $merged['tipoContabilita'];

            // Decide representation for the voice
            $voiceMode = null; // 'bollo'|'entrata'|'riferimento'
            if ($finalTipoBollo !== '') {
                $voiceMode = 'bollo';
            } else {
                $hasEntrata = ($finalIban !== '' && $finalTipoContabilita !== '' && $finalCodEntrata !== '');
                if ($hasEntrata) {
                    $voiceMode = 'entrata';
                } elseif ($finalCodEntrata !== '') {
                    $voiceMode = 'riferimento';
                } else {
                    $voiceMode = null;
                }
            }

            if ($voiceMode === 'bollo') {
                $vv['tipoBollo'] = $finalTipoBollo;
            } elseif ($voiceMode === 'entrata') {
                $vv['ibanAccredito'] = $finalIban;
                $vv['tipoContabilita'] = $finalTipoContabilita;
                $vv['codiceContabilita'] = $finalCodEntrata;
            } elseif ($voiceMode === 'riferimento') {
                $vv['codEntrata'] = $finalCodEntrata;
            } else {
                $errors[] = "Voce '{$vv['idVocePendenza']}' priva di informazioni di contabilita' (IBAN+tipoContabilita+codiceContabilita o codEntrata o tipoBollo).";
            }
            $out[] = $vv;
        }
        return $out;
    }

    /**
     * Invia il tracciato costruito dalle pendenze/parti
     * Restituisce ['success'=>bool, 'idTracciato'=>string|null, 'errors'=>array, 'response'=>mixed|null]
     */
    public function sendTracciato(array $merged, array $parts, bool $stampaAvvisi = true): array
    {
        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        $idDominio = $merged['idDominio'] ?? SettingsRepository::get('entity', 'id_dominio', '');
        $errors = [];
        if ($backofficeUrl === '') {
            $errors[] = 'GOVPAY_BACKOFFICE_URL non impostata: configura il Backoffice URL nelle Impostazioni > GovPay';
            return ['success' => false, 'errors' => $errors, 'idTracciato' => null, 'response' => null];
        }

        try {
            $api = $this->api;
            if ($api === null) {
                $config = new BackofficeConfiguration();
                $config->setHost(rtrim($backofficeUrl, '/'));
                $username = SettingsRepository::get('govpay', 'user', '');
                $password = SettingsRepository::get('govpay', 'password', '');
                if ($username !== '' && $password !== '') {
                    $config->setUsername($username);
                    $config->setPassword($password);
                }

                $guzzleOptions = [];
                $authMethod = SettingsRepository::get('govpay', 'authentication_method', '');
                if (strtolower($authMethod) === 'sslheader') {
                    $cert    = SettingsRepository::get('govpay', 'tls_cert_path', '');
                    $key     = SettingsRepository::get('govpay', 'tls_key_path', '');
                    $keyPass = SettingsRepository::get('govpay', 'tls_key_password') ?: null;
                    if (!empty($cert) && !empty($key)) {
                        $guzzleOptions['cert'] = $cert;
                        $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                    }
                }

                // Setup handler stack to add logging middleware when APP_DEBUG
                $handlerStack = HandlerStack::create();
                if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                    $log = \App\Logger::getInstance();
                    $handlerStack->push(function (callable $handler) use ($log) {
                        return function ($request, array $options) use ($handler, $log) {
                            $redactHeaders = function (array $headers): array {
                                $sensitive = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];
                                $out = [];
                                foreach ($headers as $k => $v) {
                                    if (in_array(strtolower($k), $sensitive, true)) {
                                        $out[$k] = ['REDACTED'];
                                    } else {
                                        $out[$k] = $v;
                                    }
                                }
                                return $out;
                            };

                            return $handler($request, $options)->then(
                                function ($response) use ($request, $log, $redactHeaders) {
                                    $reqBody = '';
                                    try {
                                        $rs = $request->getBody();
                                        if (is_callable([$rs, 'rewind'])) { try { $rs->rewind(); } catch (\Throwable $_) { } }
                                        $reqBody = is_callable([$rs, 'getContents']) ? $rs->getContents() : (string)$rs;
                                    } catch (\Throwable $_) { $reqBody = ''; }
                                    $resBody = '';
                                    try {
                                        $rs = $response->getBody();
                                        if (is_callable([$rs, 'rewind'])) { try { $rs->rewind(); } catch (\Throwable $_) { } }
                                        $resBody = is_callable([$rs, 'getContents']) ? $rs->getContents() : (string)$rs;
                                    } catch (\Throwable $_) { $resBody = ''; }

                                    $log->debug('Guzzle HTTP transaction', [
                                        'request' => [
                                            'method' => $request->getMethod(),
                                            'uri' => (string)$request->getUri(),
                                            'headers' => $redactHeaders($request->getHeaders()),
                                            'body' => $reqBody,
                                        ],
                                        'response' => [
                                            'status' => $response->getStatusCode(),
                                            'headers' => $redactHeaders($response->getHeaders()),
                                            'body' => $resBody,
                                        ],
                                    ]);
                                    return $response;
                                },
                                function ($reason) use ($request, $log, $redactHeaders) {
                                    $status = null;
                                    $resBody = '';
                                    $resp = null;
                                    if (is_object($reason) && method_exists($reason, 'getResponse')) {
                                        try { $resp = $reason->getResponse(); } catch (\Throwable $_) { $resp = null; }
                                    }
                                    if ($resp) {
                                        try {
                                            $rs = $resp->getBody();
                                            if (is_callable([$rs, 'rewind'])) { try { $rs->rewind(); } catch (\Throwable $_) { } }
                                            $resBody = is_callable([$rs, 'getContents']) ? $rs->getContents() : (string)$rs;
                                            $status = $resp->getStatusCode();
                                        } catch (\Throwable $_) { }
                                    }
                                    $log->error('Guzzle HTTP error', [
                                        'request' => [
                                            'method' => $request->getMethod(),
                                            'uri' => (string)$request->getUri(),
                                            'headers' => $redactHeaders($request->getHeaders()),
                                        ],
                                        'status' => $status,
                                        'body' => $resBody,
                                        'exception' => (string)$reason,
                                    ]);
                                    return \GuzzleHttp\Promise\rejection_for($reason);
                                }
                            );
                        };
                    });
                }

                // Merge handler into guzzle options
                $guzzleOptions['handler'] = $handlerStack;
                $client = new Client($guzzleOptions);
                $api = new BackofficePendenzeApi($client, $config);
            }

            // Verifica idA2A prima di costruire il tracciato
            $idA2AEnv = SettingsRepository::get('entity', 'id_a2a', '');
            if ($idA2AEnv === '') {
                $msg = "ID_A2A non impostata: configura l'ID A2A nelle Impostazioni > Generale";
                Logger::getInstance()->error($msg);
                return ['success' => false, 'errors' => [$msg], 'idTracciato' => null, 'response' => null];
            }

            // Rimuovi campi specifici del piano rate (usati nella rappresentazione single-pendenza)
            if (isset($merged['proprieta']) && is_array($merged['proprieta'])) {
                if (array_key_exists('rate', $merged['proprieta'])) unset($merged['proprieta']['rate']);
                if (array_key_exists('numeroRate', $merged['proprieta'])) unset($merged['proprieta']['numeroRate']);
            }
            // Se il documento contiene il campo 'rata' come array (piano), rimuovilo perché
            // per il tracciato ogni inserimento deve avere documento.rata int (indice della rata)
            if (isset($merged['documento']) && is_array($merged['documento']) && isset($merged['documento']['rata']) && is_array($merged['documento']['rata'])) {
                unset($merged['documento']['rata']);
            }

            // Controllo idDominio
            if (empty($idDominio)) {
                $msg = 'ID dominio mancante: imposta la variabile d\'ambiente ID_DOMINIO o includi idDominio nei parametri della pendenza';
                Logger::getInstance()->error($msg);
                return ['success' => false, 'errors' => [$msg], 'idTracciato' => null, 'response' => null];
            }

            // Assicuriamoci che ogni pendenza abbia un idPendenza (se non fornito generiamo uno)
            $idPendenzaRaw = trim((string)($merged['idPendenza'] ?? ''));
            if ($idPendenzaRaw === '') {
                try {
                    $rand = bin2hex(random_bytes(8));
                } catch (\Throwable $_) {
                    $rand = preg_replace('/[^A-Za-z0-9]/', '', uniqid());
                }
                $idPCand = 'GIL-' . substr($rand, 0, 16);
                $idPSanitized = preg_replace('/[^A-Za-z0-9\-_]/', '-', substr($idPCand, 0, 35));
                $merged['idPendenza'] = $idPSanitized;
            }

            // Costruzione tracciato
            $idTracciato = 'TR-' . substr(bin2hex(random_bytes(6)), 0, 12);
            $inserimenti = [];
            foreach ($parts as $idx => $p) {
                $idP = ($merged['idPendenza'] ?? '') . '-R' . ($p['indice'] ?? ($idx + 1));
                $nuova = [
                    'idDominio' => $idDominio,
                    'idTipoPendenza' => $merged['idTipoPendenza'] ?? null,
                    'causale' => $merged['causale'] ?? '',
                    'soggettoPagatore' => $merged['soggettoPagatore'] ?? null,
                    'importo' => (float)$p['importo'],
                    'dataValidita' => $p['dataValidita'] ?? null,
                    'dataScadenza' => $p['dataScadenza'] ?? null,
                    'annoRiferimento' => isset($merged['annoRiferimento']) && is_numeric((string)$merged['annoRiferimento']) ? (int)$merged['annoRiferimento'] : ($merged['annoRiferimento'] ?? null),
                    'documento' => [
                        'identificativo' => $merged['documento']['identificativo'] ?? $idTracciato,
                        'descrizione' => $merged['documento']['descrizione'] ?? ('Rata ' . ($p['indice'] ?? ($idx + 1))),
                        'rata' => (int)($p['indice'] ?? ($idx + 1)),
                    ],
                    // le voci verranno normalizzate seguendo la logica DB-first di contabilita'
                    'voci' => $merged['voci'] ?? [],
                    'idA2A' => $idA2AEnv,
                    'idPendenza' => $idP,
                ];

                // Normalizza il soggettoPagatore: unisci nome+anagrafica e rimuovi 'nome' per evitare UnrecognizedPropertyException
                if (isset($nuova['soggettoPagatore']) && is_array($nuova['soggettoPagatore'])) {
                    $nuova['soggettoPagatore'] = $this->sanitizeSoggettoPagatore($nuova['soggettoPagatore']);
                }

                // Normalizza e costruisci le voci (DB-first) senza fare fallire l'invio in assenza di DB
                $nuova['voci'] = $this->buildVociForInsertion($merged, $nuova['voci'], $idDominio, (string)($nuova['idTipoPendenza'] ?? ''));
                $inserimenti[] = $nuova;
            }

            // Costruiamo l'array del tracciato usando nomi locali (camelCase) e prepariamo il
            // payload per l'invio. Se sono disponibili i modelli generati, istanziamoli
            // (accettano le chiavi locali in snake_case) così che l'ObjectSerializer del
            // client genererà il JSON con i nomi originali (camelCase) attesi dal Backoffice.
            $tracciatoArray = [
                'idTracciato' => $idTracciato,
                'idDominio' => $idDominio,
                'inserimenti' => $inserimenti,
            ];

            // Rimuoviamo eventuali campi 'annullamenti' vuoti o privi di valori
            // significativi per evitare che il Backoffice risponda con
            // "Il campo annullamenti non deve essere vuoto." quando
            // l'elemento è presente ma senza dati utili.
            $cleanupAnnullamenti = function (&$target) use ($idTracciato) {
                if (!isset($target['annullamenti'])) return;
                $anns = $target['annullamenti'];
                if (!is_array($anns) || empty($anns)) {
                    unset($target['annullamenti']);
                    Logger::getInstance()->debug('Rimosso campo annullamenti vuoto o non-array', ['idTracciato' => $idTracciato]);
                    return;
                }
                // Verifichiamo che almeno un elemento contenga un valore significativo
                $hasMeaningful = false;
                foreach ($anns as $entry) {
                    if (is_array($entry)) {
                        foreach ($entry as $v) {
                            if ($v === null) continue;
                            if (is_string($v) && trim($v) === '') continue;
                            if (is_array($v) && empty($v)) continue;
                            // valore utile trovato
                            $hasMeaningful = true;
                            break 2;
                        }
                    } elseif (is_string($entry) && trim($entry) !== '') {
                        $hasMeaningful = true;
                        break;
                    } elseif (is_numeric($entry)) {
                        $hasMeaningful = true;
                        break;
                    }
                }
                if (!$hasMeaningful) {
                    unset($target['annullamenti']);
                    Logger::getInstance()->debug('Rimosso campo annullamenti che conteneva solo elementi vuoti', ['idTracciato' => $idTracciato]);
                }
            };

            // Applicazione cleanup al tracciato root
            $cleanupAnnullamenti($tracciatoArray);
            // ...e alle singole inserzioni (se presenti)
            if (!empty($tracciatoArray['inserimenti']) && is_array($tracciatoArray['inserimenti'])) {
                foreach ($tracciatoArray['inserimenti'] as &$ins) {
                    if (is_array($ins)) {
                        $cleanupAnnullamenti($ins);
                    }
                }
                unset($ins);
            }

            // Convertiamo in snake_case per popolare i modelli generati (i loro
            // costruttori si aspettano le chiavi locali in snake_case come 'id_tracciato').
            $payloadSnake = $this->convertArrayKeysToSnake($tracciatoArray);

            $requestBody = null;
            // Se abbiamo i modelli generati, costruiamo il model e usiamolo anche per l'invio
            if (class_exists('GovPay\\Backoffice\\Model\\TracciatoPendenzePost')) {
                // Costruiamo un albero di Model generati per garantire che la serializzazione
                // utilizzi i nomi originali (camelCase) anche per gli oggetti annidati.
                try {
                    $model = $this->buildTracciatoModelFromArray($payloadSnake);
                } catch (\Throwable $e) {
                    Logger::getInstance()->error('Errore costruzione modelli Backoffice', ['exception' => $e->getMessage(), 'tracciato' => $payloadSnake]);
                    return ['success' => false, 'errors' => [$e->getMessage()], 'idTracciato' => $idTracciato, 'response' => null];
                }
                // Validazione del tracciato e delle pendenze annidate
                $invalid = [];
                if (method_exists($model, 'valid') && !$model->valid()) {
                    $invalid = array_merge($invalid, method_exists($model, 'listInvalidProperties') ? $model->listInvalidProperties() : ['Tracciato model validation failed']);
                }
                // Controlliamo anche le inserzioni
                $inserimenti = method_exists($model, 'getInserimenti') ? $model->getInserimenti() : [];
                if (is_array($inserimenti)) {
                    foreach ($inserimenti as $iIdx => $insModel) {
                        if (is_object($insModel) && method_exists($insModel, 'valid') && !$insModel->valid()) {
                            $pref = "Inserimento #" . ($iIdx) . ": ";
                            $invalid = array_merge($invalid, array_map(fn($m) => $pref . $m, method_exists($insModel, 'listInvalidProperties') ? $insModel->listInvalidProperties() : ['Inserimento invalid']));
                        }
                    }
                }
                if (!empty($invalid)) {
                    Logger::getInstance()->error('Tracciato model validation failed', ['errors' => $invalid, 'tracciato' => $payloadSnake]);
                    return ['success' => false, 'errors' => $invalid, 'idTracciato' => $idTracciato, 'response' => null];
                }
                $requestBody = $model;
            } else {
                // Nessun model disponibile: inviamo un array con i nomi ORIGINALI (camelCase)
                // così che il Backoffice riceva le chiavi attese.
                $requestBody = $this->convertKeysSnakeToCamel($payloadSnake);
            }

            // Log payload definitivo (come sarà serializzato) prima dell'invio
            if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                $bodyForLog = is_object($requestBody) ? BackofficeSerializer::sanitizeForSerialization($requestBody) : $requestBody;
                Logger::getInstance()->debug('Tracciato payload (to-send)', ['tracciato' => $bodyForLog]);
            }

            try {
                // Passiamo il model (se presente) o l'array camelCase; il client si occuperà
                // della serializzazione corretta.
                if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                    // Log grezzo della request come verrà serializzata
                    $raw = is_object($requestBody) ? BackofficeSerializer::sanitizeForSerialization($requestBody) : $requestBody;
                    Logger::getInstance()->debug('Tracciato request raw (pre-send)', ['raw' => $raw]);
                }
                $res = $api->addTracciatoPendenze($requestBody, $stampaAvvisi);
            } catch (ClientException $ce) {
                // Raccogliamo lo status e il body (se possibile) per popolare la risposta,
                // ma non salviamo file di debug o rendiamo la UI responsabile della
                // diagnostica completa: usiamo il middleware Guzzle per i log completi.
                $resp = $ce->getResponse();
                $body = '';
                if ($resp) {
                    try {
                        $stream = $resp->getBody();
                        if (is_callable([$stream, 'rewind'])) {
                            try { $stream->rewind(); } catch (\Throwable $_) { }
                        }
                        if (is_callable([$stream, 'getContents'])) {
                            $body = $stream->getContents();
                        } else {
                            $body = (string)$stream;
                        }
                    } catch (\Throwable $_) {
                        // fallback
                        try { $body = (string)$resp->getBody(); } catch (\Throwable $_) { $body = ''; }
                    }
                }
                $parsed = null;
                if ($body !== '') {
                    $tmp = json_decode($body, true);
                    $parsed = (json_last_error() === JSON_ERROR_NONE) ? $tmp : $body;
                }
                // Log compatto qui: dettagli completi delle request/response sono già
                // scritti dal middleware Guzzle quando APP_DEBUG è attivo.
                Logger::getInstance()->error('Tracciato API ClientException (send)', ['status' => $resp ? $resp->getStatusCode() : null, 'message' => $ce->getMessage()]);
                $errMsgFull = $parsed && is_array($parsed) && isset($parsed['dettaglio']) ? $parsed['dettaglio'] : ($body ?: $ce->getMessage());
                $errMsgDisplay = \App\Logger::sanitizeErrorForDisplay((string)$errMsgFull);

                return [
                    'success' => false,
                    'errors' => [$errMsgDisplay],
                    'idTracciato' => $idTracciato,
                    'response' => $parsed,
                ];
            }
            $data = $res ? (is_array($res) ? $res : BackofficeSerializer::sanitizeForSerialization($res)) : null;

            if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                Logger::getInstance()->debug('Tracciato inviato', ['idTracciato' => $idTracciato, 'response' => $data]);
            }

            return ['success' => true, 'idTracciato' => $idTracciato, 'errors' => [], 'response' => $data];
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            return ['success' => false, 'errors' => $errors, 'idTracciato' => null, 'response' => null];
        }
    }
}
