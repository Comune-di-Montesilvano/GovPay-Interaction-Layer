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
use App\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

/**
 * Gestisce backup e restore di sistema tramite archivio ZIP.
 */
class BackupController
{
    private const BACKUP_DIR = '/backups';

    /** Volumi inclusi nel backup di sistema. */
    private const BACKUP_VOLUMES = [
        'gil_certs'                => '/var/www/certificate',
        'gil_images'               => '/var/www/html/public/img',
    ];

    /** Sezioni dati applicativi supportate nel govpay-config.json. */
    private const GOVPAY_SECTIONS = [
        'tipologie',
        'tipologie_esterne',
        'templates',
        'io_services',
        'utenti',
        'gruppi-utenti',
        'mapping_tipologie_custom',
        'mapping_pendenze',
        'rendicontazione',
    ];

    public function __construct(private readonly Twig $twig)
    {
    }

    // ──────────────────────────────────────────────────────────────────────
    // BACKUP DI SISTEMA (logica PHP diretta su volume gil_backups)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * POST /backup/sistema/crea — crea un archivio ZIP in /backups con:
     *   - settings.json (sempre)
     *   - db_dump.sql.gz (sempre, se mysqldump disponibile)
     *   - govpay-config.json (solo se richieste sezioni applicative)
     *   - volumes/<nome>/<file> (solo volumi selezionati)
     */
    public function systemBackupCreate(Request $request, Response $response): Response
    {
        $requestId = bin2hex(random_bytes(6));

        if (!$this->isSuperadmin()) {
            Logger::getInstance()->warning('Backup create negato: utente non superadmin', ['request_id' => $requestId]);
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $payload = (array)($request->getParsedBody() ?? []);
        if (!$this->validateImpostazioniCsrf($payload)) {
            Logger::getInstance()->warning('Backup create negato: CSRF non valido', ['request_id' => $requestId]);
            return $this->jsonError('Token non valido.', 403);
        }

        $requestedVolumes = $this->filterAllowedSelections(
            is_array($payload['volumes'] ?? null) ? $payload['volumes'] : [],
            array_keys(self::BACKUP_VOLUMES)
        );
        $requestedGovpaySections = $this->filterAllowedSelections(
            is_array($payload['govpay_sections'] ?? null) ? $payload['govpay_sections'] : [],
            self::GOVPAY_SECTIONS
        );

        $appVersion   = \App\Config\Config::getVersion();
        $appCommit    = (string)(getenv('GIT_COMMIT_SHA') ?: 'unknown');
        if (str_starts_with($appVersion, 'v')) {
            $rawLabel = $appVersion;
        } else {
            $rawLabel = $appVersion . '@' . substr($appCommit, 0, 7);
        }
        $versionLabel = preg_replace('/[^a-zA-Z0-9.\-_]/', '-', $rawLabel);

        $timestamp   = date('Ymd_His');
        $zipFilename = "backup-{$versionLabel}-{$timestamp}.zip";
        $zipPath     = self::BACKUP_DIR . '/' . $zipFilename;

        try {
            if (!$this->ensureBackupDirWritable()) {
                Logger::getInstance()->error('Backup create fallito: directory non scrivibile', [
                    'request_id' => $requestId,
                    'backup_dir' => self::BACKUP_DIR,
                    'diag' => $this->pathDiagnostics(self::BACKUP_DIR),
                ]);
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Directory backup non scrivibile.',
                    'request_id' => $requestId,
                ], 500);
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                Logger::getInstance()->error('Backup create fallito: ZipArchive::open non riuscito', [
                    'request_id' => $requestId,
                    'zip_path' => $zipPath,
                    'backup_dir' => self::BACKUP_DIR,
                    'diag' => $this->pathDiagnostics(self::BACKUP_DIR),
                ]);
                return $this->jsonResponse([
                    'success' => false,
                    'message' => 'Impossibile creare il file di backup.',
                    'request_id' => $requestId,
                ], 500);
            }

            // 1. Export impostazioni DB
            $sections = ['govpay', 'pagopa', 'backoffice', 'frontoffice', 'entity', 'iam_proxy', 'ui', 'app'];
            $settings = [];
            foreach ($sections as $s) {
                $settings[$s] = SettingsRepository::getSection($s);
            }
            $zip->addFromString('settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 2. Export dati applicativi opzionale
            if (!empty($requestedGovpaySections)) {
                $govpayConfig = $this->buildGovpayExport($requestedGovpaySections);
                $zip->addFromString('govpay-config.json', json_encode($govpayConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            // 3. DB dump via mysqldump
            $dbDumpFile = $this->runMysqldump();
            if ($dbDumpFile !== null) {
                $zip->addFile($dbDumpFile, 'db_dump.sql.gz');
            }

            // 4. Volumi selezionati
            foreach ($requestedVolumes as $name) {
                $mountPath = self::BACKUP_VOLUMES[$name] ?? null;
                if ($mountPath === null) {
                    continue;
                }
                if (is_dir($mountPath)) {
                    $this->addDirToZip($zip, $mountPath, "volumes/{$name}");
                }
            }

            $zip->close();
            if ($dbDumpFile !== null) {
                @unlink($dbDumpFile);
            }
            Logger::getInstance()->info('Backup di sistema creato', [
                'request_id' => $requestId,
                'file' => $zipFilename,
                'volumes' => $requestedVolumes,
                'govpay_sections' => $requestedGovpaySections,
            ]);
            return $this->jsonResponse([
                'success' => true,
                'message' => "Backup creato: {$zipFilename}",
                'request_id' => $requestId,
                'detail' => [
                    'volumes' => $requestedVolumes,
                    'govpay_sections' => $requestedGovpaySections,
                ],
            ]);
        } catch (\Throwable $e) {
            if (isset($zip) && $zip instanceof \ZipArchive) {
                @$zip->close();
            }
            if (isset($dbDumpFile) && $dbDumpFile !== null) {
                @unlink($dbDumpFile);
            }
            @unlink($zipPath);
            Logger::getInstance()->error('Errore creazione backup sistema', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Errore interno durante la creazione del backup.',
                'request_id' => $requestId,
            ], 500);
        }
    }

    /**
     * GET /backup/sistema/lista — lista i .zip disponibili nel volume gil_backups.
     */
    public function systemBackupList(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $files = [];
        foreach (glob(self::BACKUP_DIR . '/*.zip') ?: [] as $path) {
            $name    = basename($path);
            $files[] = [
                'name'     => $name,
                'size'     => filesize($path),
                'created'  => date('c', filemtime($path)),
            ];
        }
        usort($files, fn($a, $b) => strcmp($b['created'], $a['created']));

        return $this->jsonResponse(['success' => true, 'files' => $files]);
    }

    /**
     * GET /backup/sistema/download?file=nome_file.zip — scarica direttamente dal volume.
     */
    public function systemBackupDownload(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $response->withStatus(403);
        }

        $filename = $request->getQueryParams()['file'] ?? '';
        if (!$this->isValidBackupFilename($filename)) {
            return $response->withStatus(400);
        }

        $path = self::BACKUP_DIR . '/' . $filename;
        if (!is_file($path)) {
            return $response->withStatus(404);
        }

        $body = file_get_contents($path);
        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) filesize($path));
    }

    /**
     * POST /backup/sistema/elimina — cancella un archivio ZIP dal volume backup.
     */
    public function systemBackupDelete(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $payload = (array)($request->getParsedBody() ?? []);
        if (!$this->validateImpostazioniCsrf($payload)) {
            return $this->jsonError('Token non valido.', 403);
        }

        $filename = (string)($payload['file'] ?? '');
        if (!$this->isValidBackupFilename($filename)) {
            return $this->jsonError('Nome file non valido.', 400);
        }

        $path = self::BACKUP_DIR . '/' . $filename;
        if (!is_file($path)) {
            return $this->jsonError('Backup non trovato.', 404);
        }

        if (!@unlink($path)) {
            return $this->jsonError('Impossibile eliminare il backup.', 500);
        }

        Logger::getInstance()->info('Backup di sistema eliminato', ['file' => $filename]);

        return $this->jsonResponse([
            'success' => true,
            'message' => 'Backup eliminato: ' . $filename,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // RIPRISTINO DA ZIP
    // ──────────────────────────────────────────────────────────────────────

    /**
     * POST /backup/sistema/ripristina — carica un archivio ZIP e ripristina:
     *   - settings.json  → tutte le sezioni DB (incluse chiavi SATOSA, cifrate al primo salvataggio UI)
    *   - volumes/<nome> → file sui volumi montati (branding, certificati, backup legacy)
     *   - govpay-config.json → dati applicativi (tipologie, templates, IO, utenti) se presenti
     */
    public function systemBackupRestore(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['backup_file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError('Nessun file caricato o errore nel trasferimento.');
        }

        $originalName = $file->getClientFilename() ?? 'backup.zip';
        if (!str_ends_with(strtolower($originalName), '.zip')) {
            return $this->jsonError('Il file deve essere un archivio .zip.');
        }

        // Scrivi su file temporaneo
        $tmpPath = sys_get_temp_dir() . '/gil-restore-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $stream = $file->getStream();
            file_put_contents($tmpPath, (string) $stream);

            $result = $this->doRestoreFromZip($tmpPath);

            Logger::getInstance()->info('Ripristino ZIP completato', array_merge(['file' => $originalName], $result));
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Ripristino completato.',
                'detail'  => $result,
            ]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore ripristino ZIP', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore durante il ripristino: ' . $e->getMessage());
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * POST /backup/sistema/ripristina/chunk — ricezione chunked di un archivio ZIP.
     * Il client divide il file in chunk ≤512KB e li invia in sequenza.
     * Sull'ultimo chunk il server assembla e lancia il restore.
     */
    public function systemBackupRestoreChunk(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $body        = $request->getParsedBody() ?? [];
        $uploadId    = (string)($body['upload_id']    ?? '');
        $chunkIndex  = (int)  ($body['chunk_index']   ?? -1);
        $totalChunks = (int)  ($body['total_chunks']  ?? 0);

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uploadId)) {
            return $this->jsonError('upload_id non valido.');
        }
        if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            return $this->jsonError('Parametri chunk non validi.');
        }

        $uploadedFiles = $request->getUploadedFiles();
        $chunk = $uploadedFiles['chunk'] ?? null;
        if (!$chunk || $chunk->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError('Errore nel trasferimento del chunk.');
        }

        $tmpDir    = sys_get_temp_dir() . '/gil-chunk-' . $uploadId;
        $chunkPath = $tmpDir . '/chunk-' . str_pad((string)$chunkIndex, 6, '0', STR_PAD_LEFT) . '.bin';

        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0700, true);
        }
        $chunk->moveTo($chunkPath);

        // Limite totale 256 MB
        $totalSize = array_sum(array_map('filesize', glob($tmpDir . '/chunk-*.bin') ?: []));
        if ($totalSize > 256 * 1024 * 1024) {
            array_map('unlink', glob($tmpDir . '/*') ?: []);
            rmdir($tmpDir);
            return $this->jsonError('File troppo grande (limite 256 MB).');
        }

        $received = count(glob($tmpDir . '/chunk-*.bin') ?: []);
        if ($received < $totalChunks) {
            return $this->jsonResponse(['success' => true, 'received' => $received, 'total' => $totalChunks]);
        }

        // Tutti i chunk ricevuti: assembla e salva in pending (il restore avviene in /avvia).
        $zipPath     = $tmpDir . '/assembled.zip';
        $pendingPath = sys_get_temp_dir() . '/gil-pending-' . $uploadId . '.zip';
        try {
            $out = fopen($zipPath, 'wb');
            if ($out === false) {
                throw new \RuntimeException('Impossibile creare file temporaneo.');
            }
            $chunks = glob($tmpDir . '/chunk-*.bin') ?: [];
            sort($chunks);
            foreach ($chunks as $cp) {
                $data = file_get_contents($cp);
                if ($data === false) {
                    throw new \RuntimeException('Errore lettura chunk.');
                }
                fwrite($out, $data);
                unlink($cp);
            }
            fclose($out);
            rename($zipPath, $pendingPath);
            return $this->jsonResponse(['success' => true, 'ready' => true, 'token' => $uploadId]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore assemblaggio chunk ZIP', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore durante l\'assemblaggio: ' . $e->getMessage());
        } finally {
            @unlink($zipPath);
            if (is_dir($tmpDir)) {
                foreach (glob($tmpDir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($tmpDir);
            }
        }
    }

    /**
     * POST /backup/sistema/ripristina/avvia — avvia il restore da ZIP pending.
     * Usa ignore_user_abort(true) + set_time_limit(0) per sopravvivere a proxy timeout.
     * Scrive lo stato in un file per permettere il polling via /status.
     */
    public function systemBackupRestoreAvvia(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $body  = $request->getParsedBody() ?? [];
        $token = (string)($body['token'] ?? '');

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token)) {
            return $this->jsonError('Token non valido.');
        }

        $pendingPath = sys_get_temp_dir() . '/gil-pending-' . $token . '.zip';
        $statusFile  = sys_get_temp_dir() . '/gil-restore-status-' . $token . '.json';

        if (!file_exists($pendingPath)) {
            return $this->jsonError('Sessione di ripristino scaduta o non trovata.');
        }

        ignore_user_abort(true);
        set_time_limit(0);

        file_put_contents($statusFile, json_encode(['status' => 'running']));

        try {
            $result = $this->doRestoreFromZip($pendingPath);
            file_put_contents($statusFile, json_encode(['status' => 'done', 'result' => $result]));
            Logger::getInstance()->info('Ripristino ZIP completato', $result);
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Ripristino completato.',
                'detail'  => $result,
            ]);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            file_put_contents($statusFile, json_encode(['status' => 'error', 'message' => $msg]));
            Logger::getInstance()->error('Errore ripristino ZIP', ['error' => $msg]);
            return $this->jsonError('Errore durante il ripristino: ' . $msg);
        } finally {
            @unlink($pendingPath);
        }
    }

    /**
     * GET /backup/sistema/ripristina/status?token=xxx — polling dello stato del restore.
     */
    public function systemBackupRestoreStatus(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $token = (string)($request->getQueryParams()['token'] ?? '');

        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $token)) {
            return $this->jsonError('Token non valido.');
        }

        $statusFile = sys_get_temp_dir() . '/gil-restore-status-' . $token . '.json';

        if (!file_exists($statusFile)) {
            return $this->jsonResponse(['status' => 'unknown']);
        }

        $data = json_decode((string)file_get_contents($statusFile), true) ?? ['status' => 'unknown'];

        if (in_array($data['status'] ?? '', ['done', 'error'], true)) {
            @unlink($statusFile);
        }

        return $this->jsonResponse($data);
    }

    /**
     * Esegue il ripristino da un file ZIP già su disco.
     * Restituisce un array di statistiche.
     */
    private function doRestoreFromZip(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Impossibile aprire il file ZIP.');
        }

        $stats = ['settings_sections' => 0, 'volume_files' => 0, 'govpay_sections' => 0, 'db_restored' => false];

        // 1. settings.json → DB
        $settingsJson = $zip->getFromName('settings.json');
        if ($settingsJson) {
            $settings = json_decode($settingsJson, true) ?? [];
            foreach ($settings as $section => $values) {
                if (is_array($values) && !empty($values)) {
                    SettingsRepository::setSection($section, $values, 'restore');
                    $stats['settings_sections']++;
                }
            }
        }

        // 2. govpay-config.json → dati applicativi
        $govpayJson = $zip->getFromName('govpay-config.json');
        if ($govpayJson) {
            $govpay = json_decode($govpayJson, true) ?? [];
            $sections = $govpay['sections'] ?? [];
            $idDominio = (string)(\App\Config\Config::get('ID_DOMINIO') ?: '');
            $pdo = Connection::getPDO();

            if (!empty($sections['tipologie'])) {
                (new EntrateRepository())->replaceLocalOverrides($idDominio, $sections['tipologie']);
                $stats['govpay_sections']++;
            }
            if (!empty($sections['tipologie_esterne'])) {
                $pdo->exec('DELETE FROM tipologie_pagamento_esterne');
                $extRepo = new ExternalPaymentTypeRepository();
                foreach ($sections['tipologie_esterne'] as $t) {
                    if (!empty($t['descrizione']) && !empty($t['url'])) {
                        $extRepo->create($t['descrizione'], $t['descrizione_estesa'] ?? null, $t['url']);
                    }
                }
                $stats['govpay_sections']++;
            }
            if (!empty($sections['io_services'])) {
                $pdo->exec('DELETE FROM io_service_tipologie');
                $pdo->exec('DELETE FROM io_services');
                $ioRepo = new \App\Database\IoServiceRepository();
                foreach ($sections['io_services'] as $s) {
                    if (empty($s['nome']) || empty($s['id_service']) || empty($s['api_key_primaria'])) {
                        continue;
                    }
                    $newId = $ioRepo->create($s['nome'], $s['descrizione'] ?? null, $s['id_service'], $s['api_key_primaria'], $s['api_key_secondaria'] ?? null, $s['codice_catalogo'] ?? null, !empty($s['is_default']));
                    foreach ($s['tipologie'] ?? [] as $idEntrata) {
                        $ioRepo->setTipologiaService((string)$idEntrata, $newId);
                    }
                }
                $stats['govpay_sections']++;
            }
            if (!empty($sections['templates'])) {
                $tplRepo = new \App\Database\PendenzaTemplateRepository();
                $tplRepo->deleteAllByDominio($idDominio);
                $usersStmt = $pdo->query('SELECT id, email FROM users');
                $emailToId = [];
                foreach ($usersStmt->fetchAll() as $u) {
                    $emailToId[strtolower($u['email'])] = (int)$u['id'];
                }
                foreach ($sections['templates'] as $t) {
                    if (empty($t['titolo']) || empty($t['id_tipo_pendenza'])) {
                        continue;
                    }
                    $newId = $tplRepo->create(['id_dominio' => $idDominio, 'titolo' => $t['titolo'], 'id_tipo_pendenza' => $t['id_tipo_pendenza'], 'causale' => $t['causale'] ?? '', 'importo' => (float)($t['importo'] ?? 0)]);
                    $userIds = array_filter(array_map(fn($e) => $emailToId[strtolower((string)$e)] ?? null, $t['assigned_users'] ?? []));
                    if ($userIds) {
                        $tplRepo->assignUsers($newId, array_values($userIds));
                    }
                }
                $stats['govpay_sections']++;
            }
            if (!empty($sections['utenti'])) {
                $upsert = $pdo->prepare('INSERT INTO users (email, role, first_name, last_name, is_disabled, password_hash, created_at, updated_at) VALUES (:email, :role, :fn, :ln, :disabled, :hash, NOW(), NOW()) ON DUPLICATE KEY UPDATE role=VALUES(role), first_name=VALUES(first_name), last_name=VALUES(last_name), is_disabled=VALUES(is_disabled), password_hash=COALESCE(VALUES(password_hash), password_hash), updated_at=NOW()');
                foreach ($sections['utenti'] as $u) {
                    if (empty($u['email'])) {
                        continue;
                    }
                    $upsert->execute([':email' => $u['email'], ':role' => $u['role'] ?? 'user', ':fn' => $u['first_name'] ?? '', ':ln' => $u['last_name'] ?? '', ':disabled' => empty($u['is_disabled']) ? 0 : 1, ':hash' => $u['password_hash'] ?? null]);
                }
                $stats['govpay_sections']++;
            }
            if (!empty($sections['gruppi-utenti'])) {
                $groupRepo = new \App\Database\UserGroupRepository();
                // email→id map
                $emailToId = [];
                foreach ($pdo->query('SELECT id, email FROM users')->fetchAll() as $u) {
                    $emailToId[strtolower($u['email'])] = (int)$u['id'];
                }
                // titolo→id map
                $titToId = [];
                foreach ($pdo->query('SELECT id, titolo FROM pendenza_template')->fetchAll() as $t) {
                    $titToId[strtolower($t['titolo'])] = (int)$t['id'];
                }
                foreach ($sections['gruppi-utenti'] as $g) {
                    if (empty($g['nome'])) {
                        continue;
                    }
                    // UPSERT by nome: check existing
                    $existingRow = $pdo->prepare('SELECT id FROM user_groups WHERE nome = ?');
                    $existingRow->execute([$g['nome']]);
                    $existing = $existingRow->fetchColumn();
                    if ($existing !== false) {
                        $groupRepo->update((int)$existing, $g['nome'], $g['descrizione'] ?? null, $g['default_id_entrata'] ?? null);
                        $gid = (int)$existing;
                    } else {
                        $gid = $groupRepo->create($g['nome'], $g['descrizione'] ?? null, $g['default_id_entrata'] ?? null);
                    }
                    // members by email
                    $memberIds = array_values(array_filter(array_map(fn($e) => $emailToId[strtolower((string)$e)] ?? null, $g['members'] ?? [])));
                    $groupRepo->setMembers($gid, $memberIds);
                    // tipologie by id_entrata
                    if ($idDominio) {
                        $groupRepo->setTipologie($gid, $idDominio, array_values(array_filter($g['tipologie'] ?? [])));
                    }
                    // templates by titolo
                    $tplIds = array_values(array_filter(array_map(fn($title) => $titToId[strtolower((string)$title)] ?? null, $g['templates'] ?? [])));
                    $groupRepo->setTemplates($gid, $tplIds);
                    // tipologie rendicontazione (id_entrata + modalita)
                    if ($idDominio) {
                        $groupRepo->setRendicontazioneTipologie($gid, $idDominio, $g['rendicontazione_tipologie'] ?? []);
                    }
                }
                $stats['govpay_sections']++;
            }
            if (!empty($sections['mapping_tipologie_custom'])) {
                $pdo->prepare('DELETE FROM mapping_tipologie_custom WHERE id_dominio = ?')->execute([$idDominio]);
                $stmt = $pdo->prepare('INSERT INTO mapping_tipologie_custom (id_dominio, cod_entrata, descrizione) VALUES (?, ?, ?)');
                foreach ($sections['mapping_tipologie_custom'] as $item) {
                    if (!empty($item['cod_entrata']) && !empty($item['descrizione'])) {
                        $stmt->execute([$idDominio, $item['cod_entrata'], $item['descrizione']]);
                    }
                }
                $stats['govpay_sections']++;
            }
            if (isset($sections['mapping_pendenze'])) {
                $patterns = $sections['mapping_pendenze']['patterns'] ?? [];
                $vocab    = $sections['mapping_pendenze']['vocab'] ?? [];
                // Passata 1: inserisce tutti i pattern senza accorpato_a per evitare FK violation
                // sulla FK self-referenziale (accorpato_a → pattern_iuv stessa tabella).
                $upsertP = $pdo->prepare(
                    'INSERT INTO mapping_pendenze_pattern (pattern_iuv, id_dominio, fornitore, cod_entrata, accorpato_a, is_custom) VALUES (?,?,?,?,NULL,1)
                     ON DUPLICATE KEY UPDATE fornitore=VALUES(fornitore), cod_entrata=VALUES(cod_entrata), is_custom=1'
                );
                foreach ($patterns as $p) {
                    if (empty($p['pattern_iuv'])) {
                        continue;
                    }
                    $upsertP->execute([$p['pattern_iuv'], $idDominio, $p['fornitore'] ?? null, $p['cod_entrata'] ?? null]);
                }
                // Passata 2: aggiorna accorpato_a ora che tutti i parent esistono.
                $updAcc = $pdo->prepare(
                    'UPDATE mapping_pendenze_pattern SET accorpato_a = ? WHERE pattern_iuv = ? AND id_dominio = ?'
                );
                foreach ($patterns as $p) {
                    if (empty($p['pattern_iuv']) || empty($p['accorpato_a'])) {
                        continue;
                    }
                    $updAcc->execute([$p['accorpato_a'], $p['pattern_iuv'], $idDominio]);
                }
                // Sostituisci tutto il vocab per questo dominio
                $pdo->prepare('DELETE FROM mapping_pendenze_vocab WHERE id_dominio = ?')->execute([$idDominio]);
                $insV = $pdo->prepare(
                    'INSERT IGNORE INTO mapping_pendenze_vocab (pattern_iuv, id_dominio, keyword, cod_entrata, priorita) VALUES (?,?,?,?,?)'
                );
                foreach ($vocab as $v) {
                    if (empty($v['pattern_iuv']) || empty($v['keyword']) || empty($v['cod_entrata'])) {
                        continue;
                    }
                    $insV->execute([$v['pattern_iuv'], $idDominio, $v['keyword'], $v['cod_entrata'], (int)($v['priorita'] ?? 10)]);
                }
                $stats['govpay_sections']++;
            }
            if (isset($sections['rendicontazione'])) {
                $rendData = $sections['rendicontazione'];
                $by       = (string)($_SESSION['user']['id'] ?? 'system');
                if (isset($rendData['settings']) && is_array($rendData['settings'])) {
                    foreach (['iuv_prefix_gil', 'scan_interval_minuti', 'scansioni_quiete_soglia', 'max_giorni_retry', 'geri_max_tentativi', 'notifica_admin_auto', 'admin_emails', 'bridge_url'] as $key) {
                        if (isset($rendData['settings'][$key])) {
                            SettingsRepository::set('rendicontazione', $key, (string)$rendData['settings'][$key], false, $by);
                        }
                    }
                    if (!empty($rendData['settings']['bridge_token'])) {
                        SettingsRepository::set('rendicontazione', 'bridge_token', (string)$rendData['settings']['bridge_token'], true, $by);
                    }
                }
                $rendRepo = new \App\Database\RendicontazioneRepository();
                $pdo->prepare('DELETE FROM rendicontazione_regole_esterne WHERE id_dominio = ?')->execute([$idDominio]);
                foreach ($rendData['regole_esterne'] ?? [] as $regola) {
                    if (!empty($regola['pattern_tipo']) && !empty($regola['pattern_valore']) && !empty($regola['handler'])) {
                        $rendRepo->addRegolaEsterna($idDominio, (string)$regola['pattern_tipo'], (string)$regola['pattern_valore'], (string)$regola['handler']);
                    }
                }
                $stats['govpay_sections']++;
            }
        }

        // 3. DB restore
        $dbDumpEntry = $zip->getFromName('db_dump.sql.gz');
        if ($dbDumpEntry !== false) {
            $tmpDump = sys_get_temp_dir() . '/gil-dbrestore-' . bin2hex(random_bytes(6)) . '.sql.gz';
            file_put_contents($tmpDump, $dbDumpEntry);
            try {
                $this->runMysqlRestore($tmpDump);
                $stats['db_restored'] = true;
            } finally {
                @unlink($tmpDump);
            }
        }

        // 4. volumes/* → path montati
        $volumeMap = [];
        foreach (self::BACKUP_VOLUMES as $name => $mountPath) {
            $volumeMap["volumes/{$name}"] = $mountPath;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || str_ends_with($name, '/')) {
                continue;
            }
            foreach ($volumeMap as $prefix => $destBase) {
                if (str_starts_with($name, $prefix . '/')) {
                    $relPath  = substr($name, strlen($prefix) + 1);
                    $destPath = $destBase . '/' . $relPath;
                    @mkdir(dirname($destPath), 0755, true);
                    $content = $zip->getFromIndex($i);
                    if ($content !== false) {
                        file_put_contents($destPath, $content);
                        $stats['volume_files']++;
                    }
                    break;
                }
            }
        }

        $zip->close();

        // 5. Check decryption status of all encrypted=1 settings
        $stats['decrypt_failures'] = $this->checkEncryptedKeysDecryption();

        return $stats;
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Esegue il dump del DB via PDO e lo scrive in streaming nel file compresso,
     * consumando pochissima memoria ed evitando fetchAll su grandi volumi.
     */
    private function runMysqldump(): ?string
    {
        $dbName = (string)(getenv('DB_NAME') ?: '');
        if ($dbName === '') {
            Logger::getInstance()->warning('mysqldump saltato: DB_NAME non configurato');
            return null;
        }

        $outFile = null;
        $gz = null;
        try {
            $pdo = Connection::getPDO();
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

            // Raccoglie DDL per tutte le tabelle prima di generare il dump
            $ddls = [];
            foreach ($tables as $table) {
                $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_ASSOC);
                $ddls[$table] = $row['Create Table'] ?? $row[array_keys($row)[1]] ?? '';
            }

            // Ordina: tabelle senza FK prima, con FK dopo — così durante il ripristino
            // le tabelle referenziate esistono già quando si creano i vincoli.
            usort($tables, function (string $a, string $b) use ($ddls): int {
                $aFk = str_contains($ddls[$a], 'FOREIGN KEY');
                $bFk = str_contains($ddls[$b], 'FOREIGN KEY');
                if ($aFk === $bFk) {
                    return 0;
                }
                return $aFk ? 1 : -1;
            });

            $outFile = sys_get_temp_dir() . '/gil-dbdump-' . bin2hex(random_bytes(6)) . '.sql.gz';
            $gz = gzopen($outFile, 'wb9');
            if ($gz === false) {
                Logger::getInstance()->warning('mysqldump: impossibile aprire file gz di output');
                return null;
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                $ddl = $ddls[$table];
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
                gzwrite($gz, $ddl . ";\n\n");

                $stmt = $pdo->query("SELECT * FROM `{$table}`");
                $cols      = '';
                $batchRows = [];
                $batchSize = 500;

                while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($cols === '') {
                        $cols = '`' . implode('`, `', array_keys($row)) . '`';
                    }
                    $vals        = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
                    $batchRows[] = '(' . implode(', ', $vals) . ')';

                    if (count($batchRows) >= $batchSize) {
                        gzwrite($gz, "INSERT IGNORE INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batchRows) . ";\n");
                        $batchRows = [];
                    }
                }
                if (!empty($batchRows)) {
                    gzwrite($gz, "INSERT IGNORE INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $batchRows) . ";\n");
                }
                if ($cols !== '') {
                    gzwrite($gz, "\n");
                }
            }

            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
            gzclose($gz);
            $gz = null;

            return $outFile;
        } catch (\Throwable $e) {
            if ($gz !== null) {
                @gzclose($gz);
            }
            if ($outFile !== null) {
                @unlink($outFile);
            }
            Logger::getInstance()->warning('mysqldump PDO fallito', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Ripristina il DB in modo efficiente riga per riga dal file .sql.gz via PDO.
     */
    private function runMysqlRestore(string $gzFile): void
    {
        $gz = gzopen($gzFile, 'rb');
        if ($gz === false) {
            throw new \RuntimeException("Impossibile aprire il dump compresso per il ripristino.");
        }

        try {
            $pdo = Connection::getPDO();
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0; SET autocommit=0;");

            $currentStmt = '';
            $stmtCount   = 0;
            $commitEvery = 200; // commit ogni N statement per limitare la dimensione della transazione

            while (!gzeof($gz)) {
                $line = gzgets($gz, 4194304); // 4 MB per supportare bulk INSERT row molto larghe
                if ($line === false) {
                    break;
                }

                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                $currentStmt .= $line;

                if (str_ends_with($trimmed, ';')) {
                    $stmt = preg_replace('/^INSERT INTO\b/i', 'INSERT IGNORE INTO', ltrim($currentStmt));
                    $pdo->exec($stmt ?? $currentStmt);
                    $currentStmt = '';
                    $stmtCount++;
                    if ($stmtCount % $commitEvery === 0) {
                        $pdo->exec('COMMIT');
                    }
                }
            }

            $pdo->exec("COMMIT; SET autocommit=1; SET FOREIGN_KEY_CHECKS=1;");
        } catch (\Throwable $e) {
            try { $pdo->exec("ROLLBACK; SET autocommit=1; SET FOREIGN_KEY_CHECKS=1;"); } catch (\Throwable $_) {}
            throw new \RuntimeException("Ripristino DB fallito: " . $e->getMessage(), 0, $e);
        } finally {
            @gzclose($gz);
        }
    }

    private function isSuperadmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'superadmin';
    }

    /**
     * Esporta i dati applicativi GovPay selezionati nello stesso formato usato per il restore.
     *
     * @param string[] $sections
     */
    private function buildGovpayExport(array $sections): array
    {
        $idDominio = (string) (\App\Config\Config::get('ID_DOMINIO') ?: '');
        $pdo       = Connection::getPDO();

        $resultSections = [];
        if (in_array('tipologie', $sections, true)) {
            $resultSections['tipologie'] = (new EntrateRepository())->listLocalOverrides($idDominio);
        }

        if (in_array('tipologie_esterne', $sections, true)) {
            $resultSections['tipologie_esterne'] = (new ExternalPaymentTypeRepository())->listAll();
        }

        if (in_array('templates', $sections, true)) {
            $tplRepo = new \App\Database\PendenzaTemplateRepository();
            $resultSections['templates'] = $tplRepo->findAllByDominioWithUsers($idDominio);
        }

        if (in_array('io_services', $sections, true)) {
            $ioRepo    = new \App\Database\IoServiceRepository();
            $services  = $ioRepo->listAll();
            $stmt      = $pdo->query('SELECT id_entrata, io_service_id FROM io_service_tipologie');
            $links     = $stmt->fetchAll();
            $svcLinks  = [];
            foreach ($links as $l) {
                $svcLinks[(int) $l['io_service_id']][] = $l['id_entrata'];
            }
            foreach ($services as &$s) {
                $s['tipologie'] = $svcLinks[(int) $s['id']] ?? [];
                unset($s['id'], $s['created_at'], $s['updated_at']);
            }
            unset($s);
            $resultSections['io_services'] = $services;
        }

        if (in_array('utenti', $sections, true)) {
            $resultSections['utenti'] = $pdo->query('SELECT email, role, first_name, last_name, is_disabled, password_hash FROM users ORDER BY email ASC')->fetchAll();
        }

        if (in_array('gruppi-utenti', $sections, true)) {
            $groupRepo = new \App\Database\UserGroupRepository();
            $groups    = $groupRepo->listAll();
            // Risolvi email membri
            $userEmailStmt = $pdo->query('SELECT id, email FROM users');
            $idToEmail = [];
            foreach ($userEmailStmt->fetchAll() as $u) {
                $idToEmail[(int)$u['id']] = $u['email'];
            }
            // Risolvi titoli template
            $tplTitleStmt = $pdo->query('SELECT id, titolo FROM pendenza_template');
            $idToTitle = [];
            foreach ($tplTitleStmt->fetchAll() as $t) {
                $idToTitle[(int)$t['id']] = $t['titolo'];
            }
            foreach ($groups as &$g) {
                $gid = (int)$g['id'];
                $memberIds = $groupRepo->getMemberIds($gid);
                $g['members'] = array_values(array_filter(array_map(fn($uid) => $idToEmail[$uid] ?? null, $memberIds)));
                $g['tipologie'] = $idDominio ? $groupRepo->getTipologie($gid, $idDominio) : [];
                $tplIds = $groupRepo->getTemplateIds($gid);
                $g['templates'] = array_values(array_filter(array_map(fn($tid) => $idToTitle[$tid] ?? null, $tplIds)));
                $g['rendicontazione_tipologie'] = $idDominio ? $groupRepo->getRendicontazioneTipologie($gid, $idDominio) : [];
                unset($g['id'], $g['created_at'], $g['updated_at'], $g['member_count']);
            }
            unset($g);
            $resultSections['gruppi-utenti'] = $groups;
        }

        if (in_array('mapping_tipologie_custom', $sections, true)) {
            $stmt = $pdo->prepare('SELECT cod_entrata, descrizione FROM mapping_tipologie_custom WHERE id_dominio = ? ORDER BY cod_entrata ASC');
            $stmt->execute([$idDominio]);
            $resultSections['mapping_tipologie_custom'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        if (in_array('mapping_pendenze', $sections, true)) {
            $pStmt = $pdo->prepare(
                'SELECT pattern_iuv, fornitore, cod_entrata, accorpato_a FROM mapping_pendenze_pattern WHERE id_dominio = ? AND is_custom = 1 ORDER BY pattern_iuv ASC'
            );
            $pStmt->execute([$idDominio]);
            $vStmt = $pdo->prepare(
                'SELECT mpv.pattern_iuv, mpv.keyword, mpv.cod_entrata, mpv.priorita FROM mapping_pendenze_vocab mpv WHERE mpv.id_dominio = ? ORDER BY mpv.pattern_iuv ASC, mpv.priorita ASC, mpv.keyword ASC'
            );
            $vStmt->execute([$idDominio]);
            $resultSections['mapping_pendenze'] = [
                'patterns' => $pStmt->fetchAll(\PDO::FETCH_ASSOC),
                'vocab'    => $vStmt->fetchAll(\PDO::FETCH_ASSOC),
            ];
        }

        if (in_array('rendicontazione', $sections, true)) {
            $regole = (new \App\Database\RendicontazioneRepository())->getRegoleEsterne($idDominio);
            foreach ($regole as &$r) {
                unset($r['id'], $r['id_dominio'], $r['created_at'], $r['updated_at']);
            }
            unset($r);
            $resultSections['rendicontazione'] = [
                'settings' => [
                    'iuv_prefix_gil'          => SettingsRepository::get('rendicontazione', 'iuv_prefix_gil', 'GIL'),
                    'scan_interval_minuti'    => SettingsRepository::get('rendicontazione', 'scan_interval_minuti', '15'),
                    'scansioni_quiete_soglia' => SettingsRepository::get('rendicontazione', 'scansioni_quiete_soglia', '3'),
                    'max_giorni_retry'        => SettingsRepository::get('rendicontazione', 'max_giorni_retry', '7'),
                    'geri_max_tentativi'      => SettingsRepository::get('rendicontazione', 'geri_max_tentativi', '3'),
                    'notifica_admin_auto'     => SettingsRepository::get('rendicontazione', 'notifica_admin_auto', 'false'),
                    'admin_emails'            => SettingsRepository::get('rendicontazione', 'admin_emails', ''),
                    'bridge_url'              => SettingsRepository::get('rendicontazione', 'bridge_url', ''),
                    'bridge_token'            => SettingsRepository::get('rendicontazione', 'bridge_token', ''),
                ],
                'regole_esterne' => $regole,
            ];
        }

        return [
            'version'     => '1.0',
            'exported_at' => (new \DateTimeImmutable())->format('c'),
            'exported_by' => $_SESSION['user']['email'] ?? 'system',
            'id_dominio'  => $idDominio,
            'sections'    => $resultSections,
        ];
    }

    /**
     * @param array<int, mixed> $requested
     * @param string[] $allowed
     * @return string[]
     */
    private function filterAllowedSelections(array $requested, array $allowed): array
    {
        $normalized = [];
        foreach ($requested as $value) {
            if (!is_string($value)) {
                continue;
            }
            if (in_array($value, $allowed, true) && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }
        return $normalized;
    }

    private function checkEncryptedKeysDecryption(): array
    {
        $failures = [];
        try {
            $runtimeKey = (string)(\App\Config\ConfigLoader::get('app.encryption_key') ?? '');
            if ($runtimeKey === '') {
                $runtimeKey = (string)($_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY') ?: '');
            }
            if ($runtimeKey === '') {
                return [['section' => '—', 'key_name' => 'APP_ENCRYPTION_KEY non configurata nel runtime']];
            }

            $pdo = Connection::getPDO();
            $stmt = $pdo->query(
                "SELECT section, key_name, value FROM settings WHERE encrypted = 1 AND value IS NOT NULL AND value != ''"
            );
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
            foreach ($rows as $row) {
                $decoded = base64_decode((string)$row['value'], true);
                if ($decoded === false || strlen($decoded) <= $ivLength) {
                    continue; // valore in chiaro, non cifrato
                }
                $iv = substr($decoded, 0, $ivLength);
                $ciphertext = substr($decoded, $ivLength);
                $cleartext = openssl_decrypt($ciphertext, 'aes-256-cbc', $runtimeKey, OPENSSL_RAW_DATA, $iv);
                if ($cleartext === false) {
                    $failures[] = ['section' => (string)$row['section'], 'key_name' => (string)$row['key_name']];
                }
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('checkEncryptedKeysDecryption fallito', ['error' => $e->getMessage()]);
        }
        return $failures;
    }

    private function validateImpostazioniCsrf(array $payload): bool
    {
        $expected = (string)($_SESSION['impostazioni_csrf'] ?? '');
        $provided = (string)($payload['csrf_token'] ?? '');
        $valid = $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
        if ($valid) {
            unset($_SESSION['impostazioni_csrf']); // Invalida dopo uso
        }
        return $valid;
    }

    private function ensureBackupDirWritable(): bool
    {
        if (!is_dir(self::BACKUP_DIR) && !@mkdir(self::BACKUP_DIR, 0775, true) && !is_dir(self::BACKUP_DIR)) {
            return false;
        }
        return is_writable(self::BACKUP_DIR);
    }

    private function isValidBackupFilename(string $filename): bool
    {
        return $filename !== ''
            && !str_contains($filename, '/')
            && !str_contains($filename, '..')
            && str_ends_with($filename, '.zip');
    }

    private function pathDiagnostics(string $path): array
    {
        $perms = @fileperms($path);
        return [
            'exists' => file_exists($path),
            'is_dir' => is_dir($path),
            'is_writable' => is_writable($path),
            'owner' => @fileowner($path),
            'group' => @filegroup($path),
            'perms' => $perms === false ? null : substr(sprintf('%o', $perms), -4),
        ];
    }

    /**
     * Aggiunge ricorsivamente il contenuto di una directory a un archivio ZIP.
     */
    private function addDirToZip(\ZipArchive $zip, string $dir, string $zipPrefix): void
    {
        $dir = rtrim($dir, '/\\');
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $localPath = $zipPrefix . '/' . ltrim(substr((string) $file, strlen($dir)), '/\\');
            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
            } else {
                $zip->addFile((string) $file, $localPath);
            }
        }
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
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_SESSION['impostazioni_csrf'])) {
                $_SESSION['impostazioni_csrf'] = bin2hex(random_bytes(32));
            }
            $data['csrf_token'] = $_SESSION['impostazioni_csrf'];
        }
        $resp = new SlimResponse($status);
        $resp->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $resp->withHeader('Content-Type', 'application/json');
    }
}
