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
 * Gestisce backup e restore in due scenari:
 *
 * 1. Backup dati GovPay (dal tab /configurazione):
 *    - exportBackup() — scarica un JSON con tipologie/templates/io_services/utenti
 *    - importBackup() — carica e applica un JSON di backup dati
 *
 * 2. Backup di sistema (lettura/scrittura diretta sul volume gil_backups):
 *    - systemBackupCreate()      — crea un ZIP con settings DB + dati GovPay + volumi certificati + DB dump
 *    - systemBackupList()        — lista i .zip in /backups
 *    - systemBackupDownload()    — scarica un archivio .zip
 *    - systemBackupRestore()     — carica un .zip e ripristina (upload via form)
 *    - systemBackupUploadRestore() — carica un .zip e ripristina immediatamente (API JSON)
 */
class BackupController
{
    private const BACKUP_DIR = '/backups';

    /** Volumi montati sul container backoffice da includere nel backup. */
    private const BACKUP_VOLUMES = [
        'gil_certs'                => '/var/www/certificate',
        'spid_certs'               => '/var/www/html/spid-certs',
        'frontoffice_sp_metadata'  => '/var/www/html/metadata-sp',
        'gil_metadata_cieoidc'     => '/var/www/html/metadata-cieoidc',
        'gil_cieoidc_keys'         => '/var/www/html/cieoidc-keys',
    ];

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
    // BACKUP DI SISTEMA (logica PHP diretta su volume gil_backups)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * POST /backup/sistema/crea — crea un archivio ZIP in /backups con:
     *   - settings.json  (tutte le sezioni DB)
     *   - govpay-config.json  (dati applicativi: tipologie, templates, utenti, IO)
     *   - volumes/<nome>/<file>  (contenuto dei volumi certificati/metadata montati)
     */
    public function systemBackupCreate(Request $request, Response $response): Response
    {
        if (!$this->isSuperadmin()) {
            return $this->jsonError('Accesso riservato al superadmin.', 403);
        }

        $timestamp = date('Ymd_His');
        $zipPath   = self::BACKUP_DIR . "/backup-{$timestamp}.zip";

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return $this->jsonError('Impossibile creare il file di backup in ' . self::BACKUP_DIR);
            }

            // 1. Export impostazioni DB
            $sections = ['govpay', 'pagopa', 'backoffice', 'frontoffice', 'entity', 'iam_proxy', 'ui', 'app'];
            $settings = [];
            foreach ($sections as $s) {
                $settings[$s] = SettingsRepository::getSection($s);
            }
            $zip->addFromString('settings.json', json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 2. Export dati GovPay (stesso formato di exportBackup)
            $govpayConfig = $this->buildFullGovpayExport();
            $zip->addFromString('govpay-config.json', json_encode($govpayConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            // 3. DB dump via mysqldump
            $dbDumpFile = $this->runMysqldump();
            if ($dbDumpFile !== null) {
                $zip->addFile($dbDumpFile, 'db_dump.sql.gz');
            }

            // 4. Volumi montati
            foreach (self::BACKUP_VOLUMES as $name => $mountPath) {
                if (is_dir($mountPath)) {
                    $this->addDirToZip($zip, $mountPath, "volumes/{$name}");
                }
            }

            $zip->close();
            if ($dbDumpFile !== null) {
                @unlink($dbDumpFile);
            }
            Logger::getInstance()->info('Backup di sistema creato', ['file' => "backup-{$timestamp}.zip"]);
            return $this->jsonOk("Backup creato: backup-{$timestamp}.zip");
        } catch (\Throwable $e) {
            if (isset($zip) && $zip instanceof \ZipArchive) {
                @$zip->close();
            }
            if (isset($dbDumpFile) && $dbDumpFile !== null) {
                @unlink($dbDumpFile);
            }
            @unlink($zipPath);
            Logger::getInstance()->error('Errore creazione backup sistema', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore creazione backup: ' . $e->getMessage());
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
        if ($filename === '' || str_contains($filename, '/') || str_contains($filename, '..') || !str_ends_with($filename, '.zip')) {
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

    // ──────────────────────────────────────────────────────────────────────
    // RIPRISTINO DA ZIP
    // ──────────────────────────────────────────────────────────────────────

    /**
     * POST /backup/sistema/ripristina — carica un archivio ZIP e ripristina:
     *   - settings.json  → tutte le sezioni DB (incluse chiavi SATOSA, cifrate al primo salvataggio UI)
     *   - volumes/<nome> → file sui volumi montati (spid_certs, cieoidc_keys, metadata…)
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
        return $stats;
    }

    /**
     * POST /backup/sistema/carica-ripristina — carica un ZIP e ripristina immediatamente.
     * Risposta JSON (non redirect), per uso da UI o API.
     */
    public function systemBackupUploadRestore(Request $request, Response $response): Response
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

        $tmpPath = sys_get_temp_dir() . '/gil-upload-restore-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $stream = $file->getStream();
            file_put_contents($tmpPath, (string) $stream);

            $result = $this->doRestoreFromZip($tmpPath);

            Logger::getInstance()->info('Upload e ripristino ZIP completato', array_merge(['file' => $originalName], $result));
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Ripristino completato.',
                'detail'  => $result,
            ]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Errore upload-ripristino ZIP', ['error' => $e->getMessage()]);
            return $this->jsonError('Errore durante il ripristino: ' . $e->getMessage());
        } finally {
            @unlink($tmpPath);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Esegue mysqldump e restituisce il path del file .sql.gz temporaneo,
     * oppure null se mysqldump non è disponibile o fallisce.
     */
    private function runMysqldump(): ?string
    {
        $dbHost = (string)(getenv('DB_HOST') ?: '127.0.0.1');
        $dbPort = (string)(getenv('DB_PORT') ?: '3306');
        $dbName = (string)(getenv('DB_NAME') ?: '');
        $dbUser = (string)(getenv('DB_USER') ?: '');
        $dbPass = (string)(getenv('DB_PASSWORD') ?: '');

        if ($dbName === '' || $dbUser === '') {
            Logger::getInstance()->warning('mysqldump saltato: DB_NAME o DB_USER non configurati');
            return null;
        }

        $outFile = sys_get_temp_dir() . '/gil-dbdump-' . bin2hex(random_bytes(6)) . '.sql.gz';
        $cmd = sprintf(
            'mysqldump --single-transaction --routines --triggers -h %s -P %s -u %s -p%s %s 2>/dev/null | gzip > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($outFile)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !is_file($outFile) || filesize($outFile) === 0) {
            Logger::getInstance()->warning('mysqldump fallito o output vuoto', ['exit_code' => $exitCode]);
            @unlink($outFile);
            return null;
        }

        return $outFile;
    }

    /**
     * Ripristina il DB da un file .sql.gz via mysql CLI.
     */
    private function runMysqlRestore(string $gzFile): void
    {
        $dbHost = (string)(getenv('DB_HOST') ?: '127.0.0.1');
        $dbPort = (string)(getenv('DB_PORT') ?: '3306');
        $dbName = (string)(getenv('DB_NAME') ?: '');
        $dbUser = (string)(getenv('DB_USER') ?: '');
        $dbPass = (string)(getenv('DB_PASSWORD') ?: '');

        if ($dbName === '' || $dbUser === '') {
            throw new \RuntimeException('DB_NAME o DB_USER non configurati: impossibile ripristinare il database.');
        }

        $cmd = sprintf(
            'zcat %s | mysql -h %s -P %s -u %s -p%s %s 2>&1',
            escapeshellarg($gzFile),
            escapeshellarg($dbHost),
            escapeshellarg($dbPort),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName)
        );
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $errMsg = implode("\n", $output);
            throw new \RuntimeException("Ripristino DB fallito (exit {$exitCode}): {$errMsg}");
        }
    }

    private function isSuperadmin(): bool
    {
        return ($_SESSION['user']['role'] ?? '') === 'superadmin';
    }

    private function redirectToConfigTab(Response $response, string $tab): Response
    {
        return $response->withHeader('Location', '/impostazioni?tab=' . $tab)->withStatus(302);
    }

    /**
     * Esporta tutti i dati applicativi GovPay nello stesso formato di exportBackup().
     */
    private function buildFullGovpayExport(): array
    {
        $idDominio = (string) (\App\Config\Config::get('ID_DOMINIO') ?: '');
        $pdo       = Connection::getPDO();

        $entrateRepo = new EntrateRepository();
        $tipologie   = $entrateRepo->listLocalOverrides($idDominio);

        $extRepo   = new ExternalPaymentTypeRepository();
        $tipEsterne = $extRepo->listAll();

        $tplRepo   = new \App\Database\PendenzaTemplateRepository();
        $templates = $tplRepo->findAllByDominioWithUsers($idDominio);

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

        $utenti = $pdo->query('SELECT email, role, first_name, last_name, is_disabled, password_hash FROM users ORDER BY email ASC')->fetchAll();

        return [
            'version'     => '1.0',
            'exported_at' => (new \DateTimeImmutable())->format('c'),
            'exported_by' => $_SESSION['user']['email'] ?? 'system',
            'id_dominio'  => $idDominio,
            'sections'    => [
                'tipologie'         => $tipologie,
                'tipologie_esterne' => $tipEsterne,
                'templates'         => $templates,
                'io_services'       => $services,
                'utenti'            => $utenti,
            ],
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
        $resp = new SlimResponse($status);
        $resp->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $resp->withHeader('Content-Type', 'application/json');
    }
}
