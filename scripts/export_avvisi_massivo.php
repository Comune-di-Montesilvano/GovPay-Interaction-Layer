<?php
declare(strict_types=1);

/**
 * Worker one-shot: genera lo ZIP avvisi PDF di un batch pendenze massive.
 *
 * Uso: php scripts/export_avvisi_massivo.php <jobId>
 * Lanciato in background da MassivePendenzeController::avviaExportAvvisi(). Non è un demone:
 * elabora un solo job (pendenze_massive_export) e termina.
 *
 * Contenuto ZIP: un PDF per riga CSV di input ("CAUSALE_CF.pdf" — avviso singolo o PDF
 * documento multirata per le rateizzate), tracciato CSV di output, ERRORI.txt se qualche
 * PDF non è stato scaricato.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Controllers\PendenzeController;
use App\Database\MassivePendenzeRepository;
use App\Database\MassivoExportRepository;
use App\Services\MassivoExportService;
use Dotenv\Dotenv;
use Slim\Views\Twig;

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__))->load();
}
if (!getenv('DB_HOST')) {
    putenv('DB_HOST=db');
}

set_time_limit(0);

const EXPORT_DIR = '/var/www/cache/exports/massivo';
const RETENTION_GIORNI = 7;

$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] [export-avvisi] ' . $msg . "\n";
    flush();
};

\App\Monitoring\SentryReporter::init('export-avvisi-massivo');

$jobId = (int)($argv[1] ?? 0);
if ($jobId <= 0) {
    $log('jobId mancante. Uso: php export_avvisi_massivo.php <jobId>');
    exit(1);
}

$exportRepo = new MassivoExportRepository();
$job = $exportRepo->find($jobId);
if ($job === null || $job['stato'] !== 'PENDING') {
    $log("Job $jobId non trovato o non in PENDING. Uscita.");
    exit(0);
}
$batchId = (string)$job['file_batch_id'];

try {
    // Directory condivisa tra www-data (Apache) e root (docker exec): setgid come storage/logs.
    if (!is_dir(EXPORT_DIR)) {
        @mkdir(EXPORT_DIR, 02775, true);
        @chmod(EXPORT_DIR, 02775);
    }
    foreach ($exportRepo->expireOlderThan(RETENTION_GIORNI) as $old) {
        if (str_starts_with($old, EXPORT_DIR . '/')) {
            @unlink($old);
        }
    }

    $rows = (new MassivePendenzeRepository())->listAllByBatch($batchId);
    $plan = MassivoExportService::buildPdfPlan($rows);
    [$header, $tracciato] = MassivoExportService::buildTracciato($rows);
    $exportRepo->markRunning($jobId, count($plan));
    $log("Job $jobId batch $batchId: " . count($plan) . ' PDF da scaricare.');

    $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
    $controller = new PendenzeController(Twig::create(dirname(__DIR__) . '/backoffice/templates'), null);

    $safeBatch = preg_replace('/[^A-Za-z0-9\-_]/', '_', $batchId);
    $zipPath = EXPORT_DIR . '/avvisi_' . $safeBatch . '_' . $jobId . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Impossibile creare ZIP: ' . $zipPath);
    }
    $zip->addFromString('tracciato_' . $safeBatch . '.csv', MassivoExportService::toCsv($header, $tracciato));

    $errori = [];
    $processati = 0;
    foreach ($plan as $item) {
        try {
            $resp = $item['tipo'] === 'documento'
                ? $controller->fetchDocumentoPdfResponse($idDominio, $item['codice'])
                : $controller->fetchAvvisoPdfResponse($idDominio, $item['codice']);
            $pdf = (string)$resp->getBody();
            if ($resp->getStatusCode() !== 200 || !str_starts_with($pdf, '%PDF')) {
                throw new RuntimeException('Risposta GovPay non PDF (HTTP ' . $resp->getStatusCode() . ')');
            }
            $zip->addFromString($item['filename'], $pdf);
        } catch (\Throwable $e) {
            $errori[] = sprintf('Riga %d - %s %s (%s): %s', $item['riga'], $item['tipo'], $item['codice'], $item['filename'], $e->getMessage());
        }
        $processati++;
        if ($processati % 10 === 0) {
            $exportRepo->updateProgress($jobId, $processati, count($errori));
        }
    }
    if ($errori !== []) {
        $zip->addFromString('ERRORI.txt', implode("\n", $errori) . "\n");
    }
    if (!$zip->close()) {
        throw new RuntimeException('Errore scrittura ZIP: ' . $zipPath);
    }
    @chmod($zipPath, 0664);

    $exportRepo->markDone($jobId, $zipPath, $processati, count($errori));
    $log("Job $jobId completato: $processati PDF, " . count($errori) . ' errori.');
} catch (\Throwable $e) {
    \Sentry\captureException($e);
    $exportRepo->markError($jobId, $e->getMessage());
    $log("ERRORE job $jobId: " . $e->getMessage());
    exit(1);
}
