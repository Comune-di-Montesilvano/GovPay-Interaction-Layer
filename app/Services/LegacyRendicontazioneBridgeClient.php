<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use GuzzleHttp\Client;

class LegacyRendicontazioneBridgeClient
{
    private Client $client;
    private string $bridgeUrl = '';

    public function __construct(?Client $client = null)
    {
        if ($client !== null) {
            $this->client = $client;
            return;
        }

        $this->bridgeUrl = trim((string)SettingsRepository::get('rendicontazione', 'bridge_url', ''));
        $token           = (string)SettingsRepository::get('rendicontazione', 'bridge_token', '');

        $this->client = new Client([
            'connect_timeout' => 5.0,
            'timeout'         => 15.0,
            'headers'         => $token !== '' ? ['Authorization' => 'Bearer ' . $token] : [],
        ]);
    }

    /** @return array{esito: bool, messaggio: string} */
    public function invia(
        string $handler,
        string $iuv,
        string $idAtto,
        string $dataPagamento,
        float  $importo,
        ?string $rata = null
    ): array {
        $payload = [
            'handler'        => $handler,
            'iuv'            => $iuv,
            'id_atto'        => $idAtto,
            'data_pagamento' => $dataPagamento,
            'importo'        => $importo,
        ];
        if ($rata !== null) {
            $payload['rata'] = $rata;
        }

        try {
            $response = $this->client->post($this->bridgeUrl, ['json' => $payload]);
            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) {
                return ['esito' => false, 'messaggio' => 'Risposta non valida dal bridge'];
            }
            return [
                'esito'     => (bool)($data['esito'] ?? false),
                'messaggio' => (string)($data['messaggio'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return ['esito' => false, 'messaggio' => $e->getMessage()];
        }
    }
}
