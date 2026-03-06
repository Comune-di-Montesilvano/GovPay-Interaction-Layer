<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Database\EntrateRepository;
use App\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Middleware;
use GovPay\Backoffice\Api\PendenzeApi as BackofficePendenzeApi;
use GovPay\Backoffice\Configuration as BackofficeConfiguration;
use GovPay\Backoffice\Model\RaggruppamentoStatistica;
use GovPay\Backoffice\Model\StatoPendenza;
use GovPay\Backoffice\ObjectSerializer as BackofficeSerializer;
use GovPay\Pendenze\Api\PendenzeApi;
use Psr\Http\Message\RequestInterface as HttpRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\TracciatoService;
use App\Services\ValidationService;

class PendenzeController
{
    public function __construct(private readonly Twig $twig, private ?TracciatoService $tracciatoService = null)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        // Se la route riceve una POST, la trattiamo come richiesta di creazione pendenza
        if (strtoupper((string)$request->getMethod()) === 'POST') {
            return $this->create($request, $response);
        }

        $debug = '';
        $apiClass = PendenzeApi::class;
        if (class_exists($apiClass)) {
            $debug .= "Classe trovata: {$apiClass}\n";
            try {
                $client = new Client();
                new PendenzeApi($client, new PendenzeConfiguration());
                $debug .= "Istanza API creata con successo.\n";
            } catch (\Throwable $e) {
                $debug .= 'Errore: ' . $e->getMessage() . "\n";
            }
        } else {
            $debug .= "Classe API non trovata.\n";
        }

    $errors = [];
    $warnings = [];
        $statsJson = null;
        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        if (class_exists(BackofficePendenzeApi::class)) {
            if (!empty($backofficeUrl)) {
                try {
                    $config = new BackofficeConfiguration();
                    $config->setHost(rtrim($backofficeUrl, '/'));

                    $username = getenv('GOVPAY_USER');
                    $password = getenv('GOVPAY_PASSWORD');
                    if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                        $config->setUsername($username);
                        $config->setPassword($password);
                    }

                    $guzzleOptions = [];
                    $authMethod = getenv('AUTHENTICATION_GOVPAY');
                    if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
                        $cert = getenv('GOVPAY_TLS_CERT');
                        $key = getenv('GOVPAY_TLS_KEY');
                        $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
                        if (!empty($cert) && !empty($key)) {
                            $guzzleOptions['cert'] = $cert;
                            $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                        } else {
                            $errors[] = 'mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati';
                        }
                    }

                    $httpClient = new Client($guzzleOptions);
                    $api = new BackofficePendenzeApi($httpClient, $config);

                    $gruppi = [RaggruppamentoStatistica::DOMINIO];
                    $idDominioEnv = getenv('ID_DOMINIO');
                    if ($idDominioEnv !== false && $idDominioEnv !== '') {
                        $stats = $api->findQuadratureRiscossioni($gruppi, 1, 10, null, null, trim((string)$idDominioEnv));
                    } else {
                        $stats = $api->findQuadratureRiscossioni($gruppi, 1, 10);
                    }

                    $data = BackofficeSerializer::sanitizeForSerialization($stats);
                    $statsJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                } catch (\Throwable $e) {
                    $errors[] = 'Errore chiamata Backoffice: ' . $e->getMessage();
                }
            } else {
                $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
            }
        } else {
            $errors[] = 'Client Backoffice non disponibile (namespace GovPay\\Backoffice)';
        }

        $this->exposeCurrentUser();

        return $this->twig->render($response, 'pendenze.html.twig', [
            'debug' => nl2br(htmlspecialchars($debug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            'stats_json' => $statsJson,
            'errors' => $errors,
        ]);
    }

    /**
     * Gestisce la creazione di una nuova pendenza inviata dal form.
     */
    public function create(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();

    $params = (array)($request->getParsedBody() ?? []);
    $errors = [];
    $warnings = [];

        // idA2A: deve essere preso esclusivamente dalla variabile d'ambiente
        $idA2A = getenv('ID_A2A') ?: '';
        if ($idA2A === '') {
            $errors[] = 'Variabile di ambiente ID_A2A non impostata';
        }

        // Campi principali
        $idTipo = trim((string)($params['idTipoPendenza'] ?? ''));
        $causale = trim((string)($params['causale'] ?? ''));
        $importoRaw = $params['importo'] ?? '';
        $anno = $params['annoRiferimento'] ?? null;

        if ($idTipo === '') {
            $errors[] = 'Tipologia pendenza obbligatoria';
        }
        if ($causale === '') {
            $errors[] = 'Causale obbligatoria';
        } elseif (!ValidationService::validateCausaleLength($causale)) {
            $errors[] = 'La causale supera i 140 caratteri consentiti';
        }
        if ($importoRaw === '' || !is_numeric(str_replace(',', '.', (string)$importoRaw))) {
            $errors[] = 'Importo non valido';
        }
        $importo = (float)str_replace(',', '.', (string)$importoRaw);
        if ($anno === null || !is_numeric((string)$anno)) {
            $errors[] = 'Anno di riferimento non valido';
        } else {
            $anno = (int)$anno;
        }

        // Soggetto
        $sog = $params['soggettoPagatore'] ?? [];
        if (!is_array($sog)) $sog = [];
        $tipoSog = strtoupper((string)($sog['tipo'] ?? 'F'));
        if (!in_array($tipoSog, ['F', 'G'], true)) {
            $errors[] = 'Tipo soggetto non valido';
        }
    $identificativo = strtoupper(trim((string)($sog['identificativo'] ?? '')));
    $anagrafica = strtoupper(trim((string)($sog['anagrafica'] ?? '')));
    $nome = strtoupper(trim((string)($sog['nome'] ?? '')));
        $email = trim((string)($sog['email'] ?? ''));
        if ($identificativo === '') $errors[] = 'Codice fiscale / Partita IVA obbligatorio';
        if ($anagrafica === '') $errors[] = ($tipoSog === 'F') ? 'Cognome obbligatorio' : 'Ragione sociale obbligatoria';
        // Validazioni specifiche CF/P.IVA secondo tipo soggetto
        if ($identificativo !== '') {
            if ($tipoSog === 'F') {
                $res = ValidationService::validateCodiceFiscale($identificativo, $nome, $anagrafica);
                if (!$res['format_ok'] || !$res['check_ok']) {
                    $errors[] = $res['message'] ?? 'Codice fiscale non valido';
                } elseif (!$res['name_match']) {
                    $errors[] = $res['message'] ?? 'Codice fiscale non coerente con nome e cognome indicati';
                }
            } elseif ($tipoSog === 'G') {
                $res = ValidationService::validatePartitaIva($identificativo);
                if (!$res['valid']) {
                    $errors[] = $res['message'] ?? 'Partita IVA non valida';
                }
            }
        }

        // Voci
        $vociRaw = $params['voci'] ?? [];
        $voci = [];
        if (!is_array($vociRaw) || count($vociRaw) === 0) {
            // Crea una voce di default
            $voci[] = [
                'idVocePendenza' => '1',
                'descrizione' => $causale,
                'importo' => $importo,
            ];
        } else {
            $sum = 0.0;
            foreach ($vociRaw as $k => $vr) {
                $idV = trim((string)($vr['idVocePendenza'] ?? ''));
                $desc = trim((string)($vr['descrizione'] ?? ''));
                $impRaw = $vr['importo'] ?? '';
                if ($idV === '') $errors[] = "ID voce mancante per voce #{$k}";
                if ($desc === '') $errors[] = "Descrizione voce mancante per voce #{$k}";
                if ($impRaw === '' || !is_numeric(str_replace(',', '.', (string)$impRaw))) {
                    $errors[] = "Importo voce non valido per voce #{$k}";
                    $imp = 0.0;
                } else {
                    $imp = (float)str_replace(',', '.', (string)$impRaw);
                }
                $sum += $imp;
                $voci[] = ['idVocePendenza' => $idV, 'descrizione' => $desc, 'importo' => $imp];
            }
            // Se la somma delle voci non corrisponde all'importo principale, proviamo a riallineare la prima voce
            if (abs($sum - $importo) > 0.001) {
                if (count($voci) >= 1) {
                    $other = $sum - $voci[0]['importo'];
                    $voci[0]['importo'] = max(0.0, $importo - $other);
                    $sum = 0.0; foreach ($voci as $vv) $sum += $vv['importo'];
                    if (abs($sum - $importo) > 0.001) {
                        $errors[] = 'La somma delle voci non corrisponde all\'importo totale';
                    }
                } else {
                    $errors[] = 'La somma delle voci non corrisponde all\'importo totale';
                }
            }
        }

        // Se abbiamo errori: ricarichiamo il form con i valori precedenti
        if ($errors) {
            $idDominio = getenv('ID_DOMINIO') ?: '';
            $tipologie = [];
            if ($idDominio) {
                try {
                    $repo = new EntrateRepository();
                    $tipologie = $repo->listAbilitateByDominio($idDominio);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            $templates = [];
            if ($idDominio && isset($_SESSION['user']['id'])) {
                try {
                    $templateRepo = new \App\Database\PendenzaTemplateRepository();
                    $templates = $templateRepo->findAllAssignedToUser($idDominio, (int)$_SESSION['user']['id']);
                } catch (\Throwable $e) {}
            }
            return $this->twig->render($response, 'pendenze/inserimento.html.twig', [
                'errors' => $errors,
                'warnings' => $warnings,
                'old' => $params,
                'tipologie_pendenze' => $tipologie,
                'pendenza_templates' => $templates,
                'id_dominio' => $idDominio,
                'id_a2a' => $idA2A,
                'default_anno' => (int)date('Y'),
            ]);
        }

        // Costruzione payload
        $payload = [
            'idTipoPendenza' => $idTipo,
            'idDominio' => $params['idDominio'] ?? (getenv('ID_DOMINIO') ?: ''),
            'causale' => $causale,
            'importo' => $importo,
            'annoRiferimento' => $anno,
            'soggettoPagatore' => [
                'tipo' => $tipoSog,
                'identificativo' => $identificativo,
                'anagrafica' => $anagrafica,
            ],
            'voci' => $voci,
        ];
        // Il Backoffice si aspetta il campo 'anagrafica' come nome e cognome insieme;
        // non inviare il campo 'nome' separato (causa UnrecognizedPropertyException).
        if ($tipoSog === 'F') {
            // Combina nome + cognome (se fornito) rispettando il formato 'Nome Cognome'
            $full = trim(((string)$nome !== '' ? ((string)$nome . ' ') : '') . (string)$anagrafica);
            $payload['soggettoPagatore']['anagrafica'] = $full !== '' ? $full : $anagrafica;
        } else {
            $payload['soggettoPagatore']['anagrafica'] = $anagrafica;
        }
        if (!empty($email)) {
            $payload['soggettoPagatore']['email'] = $email;
        }
        // Aggiorna l'identificativo normalizzato
        if ($identificativo !== '') {
            $payload['soggettoPagatore']['identificativo'] = $identificativo;
        }
        if (!empty($params['dataValidita'])) $payload['dataValidita'] = $params['dataValidita'];
        if (!empty($params['dataScadenza'])) $payload['dataScadenza'] = $params['dataScadenza'];
        foreach (['direzione', 'divisione', 'cartellaPagamento'] as $f) {
            if (!empty($params[$f])) $payload[$f] = $params[$f];
        }

        // Se il client ha fornito un 'documento' (es. per rateizzazione) lo includiamo
        if (!empty($params['documento']) && is_array($params['documento'])) {
            $payload['documento'] = $params['documento'];
        }
        // Includiamo anche eventuali proprieta' o allegati se forniti
        if (!empty($params['proprieta']) && is_array($params['proprieta'])) {
            $payload['proprieta'] = $params['proprieta'];
        }
        if (!empty($params['allegati']) && is_array($params['allegati'])) {
            $payload['allegati'] = $params['allegati'];
        }

        // Inject operator and source information
        $payload['datiAllegati'] = $payload['datiAllegati'] ?? [];
        $payload['datiAllegati']['operatore'] = self::getCurrentOperatorString();
        $payload['datiAllegati']['sorgente'] = 'GIL-Backoffice';

        // Costruisce le voci applicando la stessa logica usata anche in fase di modifica
        $accountingParams = $params;
        if (!isset($accountingParams['idDominio'])) {
            $accountingParams['idDominio'] = $payload['idDominio'] ?? ($params['idDominio'] ?? (getenv('ID_DOMINIO') ?: ''));
        }
        if (!isset($accountingParams['idTipoPendenza'])) {
            $accountingParams['idTipoPendenza'] = $payload['idTipoPendenza'] ?? $idTipo;
        }
        $payload['voci'] = $this->buildVociWithAccounting($voci, $accountingParams, null, $errors, $warnings);

        if ($errors) {
            $idDominio = getenv('ID_DOMINIO') ?: '';
            $tipologie = [];
            if ($idDominio) {
                try {
                    $repo = new EntrateRepository();
                    $tipologie = $repo->listAbilitateByDominio($idDominio);
                } catch (\Throwable $e) {}
            }
            $templates = [];
            if ($idDominio && isset($_SESSION['user']['id'])) {
                try {
                    $templateRepo = new \App\Database\PendenzaTemplateRepository();
                    $templates = $templateRepo->findAllAssignedToUser($idDominio, (int)$_SESSION['user']['id']);
                } catch (\Throwable $e) {}
            }
            return $this->twig->render($response, 'pendenze/inserimento.html.twig', [
                'errors' => $errors,
                'warnings' => $warnings,
                'old' => $params,
                'tipologie_pendenze' => $tipologie,
                'pendenza_templates' => $templates,
                'id_dominio' => $idDominio,
                'id_a2a' => $idA2A,
                'default_anno' => (int)date('Y'),
            ]);
        }

        // (La costruzione delle voci è stata gestita dall'helper buildVociWithAccounting)

        // Invio al Backoffice
        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        if (empty($backofficeUrl)) {
            $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
            $idDominio = getenv('ID_DOMINIO') ?: '';
            $tipologie = [];
            if ($idDominio) {
                try {
                    $repo = new EntrateRepository();
                    $tipologie = $repo->listAbilitateByDominio($idDominio);
                } catch (\Throwable $e) {}
            }
            $templates = [];
            if ($idDominio && isset($_SESSION['user']['id'])) {
                try {
                    $templateRepo = new \App\Database\PendenzaTemplateRepository();
                    $templates = $templateRepo->findAllAssignedToUser($idDominio, (int)$_SESSION['user']['id']);
                } catch (\Throwable $e) {}
            }
                return $this->twig->render($response, 'pendenze/inserimento.html.twig', [
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'old' => $params,
                    'tipologie_pendenze' => $tipologie,
                    'pendenza_templates' => $templates,
                    'id_dominio' => $idDominio,
                    'default_anno' => (int)date('Y'),
                ]);
        }

        $guzzleOptions = [];
        $authMethod = getenv('AUTHENTICATION_GOVPAY');
        if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
            $cert = getenv('GOVPAY_TLS_CERT');
            $key = getenv('GOVPAY_TLS_KEY');
            $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert'] = $cert;
                $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            } else {
                $errors[] = 'mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati';
                $idDominio = getenv('ID_DOMINIO') ?: '';
                $tipologie = [];
                if ($idDominio) {
                    try {
                        $repo = new EntrateRepository();
                        $tipologie = $repo->listAbilitateByDominio($idDominio);
                    } catch (\Throwable $e) {}
                }
                $templates = [];
                if ($idDominio && isset($_SESSION['user']['id'])) {
                    try {
                        $templateRepo = new \App\Database\PendenzaTemplateRepository();
                        $templates = $templateRepo->findAllAssignedToUser($idDominio, (int)$_SESSION['user']['id']);
                    } catch (\Throwable $e) {}
                }
                return $this->twig->render($response, 'pendenze/inserimento.html.twig', [
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'old' => $params,
                    'tipologie_pendenze' => $tipologie,
                    'pendenza_templates' => $templates,
                    'id_dominio' => $idDominio,
                    'id_a2a' => $idA2A,
                    'default_anno' => (int)date('Y'),
                ]);
            }
        }

        // Invia al Backoffice tramite helper
        $idPendenzaRaw = trim((string)($params['idPendenza'] ?? '')) ?: null;
        $sendResult = $this->sendPendenzaToBackoffice($payload, $idPendenzaRaw);
        if ($sendResult['success']) {
            // Mostra eventuali avvisi di sanitizzazione prima del messaggio di successo
            if (!empty($warnings)) {
                foreach ($warnings as $w) {
                    $_SESSION['flash'][] = ['type' => 'warning', 'text' => $w];
                }
            }
            
            $newId = $sendResult['idPendenza'] ?? null;
            $responsePayload = is_array($sendResult['response'] ?? null) ? $sendResult['response'] : [];
            $fetchedPendenza = null;
            [$iuvFromResponse, $numeroAvvisoFromResponse] = $this->extractIuvAndNumeroAvviso($responsePayload);
            if (($iuvFromResponse === '' || $numeroAvvisoFromResponse === '') && is_string($newId) && $newId !== '') {
                $fetchedPendenza = $this->fetchPendenzaById($newId);
                if (is_array($fetchedPendenza)) {
                    [$fetchedIuv, $fetchedNumeroAvviso] = $this->extractIuvAndNumeroAvviso($fetchedPendenza);
                    if ($iuvFromResponse === '') {
                        $iuvFromResponse = $fetchedIuv;
                    }
                    if ($numeroAvvisoFromResponse === '') {
                        $numeroAvvisoFromResponse = $fetchedNumeroAvviso;
                    }
                }
            }
            
            // Invio email automatico al cittadino se presente
            if ($newId && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $mailer = \App\Services\MailerService::forSuite('backoffice');

                    $pendenzaDataForEmail = [
                        'causale' => $causale,
                        'importo' => $importo,
                        'iuv' => $iuvFromResponse,
                        'numeroAvviso' => $numeroAvvisoFromResponse,
                    ];
                    if (!empty($params['dataScadenza'])) {
                        $pendenzaDataForEmail['dataScadenza'] = $params['dataScadenza'];
                    }
                    
                    // Costruisci gli URL del frontoffice per PDF e pagamento
                    $frontofficeUrl = $this->resolveFrontofficePublicBaseUrl($request);
                    $idDominio = (string)(
                        $responsePayload['idDominio']
                        ?? ($responsePayload['pendenza']['idDominio'] ?? null)
                        ?? (is_array($fetchedPendenza) ? ($fetchedPendenza['idDominio'] ?? null) : null)
                        ?? ($payload['idDominio'] ?? null)
                        ?? (getenv('ID_DOMINIO') ?: '')
                    );
                    $pdfUrl = '';
                    $paymentUrl = '';
                    $logoPath = $this->resolveEmbeddedLogoPath();
                    
                    if ($frontofficeUrl !== '') {
                        $paymentUrl = $this->buildCheckoutStartUrl(
                            $frontofficeUrl,
                            $identificativo,
                            $iuvFromResponse,
                            $numeroAvvisoFromResponse
                        );

                        // URL PDF firmato (preferito) o fallback classico.
                        $pdfUrl = $this->buildPdfDownloadUrl(
                            $frontofficeUrl,
                            $idDominio,
                            $identificativo,
                            $iuvFromResponse,
                            $numeroAvvisoFromResponse
                        );
                    }
                    
                    $appName = (string)(getenv('APP_ENTITY_NAME') ?: 'GIL');
                    $notificationResult = $mailer->sendPendenzaCreatedNotification(
                        $email,
                        $anagrafica,
                        $pendenzaDataForEmail,
                        $appName,
                        $pdfUrl,
                        $paymentUrl,
                        $logoPath
                    );
                    
                    // Aggiorna i datiAllegati della pendenza con le informazioni della notifica
                    if ($notificationResult['esito'] === 'OK') {
                        $this->addNotificationToPendenza($newId, $notificationResult);
                        $_SESSION['flash'][] = ['type' => 'info', 'text' => 'Email di notifica inviata al cittadino'];
                    } else {
                        Logger::getInstance()->warning('Errore invio email notifica pendenza', [
                            'idPendenza' => $newId,
                            'email' => $email,
                            'errore' => $notificationResult['errore'] ?? 'sconosciuto'
                        ]);
                        $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Email non inviata: ' . ($notificationResult['errore'] ?? 'errore sconosciuto')];
                    }
                } catch (\Throwable $e) {
                    Logger::getInstance()->error('Eccezione durante invio email notifica pendenza', [
                        'idPendenza' => $newId,
                        'email' => $email,
                        'exception' => $e->getMessage()
                    ]);
                    $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Impossibile inviare email di notifica'];
                }
            }

            // Invio messaggio App IO se codice fiscale disponibile e servizio IO configurato
            if ($newId && $identificativo !== '' && $tipoSog === 'F') {
                try {
                    $ioRepo = new \App\Database\IoServiceRepository();
                    // Prova a trovare il servizio associato alla tipologia, altrimenti quello predefinito
                    $ioService = $ioRepo->getTipologiaService($idTipo) ?? $ioRepo->findDefault();
                    
                    if ($ioService) {
                        $ioSvc = new \App\Services\AppIoService();
                        // Oggetto del messaggio allineato alle specifiche
                        $ioSubject = 'Pendenza PagoPA - ' . substr($causale, 0, 100);
                        
                        // Recupera IUV / numero avviso dalla risposta
                        $idDominio = (string)(
                            $responsePayload['idDominio']
                            ?? ($responsePayload['pendenza']['idDominio'] ?? null)
                            ?? (is_array($fetchedPendenza) ? ($fetchedPendenza['idDominio'] ?? null) : null)
                            ?? ($payload['idDominio'] ?? null)
                            ?? (getenv('ID_DOMINIO') ?: '')
                        );
                        $frontofficeUrl = $this->resolveFrontofficePublicBaseUrl($request);
                        $numeroAvvisoPendenza = $numeroAvvisoFromResponse;
                        $iuvPendenza = $iuvFromResponse !== '' ? $iuvFromResponse : $numeroAvvisoPendenza;

                        // Costruisci URL firmato PDF.
                        $signedPdfUrl = $this->buildPdfDownloadUrl(
                            $frontofficeUrl,
                            $idDominio,
                            $identificativo,
                            $iuvPendenza,
                            $numeroAvvisoPendenza
                        );

                        // Costruisci il contenuto markdown
                        $markdown = "## Pendenza PagoPA\n\n";
                        $markdown .= "**Causale**: " . $causale . "\n\n";
                        $markdown .= "**Importo**: € " . number_format($importo, 2, ',', '.') . "\n\n";
                        if ($iuvPendenza !== '') {
                            $markdown .= "**IUV**: " . $iuvPendenza . "\n\n";
                        }
                        if (!empty($params['dataScadenza'])) {
                            $markdown .= "**Scadenza**: " . $params['dataScadenza'] . "\n\n";
                        }
                        if ($signedPdfUrl !== '') {
                            $markdown .= "[Scarica il PDF dell'avviso](" . $signedPdfUrl . ")\n\n";
                        }
                        $markdown .= "Usa il pulsante **Paga ora** per effettuare il pagamento.";

                        // Costruisci payment_data: preferisci numero avviso/iuv avviso (18 cifre).
                        $paymentData = null;
                        $noticeNum = preg_replace('/\D/', '', $numeroAvvisoPendenza !== '' ? $numeroAvvisoPendenza : $iuvPendenza);
                        if (strlen($noticeNum) === 18) {
                            $paymentData = [
                                'noticeNumber' => $noticeNum,
                                'amount' => (int)round($importo * 100),
                                'invalidAfterDueDate' => !empty($params['dataScadenza']),
                            ];
                        }

                        $dueDate = !empty($params['dataScadenza'])
                            ? $params['dataScadenza']
                            : null;
                        // Se presente una data, normalizza in formato ISO8601 completo
                        if ($dueDate !== null && strlen($dueDate) === 10) {
                            $dueDate = $dueDate . 'T00:00:00Z';
                        }

                        $result = $ioSvc->sendMessage(
                            $ioService['api_key_primaria'],
                            $identificativo,
                            $ioSubject,
                            $markdown,
                            $dueDate,
                            $paymentData
                        );
                        
                        if ($result['esito'] === 'OK') {
                            $ioNotification = [
                                'timestamp' => date('Y-m-d H:i:s'),
                                'esito' => 'OK',
                                'canale' => 'app_io',
                                'destinatario' => $result['id'] ?? 'N/A',
                                'message_id' => $result['id'] ?? null,
                            ];
                            $this->addNotificationToPendenza((string)$newId, $ioNotification);
                            $_SESSION['flash'][] = ['type' => 'info', 'text' => 'Messaggio App IO inviato al cittadino'];
                        } else {
                            Logger::getInstance()->warning('Errore invio messaggio IO', [
                                'idPendenza' => $newId,
                                'identificativo' => substr($identificativo, -4),
                                'errore' => $result['errore'] ?? 'sconosciuto'
                            ]);
                            $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Messaggio App IO non inviato: ' . ($result['errore'] ?? 'errore sconosciuto')];
                        }
                    }
                } catch (\Throwable $e) {
                    Logger::getInstance()->error('Eccezione durante invio messaggio IO', [
                        'idPendenza' => $newId,
                        'exception' => $e->getMessage()
                    ]);
                    $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Impossibile inviare messaggio App IO'];
                }
            }
            
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Pendenza creata con successo'];
            if ($newId) {
                $base = '/pendenze/dettaglio/' . rawurlencode((string)$newId);
                $query = ['from' => 'insert'];
                if (!empty($params['return'])) {
                    $query['return'] = $params['return'];
                }
                $location = $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                return $response->withHeader('Location', $location)->withStatus(302);
            }
            return $response->withHeader('Location', '/pendenze/ricerca')->withStatus(302);
        }
        // Se l'invio fallisce, copia gli errori restituiti
        foreach ($sendResult['errors'] as $e) {
            $errors[] = $e;
        }

        // In caso di errore durante l'invio, ricarichiamo il form con gli errori
        $idDominio = getenv('ID_DOMINIO') ?: '';
        $tipologie = [];
        if ($idDominio) {
            try {
                $repo = new EntrateRepository();
                $tipologie = $repo->listAbilitateByDominio($idDominio);
            } catch (\Throwable $e) {}
        }
            $templates = [];
            if ($idDominio && isset($_SESSION['user']['id'])) {
                try {
                    $templateRepo = new \App\Database\PendenzaTemplateRepository();
                    $templates = $templateRepo->findAllAssignedToUser($idDominio, (int)$_SESSION['user']['id']);
                } catch (\Throwable $e) {}
            }
            return $this->twig->render($response, 'pendenze/inserimento.html.twig', [
                'errors' => $errors,
                'warnings' => $warnings,
                'old' => $params,
                'tipologie_pendenze' => $tipologie,
                'pendenza_templates' => $templates,
                'id_dominio' => $idDominio,
                'id_a2a' => $idA2A,
                'default_anno' => (int)date('Y'),
            ]);
    }

    public function search(Request $request, Response $response): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

        $sessionKey = 'pendenze_last_search';
        $sessionContext = $_SESSION[$sessionKey] ?? null;

        $params = (array)($request->getQueryParams() ?? []);
        $resetRequested = array_key_exists('reset', $params);
        if ($resetRequested) {
            unset($_SESSION[$sessionKey], $_SESSION['pendenze_last_highlight']);
            unset($params['reset']);
            $sessionContext = null;
        }

        $paramsWithoutHighlight = $params;
        unset($paramsWithoutHighlight['highlight']);
        $hasExplicitSearch = isset($params['q']) && trim((string)$params['q']) !== '';

        if (!$hasExplicitSearch) {
            $hasAnyFilter = false;
            foreach ($paramsWithoutHighlight as $value) {
                if ($value !== null && $value !== '') {
                    $hasAnyFilter = true;
                    break;
                }
            }

            if (!$hasAnyFilter && $sessionContext && isset($sessionContext['query_params'])) {
                $params = $sessionContext['query_params'];
                $hasExplicitSearch = true;
            } elseif ($hasAnyFilter) {
                $params['q'] = '1';
                $hasExplicitSearch = true;
            }
        }

        $errors = [];
        $queryMade = false;
        $results = null;
        $numPagine = null;
        $numRisultati = null;
        $prevUrl = null;
        $nextUrl = null;

        $envIdDominio = (string)(getenv('ID_DOMINIO') ?: '');
        $envIdA2A = (string)(getenv('ID_A2A') ?: '');
        $ordinamentoParam = isset($params['ordinamento']) ? (string)$params['ordinamento'] : '-dataCaricamento';

        $filters = [
            'q' => $hasExplicitSearch ? '1' : null,
            'pagina' => max(1, (int)($params['pagina'] ?? 1)),
            'risultatiPerPagina' => min(200, max(1, (int)($params['risultatiPerPagina'] ?? 25))),
            'ordinamento' => $ordinamentoParam,
            'idDominio' => (string)($params['idDominio'] ?? $envIdDominio),
            'idA2A' => (string)($params['idA2A'] ?? $envIdA2A),
            'idPendenza' => (string)($params['idPendenza'] ?? ''),
            'idDebitore' => (string)($params['idDebitore'] ?? ''),
            'stato' => (string)($params['stato'] ?? ''),
            'idPagamento' => (string)($params['idPagamento'] ?? ''),
            'dataDa' => (string)($params['dataDa'] ?? ''),
            'dataA' => (string)($params['dataA'] ?? ''),
            'direzione' => (string)($params['direzione'] ?? ''),
            'divisione' => (string)($params['divisione'] ?? ''),
            'iuv' => (string)($params['iuv'] ?? ''),
            'tipologiaPendenza' => (string)($params['tipologiaPendenza'] ?? ''),
        ];

        $idDominio = $filters['idDominio'];
        $tipologie = [];
        if ($idDominio !== '') {
            try {
                $repo = new EntrateRepository();
                $tipologie = $repo->listAbilitateByDominio($idDominio);
            } catch (\Throwable $e) {
                $tipologie = [];
            }
        }

        $allowedStates = class_exists(StatoPendenza::class)
            ? StatoPendenza::getAllowableEnumValues()
            : [];

        $validFields = ['dataCaricamento', 'dataValidita', 'dataScadenza', 'stato'];

        $normalizeOrder = static function (string $value, array $allowedFields): string {
            $value = trim($value);
            $direction = null;
            if ($value !== '') {
                $first = $value[0];
                if ($first === '+' || $first === '-') {
                    $direction = $first;
                    $value = substr($value, 1);
                }
            }
            $field = ltrim($value, '+-');
            if ($field === '' || !in_array($field, $allowedFields, true)) {
                $field = 'dataCaricamento';
            }
            if ($direction === null) {
                $direction = '+';
            }
            return $direction . $field;
        };

        $filters['ordinamento'] = $normalizeOrder((string)$filters['ordinamento'], $validFields);

        if ($filters['stato'] !== '' && !in_array($filters['stato'], $allowedStates, true)) {
            $errors[] = 'Valore "stato" non valido';
            $filters['stato'] = '';
        }

        if ($filters['q'] !== null) {
            $queryMade = true;
            $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
            if (!class_exists(BackofficePendenzeApi::class)) {
                $errors[] = 'Client Backoffice non disponibile (namespace GovPay\\Backoffice)';
            } elseif ($backofficeUrl === '') {
                $errors[] = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
            } else {
                try {
                    $config = new BackofficeConfiguration();
                    $config->setHost(rtrim($backofficeUrl, '/'));

                    $username = getenv('GOVPAY_USER');
                    $password = getenv('GOVPAY_PASSWORD');
                    if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                        $config->setUsername($username);
                        $config->setPassword($password);
                    }

                    $guzzleOptions = [];
                    $authMethod = getenv('AUTHENTICATION_GOVPAY');
                    if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
                        $cert = getenv('GOVPAY_TLS_CERT');
                        $key = getenv('GOVPAY_TLS_KEY');
                        $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
                        if (!empty($cert) && !empty($key)) {
                            $guzzleOptions['cert'] = $cert;
                            $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                        } else {
                            $errors[] = 'mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati';
                        }
                    }

                    $pagina = $filters['pagina'];
                    $rpp = $filters['risultatiPerPagina'];
                    $ordinamento = $filters['ordinamento'];
                    $idDominioFilter = $filters['idDominio'] !== '' ? $filters['idDominio'] : null;
                    $idA2AFilter = $filters['idA2A'] !== '' ? $filters['idA2A'] : null;
                    $idDebitore = $filters['idDebitore'] !== '' ? $filters['idDebitore'] : null;
                    $stato = $filters['stato'] !== '' ? $filters['stato'] : null;
                    $idPagamento = $filters['idPagamento'] !== '' ? $filters['idPagamento'] : null;
                    $idPendenza = $filters['idPendenza'] !== '' ? $filters['idPendenza'] : null;
                    $dataDa = $filters['dataDa'] !== '' ? $filters['dataDa'] : null;
                    $dataA = $filters['dataA'] !== '' ? $filters['dataA'] : null;
                    $direzione = $filters['direzione'] !== '' ? $filters['direzione'] : null;
                    $divisione = $filters['divisione'] !== '' ? $filters['divisione'] : null;
                    $iuv = $filters['iuv'] !== '' ? $filters['iuv'] : null;
                    $idEntrata = $filters['tipologiaPendenza'] !== '' ? $filters['tipologiaPendenza'] : null;

                    $mostraSpontanei = 'false';
                    $metadatiPaginazione = 'true';
                    $maxRisultati = 'true';

                    if ($idA2AFilter === null || $idA2AFilter === '') {
                        $errors[] = 'Parametro idA2A obbligatorio per la ricerca pendenze';
                    } else {
                        $url = rtrim($backofficeUrl, '/') . '/pendenze';
                        $query = [
                            'pagina' => $pagina,
                            'risultatiPerPagina' => $rpp,
                            'ordinamento' => $ordinamento,
                            'campi' => null,
                            'idDominio' => $idDominioFilter,
                            'idA2A' => $idA2AFilter,
                            'idDebitore' => $idDebitore,
                            'stato' => $stato,
                            'idPagamento' => $idPagamento,
                            'idPendenza' => $idPendenza,
                            'dataDa' => $dataDa,
                            'dataA' => $dataA,
                            'direzione' => $direzione,
                            'divisione' => $divisione,
                            'iuv' => $iuv,
                            'mostraSpontaneiNonPagati' => $mostraSpontanei,
                            'metadatiPaginazione' => $metadatiPaginazione,
                            'maxRisultati' => $maxRisultati,
                            'idTipoPendenza' => $idEntrata,
                        ];

                        $query = array_filter($query, static fn($v) => $v !== null && $v !== '');

                        if (getenv('APP_DEBUG') && $filters['q']) {
                            error_log('[PendenzeController] GET ' . $url . '?' . http_build_query($query));
                        }

                        $dataArr = $this->callBackofficeFindPendenze($query);

                        $extractInt = static function (array $source, array $paths): ?int {
                            foreach ($paths as $path) {
                                $cursor = $source;
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

                        $numPagine = $extractInt($dataArr, [
                            'numPagine',
                            'num_pagine',
                            'metaDatiPaginazione.numPagine',
                            'metaDatiPaginazione.num_pagine',
                            'metadatiPaginazione.numPagine',
                            'metadatiPaginazione.num_pagine',
                            'paginazione.numeroPagine',
                            'paginazione.numPagine',
                        ]);
                        $numRisultati = $extractInt($dataArr, [
                            'numRisultati',
                            'num_risultati',
                            'metaDatiPaginazione.numRisultati',
                            'metaDatiPaginazione.num_risultati',
                            'metadatiPaginazione.numRisultati',
                            'metadatiPaginazione.num_risultati',
                            'paginazione.numeroRisultati',
                            'paginazione.numRisultati',
                        ]);
                        if ($numPagine === null && $numRisultati !== null && $rpp > 0) {
                            $numPagine = (int)ceil($numRisultati / $rpp);
                        }
                        $results = $dataArr;

                        $basePath = $request->getUri()->getPath();
                        $qsBase = $params;
                        $qsBase['q'] = '1';
                        unset($qsBase['ordRecentiPrima'], $qsBase['highlight']);
                        $qsBase['ordinamento'] = $filters['ordinamento'];
                        $qsBase['pagina'] = $filters['pagina'];
                        $qsBase['risultatiPerPagina'] = $filters['risultatiPerPagina'];

                        $buildUrl = static fn(array $paramSet): string => $basePath . '?' . http_build_query($paramSet, '', '&', PHP_QUERY_RFC3986);
                        $extractQueryString = static function (string $link): string {
                            $parts = parse_url($link);
                            if ($parts !== false && isset($parts['query'])) {
                                return (string)$parts['query'];
                            }
                            $pos = strpos($link, '?');
                            return $pos === false ? '' : substr($link, $pos + 1);
                        };

                        if ($filters['pagina'] > 1) {
                            $prevParams = $qsBase;
                            $prevParams['pagina'] = $filters['pagina'] - 1;
                            $prevUrl = $buildUrl($prevParams);
                        }
                        if ($numPagine !== null && $filters['pagina'] < $numPagine) {
                            $nextParams = $qsBase;
                            $nextParams['pagina'] = $filters['pagina'] + 1;
                            $nextUrl = $buildUrl($nextParams);
                        }

                        $nextLinkRaw = $results['prossimiRisultati'] ?? $results['prossimi_risultati'] ?? null;
                        if ($nextUrl === null && is_string($nextLinkRaw) && $nextLinkRaw !== '') {
                            $queryString = $extractQueryString($nextLinkRaw);
                            if ($queryString !== '') {
                                $linkParams = $qsBase;
                                parse_str($queryString, $linkQuery);
                                if (isset($linkQuery['pagina'])) {
                                    $linkParams['pagina'] = max(1, (int)$linkQuery['pagina']);
                                } elseif (isset($linkQuery['page'])) {
                                    $linkParams['pagina'] = max(1, (int)$linkQuery['page']);
                                } elseif (isset($linkQuery['offset']) && isset($linkQuery['risultati_per_pagina'])) {
                                    $perPage = (int)$linkQuery['risultati_per_pagina'];
                                    $pageFromOffset = $perPage > 0 ? (int)floor(((int)$linkQuery['offset']) / $perPage) + 1 : null;
                                    if ($pageFromOffset !== null && $pageFromOffset > 0) {
                                        $linkParams['pagina'] = $pageFromOffset;
                                    }
                                }

                                if (isset($linkQuery['risultati_per_pagina'])) {
                                    $linkParams['risultatiPerPagina'] = max(1, (int)$linkQuery['risultati_per_pagina']);
                                } elseif (isset($linkQuery['risultatiPerPagina'])) {
                                    $linkParams['risultatiPerPagina'] = max(1, (int)$linkQuery['risultatiPerPagina']);
                                }

                                if (isset($linkQuery['ordinamento'])) {
                                    $linkParams['ordinamento'] = (string)$linkQuery['ordinamento'];
                                }

                                if (($linkParams['pagina'] ?? $filters['pagina']) !== $filters['pagina']) {
                                    $nextUrl = $buildUrl($linkParams);
                                }
                            }
                        }
                    }
                } catch (ClientException $ce) {
                    $errors[] = 'Errore chiamata Pendenze: ' . $ce->getMessage();
                    $detailBody = $ce->getResponse() ? (string)$ce->getResponse()->getBody() : '';
                    if ($detailBody !== '') {
                        $errors[] = 'Dettaglio API: ' . $detailBody;
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'Errore chiamata Pendenze: ' . $e->getMessage();
                }
            }
        }

        $returnParams = null;
        $returnUrl = '/pendenze/ricerca';
        if ($filters['q'] !== null) {
            $returnParams = [
                'q' => '1',
                'pagina' => $filters['pagina'],
                'risultatiPerPagina' => $filters['risultatiPerPagina'],
                'ordinamento' => $filters['ordinamento'],
            ];
            foreach (['idDominio','idA2A','idPendenza','idDebitore','stato','idPagamento','dataDa','dataA','direzione','divisione','iuv','tipologiaPendenza'] as $key) {
                $val = $filters[$key] ?? null;
                if ($val !== null && $val !== '') {
                    $returnParams[$key] = $val;
                }
            }
            $returnQuery = http_build_query($returnParams, '', '&', PHP_QUERY_RFC3986);
            $returnUrl = '/pendenze/ricerca' . ($returnQuery !== '' ? ('?' . $returnQuery) : '');

            $_SESSION[$sessionKey] = [
                'return_url' => $returnUrl,
                'filters' => $filters,
                'query_params' => $returnParams,
                'updated_at' => time(),
            ];
        } elseif ($sessionContext && isset($sessionContext['return_url'])) {
            $returnUrl = (string)$sessionContext['return_url'];
        }

        if (!is_string($returnUrl) || $returnUrl === '' || strpos($returnUrl, '/pendenze/ricerca') !== 0) {
            $returnUrl = '/pendenze/ricerca';
        }

        $highlightId = $params['highlight'] ?? null;
        if ($highlightId === null && isset($_SESSION['pendenze_last_highlight'])) {
            $highlightId = $_SESSION['pendenze_last_highlight'];
            unset($_SESSION['pendenze_last_highlight']);
        }

        $this->exposeCurrentUser();

        return $this->twig->render($response, 'pendenze/ricerca.html.twig', [
            'filters' => $filters,
            'errors' => $errors,
            'allowed_states' => $allowedStates,
            'results' => $results,
            'num_pagine' => $numPagine,
            'num_risultati' => $numRisultati,
            'query_made' => $queryMade,
            'prev_url' => $prevUrl,
            'next_url' => $nextUrl,
            'return_url' => $returnUrl,
            'highlight_id' => $highlightId,
            'tipologie_pendenze' => $tipologie,
        ]);
    }

    public function showInsert(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();

        // If the inserimento route is called with POST (from preview->Modifica),
        // use posted params to prefill the form via `old` variable in the template.
        $posted = (array)($request->getParsedBody() ?? []);

        // Recupera tipologie abilitata per il dominio (se configurato)
        $idDominio = getenv('ID_DOMINIO') ?: '';
        $tipologie = [];
        if ($idDominio) {
            try {
                $repo = new EntrateRepository();
                $tipologie = $repo->listAbilitateByDominio($idDominio);
            } catch (\Throwable $e) {
                // Non blocchiamo la pagina: se il repository fallisce mostriamo comunque il form vuoto
                $tipologie = [];
            }
        }

        $templates = [];
        if ($idDominio && isset($_SESSION['user']['id'])) {
            try {
                $templateRepo = new \App\Database\PendenzaTemplateRepository();
                $templates = $templateRepo->findAllByDominioForUser($idDominio, (int)$_SESSION['user']['id']);
            } catch (\Throwable $e) {
                error_log("Error fetching Pendenza Templates: " . $e->getMessage());
            }
        }

        return $this->twig->render($response, 'pendenze/inserimento.html.twig', [
            'tipologie_pendenze' => $tipologie,
            'pendenza_templates' => $templates,
            'id_dominio' => $idDominio,
            'id_a2a' => getenv('ID_A2A') ?: '',
            'default_anno' => (int)date('Y'),
            'old' => $posted,
        ]);
    }

    public function showBulkInsert(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        return $this->twig->render($response, 'pendenze/inserimento_massivo.html.twig');
    }

    /**
     * Anteprima della pendenza prima dell'invio: rende una pagina di conferma
     */
    public function preview(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        $params = (array)($request->getParsedBody() ?? []);
        // Validazioni leggere: ricava campi essenziali e passa alla view di conferma
        $errors = [];
        $idTipo = trim((string)($params['idTipoPendenza'] ?? ''));
        $causale = trim((string)($params['causale'] ?? ''));
        $importo = $params['importo'] ?? '';
        $voci = $params['voci'] ?? [];
        if ($idTipo === '') $errors[] = 'Tipologia pendenza obbligatoria';
        if ($causale === '') $errors[] = 'Causale obbligatoria';
        if ($importo === '' || !is_numeric(str_replace(',', '.', (string)$importo))) $errors[] = 'Importo non valido';
        // Normalizza importo
        $importoFloat = is_numeric(str_replace(',', '.', (string)$importo)) ? (float)str_replace(',', '.', (string)$importo) : 0.0;

        // Recupera tipologia per mostrare descrizione
        $idDominio = getenv('ID_DOMINIO') ?: '';
        $tipologia = null;
        if ($idDominio && $idTipo) {
            try {
                $repo = new EntrateRepository();
                $tipologia = $repo->findDetails($idDominio, $idTipo);
            } catch (\Throwable $_) { $tipologia = null; }
        }

        // Calcola i canali di notifica attesi (non bloccante)
        $canaliNotifica = [];
        $sog = $params['soggettoPagatore'] ?? [];
        if (!is_array($sog)) $sog = [];
        $tipoSogPreview = strtoupper((string)($sog['tipo'] ?? 'F'));
        $emailPreview = trim((string)($sog['email'] ?? ''));
        $cfPreview = strtoupper(trim((string)($sog['identificativo'] ?? '')));

        if ($emailPreview !== '' && filter_var($emailPreview, FILTER_VALIDATE_EMAIL)) {
            $canaliNotifica[] = ['canale' => 'email', 'label' => 'Email', 'attivo' => true, 'motivo' => null];
        } else {
            $canaliNotifica[] = ['canale' => 'email', 'label' => 'Email', 'attivo' => false, 'motivo' => 'Indirizzo email non fornito o non valido'];
        }

        if ($tipoSogPreview === 'F' && $cfPreview !== '') {
            // Verifica se esiste un servizio IO per questa tipologia o il default
            $ioAttivo = false;
            $ioMotivo = null;
            try {
                $ioRepo = new \App\Database\IoServiceRepository();
                $ioSvc = $ioRepo->getTipologiaService($idTipo) ?? $ioRepo->findDefault();
                if ($ioSvc !== null) {
                    $ioAttivo = true;
                } else {
                    $ioMotivo = 'Nessun servizio App IO configurato (né per tipologia né predefinito)';
                }
            } catch (\Throwable $_) {
                $ioMotivo = 'Impossibile verificare la configurazione App IO';
            }
            $canaliNotifica[] = ['canale' => 'app_io', 'label' => 'App IO', 'attivo' => $ioAttivo, 'motivo' => $ioMotivo];
        } else {
            $canaliNotifica[] = [
                'canale' => 'app_io',
                'label' => 'App IO',
                'attivo' => false,
                'motivo' => $tipoSogPreview !== 'F'
                    ? 'App IO disponibile solo per soggetti Persona Fisica (F)'
                    : 'Codice fiscale non fornito',
            ];
        }

        return $this->twig->render($response, 'pendenze/conferma.html.twig', [
            'errors' => $errors,
            'params' => $params,
            'tipologia' => $tipologia,
            'importo' => $importoFloat,
            'voci' => $voci,
            'canali_notifica' => $canaliNotifica,
        ]);
    }

    /**
     * Mostra la form per generare le rate di una pendenza (da preview)
     */
    public function showRateizzazione(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        $params = (array)($request->getParsedBody() ?? []);
        // Attendi i dati della pendenza da preview
        $importo = is_numeric(str_replace(',', '.', (string)($params['importo'] ?? ''))) ? (float)str_replace(',', '.', (string)$params['importo']) : 0.0;
        $defaultRates = (int)($params['rate'] ?? 3);
        $rate = max(1, $defaultRates);
        // If the caller provided explicit parts (from preview/previous edit), prefer them
        // and preserve any empty dataScadenza values the user left blank. Only when no
        // parts are provided we generate defaults via the service.
        $parts = [];
        if (!empty($params['parts']) && is_array($params['parts'])) {
            foreach ($params['parts'] as $k => $pp) {
                $idx = is_numeric($k) ? ((int)$k) : $k;
                $parts[] = [
                    'indice' => isset($pp['indice']) ? (int)$pp['indice'] : ($idx + 1),
                    'importo' => isset($pp['importo']) ? $pp['importo'] : (isset($pp['amount']) ? $pp['amount'] : ''),
                    // preserve submitted names and empty strings (do not fill defaults)
                    'dataValidita' => $pp['dataValidita'] ?? $pp['data_validita'] ?? '',
                    'dataScadenza' => array_key_exists('dataScadenza', $pp) ? ($pp['dataScadenza'] ?? '') : ($pp['data_scadenza'] ?? ''),
                ];
            }
        } else {
            // Build default parts using service
            $parts = \App\Services\RateizzazioneService::buildPartsWithDates($importo, $rate);
        }
        return $this->twig->render($response, 'pendenze/rateizzazione.html.twig', [
            'params' => $params,
            'importo' => $importo,
            'parts' => $parts,
        ]);
    }

    /**
     * Riceve la richiesta di creare la pendenza con le rate specificate.
     * Valida la somma e poi richiama internamente create() con i parametri modificati
     */
    public function createRateizzazione(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        $params = (array)($request->getParsedBody() ?? []);
        // Safety guard: require an explicit confirmation flag to actually create the rates.
        // This avoids accidental creation when a POST targeting this route is triggered
        // by navigation or ambiguous submissions (e.g. from the rateizzazione 'Annulla').
        if (empty($params['confirm_create_rate'])) {
            // Delegate back to preview to show the confirmation page instead of creating.
            return $this->preview($request, $response);
        }
        $originalJson = $params['original_params'] ?? null;
        $orig = $originalJson ? json_decode($originalJson, true) : [];
        // Merge explicit posted scalar values over decoded original (safety)
        foreach ($params as $k => $v) {
            // Evitiamo di propagare parametri della view (es. 'return') nei
            // payload inviati al Backoffice. Skip espliciti per i parametri
            // usati solo dalla UI.
            if (in_array($k, ['original_params', 'parts', 'return', 'submit', 'csrf_token'], true)) continue;
            if (is_array($v)) continue; // parts or nested arrays handled elsewhere
            $orig[$k] = $v;
        }
        $parts = $params['parts'] ?? [];
        // Normalizza importi
        $sumCents = 0;
        foreach ($parts as $p) {
            $raw = (string)($p['importo'] ?? '');
            $v = is_numeric(str_replace(',', '.', $raw)) ? (float)str_replace(',', '.', $raw) : 0.0;
            $sumCents += (int)round($v * 100);
        }
        $originalTotalRaw = (string)($orig['importo'] ?? '');
        $originalTotal = is_numeric(str_replace(',', '.', $originalTotalRaw)) ? (float)str_replace(',', '.', $originalTotalRaw) : 0.0;
        $totalCents = (int)round($originalTotal * 100);
        if ($sumCents !== $totalCents) {
            // Ritorna al form di rateizzazione con messaggio d'errore e dati ricostruiti
            $error = 'La somma delle rate (' . number_format($sumCents/100, 2, ',', '.') . ') non corrisponde esattamente all\'importo totale (' . number_format($totalCents/100, 2, ',', '.') . ').';
            return $this->twig->render($response, 'pendenze/rateizzazione.html.twig', [
                'params' => $orig,
                'importo' => $totalCents/100,
                'parts' => $parts,
                'errors' => [$error],
            ]);
        }
        // Costruisci il documento di rateizzazione e non sostituire le voci originali.
        // Manteniamo le voci originali (la pendenza rimane concettualmente la stessa)
        $doc = is_array($orig['documento'] ?? null) ? $orig['documento'] : [];
        // assicurati identificativo/descrizione documento
        if (empty($doc['identificativo'])) {
            try {
                $doc['identificativo'] = 'RATA-' . substr(bin2hex(random_bytes(6)), 0, 12);
            } catch (\Throwable $_) {
                $doc['identificativo'] = 'RATA-' . uniqid();
            }
        }
        // Imposta la descrizione del documento sulla causale originale (dal form precedente),
        // aggiungendo il numero di rate tra parentesi.
        $originalCausale = $orig['causale'] ?? 'Pagamento rateizzato';
        $numRate = count($parts);
        $doc['descrizione'] = trim((string)$originalCausale) . " ({$numRate} rate)";

        $doc['rata'] = [];
        foreach ($parts as $idx => $p) {
            $doc['rata'][] = [
                'indice' => isset($p['indice']) ? (int)$p['indice'] : ($idx + 1),
                'importo' => is_numeric(str_replace(',', '.', (string)($p['importo'] ?? ''))) ? (float)str_replace(',', '.', (string)$p['importo']) : 0.0,
                'dataValidita' => $p['dataValidita'] ?? ($p['data_validita'] ?? null),
                'dataScadenza' => $p['dataScadenza'] ?? ($p['data_scadenza'] ?? null),
            ];
        }

        // Merge: mantieni le voci originali e aggiungi il documento + metadata di rate
        $merged = $orig;
        $merged['documento'] = $doc;
        $merged['proprieta'] = is_array($merged['proprieta'] ?? null) ? $merged['proprieta'] : [];
        
        // Inject operator and source information
        $merged['datiAllegati'] = $merged['datiAllegati'] ?? [];
        $merged['datiAllegati']['operatore'] = $this->getCurrentOperatorString();
        $merged['datiAllegati']['sorgente'] = 'GIL-Backoffice';

        $merged['proprieta']['numeroRate'] = count($parts);
        $merged['proprieta']['rate'] = $doc['rata'];

        // Genera un idPendenza deterministico (client-side) se non fornito, in modo
        // che il redirect al dettaglio sia prevedibile. Rispetta il pattern
        // (^ [A-Za-z0-9\-_]{1,35} $)
        $idPendenzaRaw = trim((string)($merged['idPendenza'] ?? ''));
        if ($idPendenzaRaw === '') {
            try {
                $rand = bin2hex(random_bytes(8));
            } catch (\Throwable $_) {
                $rand = preg_replace('/[^A-Za-z0-9]/', '', uniqid());
            }
            $idPendenzaCand = 'GIL-' . substr($rand, 0, 16);
        } else {
            $idPendenzaCand = $idPendenzaRaw;
        }
        $idPendenzaSanitized = preg_replace('/[^A-Za-z0-9\-_]/', '-', $idPendenzaCand);
        $idPendenzaSanitized = substr($idPendenzaSanitized, 0, 35);
        $merged['idPendenza'] = $idPendenzaSanitized;

    // Inviare ogni rata come singola pendenza attraverso l'API Pendenze (PUT).
    // Creiamo una pendenza per ogni rata e chiamiamo internamente
    // sendPendenzaToBackoffice(). Se tutte le invii hanno successo, redirect
    // alla ricerca; altrimenti mostriamo gli errori.
    $created = [];
    $responses = [];
    $allErrors = [];
    $baseId = $merged['idPendenza'] ?? '';
    $totalParts = count($parts); // Numero totale delle rate
    foreach ($parts as $idx => $p) {
        $rIndex = isset($p['indice']) ? (int)$p['indice'] : ($idx + 1);
        $single = $merged;
        // imposta importo e date specifiche della rata
        $single['importo'] = is_numeric(str_replace(',', '.', (string)($p['importo'] ?? ''))) ? (float)str_replace(',', '.', (string)$p['importo']) : 0.0;
        if (!empty($p['dataValidita'])) $single['dataValidita'] = $p['dataValidita'];
        if (!empty($p['dataScadenza'])) $single['dataScadenza'] = $p['dataScadenza'];
        $docSingle = is_array($single['documento'] ?? null) ? $single['documento'] : [];
        $docSingle['rata'] = $rIndex;

        // *** INIZIO MODIFICA DESCRIZIONE SINGOLA RATA ***
        // Recupera la causale originale (che è la "Descrizione originale" dal form)
        $originalCausale = $orig['causale'] ?? 'Pagamento rateizzato';
        // Assicurati che rIndex sia un intero valido
        if (!is_int($rIndex) || $rIndex < 1) {
            $rIndex = $idx + 1;
        }
        // Assicurati che totalParts sia un intero valido
        if (!is_int($totalParts) || $totalParts < 1) {
            $totalParts = count($parts);
        }
        // Imposta la causale della singola rata
        $single['causale'] = trim((string)$originalCausale) . " (Rata {$rIndex} di {$totalParts})";
        // Imposta anche la descrizione del documento della singola rata (per completezza)
        $docSingle['descrizione'] = trim((string)$originalCausale) . " (Rate {$totalParts})";
        // *** FINE MODIFICA DESCRIZIONE SINGOLA RATA ***

        $single['documento'] = $docSingle;
        // idPendenza per la rata
        $idPForRate = ($baseId !== '' ? $baseId : (function() {
            try { return 'GIL-' . substr(bin2hex(random_bytes(8)), 0, 16); } catch (\Throwable $_) { return 'GIL-' . uniqid(); }
        })()) . '-R' . $rIndex;
        $single['idPendenza'] = preg_replace('/[^A-Za-z0-9\-_]/', '-', substr((string)$idPForRate, 0, 35));

        // DB-first: normalize voci (try to enrich from DB and validate required accounting fields)
        $localVociErrors = [];
        $idDominioUsed = $single['idDominio'] ?? (getenv('ID_DOMINIO') ?: '');
        // Preserve the original voci as basis for proportional allocation
        $originalVociBasis = is_array($single['voci'] ?? null) ? $single['voci'] : [];
        $single['voci'] = $this->buildVociForInsertionFromMerged($single, $single['voci'] ?? [], $idDominioUsed, $localVociErrors);

        // Ensure the sum of voce.importo equals the rata importo by allocating
        // amounts proportionally (in cents) based on the original voci basis.
        $rateTotal = is_numeric(str_replace(',', '.', (string)($single['importo'] ?? 0))) ? (float)str_replace(',', '.', (string)$single['importo']) : 0.0;
        $rateCents = (int)round($rateTotal * 100);
        $allocatedCents = [];
        $numBuilt = count($single['voci']);
        $origSum = 0.0;
        foreach ($originalVociBasis as $ov) {
            $origSum += is_numeric(str_replace(',', '.', (string)($ov['importo'] ?? 0))) ? (float)str_replace(',', '.', (string)$ov['importo']) : 0.0;
        }
        // Choose an allocation basis: original basis if counts match and sum > 0,
        // otherwise fall back to built voci amounts; if still zero, equal split.
        $basis = $originalVociBasis;
        $basisSum = $origSum;
        if ($basisSum <= 0.0 || count($basis) !== $numBuilt) {
            $basis = $single['voci'];
            $basisSum = 0.0;
            foreach ($basis as $b) {
                $basisSum += is_numeric(str_replace(',', '.', (string)($b['importo'] ?? 0))) ? (float)str_replace(',', '.', (string)$b['importo']) : 0.0;
            }
        }

        if ($numBuilt === 0) {
            $localVociErrors[] = 'Nessuna voce definita per la rata.';
        } else {
            if ($basisSum > 0.0) {
                // proportional allocation
                for ($i = 0; $i < $numBuilt; $i++) {
                    $share = is_numeric(str_replace(',', '.', (string)($basis[$i]['importo'] ?? 0))) ? (float)str_replace(',', '.', (string)$basis[$i]['importo']) : 0.0;
                    $allocatedCents[$i] = (int)round(($share / $basisSum) * $rateCents);
                }
            } else {
                // equal split
                $base = intdiv($rateCents, $numBuilt);
                $rem = $rateCents - ($base * $numBuilt);
                for ($i = 0; $i < $numBuilt; $i++) {
                    $allocatedCents[$i] = $base + ($i < $rem ? 1 : 0);
                }
            }

            // Fix rounding differences by distributing the diff across entries
            $sumAllocated = array_sum($allocatedCents);
            $diff = $rateCents - $sumAllocated;
            if ($diff !== 0) {
                $step = $diff > 0 ? 1 : -1;
                $remain = abs($diff);
                for ($k = 0; $k < $remain; $k++) {
                    $idx = $k % $numBuilt;
                    $allocatedCents[$idx] += $step;
                }
            }

            // Apply allocated amounts back to built voci
            for ($i = 0; $i < $numBuilt; $i++) {
                $single['voci'][$i]['importo'] = ($allocatedCents[$i] ?? 0) / 100.0;

                // *** INIZIO MODIFICA RICHIESTA ***
                // Modifica la descrizione della voce per includere il numero rata
                $originalDesc = $single['voci'][$i]['descrizione'] ?? $merged['causale'] ?? 'Pagamento';
                // Rimuovi eventuale "Rata X di Y" precedente se la descrizione la conteneva già
                $originalDesc = preg_replace('/\s*\(Rata \d+ di \d+\)$/', '', $originalDesc);
                $single['voci'][$i]['descrizione'] = trim($originalDesc) . " (Rata {$rIndex} di {$totalParts})";
                // *** FINE MODIFICA RICHIESTA ***
            }

            if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                Logger::getInstance()->debug('Allocated voce importi per rata', [
                    'idPendenza' => $idPForRate,
                    'rata_importo' => $rateTotal,
                    'basis_sum' => $basisSum,
                    'allocated' => array_map(fn($c) => $c / 100.0, $allocatedCents),
                ]);
            }
        }
        if (!empty($localVociErrors)) {
            foreach ($localVociErrors as $le) $allErrors[] = "Rata {$rIndex}: " . $le;
            // skip sending this rata
            continue;
        }

        // Sanitize top-level keys to avoid sending UI-only parameters (es. 'return')
        $allowed = [
            'idTipoPendenza','idDominio','causale','importo','annoRiferimento',
            'soggettoPagatore','voci','documento','proprieta','allegati',
            'dataValidita','dataScadenza','direzione','divisione','cartellaPagamento',
            'numeroAvviso','tassonomia','dataPromemoriaScadenza','idUnitaOperativa',
            'dataCaricamento','datiAllegati','tassonomiaAvviso','dataNotificaAvviso',
            'nome'
        ];
    // Normalizza il soggettoPagatore: unisci nome+anagrafica e rimuovi 'nome'
        if (isset($single['soggettoPagatore']) && is_array($single['soggettoPagatore'])) {
            $s = $single['soggettoPagatore'];
            $tipo = strtoupper((string)($s['tipo'] ?? 'F'));
            $anag = trim((string)($s['anagrafica'] ?? ''));
            $nome = trim((string)($s['nome'] ?? ''));
            if ($tipo === 'F') {
                $full = trim(($nome !== '' ? $nome . ' ' : '') . $anag);
                $s['anagrafica'] = $full !== '' ? $full : $anag;
            } else {
                $s['anagrafica'] = $anag;
            }
            // Email: keep only if scalar and valid
            if (array_key_exists('email', $s)) {
                $raw = $s['email'];
                if (!is_scalar($raw) || trim((string)$raw) === '' || filter_var(trim((string)$raw), FILTER_VALIDATE_EMAIL) === false) {
                    unset($s['email']);
                } else {
                    $s['email'] = trim((string)$raw);
                }
            }
            // Cellulare: keep only scalar non-empty
            if (array_key_exists('cellulare', $s)) {
                $raw = $s['cellulare'];
                if (!is_scalar($raw) || trim((string)$raw) === '') {
                    unset($s['cellulare']);
                } else {
                    $s['cellulare'] = trim((string)$raw);
                }
            }
            if (isset($s['nome'])) unset($s['nome']);
            $single['soggettoPagatore'] = $s;
        }

        // Rimuoviamo campi che possono essere passati come array vuoto dalla UI
        // e che il Backoffice si aspetta come stringhe (es. cartellaPagamento).
        $stringFields = ['cartellaPagamento', 'direzione', 'divisione'];
        foreach ($stringFields as $sf) {
            if (!array_key_exists($sf, $single)) continue;
            $val = $single[$sf];
            if (!is_scalar($val) || trim((string)$val) === '') {
                unset($single[$sf]);
            } else {
                $single[$sf] = trim((string)$val);
            }
        }

        // Sanitize 'proprieta' per evitare di inviare campi non supportati
        if (isset($single['proprieta']) && is_array($single['proprieta'])) {
            $allowedPropKeys = [
                'descrizioneImporto', 'lineaTestoRicevuta1', 'lineaTestoRicevuta2',
                'linguaSecondaria', 'linguaSecondariaCausale'
            ];
            $single['proprieta'] = array_intersect_key($single['proprieta'], array_flip($allowedPropKeys));
            if (empty($single['proprieta'])) {
                unset($single['proprieta']);
            }
        }

        $single = array_intersect_key($single, array_flip($allowed));

        // Rimuoviamo idPendenza dal body (deve essere passato solo nell'URL)
        $idForUrl = $idPForRate;
        if (isset($single['idPendenza'])) unset($single['idPendenza']);

        // Invia singola pendenza
        $res = $this->sendPendenzaToBackoffice($single, $idForUrl);
        $responses[] = $res;
        if (!empty($res) && !empty($res['success'])) {
            $created[] = $res['idPendenza'] ?? $idForUrl;
        } else {
            $errs = $res['errors'] ?? ['Errore invio pendenza rata ' . $rIndex];
            foreach ((array)$errs as $e) $allErrors[] = "Rata {$rIndex}: " . (string)$e;
        }
    }

    // moved buildVociForInsertionFromMerged to class scope (below)

    if (empty($allErrors)) {
        // Tutte le rate inviate con successo: costruiamo un documento multirata
        // da poter stampare. Salviamo in sessione il documento sintetico con le
        // risposte per ogni rata (ad uso stampa/preview).
        $multi = [
            'documento' => $merged['documento'] ?? null,
            'proprieta' => $merged['proprieta'] ?? null,
            'soggettoPagatore' => $merged['soggettoPagatore'] ?? null,
            'voci' => $merged['voci'] ?? null,
            'idDominio' => $merged['idDominio'] ?? (getenv('ID_DOMINIO') ?: ''),
            'rates' => [],
            'responses' => $responses,
        ];
        foreach ($parts as $i => $p) {
            $rateId = $created[$i] ?? null;
            $rateResp = $controllerRate = null; // placeholder
            // sendPendenzaToBackoffice returns 'response' when available; try to get it
            // from past call results: in our loop above we used $res variable but
            // did not persist it — modify loop to persist responses if needed.
        }
        // Forniamo un semplice array con gli id creati e i metadati della rata
        foreach ($created as $i => $cid) {
            $r = $parts[$i] ?? [];
            $resp = $responses[$i]['response'] ?? null;
            $numeroAvviso = null;
            if (is_array($resp)) {
                if (!empty($resp['numeroAvviso'])) $numeroAvviso = $resp['numeroAvviso'];
                elseif (!empty($resp['numero_avviso'])) $numeroAvviso = $resp['numero_avviso'];
                elseif (!empty($resp['pendenza']['numeroAvviso'])) $numeroAvviso = $resp['pendenza']['numeroAvviso'];
                elseif (!empty($resp['pendenza']['numero_avviso'])) $numeroAvviso = $resp['pendenza']['numero_avviso'];
                elseif (!empty($resp['avvisi'][0]['numeroAvviso'])) $numeroAvviso = $resp['avvisi'][0]['numeroAvviso'];
            }
            $multi['rates'][] = [
                'idPendenza' => $cid,
                'indice' => $r['indice'] ?? ($i + 1),
                'importo' => $r['importo'] ?? null,
                'dataValidita' => $r['dataValidita'] ?? null,
                'dataScadenza' => $r['dataScadenza'] ?? null,
                'numeroAvviso' => $numeroAvviso,
                'backoffice_response' => $resp,
            ];
        }
        // Memorizza il documento sintetico in sessione per il rendering/stampa
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $_SESSION['multi_rate_document'] = $multi;

        if (!empty($created)) {
            foreach ($created as $c) {
                $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Pendenza creata: ' . $c];
            }
            // Redirect to the dettaglio of the first created rata (behaviour like insertion)
            $firstId = $created[0];
            $loc = '/pendenze/dettaglio/' . rawurlencode((string)$firstId) . '?from=insert';
            return $response->withHeader('Location', $loc)->withStatus(302);
        } else {
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Rate create con successo'];
            // Fallback: preview multi-rate document
            $loc = '/pendenze/multirata/preview';
            if (!empty($created[0])) $loc .= '?id=' . rawurlencode($created[0]);
            return $response->withHeader('Location', $loc)->withStatus(302);
        }
    }

    // Se siamo qui, almeno una rata ha fallito: ritorniamo al form con errori
    return $this->twig->render($response, 'pendenze/rateizzazione.html.twig', [
        'params' => $orig,
        'importo' => $originalTotal,
        'parts' => $parts,
        'errors' => $allErrors,
    ]);
    }

    /**
     * Helper: invia singola pendenza al backoffice. Ritorna array con chiavi
     * 'success' (bool), 'idPendenza' e 'errors' (array)
     */
    public function sendPendenzaToBackoffice(array $payload, ?string $idPendenza = null): array
    {
        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        $idA2A = getenv('ID_A2A') ?: '';
        $errors = [];
        $warnings = [];
        if (empty($backofficeUrl)) {
            $errors[] = 'GOVPAY_BACKOFFICE_URL non impostata';
            return ['success' => false, 'errors' => $errors];
        }
        if ($idA2A === '') {
            $errors[] = 'ID_A2A non impostata';
            return ['success' => false, 'errors' => $errors];
        }

        $guzzleOptions = [];
        $authMethod = getenv('AUTHENTICATION_GOVPAY');
        if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
            $cert = getenv('GOVPAY_TLS_CERT');
            $key = getenv('GOVPAY_TLS_KEY');
            $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert'] = $cert;
                $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            } else {
                $errors[] = 'mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati';
                return ['success' => false, 'errors' => $errors];
            }
        }

        try {
            $httpClient = new Client($guzzleOptions);
            // Genera idPendenza se non fornito
            $idP = trim((string)($idPendenza ?? ($payload['idPendenza'] ?? '')));
            if ($idP === '') {
                try {
                    $rand = bin2hex(random_bytes(8));
                } catch (\Throwable $_) {
                    $rand = preg_replace('/[^A-Za-z0-9]/', '', uniqid());
                }
                $idPCand = 'GIL-' . substr($rand, 0, 16);
                $idP = preg_replace('/[^A-Za-z0-9\-_]/', '-', substr($idPCand, 0, 35));
            }

            // Ensure idPendenza is not sent in the request body: the API expects it in the URL
            if (array_key_exists('idPendenza', $payload)) {
                if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                    Logger::getInstance()->debug('Removed idPendenza from request body (sent in URL)', ['idPendenza' => $payload['idPendenza']]);
                }
                unset($payload['idPendenza']);
            }
            // Defensive sanitization: filter proprieta to only allowed Backoffice fields
            if (isset($payload['proprieta']) && is_array($payload['proprieta'])) {
                $allowedPropKeys = [
                    'descrizioneImporto', 'lineaTestoRicevuta1', 'lineaTestoRicevuta2',
                    'linguaSecondaria', 'linguaSecondariaCausale'
                ];
                $payload['proprieta'] = array_intersect_key($payload['proprieta'], array_flip($allowedPropKeys));
                if (empty($payload['proprieta'])) {
                    unset($payload['proprieta']);
                }
                if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                    Logger::getInstance()->debug('Sanitized proprieta before sending pendenza', ['proprieta' => $payload['proprieta'] ?? null]);
                }
            }
            // Remove empty string-like fields that the Backoffice enforces as non-empty
            foreach (['cartellaPagamento', 'direzione', 'divisione'] as $sf) {
                if (isset($payload[$sf]) && (!is_scalar($payload[$sf]) || trim((string)$payload[$sf]) === '')) {
                    if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                        Logger::getInstance()->debug('Removed empty string-like field before send', ['field' => $sf]);
                    }
                    unset($payload[$sf]);
                }
            }

            $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idP);
            $username = getenv('GOVPAY_USER');
            $password = getenv('GOVPAY_PASSWORD');
            $requestOptions = [
                'headers' => ['Accept' => 'application/json'],
                'json' => $payload,
            ];
            if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                $requestOptions['auth'] = [$username, $password];
            }

            if ((getenv('APP_DEBUG') !== false) && getenv('APP_DEBUG')) {
                Logger::getInstance()->debug('Pendenze PUT ' . $url, ['payload' => $payload]);
            }

            $resp = $httpClient->request('PUT', $url, $requestOptions);
            $code = $resp->getStatusCode();
            $body = (string)$resp->getBody();
            $data = json_decode($body, true);
            return ['success' => $code >= 200 && $code < 300, 'idPendenza' => $idP, 'errors' => [], 'response' => $data];
        } catch (ClientException $ce) {
            $detail = '';
            $resp = $ce->getResponse();
            if ($resp) {
                try {
                    $stream = $resp->getBody();
                    if (is_callable([$stream, 'rewind'])) {
                        try { $stream->rewind(); } catch (\Throwable $_) { }
                    }
                    if (is_callable([$stream, 'getContents'])) {
                        $detail = $stream->getContents();
                    } else {
                        $detail = (string)$stream;
                    }
                } catch (\Throwable $_) {
                    try { $detail = (string)$resp->getBody(); } catch (\Throwable $_) { $detail = ''; }
                }
            }
            $errors[] = $detail ?: $ce->getMessage();
            // Non esponiamo il raw_response alla UI; i log Guzzle con APP_DEBUG
            // contengono l'intera transazione per i debug necessari.
            $parsed = null;
            if ($detail !== '') {
                $tmp = json_decode($detail, true);
                $parsed = (json_last_error() === JSON_ERROR_NONE) ? $tmp : $detail;
            }
            return ['success' => false, 'errors' => $errors, 'response' => $parsed];
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            return ['success' => false, 'errors' => $errors];
        }
    }

    /**
     * Applica la logica di arricchimento delle voci partendo da voci base (id/descrizione/importo)
     * e dai parametri avanzati forniti dal form o dalla pendenza corrente.
     */
    public function buildVociWithAccounting(array $baseVoci, array $params, ?array $currentPendenza, array &$errors, ?array &$warnings = null): array
    {
        if ($warnings === null) {
            $warnings = [];
        }
        $idDominioUsed = (string)($params['idDominio'] ?? '');
        if ($idDominioUsed === '' && is_array($currentPendenza) && isset($currentPendenza['idDominio'])) {
            $idDominioUsed = (string)$currentPendenza['idDominio'];
        }
        if ($idDominioUsed === '') {
            $idDominioUsed = (string)(getenv('ID_DOMINIO') ?: '');
        }

        $idTipo = trim((string)($params['idTipoPendenza'] ?? ''));
        if ($idTipo === '' && is_array($currentPendenza)) {
            $idTipo = $this->extractIdTipoFromPendenza($currentPendenza);
        }

        $details = null;
        if ($idDominioUsed !== '' && $idTipo !== '') {
            try {
                $repo = new EntrateRepository();
                $details = $repo->findDetails($idDominioUsed, $idTipo);
            } catch (\Throwable $_) {
                $details = null;
            }
        }

        $existingAccounting = $this->extractAccountingDefaultsFromPendenza($currentPendenza);
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

        if ($finalIban === '' && isset($existingAccounting['ibanAccredito'])) {
            $finalIban = (string)$existingAccounting['ibanAccredito'];
        }
        if ($finalCodEntrata === '' && isset($existingAccounting['codiceContabilita'])) {
            $finalCodEntrata = (string)$existingAccounting['codiceContabilita'];
        }
        if ($finalCodEntrata === '' && isset($existingAccounting['codEntrata'])) {
            $finalCodEntrata = (string)$existingAccounting['codEntrata'];
        }
        if ($finalTipoBollo === '' && isset($existingAccounting['tipoBollo'])) {
            $finalTipoBollo = (string)$existingAccounting['tipoBollo'];
        }
        if ($finalTipoContabilita === '' && isset($existingAccounting['tipoContabilita'])) {
            $finalTipoContabilita = (string)$existingAccounting['tipoContabilita'];
        }

        if (array_key_exists('ibanAccredito', $params)) {
            $finalIban = trim((string)$params['ibanAccredito']);
        }
        if (array_key_exists('codiceContabilita', $params)) {
            $finalCodEntrata = trim((string)$params['codiceContabilita']);
        } elseif (array_key_exists('codEntrata', $params)) {
            $finalCodEntrata = trim((string)$params['codEntrata']);
        }
        if (array_key_exists('tipoBollo', $params)) {
            $finalTipoBollo = trim((string)$params['tipoBollo']);
        }
        if (array_key_exists('tipoContabilita', $params)) {
            $finalTipoContabilita = trim((string)$params['tipoContabilita']);
        }

        $hasUserOverrideCodEntrata = array_key_exists('codiceContabilita', $params) || array_key_exists('codEntrata', $params);
        if (!$hasUserOverrideCodEntrata && $idTipo !== '') {
            $finalCodEntrata = (string)$idTipo;
        } elseif ($finalCodEntrata === '' && $idTipo !== '') {
            $finalCodEntrata = (string)$idTipo;
        }

        $codPattern = '/^[A-Za-z0-9\-_.]{1,35}$/';
        if ($finalCodEntrata !== '' && !preg_match($codPattern, $finalCodEntrata)) {
            $orig = $finalCodEntrata;
            $sanitized = preg_replace('/[^A-Za-z0-9\-_.]/', '', $orig);
            $sanitized = substr($sanitized, 0, 35);
            if ($sanitized !== '') {
                Logger::getInstance()->warning("codiceContabilita sanitizzato per invio: '{$orig}' -> '{$sanitized}'", ['idTipo' => $idTipo, 'idDominio' => $idDominioUsed]);
                $warnings[] = "Il valore codiceContabilita '{$orig}' non è valido per l'API e verrà inviato come '{$sanitized}' (caratteri non validi rimossi).";
                $finalCodEntrata = $sanitized;
            } else {
                $errors[] = 'Il valore [' . $orig . '] del campo codiceContabilita non rispetta il pattern richiesto: (^[a-zA-Z0-9\-_\.]{1,35}$)';
            }
        }

        $voiceMode = null; // 'bollo'|'entrata'|'riferimento'
        if ($finalTipoBollo !== '') {
            $voiceMode = 'bollo';
        } else {
            $hasEntrata = ($finalIban !== '' && $finalTipoContabilita !== '' && $finalCodEntrata !== '');
            if ($hasEntrata) {
                $voiceMode = 'entrata';
                Logger::getInstance()->info('Entrata completa trovata: invio Entrata', ['idTipo' => $idTipo, 'idDominio' => $idDominioUsed, 'iban' => $finalIban, 'tipoContabilita' => $finalTipoContabilita, 'codiceContabilita' => $finalCodEntrata]);
                $warnings[] = 'La tipologia contiene dati completi di contabilita: verrà inviata una Entrata completa.';
            } elseif ($finalCodEntrata !== '') {
                $voiceMode = 'riferimento';
                Logger::getInstance()->info('Fallback a RiferimentoEntrata: invio codEntrata', ['idTipo' => $idTipo, 'idDominio' => $idDominioUsed, 'codEntrata' => $finalCodEntrata]);
                $warnings[] = 'La tipologia non contiene dati completi di Entrata: verrà inviato un riferimento alla entrata (RiferimentoEntrata).';
            }
        }

        if ($voiceMode === null) {
            $errors[] = 'Per la voce è necessario inviare o i dati completi di Entrata (IBAN, tipoContabilita, codiceContabilita), o il riferimento alla entrata (codEntrata), o i dati di Bollo. Configura la tipologia o inserisci i valori nei campi avanzati.';
            return [];
        }

        $builtVoci = [];
        foreach (array_values($baseVoci) as $idx => $voceBase) {
            if (!is_array($voceBase)) {
                continue;
            }
            $idVoce = trim((string)($voceBase['idVocePendenza'] ?? ''));
            if ($idVoce === '') {
                $idVoce = (string)($idx + 1);
            }
            $descrizione = trim((string)($voceBase['descrizione'] ?? ''));
            $importo = $this->normalizeImporto($voceBase['importo'] ?? 0.0);
            $nv = [
                'idVocePendenza' => $idVoce,
                'descrizione' => $descrizione,
                'importo' => $importo,
            ];
            if ($voiceMode === 'bollo') {
                if ($finalTipoBollo !== '') {
                    $nv['tipoBollo'] = $finalTipoBollo;
                }
            } elseif ($voiceMode === 'entrata') {
                $nv['ibanAccredito'] = $finalIban;
                $nv['tipoContabilita'] = $finalTipoContabilita;
                $nv['codiceContabilita'] = $finalCodEntrata;
            } elseif ($voiceMode === 'riferimento') {
                $nv['codEntrata'] = $finalCodEntrata;
            }
            $builtVoci[] = $nv;
        }

        return $builtVoci;
    }

    /**
     * Normalizza l'input delle voci provenienti dal form o dalla pendenza corrente.
     */
    private function normalizeVociInput(array $rawVoci, string $defaultDescrizione, float $defaultImporto): array
    {
        $normalized = [];
        $counter = 0;
        foreach ($rawVoci as $idx => $voceRaw) {
            if (!is_array($voceRaw)) {
                continue;
            }
            $idVoce = trim((string)($voceRaw['idVocePendenza'] ?? $voceRaw['id_voce_pendenza'] ?? ''));
            if ($idVoce === '') {
                $idVoce = (string)($counter + 1);
            }
            $descrizione = trim((string)($voceRaw['descrizione'] ?? $voceRaw['desc'] ?? ''));
            if ($descrizione === '') {
                $descrizione = $defaultDescrizione;
            }
            $importo = $this->normalizeImporto($voceRaw['importo'] ?? ($voceRaw['importoVoce'] ?? $defaultImporto));
            if ($importo <= 0 && $defaultImporto > 0) {
                $importo = $defaultImporto;
            }
            $normalized[] = [
                'idVocePendenza' => $idVoce,
                'descrizione' => $descrizione,
                'importo' => $importo,
            ];
            $counter++;
        }
        return $normalized;
    }

    private function normalizeImporto($value): float
    {
        if (is_numeric($value)) {
            return (float)$value;
        }
        $stringValue = (string)$value;
        $normalized = str_replace(',', '.', $stringValue);
        return is_numeric($normalized) ? (float)$normalized : 0.0;
    }

    private function extractAccountingDefaultsFromPendenza(?array $pendenza): array
    {
        if (!is_array($pendenza)) {
            return [];
        }
        $voci = $pendenza['voci'] ?? null;
        if (!is_array($voci)) {
            return [];
        }
        foreach ($voci as $voce) {
            if (!is_array($voce)) {
                continue;
            }
            $candidate = [
                'ibanAccredito' => $voce['ibanAccredito'] ?? ($voce['contabilita']['ibanAccredito'] ?? ''),
                'codiceContabilita' => $voce['codiceContabilita'] ?? ($voce['contabilita']['codiceContabilita'] ?? ''),
                'codEntrata' => $voce['codEntrata'] ?? ($voce['contabilita']['codEntrata'] ?? ''),
                'tipoContabilita' => $voce['tipoContabilita'] ?? ($voce['contabilita']['tipoContabilita'] ?? ''),
                'tipoBollo' => $voce['tipoBollo'] ?? '',
            ];
            $hasValue = false;
            foreach ($candidate as $val) {
                if ($val !== '' && $val !== null) {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                return $candidate;
            }
        }
        return [];
    }

    private function extractIdTipoFromPendenza(array $pendenza): string
    {
        if (isset($pendenza['idTipoPendenza'])) {
            return (string)$pendenza['idTipoPendenza'];
        }
        if (isset($pendenza['tipo']['idTipoPendenza'])) {
            return (string)$pendenza['tipo']['idTipoPendenza'];
        }
        if (isset($pendenza['tipoPendenza']['idTipoPendenza'])) {
            return (string)$pendenza['tipoPendenza']['idTipoPendenza'];
        }
        return '';
    }

    private function splitPersonaFisicaAnagrafica(string $fullName, ?string $codiceFiscale = null): array
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $fullName));
        if ($normalized === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $normalized);
        if (!$parts || count($parts) === 0) {
            return ['', $normalized];
        }
        if (count($parts) === 1) {
            return ['', $parts[0]];
        }
        $tokens = $parts;
        $tokenCount = count($tokens);
        $defaultCognome = $tokens[$tokenCount - 1];
        $defaultNomeTokens = array_slice($tokens, 0, $tokenCount - 1);
        $defaultNome = implode(' ', $defaultNomeTokens);

        $cfSurnameBlock = null;
        $cf = strtoupper(trim((string)$codiceFiscale));
        if ($cf !== '' && preg_match('/^[A-Z0-9]{16}$/', $cf)) {
            $cfSurnameBlock = substr($cf, 0, 3);
        }

        if ($cfSurnameBlock !== null) {
            for ($len = 1; $len <= $tokenCount; $len++) {
                $candidateTokens = array_slice($tokens, $tokenCount - $len, $len);
                $candidate = implode(' ', $candidateTokens);
                $block = $this->computeCfBlock($candidate, false);
                if ($block === $cfSurnameBlock) {
                    $nomeTokens = array_slice($tokens, 0, $tokenCount - $len);
                    return [trim(implode(' ', $nomeTokens)), trim($candidate)];
                }
            }
        }

        return [trim($defaultNome), trim($defaultCognome)];
    }

    private function computeCfBlock(string $value, bool $isName): string
    {
        $ascii = $this->normalizeAsciiUpper($value);
        $filtered = preg_replace('/[^A-Z]/', '', $ascii ?? '') ?? '';
        $consonants = preg_replace('/[AEIOU]/', '', $filtered);
        $vowels = preg_replace('/[^AEIOU]/', '', $filtered);
        $block = '';
        if ($isName) {
            if (strlen((string)$consonants) >= 4) {
                $block = $consonants[0] . $consonants[2] . $consonants[3];
            } else {
                $block = substr((string)$consonants, 0, 3);
            }
        } else {
            $block = substr((string)$consonants, 0, 3);
        }
        if (strlen($block) < 3) {
            $block .= substr((string)$vowels, 0, 3 - strlen($block));
        }
        while (strlen($block) < 3) {
            $block .= 'X';
        }
        return $block;
    }

    private function normalizeAsciiUpper(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT', $trimmed);
            if ($tmp !== false) {
                $trimmed = $tmp;
            }
        }
        return strtoupper($trimmed);
    }

    /**
     * Costruisce le voci per l'inserimento applicando la logica DB-first.
     * Restituisce le voci normalizzate e popola $errors se ci sono problemi.
     */
    private function buildVociForInsertionFromMerged(array $merged, array $voci, string $idDominio, array &$errors = []): array
    {
        $out = [];
        $repo = null;
        try { $repo = new EntrateRepository(); } catch (\Throwable $_) { $repo = null; }
        foreach ($voci as $v) {
            $vv = $v;
            if (isset($vv['importo'])) {
                $vv['importo'] = is_numeric(str_replace(',', '.', (string)$vv['importo'])) ? (float)str_replace(',', '.', (string)$vv['importo']) : 0.0;
            } else {
                $vv['importo'] = 0.0;
            }

            $vv['idVocePendenza'] = trim((string)($vv['idVocePendenza'] ?? ($vv['id_voce_pendenza'] ?? '')));

            // DB-first: try to get defaults
            $details = null;
            if ($repo !== null && $idDominio !== '' && !empty($merged['idTipoPendenza'] ?? null)) {
                try { $details = $repo->findDetails($idDominio, (string)$merged['idTipoPendenza']); } catch (\Throwable $_) { $details = null; }
            }
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
            if (empty($finalIban) && !empty($merged['ibanAccredito'])) $finalIban = $merged['ibanAccredito'];
            if (empty($finalCodEntrata) && (!empty($merged['codiceContabilita']) || !empty($merged['codEntrata']))) $finalCodEntrata = $merged['codiceContabilita'] ?? $merged['codEntrata'] ?? '';
            if ($merged['idTipoPendenza'] ?? '') {
                $finalCodEntrata = (string)$merged['idTipoPendenza'];
            }
            if (empty($finalTipoBollo) && !empty($merged['tipoBollo'])) $finalTipoBollo = $merged['tipoBollo'];
            if (empty($finalTipoContabilita) && !empty($merged['tipoContabilita'])) $finalTipoContabilita = $merged['tipoContabilita'];

            $voiceMode = null;
            if ($finalTipoBollo !== '') {
                $voiceMode = 'bollo';
            } else {
                $hasEntrata = ($finalIban !== '' && $finalTipoContabilita !== '' && $finalCodEntrata !== '');
                if ($hasEntrata) $voiceMode = 'entrata';
                elseif ($finalCodEntrata !== '') $voiceMode = 'riferimento';
                else $voiceMode = null;
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
     * Recupera i dettagli di una pendenza dal Backoffice per ottenere campi come numeroAvviso
     */
    // (no longer needed) fetchPendenzaDetailFromBackoffice removed

    /**
     * Crea e invia un tracciato con le pendenze delle rate al Backoffice
     * Restituisce ['success'=>bool, 'idTracciato'=>string|null, 'errors'=>[]]
     */
    private function sendTracciatoToBackoffice(array $merged, array $parts): array
    {
        $svc = $this->tracciatoService ?? new TracciatoService();
        return $svc->sendTracciato($merged, $parts);
    }

    /**
     * Crea un Guzzle client con opzioni di autenticazione e mTLS impostate
     */
    protected function makeHttpClient(array $guzzleOptions = []): Client
    {
        $authMethod = getenv('AUTHENTICATION_GOVPAY');
        if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
            $cert = getenv('GOVPAY_TLS_CERT');
            $key = getenv('GOVPAY_TLS_KEY');
            $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert'] = $cert;
                $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            }
        }

        $defaultCurlOptions = [];
        if (defined('CURL_SSLVERSION_TLSv1_2')) {
            $defaultCurlOptions[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2;
        }
        if (defined('CURL_HTTP_VERSION_1_1')) {
            $defaultCurlOptions[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        if (defined('CURLOPT_SSL_SESSIONID_CACHE')) {
            $defaultCurlOptions[CURLOPT_SSL_SESSIONID_CACHE] = false;
        }
        if (defined('CURLOPT_FORBID_REUSE')) {
            $defaultCurlOptions[CURLOPT_FORBID_REUSE] = true;
        }
        if (!empty($defaultCurlOptions)) {
            $guzzleOptions['curl'] = ($guzzleOptions['curl'] ?? []) + $defaultCurlOptions;
        }

        // Crea un handler stack con retry automatico sui problemi TLS intermittenti (cURL 35)
        $handlerStack = new \GuzzleHttp\HandlerStack();
        $handlerStack->setHandler(new \GuzzleHttp\Handler\CurlHandler());

        $handlerStack->push(Middleware::mapRequest(
            static function (HttpRequest $request): HttpRequest {
                return $request->withHeader('Connection', 'close');
            }
        ));

        $maxTlsRetries = 5;
        $handlerStack->push(Middleware::retry(
            function (int $retries, $request, $response = null, $exception = null) use ($maxTlsRetries): bool {
                if (!$exception instanceof RequestException) {
                    return false;
                }
                $context = $exception->getHandlerContext();
                $errno = (int)($context['errno'] ?? 0);
                $message = strtolower($exception->getMessage());
                $isTlsError = $errno === 35 || str_contains($message, 'curl error 35');
                if (!$isTlsError) {
                    return false;
                }
                if ($retries >= $maxTlsRetries) {
                    Logger::error(sprintf('GovPay TLS error after %d retries (errno %s): %s', $maxTlsRetries, $errno ?: 'n/a', $exception->getMessage()));
                    return false;
                }
                Logger::warning(sprintf('Retry GovPay call after TLS error (attempt %d/%d, errno %s)', $retries + 1, $maxTlsRetries, $errno ?: 'n/a'));
                return true;
            },
            static function (int $retries): int {
                if ($retries <= 0) {
                    return 0;
                }
                $baseDelay = 200 * (1 << ($retries - 1));
                $jitter = random_int(0, 100);
                return (int)min(2000, $baseDelay + $jitter);
            }
        ));

        $guzzleOptions['handler'] = $handlerStack;

        return new Client($guzzleOptions);
    }

    /**
     * Helper: call Backoffice /pendenze endpoint with given query parameters
     * Returns decoded response array or throws on error.
     *
     * @param array $query
     * @return array
     * @throws \Throwable
     */
    private function callBackofficeFindPendenze(array $query): array
    {
        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        if (empty($backofficeUrl)) {
            throw new \RuntimeException('Variabile GOVPAY_BACKOFFICE_URL non impostata');
        }

        $username = getenv('GOVPAY_USER');
        $password = getenv('GOVPAY_PASSWORD');

        $http = $this->makeHttpClient();
        $requestOptions = [
            'headers' => ['Accept' => 'application/json'],
            'query' => $query,
        ];
        if ($username !== false && $password !== false && $username !== '' && $password !== '') {
            $requestOptions['auth'] = [$username, $password];
        }

        $url = rtrim($backofficeUrl, '/') . '/pendenze';
        $resp = $http->request('GET', $url, $requestOptions);
        $json = (string)$resp->getBody();
        $dataArr = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($dataArr)) {
            throw new \RuntimeException('Parsing JSON fallito: ' . json_last_error_msg());
        }
        return $dataArr;
    }

    public function showDetail(Request $request, Response $response, array $args): Response
    {
        $this->exposeCurrentUser();

        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $sessionSearch = $_SESSION['pendenze_last_search'] ?? null;

        $idPendenza = $args['idPendenza'] ?? '';
        $queryParams = (array)$request->getQueryParams();
        $returnCandidate = $queryParams['return'] ?? null;

        $allowedReturnPrefixes = ['/pendenze/ricerca', '/pagamenti/ricerca-flussi', '/pagamenti/ricerca-flussi/dettaglio'];
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

        $returnUrl = $sanitizeReturn($returnCandidate);
        if ($returnUrl === null && is_array($sessionSearch) && isset($sessionSearch['return_url'])) {
            $returnUrl = $sanitizeReturn((string)$sessionSearch['return_url']);
        }
        if ($returnUrl === null) {
            $returnUrl = '/pendenze/ricerca';
        }

        $returnOrigin = '';
        if (isset($queryParams['origin']) && is_string($queryParams['origin'])) {
            $returnOrigin = (string)$queryParams['origin'];
        }
        if ($returnOrigin === '' && strncmp($returnUrl, '/pagamenti/ricerca-flussi/dettaglio', strlen('/pagamenti/ricerca-flussi/dettaglio')) === 0) {
            $returnOrigin = 'flusso';
        }

        $highlightParam = isset($queryParams['highlight']) ? (string)$queryParams['highlight'] : '';
        if ($highlightParam === '' && isset($_SESSION['pendenze_last_highlight'])) {
            $highlightParam = (string)$_SESSION['pendenze_last_highlight'];
        }
        if ($highlightParam !== '') {
            $_SESSION['pendenze_last_highlight'] = $highlightParam;
            $mergeQueryParam = static function (string $url, string $key, string $value): string {
                $fragment = '';
                if (($hashPos = strpos($url, '#')) !== false) {
                    $fragment = substr($url, $hashPos);
                    $url = substr($url, 0, $hashPos);
                }
                $parts = parse_url($url);
                $path = $parts['path'] ?? $url;
                $queryData = [];
                if (isset($parts['query'])) {
                    parse_str($parts['query'], $queryData);
                } elseif (($qMarkPos = strpos($url, '?')) !== false) {
                    parse_str(substr($url, $qMarkPos + 1), $queryData);
                }
                $queryData[$key] = $value;
                $queryString = http_build_query($queryData, '', '&', PHP_QUERY_RFC3986);
                return $path . ($queryString !== '' ? ('?' . $queryString) : '') . $fragment;
            };
            $returnUrl = $mergeQueryParam($returnUrl, 'highlight', $highlightParam);
        }

        $cameFromInsert = isset($queryParams['from']) && $queryParams['from'] === 'insert';

        $error = null;
        $pendenza = null;

        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        $idA2A = getenv('ID_A2A') ?: '';
        if ($idPendenza === '') {
            $error = 'ID pendenza non specificato';
        } elseif (empty($backofficeUrl)) {
            $error = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
        } elseif ($idA2A === '') {
            $error = 'Variabile ID_A2A non impostata nel file .env';
        } else {
            try {
                $username = getenv('GOVPAY_USER');
                $password = getenv('GOVPAY_PASSWORD');
                $guzzleOptions = [
                    'headers' => ['Accept' => 'application/json'],
                ];
                $authMethod = getenv('AUTHENTICATION_GOVPAY');
                if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
                    $cert = getenv('GOVPAY_TLS_CERT');
                    $key = getenv('GOVPAY_TLS_KEY');
                    $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
                    if (!empty($cert) && !empty($key)) {
                        $guzzleOptions['cert'] = $cert;
                        $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                    } else {
                        $error = 'mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati';
                    }
                }
                if (!$error && $username !== false && $password !== false && $username !== '' && $password !== '') {
                    $guzzleOptions['auth'] = [$username, $password];
                }

                if (!$error) {
                    $http = $this->makeHttpClient($guzzleOptions);
                    $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode((string)$idA2A) . '/' . rawurlencode($idPendenza);
                    $resp = $http->request('GET', $url);
                    $json = (string)$resp->getBody();
                    $data = json_decode($json, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \RuntimeException('Parsing JSON fallito: ' . json_last_error_msg());
                    }
                    $pendenza = $data;
                }
            } catch (ClientException $ce) {
                $code = $ce->getResponse() ? $ce->getResponse()->getStatusCode() : 0;
                $error = $code === 404 ? 'Pendenza non trovata (404)' : 'Errore client nella chiamata pendenza: ' . $ce->getMessage();
            } catch (\Throwable $e) {
                $error = 'Errore chiamata pendenza: ' . $e->getMessage();
            }
        }

        // Attempt to fetch related pendenze for the same document by searching
        // for pendenze with the same debtor (idDebitore) and the same
        // dataCaricamento day, then filter locally by documento.identificativo.
        $relatedPendenze = [];
        try {
            if (is_array($pendenza) && !empty($pendenza)) {
                $documentId = $pendenza['documento']['identificativo'] ?? $pendenza['documento']['identificativoDocumento'] ?? null;
                // Deriva idDebitore dal soggettoPagatore.identificativo o da idDebitore diretto
                $idDebitore = $pendenza['soggettoPagatore']['identificativo'] ?? $pendenza['idDebitore'] ?? null;
                $dataCar = $pendenza['dataCaricamento'] ?? $pendenza['data_caricamento'] ?? null;
                $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
                $idA2A = getenv('ID_A2A') ?: '';
                if ($documentId && $idDebitore && $dataCar && $backofficeUrl && $idA2A) {
                    // build HTTP client options (reuse existing approach)
                    $username = getenv('GOVPAY_USER');
                    $password = getenv('GOVPAY_PASSWORD');
                    $guzzleOptions = ['headers' => ['Accept' => 'application/json']];
                    $authMethod = getenv('AUTHENTICATION_GOVPAY');
                    if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
                        $cert = getenv('GOVPAY_TLS_CERT');
                        $key = getenv('GOVPAY_TLS_KEY');
                        $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
                        if (!empty($cert) && !empty($key)) {
                            $guzzleOptions['cert'] = $cert;
                            $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                        }
                    }
                    if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                        $guzzleOptions['auth'] = [$username, $password];
                    }
                    $http = $this->makeHttpClient($guzzleOptions);

                    // Date range for the same generation day
                    try {
                        $dt = new \DateTime($dataCar);
                        $start = $dt->format('Y-m-d') . 'T00:00:00';
                        $end = $dt->format('Y-m-d') . 'T23:59:59';
                    } catch (\Throwable $_) {
                        $start = null;
                        $end = null;
                    }

                    if ($start !== null && $end !== null) {
                        $page = 1;
                        $perPage = 100;
                        $maxPages = 50; // safety cap to avoid infinite loops
                        while ($page > 0 && $page <= $maxPages) {
                            $query = [
                                'idDominio' => $pendenza['idDominio'] ?? (getenv('ID_DOMINIO') ?: ''),
                                'idA2A' => $idA2A,
                                'idDebitore' => $idDebitore,
                                'dataDa' => $start,
                                'dataA' => $end,
                                'pagina' => $page,
                                'risultatiPerPagina' => $perPage,
                            ];
                            try {
                                $dataArr = $this->callBackofficeFindPendenze($query);
                                $candidates = $dataArr['risultati'] ?? $dataArr['results'] ?? $dataArr;
                                if (is_array($candidates)) {
                                    foreach ($candidates as $cand) {
                                        $candDocId = $cand['documento']['identificativo'] ?? $cand['documento']['identificativoDocumento'] ?? null;
                                        if ($candDocId !== null && (string)$candDocId === (string)$documentId) {
                                            $relatedPendenze[] = $cand;
                                        }
                                    }
                                }
                                // paging heuristics: try to detect last page
                                $numPagine = $dataArr['numPagine'] ?? $dataArr['num_pagine'] ?? ($dataArr['metaDatiPaginazione']['numPagine'] ?? null);
                                if ($numPagine !== null) {
                                    $numPagine = (int)$numPagine;
                                    if ($page >= $numPagine) break;
                                } else {
                                    // if fewer results than page size, likely last page
                                    if (!is_array($candidates) || count($candidates) < $perPage) break;
                                }
                                $page++;
                            } catch (\Throwable $e) {
                                // Log and break: don't fail the whole detail page
                                Logger::getInstance()->warning('Errore ricerca pendenze correlate: ' . $e->getMessage(), ['idDebitore' => $idDebitore, 'documento' => $documentId]);
                                break;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $_) {
            // swallow: non vogliamo bloccare la visualizzazione del dettaglio
            Logger::getInstance()->warning('Errore building related pendenze: ' . $_->getMessage());
            $relatedPendenze = [];
        }

        // Build a quick lookup of related pendenze keyed by their rata indice (if available)
        $relatedByRata = [];
        if (is_array($relatedPendenze) && count($relatedPendenze) > 0) {
            foreach ($relatedPendenze as $rp) {
                $indices = [];
                if (isset($rp['documento']['rata'])) {
                    if (!is_array($rp['documento']['rata'])) {
                        $indices[] = (string)$rp['documento']['rata'];
                    } else {
                        foreach ($rp['documento']['rata'] as $rra) {
                            if (isset($rra['indice'])) $indices[] = (string)$rra['indice'];
                        }
                    }
                }
                if (isset($rp['proprieta']['rate']) && is_array($rp['proprieta']['rate'])) {
                    foreach ($rp['proprieta']['rate'] as $rra) {
                        if (isset($rra['indice'])) $indices[] = (string)$rra['indice'];
                    }
                }
                $indices = array_filter(array_unique($indices), fn($v) => $v !== '');
                foreach ($indices as $i) {
                    $relatedByRata[(string)$i] = $rp;
                }
            }
        }

        // Determine explicitly whether the opened pendenza contains a scalar
        // documento.rata (the only acceptable source for automatic highlighting).
        $currentRate = null;
        $rateInfoSource = 'none';
        if (is_array($pendenza) && !empty($pendenza)) {
            if (isset($pendenza['documento']['rata']) && !is_array($pendenza['documento']['rata'])) {
                $currentRate = (string)$pendenza['documento']['rata'];
                $rateInfoSource = 'documento_rata_scalar';
            } elseif (isset($pendenza['documento']['rata']) && is_array($pendenza['documento']['rata'])) {
                $rateInfoSource = 'documento_rata_array';
            } elseif (isset($pendenza['proprieta']['rate']) && is_array($pendenza['proprieta']['rate'])) {
                $rateInfoSource = 'proprieta_rate';
            } elseif (isset($pendenza['proprieta']['numeroRate'])) {
                $rateInfoSource = 'proprieta_numeroRate';
            }

            // IMPORTANT: do not attempt to infer the current rate from related
            // pendenze. This was a heuristic that produced non-deterministic
            // highlights and hid the real issue (missing scalar value in the
            // primary pendenza). 
        }

        // Fetch tipologie for the template
        $idDominio = $pendenza['idDominio'] ?? (getenv('ID_DOMINIO') ?: '');
        $tipologie = [];
        if ($idDominio) {
            try {
                $repo = new EntrateRepository();
                $tipologie = $repo->listAbilitateByDominio($idDominio);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $this->twig->render($response, 'pendenze/dettaglio.html.twig', [
            'idPendenza' => $idPendenza,
            'return_url' => $returnUrl,
            'return_origin' => $returnOrigin,
            'pendenza' => $pendenza,
            'error' => $error,
            'id_dominio' => $idDominio,
            'came_from_insert' => $cameFromInsert,
            'related_pendenze' => $relatedPendenze,
            'related_by_rata' => $relatedByRata,
            'current_rate' => $currentRate,
            'rate_info_source' => $rateInfoSource,
            'tipologie' => $tipologie,
        ]);
    }

    public function downloadAvviso(Request $request, Response $response, array $args): Response
    {
        $idDominio = $args['idDominio'] ?? '';
        $numeroAvviso = $args['numeroAvviso'] ?? '';
        if ($idDominio === '' || $numeroAvviso === '') {
            $response->getBody()->write('Parametri mancanti');
            return $response->withStatus(400);
        }

        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        if (empty($backofficeUrl)) {
            $response->getBody()->write('GOVPAY_BACKOFFICE_URL non impostata');
            return $response->withStatus(500);
        }

        try {
            $username = getenv('GOVPAY_USER');
            $password = getenv('GOVPAY_PASSWORD');
            $guzzleOptions = [
                'headers' => ['Accept' => 'application/pdf'],
            ];
            $authMethod = getenv('AUTHENTICATION_GOVPAY');
            if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
                $cert = getenv('GOVPAY_TLS_CERT');
                $key = getenv('GOVPAY_TLS_KEY');
                $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
                if (!empty($cert) && !empty($key)) {
                    $guzzleOptions['cert'] = $cert;
                    $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                } else {
                    $response->getBody()->write('mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati');
                    return $response->withStatus(500);
                }
            }
            if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                $guzzleOptions['auth'] = [$username, $password];
            }

            $http = new Client($guzzleOptions);
            $url = rtrim($backofficeUrl, '/') . '/avvisi/' . rawurlencode($idDominio) . '/' . rawurlencode($numeroAvviso);
            $resp = $http->request('GET', $url);
            $contentType = $resp->getHeaderLine('Content-Type') ?: 'application/pdf';
            $pdf = (string)$resp->getBody();
            $filename = 'avviso-' . $idDominio . '-' . $numeroAvviso . '.pdf';

            $response = $response
                ->withHeader('Content-Type', $contentType)
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Cache-Control', 'no-store');
            $response->getBody()->write($pdf);
            return $response;
        } catch (ClientException $ce) {
            $code = $ce->getResponse() ? $ce->getResponse()->getStatusCode() : 0;
            $msg = $code === 404 ? 'Avviso non trovato' : ('Errore client avviso: ' . $ce->getMessage());
            $response->getBody()->write($msg);
            return $response->withStatus($code ?: 500);
        } catch (\Throwable $e) {
            $response->getBody()->write('Errore scaricamento avviso: ' . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    /**
     * Scarica il PDF (o lo ritrasmette) che contiene gli avvisi associati a un documento.
     * Supporta query param 'numeriAvviso' come CSV o array, e 'inline' per aprire nel browser.
     */
    public function downloadAvvisiDocumento(Request $request, Response $response, array $args): Response
    {
        $idDominio = getenv('ID_DOMINIO') ?: '';
        $numeroDocumento = $args['numeroDocumento'] ?? '';
        if ($idDominio === '' || $numeroDocumento === '') {
            $response->getBody()->write('Parametri mancanti');
            return $response->withStatus(400);
        }

        // Gather numeriAvviso from query string or POST body and normalize
        $params = (array)$request->getQueryParams();
        $numeri = [];
        if (!empty($params['numeriAvviso'])) {
            if (is_array($params['numeriAvviso'])) {
                $numeri = array_map('trim', $params['numeriAvviso']);
            } else {
                $numeri = array_filter(array_map('trim', explode(',', (string)$params['numeriAvviso'])));
            }
        }
        if (empty($numeri)) {
            $body = $request->getParsedBody();
            if (is_array($body) && !empty($body['numeriAvviso'])) {
                $numeri = is_array($body['numeriAvviso']) ? $body['numeriAvviso'] : array_filter(array_map('trim', explode(',', (string)$body['numeriAvviso'])));
            }
        }

        $inline = isset($params['inline']) && ($params['inline'] === '1' || $params['inline'] === 'true');

        // Validate numeriAvviso entries if present: must be 18 digits each (pattern from API)
        $invalid = [];
        foreach ($numeri as $n) {
            $s = trim((string)$n);
            if ($s === '') continue; // ignore blanks
            if (!preg_match('/^[0-9]{18}$/', $s)) {
                $invalid[] = $s;
            }
        }
        if (!empty($invalid)) {
            $response->getBody()->write('numeriAvviso non validi: ' . implode(', ', $invalid));
            return $response->withStatus(400);
        }

        // Use Pendenze v2 client for avvisi/documento (no ID_A2A required)
        $pendenzeHost = getenv('GOVPAY_PENDENZE_URL') ?: '';
        if (empty($pendenzeHost) || !class_exists('\GovPay\Pendenze\Api\PendenzeApi')) {
            $response->getBody()->write('Client Pendenze v2 non disponibile o GOVPAY_PENDENZE_URL non impostata');
            return $response->withStatus(500);
        }

        try {
            $config = new \GovPay\Pendenze\Configuration();
            $config->setHost(rtrim($pendenzeHost, '/'));
            $username = getenv('GOVPAY_USER');
            $password = getenv('GOVPAY_PASSWORD');
            if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                // Some generated clients accept basic auth via Configuration setters
                if (method_exists($config, 'setUsername')) $config->setUsername($username);
                if (method_exists($config, 'setPassword')) $config->setPassword($password);
            }
            $guzzleOptions = [];
            $authMethod = getenv('AUTHENTICATION_GOVPAY');
            if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
                $cert = getenv('GOVPAY_TLS_CERT');
                $key = getenv('GOVPAY_TLS_KEY');
                $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
                if (!empty($cert) && !empty($key)) {
                    $guzzleOptions['cert'] = $cert;
                    $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                } else {
                    $response->getBody()->write('mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati');
                    return $response->withStatus(500);
                }
            }

            // Log parameters for debugging
            \App\Logger::getInstance()->info('downloadAvvisiDocumento: calling Pendenze v2', [
                'idDominio' => $idDominio,
                'numeroDocumento' => $numeroDocumento,
                'numeriAvviso' => $numeri
            ]);

            $httpClient = new Client($guzzleOptions);
            $pApi = new \GovPay\Pendenze\Api\PendenzeApi($httpClient, $config);
            // Pendenze v2 signature: getAvvisiDocumento($id_dominio, $numero_documento, $lingua_secondaria = null, $numeri_avviso = null)
            $result = $pApi->getAvvisiDocumento($idDominio, $numeroDocumento, null, (!empty($numeri) ? $numeri : null));

            if ($result instanceof \SplFileObject) {
                $stream = fopen((string)$result->getRealPath(), 'rb');
                $content = stream_get_contents($stream);
                fclose($stream);
                $disposition = $inline ? 'inline' : 'attachment';
                $filename = 'avvisi-' . $numeroDocumento . '.pdf';
                $response = $response->withHeader('Content-Type', 'application/pdf')
                    ->withHeader('Content-Disposition', $disposition . '; filename="' . $filename . '"')
                    ->withHeader('Cache-Control', 'no-store');
                $response->getBody()->write($content);
                return $response;
            }

            $response->getBody()->write('Risposta inattesa dal Backoffice');
            return $response->withStatus(502);
        } catch (\GovPay\Pendenze\ApiException $e) {
            $code = $e->getCode() ?: 502;
            $body = method_exists($e, 'getResponseBody') ? $e->getResponseBody() : null;
            $obj = method_exists($e, 'getResponseObject') ? $e->getResponseObject() : null;
            $msg = is_object($obj) ? ($obj->descrizione ?? json_encode($obj)) : ($body ?? $e->getMessage());
            Logger::getInstance()->error('Errore getAvvisiDocumento (Pendenze v2 client)', ['code' => $code, 'body' => $body, 'exception' => $e->getMessage()]);
            $response->getBody()->write('Errore chiamata Pendenze v2: ' . $msg);
            return $response->withStatus($code);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore getAvvisiDocumento generico', ['error' => $e->getMessage()]);
            $response->getBody()->write('Errore chiamata Pendenze v2: ' . $e->getMessage());
            return $response->withStatus(502);
        }
    }

    public function downloadRicevuta(Request $request, Response $response, array $args): Response
    {
        $idDominio = $args['idDominio'] ?? '';
        $iuv = $args['iuv'] ?? '';
        $ccp = $args['ccp'] ?? '';
        if ($idDominio === '' || $iuv === '' || $ccp === '') {
            $response->getBody()->write('Parametri mancanti');
            return $response->withStatus(400);
        }

        $pendenzeUrl = getenv('GOVPAY_PENDENZE_URL') ?: '';
        if (empty($pendenzeUrl)) {
            $response->getBody()->write('GOVPAY_PENDENZE_URL non impostata');
            return $response->withStatus(500);
        }

        try {
            $username = getenv('GOVPAY_USER');
            $password = getenv('GOVPAY_PASSWORD');
            $guzzleOptions = [
                'headers' => ['Accept' => 'application/pdf'],
            ];
            $authMethod = getenv('AUTHENTICATION_GOVPAY');
            if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
                $cert = getenv('GOVPAY_TLS_CERT');
                $key = getenv('GOVPAY_TLS_KEY');
                $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
                if (!empty($cert) && !empty($key)) {
                    $guzzleOptions['cert'] = $cert;
                    $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                } else {
                    $response->getBody()->write('mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati');
                    return $response->withStatus(500);
                }
            }
            if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                $guzzleOptions['auth'] = [$username, $password];
            }

            $http = new Client($guzzleOptions);
            $url = rtrim($pendenzeUrl, '/') . '/rpp/'
                . rawurlencode($idDominio) . '/' . rawurlencode($iuv) . '/' . rawurlencode($ccp) . '/rt';
            $resp = $http->request('GET', $url);
            $contentType = $resp->getHeaderLine('Content-Type') ?: 'application/pdf';
            $pdf = (string)$resp->getBody();
            $filename = 'rt-' . $iuv . '-' . $ccp . '.pdf';

            $response = $response
                ->withHeader('Content-Type', $contentType)
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Cache-Control', 'no-store');
            $response->getBody()->write($pdf);
            return $response;
        } catch (ClientException $ce) {
            $code = $ce->getResponse() ? $ce->getResponse()->getStatusCode() : 0;
            $msg = $code === 404 ? 'Ricevuta non trovata' : ('Errore client ricevuta: ' . $ce->getMessage());
            $response->getBody()->write($msg);
            return $response->withStatus($code ?: 500);
        } catch (\Throwable $e) {
            $response->getBody()->write('Errore scaricamento ricevuta: ' . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    public function downloadDominioLogo(Request $request, Response $response, array $args): Response
    {
        $idDominio = $args['idDominio'] ?? '';
        if ($idDominio === '') {
            $response->getBody()->write('Parametri mancanti');
            return $response->withStatus(400);
        }

        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        if (empty($backofficeUrl)) {
            $response->getBody()->write('GOVPAY_BACKOFFICE_URL non impostata');
            return $response->withStatus(500);
        }

        $username = getenv('GOVPAY_USER');
        $password = getenv('GOVPAY_PASSWORD');
        $guzzleOptions = [
            'headers' => ['Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*;q=0.8,*/*;q=0.5'],
        ];
        $authMethod = getenv('AUTHENTICATION_GOVPAY');
        if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
            $cert = getenv('GOVPAY_TLS_CERT');
            $key = getenv('GOVPAY_TLS_KEY');
            $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
            if (!empty($cert) && !empty($key)) {
                $guzzleOptions['cert'] = $cert;
                $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
            } else {
                $response->getBody()->write('mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati');
                return $response->withStatus(500);
            }
        }
        if ($username !== false && $password !== false && $username !== '' && $password !== '') {
            $guzzleOptions['auth'] = [$username, $password];
        }

        try {
            $http = new Client($guzzleOptions);
            $url = rtrim($backofficeUrl, '/') . '/domini/' . rawurlencode($idDominio) . '/logo';
            $resp = $http->request('GET', $url);
            $contentType = $resp->getHeaderLine('Content-Type') ?: 'image/png';
            $bytes = (string)$resp->getBody();
            $filename = 'logo-' . $idDominio;

            $response = $response
                ->withHeader('Content-Type', $contentType)
                ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"')
                ->withHeader('Cache-Control', 'no-store');
            $response->getBody()->write($bytes);
            return $response;
        } catch (ClientException $ce) {
            // Continua con fallback
        } catch (\Throwable $e) {
            // Prosegue con fallback
        }

        try {
            if (!class_exists('GovPay\\Backoffice\\Api\\EntiCreditoriApi')) {
                $response->getBody()->write('Client Backoffice EntiCreditori non disponibile');
                return $response->withStatus(500);
            }

            $config = new BackofficeConfiguration();
            $config->setHost(rtrim($backofficeUrl, '/'));
            if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                $config->setUsername($username);
                $config->setPassword($password);
            }

            $httpClient = new Client($guzzleOptions);
            $entiApi = new \GovPay\Backoffice\Api\EntiCreditoriApi($httpClient, $config);
            $domRes = $entiApi->getDominio($idDominio);

            $logo = null;
            if (is_object($domRes)) {
                if (method_exists($domRes, 'getLogo')) {
                    $logo = $domRes->getLogo();
                } elseif (property_exists($domRes, 'logo')) {
                    $logo = $domRes->logo;
                }
            }
            if ($logo === null) {
                $domData = json_decode(json_encode($domRes), true);
                if (is_array($domData)) {
                    $logo = $domData['logo'] ?? null;
                }
            }

            if (!$logo || !is_string($logo)) {
                $response->getBody()->write('Logo non disponibile');
                return $response->withStatus(404);
            }

            if (preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.*)$/', $logo, $matches)) {
                $contentType = $matches[1];
                $bytes = base64_decode($matches[2], true);
                if ($bytes === false) {
                    $response->getBody()->write('Logo non valido');
                    return $response->withStatus(415);
                }

                $response = $response
                    ->withHeader('Content-Type', $contentType)
                    ->withHeader('Content-Disposition', 'inline; filename="logo-' . $idDominio . '"')
                    ->withHeader('Cache-Control', 'no-store');
                $response->getBody()->write($bytes);
                return $response;
            }

            $bytes = base64_decode($logo, true);
            if ($bytes !== false && $bytes !== '') {
                $response = $response
                    ->withHeader('Content-Type', 'image/png')
                    ->withHeader('Content-Disposition', 'inline; filename="logo-' . $idDominio . '.png"')
                    ->withHeader('Cache-Control', 'no-store');
                $response->getBody()->write($bytes);
                return $response;
            }

            if (is_string($logo) && str_starts_with($logo, '/')) {
                try {
                    $http = new Client($guzzleOptions);
                    $url = rtrim($backofficeUrl, '/') . $logo;
                    $resp = $http->request('GET', $url);
                    $contentType = $resp->getHeaderLine('Content-Type') ?: 'image/png';
                    $bytes = (string)$resp->getBody();

                    $response = $response
                        ->withHeader('Content-Type', $contentType)
                        ->withHeader('Content-Disposition', 'inline; filename="logo-' . $idDominio . '"')
                        ->withHeader('Cache-Control', 'no-store');
                    $response->getBody()->write($bytes);
                    return $response;
                } catch (\Throwable $e) {
                    // Continua verso 404
                }
            }

            $response->getBody()->write('Logo non disponibile');
            return $response->withStatus(404);
        } catch (\Throwable $e) {
            $response->getBody()->write('Errore recupero logo: ' . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    private function exposeCurrentUser(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user'])) {
            $this->twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }
    }

    private function shouldFallbackToRaw(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'invalid length')
            || str_contains($message, 'must be smaller than or equal to')
            || str_contains($message, 'length');
    }

    public function showEdit(Request $request, Response $response, array $args): Response
    {
        $this->exposeCurrentUser();

        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $sessionSearch = $_SESSION['pendenze_last_search'] ?? null;

        $idPendenza = $args['idPendenza'] ?? '';
        $queryParams = (array)$request->getQueryParams();
        $returnCandidate = $queryParams['return'] ?? null;
        $returnUrl = null;
        if (is_string($returnCandidate) && $returnCandidate !== '') {
            $returnUrl = $returnCandidate;
        } elseif (is_array($sessionSearch) && isset($sessionSearch['return_url'])) {
            $returnUrl = (string)$sessionSearch['return_url'];
        }
        if (!is_string($returnUrl) || $returnUrl === '' || strpos($returnUrl, '/pendenze/ricerca') !== 0) {
            $returnUrl = '/pendenze/ricerca';
        }

        $error = null;
        $pendenza = null;

        // Recupera i dati della pendenza esistente
        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        $idA2A = getenv('ID_A2A') ?: '';
        if ($idPendenza === '') {
            $error = 'ID pendenza non specificato';
        } elseif (empty($backofficeUrl)) {
            $error = 'Variabile GOVPAY_BACKOFFICE_URL non impostata';
        } elseif ($idA2A === '') {
            $error = 'Variabile ID_A2A non impostata nel file .env';
        } else {
            try {
                $username = getenv('GOVPAY_USER');
                $password = getenv('GOVPAY_PASSWORD');
                $guzzleOptions = [
                    'headers' => ['Accept' => 'application/json'],
                ];
                $authMethod = getenv('AUTHENTICATION_GOVPAY');
                if ($authMethod !== false && strtolower((string)$authMethod) === 'sslheader') {
                    $cert = getenv('GOVPAY_TLS_CERT');
                    $key = getenv('GOVPAY_TLS_KEY');
                    $keyPass = getenv('GOVPAY_TLS_KEY_PASSWORD') ?: null;
                    if (!empty($cert) && !empty($key)) {
                        $guzzleOptions['cert'] = $cert;
                        $guzzleOptions['ssl_key'] = $keyPass ? [$key, $keyPass] : $key;
                    } else {
                        $error = 'mTLS abilitato ma GOVPAY_TLS_CERT/GOVPAY_TLS_KEY non impostati';
                    }
                }
                if (!$error && $username !== false && $password !== false && $username !== '' && $password !== '') {
                    $guzzleOptions['auth'] = [$username, $password];
                }

                if (!$error) {
                    $http = $this->makeHttpClient($guzzleOptions);
                    $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode((string)$idA2A) . '/' . rawurlencode($idPendenza);
                    $resp = $http->request('GET', $url);
                    $json = (string)$resp->getBody();
                    $data = json_decode($json, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \RuntimeException('Parsing JSON fallito: ' . json_last_error_msg());
                    }
                    $pendenza = $data;
                }
            } catch (ClientException $ce) {
                $code = $ce->getResponse() ? $ce->getResponse()->getStatusCode() : 0;
                $error = $code === 404 ? 'Pendenza non trovata (404)' : 'Errore client nella chiamata pendenza: ' . $ce->getMessage();
            } catch (\Throwable $e) {
                $error = 'Errore chiamata pendenza: ' . $e->getMessage();
            }
        }

        // Se ci sono errori o la pendenza non è modificabile, reindirizza al dettaglio
        if ($error || !$pendenza || $pendenza['stato'] !== 'NON_ESEGUITA') {
            return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza) . '?error=' . rawurlencode($error ?: 'Pendenza non modificabile'))->withStatus(302);
        }

        // Recupera tipologie abilitate per il dominio
        $idDominio = getenv('ID_DOMINIO') ?: '';
        $tipologie = [];
        if ($idDominio) {
            try {
                $repo = new EntrateRepository();
                $tipologie = $repo->listAbilitateByDominio($idDominio);
            } catch (\Throwable $e) {
                $tipologie = [];
            }
        }

        // Prepara i dati per il form di modifica
        $old = $this->preparePendenzaForForm($pendenza);
        $defaultAnno = (int)date('Y');
        if (isset($old['annoRiferimento']) && is_numeric((string)$old['annoRiferimento'])) {
            $defaultAnno = (int)$old['annoRiferimento'];
        }

        return $this->twig->render($response, 'pendenze/modifica.html.twig', [
            'tipologie_pendenze' => $tipologie,
            'id_dominio' => $idDominio,
            'id_a2a' => $idA2A,
            'old' => $old,
            'idPendenza' => $idPendenza,
            'return_url' => $returnUrl,
            'default_anno' => $defaultAnno,
        ]);
    }

    public function annullaPendenza(Request $request, Response $response, array $args): Response
    {
        $this->exposeCurrentUser();

        $idPendenza = $args['idPendenza'] ?? '';
        $responseData = ['success' => false, 'error' => ''];

        if ($idPendenza === '') {
            $responseData['error'] = 'ID pendenza non specificato';
        } else {
            try {
                // 1) Prima aggiorna lo storico (PUT)
                // Se facessimo prima il PATCH, il PUT successivo potrebbe resettare lo stato (se il backend lo resetta in assenza di campo stato)
                $histRes = $this->fallbackFullPutUpdate($idPendenza, [], 'Annullamento');
                if (!$histRes['success']) {
                    $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore aggiornamento storico: ' . implode('; ', $histRes['errors'] ?? [])];
                    return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
                }

                // 2) Poi aggiorna lo stato (PATCH)
                $result = $this->updatePendenzaStatus($idPendenza, 'ANNULLATA');
                if ($result['success']) {
                    $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Pendenza annullata con successo'];
                    return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
                } else {
                    $_SESSION['flash'][] = ['type' => 'error', 'text' => $result['error']];
                    return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
                }
            } catch (\Throwable $e) {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
                return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
            }
        }

        // Fallback error
        $_SESSION['flash'][] = ['type' => 'error', 'text' => $responseData['error'] ?: 'Errore sconosciuto'];
        return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
    }

    public function riattivaPendenza(Request $request, Response $response, array $args): Response
    {
        $this->exposeCurrentUser();

        $idPendenza = $args['idPendenza'] ?? '';
        $responseData = ['success' => false, 'error' => ''];

        if ($idPendenza === '') {
            $responseData['error'] = 'ID pendenza non specificato';
        } else {
            try {
                // 1) Prima aggiorna lo stato (PATCH)
                // Questo sblocca la pendenza dallo stato ANNULLATA a NON_ESEGUITA
                $result = $this->updatePendenzaStatus($idPendenza, 'NON_ESEGUITA');
                
                if ($result['success']) {
                    // 2) Poi aggiorna lo storico (PUT)
                    // Ora la pendenza è NON_ESEGUITA, quindi il PUT (che implica NON_ESEGUITA) è valido.
                    $histRes = $this->fallbackFullPutUpdate($idPendenza, [], 'Riattivazione');
                    
                    // Nota: se il PUT fallisce, la pendenza è comunque riattivata.
                    // Potremmo gestire l'errore ma per ora diamo priorità al successo dell'azione principale.
                    if (!$histRes['success']) {
                        $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Pendenza riattivata, ma errore aggiornamento storico: ' . implode('; ', $histRes['errors'] ?? [])];
                    } else {
                        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Pendenza riattivata con successo'];
                    }
                    return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
                } else {
                    $_SESSION['flash'][] = ['type' => 'error', 'text' => $result['error']];
                    return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
                }
            } catch (\Throwable $e) {
                $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: ' . $e->getMessage()];
                return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
            }
        }

        // Fallback error
        $_SESSION['flash'][] = ['type' => 'error', 'text' => $responseData['error'] ?: 'Errore sconosciuto'];
        return $response->withHeader('Location', '/pendenze/dettaglio/' . rawurlencode($idPendenza))->withStatus(302);
    }

    public function reinviaNotifica(Request $request, Response $response, array $args): Response
    {
        $this->exposeCurrentUser();
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

        $idPendenza = (string)($args['idPendenza'] ?? '');
        $params = (array)($request->getParsedBody() ?? []);
        $canale = strtolower(trim((string)($params['canale'] ?? '')));
        $redirect = '/pendenze/dettaglio/' . rawurlencode($idPendenza);

        if ($idPendenza === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'ID pendenza non specificato'];
            return $response->withHeader('Location', '/pendenze/ricerca')->withStatus(302);
        }
        if ($canale !== 'email' && $canale !== 'app_io') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Canale notifica non valido'];
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        $idA2A = getenv('ID_A2A') ?: '';
        if ($backofficeUrl === '' || $idA2A === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Configurazione GovPay incompleta'];
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        try {
            $pendenza = $this->fetchPendenzaById($idPendenza);
            if (!is_array($pendenza)) {
                throw new \RuntimeException('Risposta pendenza non valida');
            }

            if ($this->isPendenzaPagata((string)($pendenza['stato'] ?? ''))) {
                $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Reinvio non consentito: pendenza gia pagata'];
                return $response->withHeader('Location', $redirect)->withStatus(302);
            }

            [$iuvFromResponse, $numeroAvvisoFromResponse] = $this->extractIuvAndNumeroAvviso($pendenza);
            $idDominio = (string)($pendenza['idDominio'] ?? (getenv('ID_DOMINIO') ?: ''));
            $causale = trim((string)($pendenza['causale'] ?? 'Pendenza PagoPA'));
            $importo = (float)($pendenza['importo'] ?? 0.0);
            $frontofficeUrl = $this->resolveFrontofficePublicBaseUrl($request);
            $identificativo = trim((string)($pendenza['soggettoPagatore']['identificativo'] ?? ''));

            $signedPdfUrl = $this->buildPdfDownloadUrl(
                $frontofficeUrl,
                $idDominio,
                $identificativo,
                $iuvFromResponse,
                $numeroAvvisoFromResponse
            );

            $paymentUrl = $this->buildCheckoutStartUrl(
                $frontofficeUrl,
                $identificativo,
                $iuvFromResponse,
                $numeroAvvisoFromResponse
            );

            if ($canale === 'email') {
                $email = trim((string)($pendenza['soggettoPagatore']['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Reinvio email non possibile: destinatario non valido'];
                    return $response->withHeader('Location', $redirect)->withStatus(302);
                }

                $anagrafica = trim((string)($pendenza['soggettoPagatore']['anagrafica'] ?? ''));
                $pendenzaDataForEmail = [
                    'causale' => $causale,
                    'importo' => $importo,
                    'iuv' => $iuvFromResponse,
                    'numeroAvviso' => $numeroAvvisoFromResponse,
                    'dataScadenza' => (string)($pendenza['dataScadenza'] ?? ''),
                ];

                $mailer = \App\Services\MailerService::forSuite('backoffice');
                $appName = (string)(getenv('APP_ENTITY_NAME') ?: 'GIL');
                $notificationResult = $mailer->sendPendenzaCreatedNotification(
                    $email,
                    $anagrafica,
                    $pendenzaDataForEmail,
                    $appName,
                    $signedPdfUrl,
                    $paymentUrl,
                    $this->resolveEmbeddedLogoPath()
                );

                if (($notificationResult['esito'] ?? 'ERRORE') === 'OK') {
                    $this->addNotificationToPendenza($idPendenza, $notificationResult, 'Reinvio notifica Email');
                    $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Email reinviata con successo'];
                } else {
                    $failed = [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'esito' => 'ERRORE',
                        'canale' => 'email',
                        'destinatario' => $email,
                        'errore' => (string)($notificationResult['errore'] ?? 'errore sconosciuto'),
                    ];
                    $this->addNotificationToPendenza($idPendenza, $failed, 'Tentativo reinvio notifica Email fallito');
                    $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Reinvio email fallito: ' . ($notificationResult['errore'] ?? 'errore sconosciuto')];
                }

                return $response->withHeader('Location', $redirect)->withStatus(302);
            }

            $sogTipo = strtoupper(trim((string)($pendenza['soggettoPagatore']['tipo'] ?? 'F')));
            if ($identificativo === '' || $sogTipo !== 'F') {
                $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Reinvio App IO non possibile: soggetto non compatibile'];
                return $response->withHeader('Location', $redirect)->withStatus(302);
            }

            $idTipo = $this->extractIdTipoFromPendenza($pendenza);
            $ioRepo = new \App\Database\IoServiceRepository();
            $ioService = $ioRepo->getTipologiaService($idTipo) ?? $ioRepo->findDefault();
            if (!$ioService || empty($ioService['api_key_primaria'])) {
                $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Reinvio App IO non possibile: servizio non configurato'];
                return $response->withHeader('Location', $redirect)->withStatus(302);
            }

            $markdown = "## Pendenza PagoPA\n\n";
            $markdown .= "**Causale**: " . $causale . "\n\n";
            $markdown .= "**Importo**: € " . number_format($importo, 2, ',', '.') . "\n\n";
            if ($iuvFromResponse !== '') {
                $markdown .= "**IUV**: " . $iuvFromResponse . "\n\n";
            }
            $dataScadenza = (string)($pendenza['dataScadenza'] ?? '');
            if ($dataScadenza !== '') {
                $markdown .= "**Scadenza**: " . $dataScadenza . "\n\n";
            }
            if ($signedPdfUrl !== '') {
                $markdown .= "[Scarica il PDF dell'avviso](" . $signedPdfUrl . ")\n\n";
            }
            $markdown .= "Usa il pulsante **Paga ora** per effettuare il pagamento.";

            $paymentData = null;
            // Reinvio App IO: usa prioritariamente il numero avviso per valorizzare payment_data.
            $noticeNum = preg_replace('/\D/', '', $numeroAvvisoFromResponse !== '' ? $numeroAvvisoFromResponse : $iuvFromResponse);
            if (strlen($noticeNum) === 18) {
                $paymentData = [
                    'noticeNumber' => $noticeNum,
                    'amount' => (int)round($importo * 100),
                    'invalidAfterDueDate' => $dataScadenza !== '',
                ];
            }

            $dueDate = $dataScadenza !== '' ? $dataScadenza : null;
            if ($dueDate !== null && strlen($dueDate) === 10) {
                $dueDate .= 'T00:00:00Z';
            }

            $ioSvc = new \App\Services\AppIoService();
            $ioSubject = 'Pendenza PagoPA - ' . substr($causale, 0, 100);
            $result = $ioSvc->sendMessage(
                (string)$ioService['api_key_primaria'],
                $identificativo,
                $ioSubject,
                $markdown,
                $dueDate,
                $paymentData
            );

            if (($result['esito'] ?? 'KO') === 'OK') {
                $ioNotification = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'esito' => 'OK',
                    'canale' => 'app_io',
                    'destinatario' => $result['id'] ?? 'N/A',
                    'message_id' => $result['id'] ?? null,
                ];
                $this->addNotificationToPendenza($idPendenza, $ioNotification, 'Reinvio notifica App IO');
                $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Messaggio App IO reinviato con successo'];
            } else {
                $ioNotification = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'esito' => 'ERRORE',
                    'canale' => 'app_io',
                    'destinatario' => $result['id'] ?? 'N/A',
                    'message_id' => $result['id'] ?? null,
                    'errore' => (string)($result['errore'] ?? 'errore sconosciuto'),
                ];
                $this->addNotificationToPendenza($idPendenza, $ioNotification, 'Tentativo reinvio notifica App IO fallito');
                $_SESSION['flash'][] = ['type' => 'warning', 'text' => 'Reinvio App IO fallito: ' . ($result['errore'] ?? 'errore sconosciuto')];
            }

            return $response->withHeader('Location', $redirect)->withStatus(302);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore reinvio notifica', [
                'idPendenza' => $idPendenza,
                'canale' => $canale,
                'error' => $e->getMessage(),
            ]);
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Impossibile reinviare la notifica: ' . $e->getMessage()];
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }
    }

    public static function getCurrentOperatorString(): string
    {
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user)) {
            return 'backoffice';
        }
        $parts = [];
        if (!empty($user['last_name'])) $parts[] = $user['last_name'];
        if (!empty($user['first_name'])) $parts[] = $user['first_name'];
        $fullName = implode(' ', $parts);
        $email = $user['email'] ?? '';
        
        if ($fullName !== '' && $email !== '') {
            return $fullName . ' - ' . $email;
        }
        if ($email !== '') {
            return $email;
        }
        if ($fullName !== '') {
            return $fullName;
        }
        return 'backoffice';
    }

    private function isPendenzaPagata(string $stato): bool
    {
        $normalized = strtoupper(trim($stato));
        return in_array($normalized, [
            'ESEGUITA',
            'ESEGUITO',
            'RISCOSSA',
            'INCASSATA',
            'INCASSATO',
            'RENDICONTATA',
            'RENDICONTATO',
        ], true);
    }

    public function aggiornaPendenza(Request $request, Response $response, array $args): Response
    {
        $this->exposeCurrentUser();

        $idPendenza = $args['idPendenza'] ?? '';
        $params = (array)($request->getParsedBody() ?? []);

        if ($idPendenza === '') {
            return $this->twig->render($response, 'pendenze/modifica.html.twig', [
                'errors' => ['ID pendenza non specificato'],
                'old' => $params,
                'idPendenza' => $idPendenza,
                'return_url' => $params['return_url'] ?? '/pendenze/ricerca',
            ]);
        }

        // Validazione e costruzione payload simile al metodo create
        $errors = [];
        $warnings = [];

        // Validazioni di base (causale, importo, ecc.)
        $causale = trim((string)($params['causale'] ?? ''));
        if ($causale === '') {
            $errors[] = 'Causale obbligatoria';
        }

        $importoRaw = $params['importo'] ?? '';
        if ($importoRaw === '' || !is_numeric(str_replace(',', '.', (string)$importoRaw))) {
            $errors[] = 'Importo non valido';
        }
        $importo = (float)str_replace(',', '.', (string)$importoRaw);

        // Se ci sono errori, ricarica il form
        if ($errors) {
            $idDominio = getenv('ID_DOMINIO') ?: '';
            $tipologie = [];
            if ($idDominio) {
                try {
                    $repo = new EntrateRepository();
                    $tipologie = $repo->listAbilitateByDominio($idDominio);
                } catch (\Throwable $e) {}
            }
            $defaultAnno = (int)date('Y');
            if (isset($params['annoRiferimento']) && is_numeric((string)$params['annoRiferimento'])) {
                $defaultAnno = (int)$params['annoRiferimento'];
            }

            return $this->twig->render($response, 'pendenze/modifica.html.twig', [
                'errors' => $errors,
                'old' => $params,
                'tipologie_pendenze' => $tipologie,
                'id_dominio' => $idDominio,
                'id_a2a' => getenv('ID_A2A') ?: '',
                'idPendenza' => $idPendenza,
                'return_url' => $params['return_url'] ?? '/pendenze/ricerca',
                'default_anno' => $defaultAnno,
            ]);
        }

        // Aggiorna direttamente via PUT completo
        \App\Logger::getInstance()->debug('Aggiorna pendenza via PUT diretto');
        $putResult = $this->fallbackFullPutUpdate($idPendenza, $params);
        if ($putResult['success']) {
            if (!empty($putResult['warnings']) && is_array($putResult['warnings'])) {
                foreach ($putResult['warnings'] as $warn) {
                    $_SESSION['flash'][] = ['type' => 'warning', 'text' => (string)$warn];
                }
            }
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Pendenza aggiornata con successo'];
            // Sempre torna al dettaglio della pendenza aggiornata, indipendentemente dal return_url
            $redirectUrl = '/pendenze/dettaglio/' . rawurlencode($idPendenza);
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        } else {
            $errors[] = 'Errore nell\'aggiornamento della pendenza (PUT): ' . \App\Logger::sanitizeErrorForDisplay(implode('; ', $putResult['errors'] ?? []));
        }

        // In caso di errore, ricarica il form
        $idDominio = getenv('ID_DOMINIO') ?: '';
        $tipologie = [];
        if ($idDominio) {
            try {
                $repo = new EntrateRepository();
                $tipologie = $repo->listAbilitateByDominio($idDominio);
            } catch (\Throwable $e) {}
        }
        $defaultAnno = (int)date('Y');
        if (isset($params['annoRiferimento']) && is_numeric((string)$params['annoRiferimento'])) {
            $defaultAnno = (int)$params['annoRiferimento'];
        }

        return $this->twig->render($response, 'pendenze/modifica.html.twig', [
            'errors' => $errors,
            'old' => $params,
            'tipologie_pendenze' => $tipologie,
            'id_dominio' => $idDominio,
            'id_a2a' => getenv('ID_A2A') ?: '',
            'idPendenza' => $idPendenza,
            'return_url' => $params['return_url'] ?? '/pendenze/ricerca',
            'default_anno' => $defaultAnno,
        ]);
    }

    /**
     * Fallback: recupera la pendenza corrente, fonde i campi modificati dal form e invia un PUT completo.
     */
    private function fallbackFullPutUpdate(string $idPendenza, array $params, string $operationName = 'Modifica'): array
    {
        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        $idA2A = getenv('ID_A2A') ?: '';
        $errors = [];
        if ($backofficeUrl === '' || $idA2A === '') {
            return ['success' => false, 'errors' => ['Configurazione GovPay incompleta']];
        }

        try {
            // 1) GET pendenza corrente
            $http = $this->makeHttpClient();
            $getUrl = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode((string)$idA2A) . '/' . rawurlencode($idPendenza);
            $resp = $http->request('GET', $getUrl, [ 'headers' => ['Accept' => 'application/json'] ]);
            $cur = json_decode((string)$resp->getBody(), true);
            if (!is_array($cur)) {
                return ['success' => false, 'errors' => ['Risposta GET pendenza non valida']];
            }

            // 2) Filtra solo i campi accettati da PendenzaPut (per evitare UnrecognizedPropertyException)
            $allowedKeys = [
                'numeroAvviso','tassonomia','dataValidita','datiAllegati','tassonomiaAvviso','importo','dataScadenza',
                'dataPromemoriaScadenza','idUnitaOperativa','idDominio','allegati','dataCaricamento','annoRiferimento',
                'divisione','nome','causale','soggettoPagatore','dataNotificaAvviso','cartellaPagamento','documento',
                'proprieta','direzione','idTipoPendenza','voci'
            ];
            $put = array_intersect_key($cur, array_flip($allowedKeys));

            // 3) Merge: applica modifiche dal form
            if (isset($params['causale'])) {
                $put['causale'] = trim((string)$params['causale']);
            }
            if (isset($params['importo'])) {
                $put['importo'] = (float)str_replace(',', '.', (string)$params['importo']);
            }
            if (!empty($params['dataValidita'])) {
                $put['dataValidita'] = $params['dataValidita'];
            } else {
                unset($put['dataValidita']);
            }
            if (!empty($params['dataScadenza'])) {
                $put['dataScadenza'] = $params['dataScadenza'];
            } else {
                unset($put['dataScadenza']);
            }
            if (array_key_exists('annoRiferimento', $params)) {
                $yearRaw = trim((string)$params['annoRiferimento']);
                if ($yearRaw !== '' && is_numeric($yearRaw)) {
                    $put['annoRiferimento'] = (int)$yearRaw;
                }
            }

            // idTipoPendenza: usa quello del form se presente, altrimenti preserva quello attuale
            $idTipoFromForm = trim((string)($params['idTipoPendenza'] ?? ''));
            $idTipoFromCur = $this->extractIdTipoFromPendenza($cur);
            $idTipoEffective = $idTipoFromForm !== '' ? $idTipoFromForm : $idTipoFromCur;
            if ($idTipoEffective !== '') {
                $put['idTipoPendenza'] = $idTipoEffective;
            }

            if (!empty($params['soggettoPagatore']) && is_array($params['soggettoPagatore'])) {
                $s = $params['soggettoPagatore'];
                $tipo = strtoupper((string)($s['tipo'] ?? 'F'));
                $anag = trim((string)($s['anagrafica'] ?? ''));
                $nome = trim((string)($s['nome'] ?? ''));
                $ident = trim((string)($s['identificativo'] ?? ''));
                $email = trim((string)($s['email'] ?? ''));
                $normalized = [ 'tipo' => $tipo, 'identificativo' => $ident ];
                $normalized['anagrafica'] = $tipo === 'F' ? trim(($nome !== '' ? $nome . ' ' : '') . $anag) : $anag;
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $normalized['email'] = $email;
                $put['soggettoPagatore'] = $normalized;
            }

            // 3b) Ricostruisce le voci SOLO SE necessario (se parametri voci o importo sono cambiati)
            // Se non ci sono modifiche a voci/importo, manteniamo le voci originali per evitare di alterare campi immutabili (es. tipoTributo)
            // che buildVociWithAccounting potrebbe sovrascrivere con i default.
            $shouldRebuildVoci = (isset($params['voci']) && is_array($params['voci'])) || 
                                 isset($params['importo']) || 
                                 isset($params['causale']); // La causale potrebbe riflettersi sulla descrizione

            if ($shouldRebuildVoci) {
                $baseVoci = [];
                $existingNormalizedVoci = [];
                if (!empty($cur['voci']) && is_array($cur['voci'])) {
                    $existingNormalizedVoci = $this->normalizeVociInput($cur['voci'], (string)($put['causale'] ?? ''), (float)($put['importo'] ?? 0.0));
                }

                if (!empty($params['voci']) && is_array($params['voci'])) {
                    $baseVoci = $this->normalizeVociInput($params['voci'], (string)($put['causale'] ?? ''), (float)($put['importo'] ?? 0.0));
                }

                if (!empty($baseVoci) && !empty($existingNormalizedVoci)) {
                    $submittedIds = array_values(array_filter(
                        array_map(static fn(array $voce) => (string)($voce['idVocePendenza'] ?? ''), $baseVoci),
                        static fn(string $id) => $id !== ''
                    ));

                    $preservedIds = [];
                    foreach ($existingNormalizedVoci as $existingVoce) {
                        $existingId = (string)($existingVoce['idVocePendenza'] ?? '');
                        if ($existingId !== '' && in_array($existingId, $submittedIds, true)) {
                            continue;
                        }
                        if ($existingId !== '') {
                            $preservedIds[] = $existingId;
                        }
                        $baseVoci[] = $existingVoce;
                    }

                    if (!empty($preservedIds)) {
                        $warnings[] = 'Le voci esistenti non modificate sono state mantenute automaticamente (' . implode(', ', $preservedIds) . ').';
                    }
                } elseif (empty($baseVoci) && !empty($existingNormalizedVoci)) {
                    $baseVoci = $existingNormalizedVoci;
                }

                if (empty($baseVoci)) {
                    $baseVoci[] = [
                        'idVocePendenza' => '1',
                        'descrizione' => (string)($put['causale'] ?? ''),
                        'importo' => (float)($put['importo'] ?? 0.0),
                    ];
                }

                $accountingParams = $params;
                if (!isset($accountingParams['idDominio'])) {
                    $accountingParams['idDominio'] = $put['idDominio'] ?? ($cur['idDominio'] ?? (getenv('ID_DOMINIO') ?: ''));
                }
                if (!isset($accountingParams['idTipoPendenza']) && $idTipoEffective !== '') {
                    $accountingParams['idTipoPendenza'] = $idTipoEffective;
                }

                $put['voci'] = $this->buildVociWithAccounting($baseVoci, $accountingParams, $cur, $errors, $warnings);
            }
            // else: $put['voci'] rimane quello copiato da $cur all'inizio (array_intersect_key)

            // Aggiungi storico modifiche
            $put['datiAllegati'] = $put['datiAllegati'] ?? [];
            if (!isset($put['datiAllegati']['storico']) || !is_array($put['datiAllegati']['storico'])) {
                $put['datiAllegati']['storico'] = [];
            }
            $put['datiAllegati']['storico'][] = [
                'data' => date('Y-m-d H:i:s'),
                'operatore' => $this->getCurrentOperatorString(),
                'operazione' => $operationName,
            ];

            if ($errors) {
                return ['success' => false, 'errors' => $errors];
            }

            // 4) Non inviare idPendenza/idA2A nel body (sono nell'URL)
            unset($put['idPendenza'], $put['idA2A']);
            // Assicura idDominio popolato: obbligatorio per PendenzaPut
            if (empty($put['idDominio'])) {
                $envDom = getenv('ID_DOMINIO') ?: '';
                if ($envDom !== '') {
                    $put['idDominio'] = $envDom;
                }
            }

            // 5) Invia PUT completo utilizzando l'helper esistente
            $res = $this->sendPendenzaToBackoffice($put, $idPendenza);
            if (!empty($warnings)) {
                $res['warnings'] = array_merge($res['warnings'] ?? [], $warnings);
            }
            return $res;
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
            return ['success' => false, 'errors' => $errors];
        }
    }

    private function updatePendenzaStatus(string $idPendenza, string $newStatus): array
    {
        $patchUrl = getenv('GOVPAY_PENDENZE_PATCH_URL') ?: getenv('GOVPAY_BACKOFFICE_URL');
        $idA2A = getenv('ID_A2A') ?: '';

        if (empty($patchUrl) || $idA2A === '') {
            return ['success' => false, 'error' => 'Configurazione GovPay incompleta (URL PATCH o ID_A2A mancanti)'];
        }

        try {
            $http = $this->makeHttpClient();
            $url = rtrim($patchUrl, '/') . '/pendenze/' . rawurlencode((string)$idA2A) . '/' . rawurlencode($idPendenza);

            // JSON Patch per aggiornare solo lo stato, come da documentazione e codice di esempio
            $payload = [
                [
                    'op'    => 'REPLACE',
                    'path'  => '/stato',
                    'value' => $newStatus
                ]
            ];

            $reqBody = json_encode($payload);

            \App\Logger::getInstance()->debug('PATCH status request preparing', [
                'url' => $url,
                'method' => 'PATCH',
                'body' => $reqBody
            ]);

            try {
                $resp = $http->patch($url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => '*/*'
                    ],
                    'body' => $reqBody,
                    'timeout' => 30,
                    'connect_timeout' => 10,
                    'http_errors' => false
                ]);

                $respBody = (string)$resp->getBody();
                \App\Logger::getInstance()->debug('PATCH status response received', [
                    'status' => $resp->getStatusCode(),
                    'body' => $respBody
                ]);

                if ($resp->getStatusCode() === 200) {
                    return ['success' => true];
                } else {
                    return ['success' => false, 'error' => 'Errore nell\'aggiornamento dello stato: HTTP ' . $resp->getStatusCode() . ' - ' . $respBody];
                }
            } catch (RequestException $e) {
                $resp = $e->getResponse();
                $respBody = $resp ? (string)$resp->getBody() : null;
                $respStatus = $resp ? $resp->getStatusCode() : null;
                \App\Logger::getInstance()->error('PATCH status request failed (RequestException)', [
                    'message' => $e->getMessage(),
                    'status' => $respStatus,
                    'response_body' => $respBody,
                    'url' => $url,
                    'body' => $reqBody
                ]);
                $errorMsg = $respBody ? \App\Logger::sanitizeErrorForDisplay($respBody) : $e->getMessage();
                return ['success' => false, 'error' => 'Errore chiamata GovPay: HTTP ' . ($respStatus ?? 'N/A') . ' - ' . $errorMsg];
            } catch (\Throwable $e) {
                \App\Logger::getInstance()->error('PATCH status request failed (Throwable)', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'url' => $url,
                    'body' => $reqBody
                ]);
                return ['success' => false, 'error' => 'Errore chiamata GovPay: ' . $e->getMessage()];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Errore chiamata GovPay: ' . $e->getMessage()];
        }
    }

    private function preparePendenzaForForm(array $pendenza): array
    {
        // Converte i dati della pendenza nel formato atteso dal form
        // idTipoPendenza può essere presente in strutture diverse: tipo.idTipoPendenza, tipoPendenza.idTipoPendenza, idTipoPendenza diretto, oppure nella prima voce come codiceEntrata
        $idTipo = '';
        if (isset($pendenza['tipo']['idTipoPendenza'])) {
            $idTipo = (string)$pendenza['tipo']['idTipoPendenza'];
        } elseif (isset($pendenza['tipoPendenza']['idTipoPendenza'])) {
            $idTipo = (string)$pendenza['tipoPendenza']['idTipoPendenza'];
        } elseif (isset($pendenza['idTipoPendenza'])) {
            $idTipo = (string)$pendenza['idTipoPendenza'];
        }

        $voci = $pendenza['voci'] ?? [];
        $causale = $pendenza['causale'] ?? '';
        if ($causale === '' && is_array($voci) && isset($voci[0]['descrizione'])) {
            // Fallback: usa la descrizione della prima voce come causale se la pendenza non ha causale diretta
            $causale = (string)$voci[0]['descrizione'];
        }

        $soggettoPagatore = $pendenza['soggettoPagatore'] ?? [];
        if (is_array($soggettoPagatore)) {
            $tipoSoggetto = strtoupper((string)($soggettoPagatore['tipo'] ?? 'F'));
            if ($tipoSoggetto === 'F') {
                $fullAnagrafica = trim((string)($soggettoPagatore['anagrafica'] ?? ''));
                $hasNome = isset($soggettoPagatore['nome']) && trim((string)$soggettoPagatore['nome']) !== '';
                if ($fullAnagrafica !== '') {
                    $codiceFiscale = isset($soggettoPagatore['identificativo']) ? (string)$soggettoPagatore['identificativo'] : null;
                    [$nomeStimato, $cognomeStimato] = $this->splitPersonaFisicaAnagrafica($fullAnagrafica, $codiceFiscale);
                    if (!$hasNome && $nomeStimato !== '') {
                        $soggettoPagatore['nome'] = $nomeStimato;
                    }
                    if ($cognomeStimato !== '') {
                        $soggettoPagatore['anagrafica'] = $cognomeStimato;
                    }
                }
            }
        } else {
            $soggettoPagatore = [];
        }

        $form = [
            'idTipoPendenza' => $idTipo,
            'causale' => $causale,
            'importo' => $pendenza['importo'] ?? '',
            'annoRiferimento' => $pendenza['annoRiferimento'] ?? '',
            'soggettoPagatore' => $soggettoPagatore,
            'voci' => $voci,
            'dataValidita' => $pendenza['dataValidita'] ?? '',
            'dataScadenza' => $pendenza['dataScadenza'] ?? '',
            'direzione' => $pendenza['direzione'] ?? '',
            'divisione' => $pendenza['divisione'] ?? '',
            'cartellaPagamento' => $pendenza['cartellaPagamento'] ?? '',
        ];

        $accountingDefaults = $this->extractAccountingDefaultsFromPendenza($pendenza);
        if ($accountingDefaults) {
            $form['ibanAccredito'] = $accountingDefaults['ibanAccredito'] ?? '';
            $form['codiceContabilita'] = $accountingDefaults['codiceContabilita'] ?? ($accountingDefaults['codEntrata'] ?? '');
            $form['tipoBollo'] = $accountingDefaults['tipoBollo'] ?? '';
            $form['tipoContabilita'] = $accountingDefaults['tipoContabilita'] ?? '';
        }

        if (empty($form['idTipoPendenza'])) {
            \App\Logger::getInstance()->debug('preparePendenzaForForm: idTipoPendenza non trovato nelle chiavi standard', [
                'keys' => array_keys($pendenza),
            ]);
        }

        return $form;
    }

    /**
     * Aggiunge i dati di una notifica ai datiAllegati della pendenza.
     * 
     * @param string $idPendenza ID della pendenza
     * @param array $notificationData Dati della notifica (timestamp, esito, destinatario, canale)
     * @return bool True se l'aggiornamento è riuscito
     */
    private function extractIuvAndNumeroAvviso(array $response): array
    {
        $iuv = '';
        $numeroAvviso = '';

        $iuvPaths = [
            ['iuvPagamento'],
            ['iuv'],
            ['iuvAvviso'],
            ['identificativoUnivocoVersamento'],
            ['numeroAvviso'],
            ['numero_avviso'],
            ['noticeNumber'],
            ['notice_number'],
            ['iuv_pagamento'],
            ['pendenza', 'iuvPagamento'],
            ['pendenza', 'iuv'],
            ['pendenza', 'iuvAvviso'],
            ['pendenza', 'identificativoUnivocoVersamento'],
            ['pendenza', 'numeroAvviso'],
            ['pendenza', 'numero_avviso'],
            ['pendenza', 'noticeNumber'],
            ['pendenza', 'notice_number'],
            ['avvisi', 0, 'iuv'],
            ['avvisi', 0, 'iuvPagamento'],
            ['avvisi', 0, 'iuvAvviso'],
            ['avvisi', 0, 'identificativoUnivocoVersamento'],
            ['avvisi', 0, 'numeroAvviso'],
            ['avvisi', 0, 'numero_avviso'],
            ['avvisi', 0, 'noticeNumber'],
            ['avvisi', 0, 'notice_number'],
            ['rpt', 'datiVersamento', 'identificativoUnivocoVersamento'],
            ['rt', 'datiPagamento', 'identificativoUnivocoVersamento'],
        ];
        foreach ($iuvPaths as $path) {
            $value = $this->extractStringByPath($response, $path);
            if ($value !== '') {
                $iuv = $value;
                break;
            }
        }

        $numeroPaths = [
            ['numeroAvviso'],
            ['numero_avviso'],
            ['iuvAvviso'],
            ['iuv_avviso'],
            ['noticeNumber'],
            ['notice_number'],
            ['pendenza', 'numeroAvviso'],
            ['pendenza', 'numero_avviso'],
            ['pendenza', 'iuvAvviso'],
            ['pendenza', 'iuv_avviso'],
            ['pendenza', 'noticeNumber'],
            ['pendenza', 'notice_number'],
            ['avvisi', 0, 'numeroAvviso'],
            ['avvisi', 0, 'numero_avviso'],
            ['avvisi', 0, 'iuvAvviso'],
            ['avvisi', 0, 'iuv_avviso'],
            ['avvisi', 0, 'noticeNumber'],
            ['avvisi', 0, 'notice_number'],
            ['iuvPagamento'],
            ['iuv'],
            ['iuvAvviso'],
            ['identificativoUnivocoVersamento'],
            ['rpt', 'datiVersamento', 'identificativoUnivocoVersamento'],
            ['rt', 'datiPagamento', 'identificativoUnivocoVersamento'],
        ];
        foreach ($numeroPaths as $path) {
            $value = $this->extractStringByPath($response, $path);
            if ($value !== '') {
                $numeroAvviso = $value;
                break;
            }
        }

        // Fallback robusto: cerca ricorsivamente nei payload (GovPay puo variare struttura/chiavi).
        if ($iuv === '') {
            $iuv = $this->findFirstValueByKeysRecursive($response, [
                'iuv',
                'iuvPagamento',
                'iuv_pagamento',
                'identificativoUnivocoVersamento',
            ]);
        }
        if ($numeroAvviso === '') {
            $numeroAvviso = $this->findFirstValueByKeysRecursive($response, [
                'numeroAvviso',
                'numero_avviso',
                'iuvAvviso',
                'iuv_avviso',
                'noticeNumber',
                'notice_number',
            ]);
        }

        // Se uno dei due manca ancora, usa l'altro come fallback (stesso comportamento usato altrove).
        if ($iuv === '' && $numeroAvviso !== '') {
            $iuv = $numeroAvviso;
        }
        if ($numeroAvviso === '' && $iuv !== '') {
            $numeroAvviso = $iuv;
        }

        return [$iuv, $numeroAvviso];
    }

    private function findFirstValueByKeysRecursive(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
                if (is_int($value) || is_float($value)) {
                    return (string)$value;
                }
            }
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = $this->findFirstValueByKeysRecursive($value, $keys);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    private function resolveFrontofficePublicBaseUrl(?Request $request = null): string
    {
        $fromEnv = trim((string)(getenv('FRONTOFFICE_PUBLIC_BASE_URL') ?: ''));
        if ($fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        $fromBackoffice = trim((string)(getenv('BACKOFFICE_PUBLIC_BASE_URL') ?: ''));
        if ($fromBackoffice !== '') {
            $normalized = rtrim($fromBackoffice, '/');
            if (str_ends_with(strtolower($normalized), '/backoffice')) {
                $normalized = substr($normalized, 0, -strlen('/backoffice'));
            }
            if ($normalized !== '') {
                return $normalized;
            }
        }

        if ($request !== null) {
            $uri = $request->getUri();
            $scheme = $uri->getScheme();
            $host = $uri->getHost();
            if ($scheme !== '' && $host !== '') {
                $port = $uri->getPort();
                $defaultPort = ($scheme === 'https' ? 443 : 80);
                $portSuffix = ($port !== null && $port !== $defaultPort) ? (':' . $port) : '';
                return $scheme . '://' . $host . $portSuffix;
            }
        }

        return '';
    }

    private function buildPdfDownloadUrl(
        string $frontofficeBaseUrl,
        string $idDominio,
        string $codiceFiscale,
        string $iuv,
        string $numeroAvviso
    ): string {
        $baseUrl = rtrim(trim($frontofficeBaseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }

        $safeIuv = trim($iuv);
        $safeNumeroAvviso = trim($numeroAvviso);
        $safeCf = trim($codiceFiscale);
        $safeIdDominio = trim($idDominio);

        // /link/avviso lato frontoffice risolve tramite "numero avviso".
        // Preferiamo quindi numeroAvviso/iuvAvviso quando disponibile.
        $iuvForSignedLink = $safeNumeroAvviso !== '' ? $safeNumeroAvviso : $safeIuv;
        if ($iuvForSignedLink !== '' && $safeCf !== '') {
            $signedParams = $this->buildSignedLinkParams(['cf' => $safeCf, 'iuv' => $iuvForSignedLink]);
            return $baseUrl . '/link/avviso?' . http_build_query($signedParams, '', '&', PHP_QUERY_RFC3986);
        }

        $numeroForClassicLink = $safeNumeroAvviso !== '' ? $safeNumeroAvviso : $iuvForSignedLink;
        if ($safeIdDominio !== '' && $numeroForClassicLink !== '') {
            return $baseUrl . '/avvisi/' . rawurlencode($safeIdDominio) . '/' . rawurlencode($numeroForClassicLink);
        }

        return '';
    }

    private function buildCheckoutStartUrl(
        string $frontofficeBaseUrl,
        string $codiceFiscale,
        string $iuv,
        string $numeroAvviso
    ): string {
        $baseUrl = rtrim(trim($frontofficeBaseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }

        $safeCf = trim($codiceFiscale);
        $safeIuv = trim($iuv);
        $safeNumeroAvviso = trim($numeroAvviso);
        $checkoutIuv = $safeNumeroAvviso !== '' ? $safeNumeroAvviso : $safeIuv;

        if ($safeCf === '' || $checkoutIuv === '') {
            return '';
        }

        $signedParams = $this->buildSignedLinkParams([
            'cf' => $safeCf,
            'iuv' => $checkoutIuv,
            'action' => 'checkout',
        ]);

        return $baseUrl . '/link/checkout?' . http_build_query($signedParams, '', '&', PHP_QUERY_RFC3986);
    }

    private function extractStringByPath(array $data, array $path): string
    {
        $current = $data;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        if (is_string($current)) {
            return trim($current);
        }
        if (is_int($current) || is_float($current)) {
            return (string)$current;
        }

        return '';
    }

    private function fetchPendenzaById(string $idPendenza): ?array
    {
        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        $idA2A = getenv('ID_A2A') ?: '';
        if ($backofficeUrl === '' || $idA2A === '' || $idPendenza === '') {
            return null;
        }

        try {
            $username = getenv('GOVPAY_USER');
            $password = getenv('GOVPAY_PASSWORD');
            $guzzleOptions = [
                'headers' => ['Accept' => 'application/json'],
            ];
            if ($username !== false && $password !== false && $username !== '' && $password !== '') {
                $guzzleOptions['auth'] = [$username, $password];
            }

            $http = $this->makeHttpClient($guzzleOptions);
            $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode((string)$idA2A) . '/' . rawurlencode($idPendenza);
            $resp = $http->request('GET', $url);
            $data = json_decode((string)$resp->getBody(), true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Impossibile recuperare pendenza per notifiche', [
                'idPendenza' => $idPendenza,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function resolveEmbeddedLogoPath(): string
    {
        $logoSrc = trim((string)(getenv('APP_LOGO_SRC') ?: ''));
        if ($logoSrc === '') {
            return '';
        }

        // Path assoluto nel filesystem del container
        if ($logoSrc[0] !== '/' && preg_match('/^[A-Za-z]:[\\\/]/', $logoSrc) !== 1) {
            return '';
        }

        // URL remoto non embeddabile via file path
        if (preg_match('/^https?:\/\//i', $logoSrc) === 1) {
            return '';
        }

        // APP_LOGO_SRC tipicamente e '/img/...': nel container i file stanno in /var/www/html/public
        if (str_starts_with($logoSrc, '/img/') || str_starts_with($logoSrc, '/assets/')) {
            $candidate = '/var/www/html/public' . $logoSrc;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Fallback: prova direttamente il path indicato
        if (is_file($logoSrc)) {
            return $logoSrc;
        }

        return '';
    }

    /**
     * Genera parametri per un link firmato stateless (HMAC-SHA256) con scadenza 2 anni.
     * Usa la variabile d'ambiente FRONTOFFICE_LINK_SIGNING_KEY come secret.
     * @param int $ttlSeconds Durata validità in secondi (default: 63072000 = 2 anni)
     */
    private function buildSignedLinkParams(array $params, int $ttlSeconds = 63072000 /* 60*60*24*365*2 = 2 anni */): array
    {
        $signingKey = (string)(getenv('FRONTOFFICE_LINK_SIGNING_KEY') ?: '');
        if ($signingKey === '') {
            // Fallback: secret derivato da variabili già presenti
            $signingKey = hash('sha256',
                (string)(getenv('APP_SECRET') ?: '')
                . (string)(getenv('ID_DOMINIO') ?: '')
                . 'gil-link-signing-fallback'
            );
        }
        $expires = time() + $ttlSeconds;
        $params['expires'] = (string)$expires;
        ksort($params);
        $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $sig = hash_hmac('sha256', $payload, $signingKey);
        return array_merge($params, ['sig' => $sig]);
    }

    private function addNotificationToPendenza(string $idPendenza, array $notificationData, ?string $storicoOperazione = null): bool
    {
        $backofficeUrl = getenv('GOVPAY_BACKOFFICE_URL') ?: '';
        $idA2A = getenv('ID_A2A') ?: '';
        
        if ($backofficeUrl === '' || $idA2A === '') {
            return false;
        }
        
        try {
            // 1) Recupera la pendenza corrente
            $http = $this->makeHttpClient();
            $getUrl = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);
            $resp = $http->request('GET', $getUrl, ['headers' => ['Accept' => 'application/json']]);
            $pendenza = json_decode((string)$resp->getBody(), true);
            
            if (!is_array($pendenza)) {
                return false;
            }
            
            // 2) Prepara il payload per il PUT
            $allowedKeys = [
                'numeroAvviso','tassonomia','dataValidita','datiAllegati','tassonomiaAvviso','importo','dataScadenza',
                'dataPromemoriaScadenza','idUnitaOperativa','idDominio','allegati','dataCaricamento','annoRiferimento',
                'divisione','nome','causale','soggettoPagatore','dataNotificaAvviso','cartellaPagamento','documento',
                'proprieta','direzione','idTipoPendenza','voci'
            ];
            $put = array_intersect_key($pendenza, array_flip($allowedKeys));
            
            // 3) Aggiorna datiAllegati con le informazioni della notifica
            $put['datiAllegati'] = $put['datiAllegati'] ?? [];
            if (!isset($put['datiAllegati']['notifiche']) || !is_array($put['datiAllegati']['notifiche'])) {
                $put['datiAllegati']['notifiche'] = [];
            }
            
            $put['datiAllegati']['notifiche'][] = [
                'timestamp' => $notificationData['timestamp'] ?? date('Y-m-d H:i:s'),
                'tipo' => $notificationData['tipo'] ?? 'creazione_pendenza',
                'canale' => $notificationData['canale'] ?? 'email',
                'destinatario' => $notificationData['destinatario'] ?? '',
                'esito' => $notificationData['esito'] ?? 'OK',
                'message_id' => $notificationData['message_id'] ?? null,
                'errore' => $notificationData['errore'] ?? null,
            ];

            if ($storicoOperazione !== null && $storicoOperazione !== '') {
                if (!isset($put['datiAllegati']['storico']) || !is_array($put['datiAllegati']['storico'])) {
                    $put['datiAllegati']['storico'] = [];
                }
                $put['datiAllegati']['storico'][] = [
                    'data' => date('Y-m-d H:i:s'),
                    'operatore' => $this->getCurrentOperatorString(),
                    'operazione' => $storicoOperazione,
                ];
            }
            
            // 4) Rimuovi campi non consentiti nel body
            unset($put['idPendenza'], $put['idA2A']);
            
            // 5) Assicura che idDominio sia presente (obbligatorio)
            if (empty($put['idDominio'])) {
                $put['idDominio'] = getenv('ID_DOMINIO') ?: '';
            }
            
            // 6) Invia il PUT
            $result = $this->sendPendenzaToBackoffice($put, $idPendenza);
            return $result['success'] ?? false;
            
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore aggiornamento datiAllegati per notifica', [
                'idPendenza' => $idPendenza,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }
}

