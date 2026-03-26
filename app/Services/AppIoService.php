<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use App\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * AppIoService — integrazione con le API App IO per l'invio di messaggi.
 * 
 * Uso:
 *   $ioService = new AppIoService();
 *   $result = $ioService->sendMessage(
 *       'api-key-here',
 *       'RSSMRA85T10A562S',  // codice fiscale
 *       'Avviso di pagamento',
 *       '# Titolo\n\nDescrizione...',
 *       '2025-12-31'
 *   );
 *   // $result = ['esito' => 'OK|KO', 'id' => '...', 'errore' => '...']
 */
class AppIoService
{
    private string $baseUrl;
    private Client $client;

    public function __construct(?string $baseUrl = null)
    {
        // Supporta URL base custom per sandbox/test
        $this->baseUrl = $baseUrl ?? 'https://api.io.pagopa.it/api/v1';
        $this->client = new Client(['timeout' => 10]);
    }

    /**
     * Invia un messaggio via App IO.
     * 
     * @param string $apiKey Chiave API IO (primaria o secondaria)
     * @param string $fiscalCode Codice fiscale (XXXXXXXX) senza X al posto dei dati sensibili
     * @param string $subject Oggetto del messaggio (max ~120 char)
     * @param string $markdown Contenuto in markdown (max ~3000 char)
     * @param ?string $dueDate Data di scadenza in formato ISO8601 (es: "2025-12-31")
     * @param ?array $paymentData Dati pagamento: ['noticeNumber' => '...', 'amount' => centesimi, 'invalidAfterDueDate' => bool]
     * @param ?string $ctaLink URL per CTA primaria (es. checkout immediato)
     * 
     * @return array{esito: string, id: ?string, errore: ?string}
     *   - esito: 'OK' se inviato, 'KO' se errore
     *   - id: ID del messaggio se OK, null se KO
     *   - errore: Messaggio di errore se KO, null se OK
     */
    public function sendMessage(
        string $apiKey,
        string $fiscalCode,
        string $subject,
        string $markdown,
        ?string $dueDate = null,
        ?array $paymentData = null,
        ?string $ctaLink = null
    ): array {
        try {
            // Default conservativo: STANDARD. Se necessario, puo essere forzato da env.
            $featureLevelType = strtoupper(SettingsRepository::get('pagopa', 'appio_feature_level', 'STANDARD') ?: 'STANDARD');
            if ($featureLevelType !== 'STANDARD' && $featureLevelType !== 'ADVANCED') {
                $featureLevelType = 'STANDARD';
            }

            // Costruisci il body JSON
            $body = [
                'fiscal_code' => $fiscalCode,
                'feature_level_type' => $featureLevelType,
                'content' => [
                    'subject' => $subject,
                    'markdown' => $markdown,
                ],
            ];

            // Aggiungi dueDate se presente (deve essere ISO8601)
            if ($dueDate !== null && $dueDate !== '') {
                $body['content']['due_date'] = $dueDate;
            }

            // Aggiungi payment_data se presente (IUV/notice_number + importo in centesimi)
            if ($paymentData !== null && isset($paymentData['noticeNumber'], $paymentData['amount'])) {
                $body['content']['payment_data'] = [
                    'notice_number' => (string)$paymentData['noticeNumber'],
                    'amount' => (int)$paymentData['amount'],
                    'invalid_after_due_date' => (bool)($paymentData['invalidAfterDueDate'] ?? false),
                ];
            }

            // Aggiungi CTA link se presente (es. checkout immediato)
            if ($ctaLink !== null && $ctaLink !== '') {
                $body['content']['third_party_data'] = [
                    'id' => hash('sha256', $ctaLink),
                    'original_sender' => SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL',
                    'original_receipt_date' => date('Y-m-d\TH:i:s\Z'),
                    'has_attachments' => false,
                    'summary' => 'Avviso di pagamento',
                ];
            }

            // Effettua la richiesta
            $response = $this->client->post(
                rtrim($this->baseUrl, '/') . '/messages',
                [
                    'headers' => [
                        'Ocp-Apim-Subscription-Key' => $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $body,
                ]
            );

            $statusCode = $response->getStatusCode();
            
            // Se la risposta è 201 o 200, il messaggio è stato creato
            if (in_array($statusCode, [200, 201], true)) {
                $responseData = json_decode($response->getBody()->getContents(), true);
                $messageId = $responseData['id'] ?? null;
                
                Logger::getInstance()->info('Messaggio IO inviato con successo', [
                    'fiscal_code' => $this->maskFiscalCode($fiscalCode),
                    'message_id' => $messageId,
                ]);

                return [
                    'esito' => 'OK',
                    'id' => $messageId,
                    'errore' => null,
                ];
            } else {
                $errorMsg = "HTTP $statusCode da API IO";
                Logger::getInstance()->warning('Errore invio messaggio IO', [
                    'fiscal_code' => $this->maskFiscalCode($fiscalCode),
                    'status' => $statusCode,
                    'body' => $response->getBody()->getContents(),
                ]);

                return [
                    'esito' => 'KO',
                    'id' => null,
                    'errore' => $errorMsg,
                ];
            }
        } catch (RequestException $e) {
            // Errori di rete o risposta HTTP non 2xx
            $statusCode = $e->getResponse()?->getStatusCode();
            $body = $e->getResponse()?->getBody()->getContents() ?? '';
            $errorMsg = $e->getMessage();

            // Prova a estrarre un messaggio from il body della risposta
            if ($body !== '') {
                $data = json_decode($body, true);
                if (is_array($data) && isset($data['detail'])) {
                    $errorMsg = $data['detail'];
                } elseif (is_array($data) && isset($data['message'])) {
                    $errorMsg = $data['message'];
                }
            }

            Logger::getInstance()->warning('Eccezione RequestException durante invio IO', [
                'fiscal_code' => $this->maskFiscalCode($fiscalCode),
                'status' => $statusCode,
                'error' => $errorMsg,
            ]);

            return [
                'esito' => 'KO',
                'id' => null,
                'errore' => $errorMsg,
            ];
        } catch (\Throwable $e) {
            // Errori generici (timeout, etc.)
            Logger::getInstance()->error('Errore generico durante invio IO', [
                'fiscal_code' => $this->maskFiscalCode($fiscalCode),
                'exception' => $e->getMessage(),
            ]);

            return [
                'esito' => 'KO',
                'id' => null,
                'errore' => $e->getMessage(),
            ];
        }
    }

    /**
     * Maschera il codice fiscale per il logging (mostra solo le ultime 4 cifre).
     */
    private function maskFiscalCode(string $code): string
    {
        if (strlen($code) <= 4) {
            return '****';
        }
        return '****' . substr($code, -4);
    }
}
