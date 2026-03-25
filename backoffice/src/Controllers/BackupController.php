<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Database\EntrateRepository;
use App\Database\ExternalPaymentTypeRepository;
use App\Config\ConfigLoader;
use App\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

/**
 * Gestisce backup e restore in due scenari:
 *
 * 1. Backup dati GovPay (legacy, dal tab /configurazione):
 *    - exportBackup() — scarica un JSON con tipologie/templates/io_services/utenti
 *    - importBackup() — carica e applica un JSON di backup dati
 *
 * 2. Backup di sistema (via Master Container):
 *    - systemBackupCreate()   — avvia il backup completo (settings DB + mysqldump + certs SPID)
 *    - systemBackupList()     — lista i backup disponibili
 *    - systemBackupDownload() — scarica un archivio .zip
 */
class BackupController
{
    private const MASTER_URL = 'http://govpay-interaction-master:8099';

    public function __construct(private readonly Twig $twig)
    {
    }

    // ──────────────────────────────────────────────────────────────────────
    // BACKUP DATI GOVPAY (esistente, spostato da ConfigurazioneController)
    // ──────────────────────────────────────────────────────────────────────

    public function exportBackup(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $response->withStatus(403);
        }

        $data     = (array)($request->getParsedBody() ?? []);
        $sections = isset($data['sections']) && is_array($data['sections']) ? $data['sections'] : [];

        $idDominio = (string)(\App\Config\Config::get('ID_DOMINIO') ?: '');

        $export = [
            'version'     => '1.0',
            'exported_at' => (new \DateTimeImmutable())->format('c'),
            'exported_by' => $_SESSION['user']['email'] ?? 'unknown',
            'id_dominio'  => $idDominio,
            'sections'    => [],
        ];

        if (in_array('tipologie', $sections, true)) {
            $repo = new EntrateRepository();
            $export['sections']['tipologie'] = $repo->listLocalOverrides($idDominio);
        }

        if (in_array('tipologie_esterne', $sections, true)) {
            $repo = new ExternalPaymentTypeRepository();
            $export['sections']['tipologie_esterne'] = $repo->listAll();
        }

        if (in_array('templates', $sections, true)) {
            $repo = new \App\Database\PendenzaTemplateRepository();
            $export['sections']['templates'] = $repo->findAllByDominioWithUsers($idDominio);
        }

        if (in_array('io_services', $sections, true)) {
            $ioRepo = new \App\Database\IoServiceRepository();
            $services = $ioRepo->listAll();
            $pdo = Connection::getPDO();
            $stmt = $pdo->query('SELECT id_entrata, io_service_id FROM io_service_tipologie');
            $links = $stmt->fetchAll();
            $serviceLinks = [];
            foreach ($links as $l) {
                $serviceLinks[(int)$l['io_service_id']][] = $l['id_entrata'];
            }
            foreach ($services as &$s) {
                $s['tipologie'] = $serviceLinks[(int)$s['id']] ?? [];
                unset($s['id'], $s['created_at'], $s['updated_at']);
            }
            unset($s);
            $export['sections']['io_services'] = $services;
        }

        if (in_array('utenti', $sections, true)) {
            $pdo = Connection::getPDO();
            $stmt = $pdo->query(
                'SELECT email, role, first_name, last_name, is_disabled, password_hash
                 FROM users ORDER BY email ASC'
            );
            $export['sections']['utenti'] = $stmt->fetchAll();
        }

        $json     = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $filename = 'govpay-config-backup-' . date('Ymd_His') . '.json';

        $response->getBody()->write($json);
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)strlen($json));
    }

    public function importBackup(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato'];
            return $this->redirectToConfigTab($response, 'backup');
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['backup_file'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Nessun file caricato o errore nel file'];
            return $this->redirectToConfigTab($response, 'backup');
        }

        $json   = (string)$file->getStream();
        $backup = json_decode($json, true);

        if (!is_array($backup) || !isset($backup['sections']) || !is_array($backup['sections'])) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'File non valido: struttura JSON non riconosciuta'];
            return $this->redirectToConfigTab($response, 'backup');
        }

        $postData         = (array)($request->getParsedBody() ?? []);
        $selectedSections = isset($postData['sections']) && is_array($postData['sections'])
            ? $postData['sections']
            : [];

        if (empty($selectedSections)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Seleziona almeno una sezione da importare'];
            return $this->redirectToConfigTab($response, 'backup');
        }

        $idDominio = (string)(\App\Config\Config::get('ID_DOMINIO') ?: '');
        $results   = [];
        $pdo       = Connection::getPDO();

        try {
            $pdo->beginTransaction();

            if (in_array('utenti', $selectedSections, true) && isset($backup['sections']['utenti'])) {
                $count      = 0;
                $upsertStmt = $pdo->prepare(
                    'INSERT INTO users (email, role, first_name, last_name, is_disabled, password_hash, created_at, updated_at)
                     VALUES (:email, :role, :fn, :ln, :disabled, :hash, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE role = VALUES(role), first_name = VALUES(first_name),
                         last_name = VALUES(last_name), is_disabled = VALUES(is_disabled),
                         password_hash = COALESCE(VALUES(password_hash), password_hash), updated_at = NOW()'
                );
                foreach ($backup['sections']['utenti'] as $u) {
                    if (empty($u['email'])) {
                        continue;
                    }
                    $upsertStmt->execute([
                        ':email'    => $u['email'],
                        ':role'     => $u['role'] ?? 'user',
                        ':fn'       => $u['first_name'] ?? '',
                        ':ln'       => $u['last_name'] ?? '',
                        ':disabled' => empty($u['is_disabled']) ? 0 : 1,
                        ':hash'     => $u['password_hash'] ?? null,
                    ]);
                    $count++;
                }
                $results[] = $count . ' utenti aggiornati/creati';
            }

            if (in_array('io_services', $selectedSections, true) && isset($backup['sections']['io_services'])) {
                $pdo->exec('DELETE FROM io_service_tipologie');
                $pdo->exec('DELETE FROM io_services');
                $ioRepo    = new \App\Database\IoServiceRepository();
                $nomeToId  = [];
                foreach ($backup['sections']['io_services'] as $s) {
                    if (empty($s['nome']) || empty($s['id_service']) || empty($s['api_key_primaria'])) {
                        continue;
                    }
                    $newId = $ioRepo->create(
                        $s['nome'],
                        $s['descrizione'] ?? null,
                        $s['id_service'],
                        $s['api_key_primaria'],
                        $s['api_key_secondaria'] ?? null,
                        $s['codice_catalogo'] ?? null,
                        !empty($s['is_default'])
                    );
                    $nomeToId[$s['nome']] = $newId;
                    foreach ($s['tipologie'] ?? [] as $idEntrata) {
                        $ioRepo->setTipologiaService((string)$idEntrata, $newId);
                    }
                }
                $results[] = count($nomeToId) . ' servizi IO importati';
            }

            if (in_array('tipologie_esterne', $selectedSections, true) && isset($backup['sections']['tipologie_esterne'])) {
                $pdo->exec('DELETE FROM tipologie_pagamento_esterne');
                $extRepo = new ExternalPaymentTypeRepository();
                $count   = 0;
                foreach ($backup['sections']['tipologie_esterne'] as $t) {
                    if (empty($t['descrizione']) || empty($t['url'])) {
                        continue;
                    }
                    $extRepo->create(
                        $t['descrizione'],
                        $t['descrizione_estesa'] ?? null,
                        $t['url']
                    );
                    $count++;
                }
                $results[] = $count . ' tipologie esterne importate';
            }

            if (in_array('tipologie', $selectedSections, true) && isset($backup['sections']['tipologie'])) {
                $entrateRepo = new EntrateRepository();
                $updated     = $entrateRepo->replaceLocalOverrides($idDominio, $backup['sections']['tipologie']);
                $results[]   = $updated . ' override tipologie applicati';
            }

            if (in_array('templates', $selectedSections, true) && isset($backup['sections']['templates'])) {
                $tplRepo = new \App\Database\PendenzaTemplateRepository();
                $tplRepo->deleteAllByDominio($idDominio);
                $count      = 0;
                $emailToId  = [];
                $usersStmt  = $pdo->query('SELECT id, email FROM users');
                foreach ($usersStmt->fetchAll() as $u) {
                    $emailToId[strtolower($u['email'])] = (int)$u['id'];
                }
                foreach ($backup['sections']['templates'] as $t) {
                    if (empty($t['titolo']) || empty($t['id_tipo_pendenza'])) {
                        continue;
                    }
                    $newId = $tplRepo->create([
                        'id_dominio'       => $idDominio,
                        'titolo'           => $t['titolo'],
                        'id_tipo_pendenza' => $t['id_tipo_pendenza'],
                        'causale'          => $t['causale'] ?? '',
                        'importo'          => (float)($t['importo'] ?? 0),
                    ]);
                    $userIds = [];
                    foreach ($t['assigned_users'] ?? [] as $email) {
                        $uid = $emailToId[strtolower((string)$email)] ?? null;
                        if ($uid !== null) {
                            $userIds[] = $uid;
                        }
                    }
                    if (!empty($userIds)) {
                        $tplRepo->assignUsers($newId, $userIds);
                    }
                    $count++;
                }
                $results[] = $count . ' template importati';
            }

            $pdo->commit();
            $summary = implode(', ', $results);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Backup importato con successo: ' . $summary];
            Logger::getInstance()->info('Backup configurazione importato', ['sections' => $selectedSections, 'results' => $results]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore durante l\'importazione: ' . $e->getMessage()];
            Logger::getInstance()->error('Errore importazione backup', ['error' => $e->getMessage()]);
        }

        return $this->redirectToConfigTab($response, 'backup');
    }

    // ──────────────────────────────────────────────────────────────────────
    // BACKUP DI SISTEMA (via Master Container)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * POST /backup/sistema/crea — chiede al master di creare un nuovo backup completo.
     */
    public function systemBackupCreate(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        return $this->masterPost('/backup/run', []);
    }

    /**
     * GET /backup/sistema/lista — lista i backup disponibili dal master.
     */
    public function systemBackupList(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $data = $this->masterGet('/backup/list');
        return $this->jsonResponse($data);
    }

    /**
     * GET /backup/sistema/download?file=nome_file.zip
     * Proxy il download del file zip attraverso il master.
     */
    public function systemBackupDownload(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $response->withStatus(403);
        }

        $filename = $request->getQueryParams()['file'] ?? '';
        if (empty($filename) || str_contains($filename, '/') || str_contains($filename, '..')) {
            return $response->withStatus(400);
        }

        $token = ConfigLoader::get('master_token');
        if (empty($token)) {
            return $response->withStatus(503);
        }

        $url = self::MASTER_URL . '/backup/download/' . rawurlencode($filename);
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer {$token}\r\n",
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return $response->withStatus(502);
        }

        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────

    private function isSuperadmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'superadmin';
    }

    private function redirectToConfigTab(Response $response, string $tab): Response
    {
        return $response->withHeader('Location', '/configurazione?tab=' . $tab)->withStatus(302);
    }

    private function masterPost(string $path, array $payload): Response
    {
        $token = ConfigLoader::get('master_token');
        if (empty($token)) {
            return $this->jsonError('Master Container non configurato (token mancante).');
        }

        $url = self::MASTER_URL . $path;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
                'content'       => json_encode($payload),
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            return $this->jsonError('Impossibile contattare il Master Container.');
        }

        $json = json_decode($result, true) ?? ['success' => false, 'message' => $result];
        return $this->jsonResponse($json, ($json['success'] ?? false) ? 200 : 500);
    }

    private function masterGet(string $path): array
    {
        $token = ConfigLoader::get('master_token');
        if (empty($token)) {
            return ['error' => 'Master Container non configurato.'];
        }

        $url = self::MASTER_URL . $path;
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Authorization: Bearer {$token}\r\n",
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        return $result ? (json_decode($result, true) ?? []) : ['error' => 'Risposta non valida'];
    }

    private function jsonOk(string $message): Response
    {
        return $this->jsonResponse(['success' => true, 'message' => $message]);
    }

    private function jsonError(string $message, int $status = 400): Response
    {
        return $this->jsonResponse(['success' => false, 'message' => $message], $status);
    }

    private function jsonResponse(array $data, int $status = 200): Response
    {
        $resp = new SlimResponse($status);
        $resp->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $resp->withHeader('Content-Type', 'application/json');
    }
}
