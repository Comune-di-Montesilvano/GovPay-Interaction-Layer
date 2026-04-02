<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services;

/**
 * PortainerClient — wrapper minimale per le Portainer API.
 *
 * Sostituisce govpay-interaction-master per la gestione dei container.
 * Si configura tramite variabili d'ambiente:
 *   PORTAINER_URL          URL base Portainer (es. http://portainer:9000)
 *   PORTAINER_API_TOKEN    API token con prefisso ptr_ (Settings → API)
 *   PORTAINER_ENDPOINT_ID  ID dell'endpoint Docker (default: 1)
 *
 * Se le variabili non sono impostate, isConfigured() restituisce false
 * e tutti i metodi di azione restituiscono un errore graceful con
 * reason='portainer_not_configured'.
 */
class PortainerClient
{
    private string $baseUrl;
    private string $apiToken;
    private int    $endpointId;

    public function __construct()
    {
        $this->baseUrl    = rtrim((string) (getenv('PORTAINER_URL') ?: ''), '/');
        $this->apiToken   = (string) (getenv('PORTAINER_API_TOKEN') ?: '');
        $this->endpointId = (int)   (getenv('PORTAINER_ENDPOINT_ID') ?: 1);
    }

    /**
     * True se PORTAINER_URL e PORTAINER_API_TOKEN sono impostati.
     * Usare per mostrare/nascondere i controlli nell'UI.
     */
    public static function isConfigured(): bool
    {
        return !empty(getenv('PORTAINER_URL')) && !empty(getenv('PORTAINER_API_TOKEN'));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Azioni container
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Riavvia i container specificati per nome.
     *
     * @param  string[] $names Nomi dei container (es. ['govpay-interaction-frontoffice'])
     * @return array{success: bool, message: string}
     */
    public function restartContainers(array $names): array
    {
        return $this->bulkAction($names, 'restart');
    }

    /**
     * Avvia i container specificati per nome (devono già esistere nello stack).
     *
     * @param  string[] $names
     * @return array{success: bool, message: string}
     */
    public function startContainers(array $names): array
    {
        return $this->bulkAction($names, 'start');
    }

    /**
     * Ferma i container specificati per nome.
     *
     * @param  string[] $names
     * @return array{success: bool, message: string}
     */
    public function stopContainers(array $names): array
    {
        return $this->bulkAction($names, 'stop');
    }

    /**
     * Restituisce lo stato dei container visibili sull'endpoint.
     * Il risultato è indicizzato per container name.
     *
     * @return array{success: bool, message: string, data?: array<string, array{state: string, status: string}>}
     */
    public function getContainersStatus(): array
    {
        if (!self::isConfigured()) {
            return $this->notConfigured();
        }

        $result = $this->request('GET', "/api/endpoints/{$this->endpointId}/docker/containers/json?all=1");
        if (!$result['ok']) {
            return ['success' => false, 'message' => $result['error']];
        }

        $containers = [];
        foreach ((array) $result['body'] as $c) {
            $name = ltrim($c['Names'][0] ?? '', '/');
            $containers[$name] = [
                'state'  => $c['State']  ?? 'unknown',
                'status' => $c['Status'] ?? 'unknown',
            ];
        }

        return ['success' => true, 'data' => $containers];
    }

    /**
     * Esegue un comando dentro un container esistente tramite Docker Exec API.
     *
     * @param  string   $containerName Nome container Docker (es. gil-metadata-builder-1)
     * @param  string[] $command       Comando e argomenti (es. ['export-agid'])
     * @param  int      $timeoutSeconds Timeout massimo in secondi
     * @return array{success: bool, message: string, output?: string, exit_code?: int}
     */
    public function execInContainer(string $containerName, array $command, int $timeoutSeconds = 120): array
    {
        if (!self::isConfigured()) {
            return $this->notConfigured();
        }

        if ($containerName === '' || $command === []) {
            return ['success' => false, 'message' => 'Container o comando non valido.'];
        }

        $container = rawurlencode($containerName);

        $create = $this->request('POST', "/api/endpoints/{$this->endpointId}/docker/containers/{$container}/exec", [
            'AttachStdout' => true,
            'AttachStderr' => true,
            'Tty'          => false,
            'Cmd'          => array_values($command),
        ]);

        if (!$create['ok']) {
            return ['success' => false, 'message' => "Impossibile creare exec su {$containerName}: {$create['error']}"];
        }

        $execId = is_array($create['body']) ? (string)($create['body']['Id'] ?? '') : '';
        if ($execId === '') {
            return ['success' => false, 'message' => "Exec ID non disponibile per {$containerName}."];
        }

        $start = $this->request('POST', "/api/endpoints/{$this->endpointId}/docker/exec/{$execId}/start", [
            'Detach' => false,
            'Tty'    => false,
        ]);

        if (!$start['ok']) {
            return ['success' => false, 'message' => "Impossibile avviare exec su {$containerName}: {$start['error']}"];
        }

        $deadline = microtime(true) + max(5, $timeoutSeconds);
        $exitCode = null;

        while (microtime(true) < $deadline) {
            $inspect = $this->request('GET', "/api/endpoints/{$this->endpointId}/docker/exec/{$execId}/json");
            if ($inspect['ok'] && is_array($inspect['body'])) {
                $running = (bool)($inspect['body']['Running'] ?? false);
                if (!$running) {
                    $exitCode = (int)($inspect['body']['ExitCode'] ?? -1);
                    break;
                }
            }
            usleep(200000);
        }

        if ($exitCode === null) {
            return ['success' => false, 'message' => "Timeout exec su {$containerName} dopo {$timeoutSeconds}s."];
        }

        $output = is_string($start['body']) ? trim($start['body']) : '';
        if ($exitCode !== 0) {
            $msg = "Comando fallito in {$containerName} (exit {$exitCode})";
            if ($output !== '') {
                $msg .= ': ' . $output;
            }
            return ['success' => false, 'message' => $msg, 'output' => $output, 'exit_code' => $exitCode];
        }

        return [
            'success'   => true,
            'message'   => "Comando eseguito correttamente in {$containerName}.",
            'output'    => $output,
            'exit_code' => 0,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    private function bulkAction(array $names, string $action): array
    {
        if (!self::isConfigured()) {
            return $this->notConfigured();
        }

        $errors = [];
        foreach ($names as $name) {
            $result = $this->request('POST', "/api/endpoints/{$this->endpointId}/docker/containers/{$name}/{$action}");
            if (!$result['ok']) {
                $errors[] = "{$name}: " . $result['error'];
            }
        }

        if ($errors) {
            return ['success' => false, 'message' => implode('; ', $errors)];
        }

        return [
            'success' => true,
            'message' => ucfirst($action) . ' completato: ' . implode(', ', $names),
        ];
    }

    /**
     * @return array{ok: bool, body: mixed, error: string}
     */
    private function request(string $method, string $path, mixed $body = null): array
    {
        $url  = $this->baseUrl . $path;
        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => "X-API-Key: {$this->apiToken}\r\nContent-Type: application/json\r\n",
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = json_encode($body);
        }

        // SSL: disabilita verifica certificato per installazioni con cert self-signed
        // (equivalente a curl -k — necessario per indirizzi interni come 10.x.x.x:9443)
        $opts['ssl'] = [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ];

        $ctx    = stream_context_create($opts);
        $raw    = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (!empty($http_response_header[0])) {
            preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m);
            $status = (int) ($m[1] ?? 0);
        }

        if ($raw === false) {
            return ['ok' => false, 'body' => null, 'error' => "Impossibile contattare Portainer ({$this->baseUrl})"];
        }

        if ($status >= 400) {
            $msg = json_decode($raw, true)['message'] ?? "HTTP {$status}";
            return ['ok' => false, 'body' => null, 'error' => $msg];
        }

        $parsed = $raw !== '' ? (json_decode($raw, true) ?? $raw) : null;
        return ['ok' => true, 'body' => $parsed, 'error' => ''];
    }

    /**
     * Risposta standard quando Portainer non è configurato.
     * L'UI può controllare il campo 'reason' per mostrare il banner informativo.
     */
    private function notConfigured(): array
    {
        return [
            'success' => false,
            'reason'  => 'portainer_not_configured',
            'message' => 'Portainer non configurato. Imposta PORTAINER_URL e PORTAINER_API_TOKEN per gestire i container dall\'interfaccia.',
        ];
    }
}
