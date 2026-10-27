<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\EntrateRepository;
use App\Database\MassivePendenzeRepository;
use App\Database\MassivoExportRepository;
use App\Services\MassivoCsvParser;
use App\Services\MassivoExportService;
use App\Services\MassivoRateizzazioneService;
use App\Services\ValidationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class MassivePendenzeController
{
    private const EXPORT_SCRIPT = '/var/www/html/scripts/export_avvisi_massivo.php';
    private const EXPORT_LOG = '/var/www/cache/export-avvisi-massivo.log';
    private const EXPORT_DIR = '/var/www/cache/exports/massivo';
    private const CSV_HEADERS = [
        'TIPO','CODICE_FISCALE_PIVA','COGNOME_ANAGRAFICA','NOME','CAUSALE','ANNO_RIFERIMENTO','IMPORTO','EMAIL','DATA_VALIDITA','DATA_SCADENZA','RATA','VOCE_1_IMPORTO','VOCE_1_CAUSALE','VOCE_2_IMPORTO','VOCE_2_CAUSALE'
    ];
    /** Colonne facoltative in coda: date esplicite per rata (RATA_1_DATA_VALIDITA, RATA_1_DATA_SCADENZA, ...). */
    private const RATA_DATE_HEADER = '/^RATA_([1-9]\d?)_DATA_(VALIDITA|SCADENZA)$/';
    private const TEMPLATE_RATE = 3;

    public function __construct(private readonly Twig $twig)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        // Lista batches (aggregati) per tabella stato
        $pdo = Connection::getPDO();
        $sql = "SELECT file_batch_id,
                       COUNT(*) AS totale,
                       SUM(stato='PENDING') AS pending,
                       SUM(stato='PROCESSING') AS processing,
                       SUM(stato='SUCCESS') AS success,
                       SUM(stato='ERROR') AS error
                FROM pendenze_massive
                GROUP BY file_batch_id
                ORDER BY MAX(created_at) DESC
                LIMIT 50";
        try {
            $batches = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $batches = [];
        }
        // Tipologie per select (se presenti nel DB)
        $tipologie = [];
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio) {
            try { $repo = new EntrateRepository(); $tipologie = $repo->listAbilitateByDominioForUser($idDominio, (int)($_SESSION['user']['id'] ?? 0), (string)($_SESSION['user']['role'] ?? 'user')); } catch (\Throwable $e) {}
        }
        return $this->twig->render($response, 'pendenze/inserimento_massivo.html.twig', [
            'batch_list' => $batches,
            'tipologie_pendenze' => $tipologie,
        ]);
    }

    public function templateCsv(Request $request, Response $response): Response
    {
        $headers = self::CSV_HEADERS;
        for ($i = 1; $i <= self::TEMPLATE_RATE; $i++) {
            array_push($headers, "RATA_{$i}_DATA_VALIDITA", "RATA_{$i}_DATA_SCADENZA");
        }
        $sample = array_merge(
            ['F','RSSMRA80A01F205X','ROSSI','MARIO','TASSA ISCRIZIONE',date('Y'), '44.00','mario.rossi@example.com',date('d/m/Y'), '31/12/' . date('Y'), '', '44.00','Quota iscrizione','',''],
            array_fill(0, self::TEMPLATE_RATE * 2, '')
        );
        // Esempio rateizzata: validità = primo del mese, scadenza = fine mese, dal mese prossimo
        $sampleRate = ['F','RSSMRA80A01F205X','ROSSI','MARIO','TARI ' . date('Y'),date('Y'), '300.00','mario.rossi@example.com','', '', (string)self::TEMPLATE_RATE, '300.00','TARI','',''];
        for ($i = 1; $i <= self::TEMPLATE_RATE; $i++) {
            $mese = (new \DateTimeImmutable('first day of this month'))->modify("+{$i} month");
            array_push($sampleRate, $mese->format('d/m/Y'), $mese->modify('last day of this month')->format('d/m/Y'));
        }
        $csv = fopen('php://temp', 'w+');
    // separatore ; con enclosure ed escape espliciti per evitare deprecazioni PHP (fputcsv richiede $escape)
    fputcsv($csv, $headers, ';', '"', '\\');
    fputcsv($csv, $sample, ';', '"', '\\');
    fputcsv($csv, $sampleRate, ';', '"', '\\');
        rewind($csv);
        $content = stream_get_contents($csv) ?: '';
        fclose($csv);
        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="template_pendenze_massive.csv"');
    }

    public function upload(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        $files = $request->getUploadedFiles();
        $file = $files['csv_file'] ?? null;
        $errors = [];
        if (!$file) {
            $errors[] = 'File CSV mancante';
            return $this->twig->render($response, 'pendenze/inserimento_massivo.html.twig', ['errors' => $errors]);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $errors[] = 'Errore upload file: ' . $file->getError();
            return $this->twig->render($response, 'pendenze/inserimento_massivo.html.twig', ['errors' => $errors]);
        }
        $stream = $file->getStream();
        $content = $stream->getContents();
        $rows = self::parseCsv($content);
        if (count($rows) === 0) {
            $errors[] = 'CSV vuoto o non leggibile';
            return $this->twig->render($response, 'pendenze/inserimento_massivo.html.twig', ['errors' => $errors]);
        }
        // Normalizza header
        $header = array_map(fn($h) => strtoupper(trim((string)$h)), array_shift($rows));
        $expected = self::CSV_HEADERS;
        if (array_slice($header, 0, count($expected)) !== $expected || !self::validRateHeaders(array_slice($header, count($expected)))) {
            $errors[] = 'Intestazioni CSV non valide. Atteso: ' . implode(';', $expected)
                . ' (seguite facoltativamente da RATA_1_DATA_VALIDITA;RATA_1_DATA_SCADENZA;RATA_2_DATA_VALIDITA;...)';
            return $this->twig->render($response, 'pendenze/inserimento_massivo.html.twig', ['errors' => $errors]);
        }
        // Processa righe: valida e costruisci preview
        $valid = [];
        $invalid = [];
        $repo = new MassivePendenzeRepository();
        $todayKeys = $repo->findTodayIdentCausaleKeys();
        $seenInBatch = [];
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // header è riga 1
            $r = self::assocRow($header, $row);
            $norm = self::normalizeRow($r);
            $err = self::validateRow($norm);
            if (!$err) {
                $dupKey = strtoupper(trim((string)$norm['identificativo'])) . '|' . strtoupper(trim((string)$norm['causale']));
                if (isset($seenInBatch[$dupKey])) {
                    $err = 'Duplicato nel file: stesso codice fiscale/partita IVA e causale già presente alla riga ' . $seenInBatch[$dupKey];
                } elseif (isset($todayKeys[$dupKey])) {
                    $err = 'Duplicato: stesso codice fiscale/partita IVA e causale già inserito oggi in un altro lotto';
                } else {
                    $seenInBatch[$dupKey] = $rowNum;
                }
            }
            if ($err) {
                $norm['_error'] = $err;
                $norm['_row'] = $rowNum;
                $invalid[] = $norm;
            } else {
                $norm['_row'] = $rowNum;
                $valid[] = $norm;
            }
        }
        // Assign batch id and persist invalids as ERROR records (optional) or keep in memory for CSV error download
        $batchId = 'B' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        // Persist just for listing counts (we will insert valid ones only on confirm step)
        foreach ($invalid as $inv) {
            $payload = $inv; unset($payload['_error'],$payload['_row']);
            $repo->insertPending($batchId, (int)$inv['_row'], $payload, $inv['_error']);
        }
        // keep first 2 valids for preview
        $firstValid = array_slice($valid, 0, 2);
        // cache valid rows into session for later confirm (simple approach)
        $_SESSION['massive_valid_rows'][$batchId] = $valid;
        // Tipologie
        $tipologie = [];
        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio) { try { $tipologie = (new EntrateRepository())->listAbilitateByDominioForUser($idDominio, (int)($_SESSION['user']['id'] ?? 0), (string)($_SESSION['user']['role'] ?? 'user')); } catch (\Throwable $e) {} }
        return $this->twig->render($response, 'pendenze/inserimento_massivo.html.twig', [
            'preview' => [
                'batch_id' => $batchId,
                'total_count' => count($rows),
                'valid_count' => count($valid),
                'invalid_count' => count($invalid),
                'first_valid' => $firstValid,
            ],
            'tipologie_pendenze' => $tipologie,
        ]);
    }

    public function downloadErroriCsv(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $batchId = $params['batch'] ?? '';
        if ($batchId === '') return $response->withStatus(400);
        $pdo = Connection::getPDO();
        $stmt = $pdo->prepare("SELECT riga, payload_json, errore FROM pendenze_massive WHERE file_batch_id = :b AND stato='ERROR' ORDER BY riga ASC");
        $stmt->execute([':b' => $batchId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $csv = fopen('php://temp', 'w+');
        $headers = ['RIGA','MOTIVO','PAYLOAD'];
        fputcsv($csv, $headers, ';', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($csv, [$r['riga'], $r['errore'], $r['payload_json']], ';', '"', '\\');
        }
        rewind($csv); $content = stream_get_contents($csv) ?: ''; fclose($csv);
        $response->getBody()->write($content);
        return $response->withHeader('Content-Type','text/csv; charset=UTF-8')
                        ->withHeader('Content-Disposition','attachment; filename="errori_batch_' . $batchId . '.csv"');
    }

    public function conferma(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        $params = (array)($request->getParsedBody() ?? []);
        $batchId = trim((string)($params['batch_id'] ?? ''));
        $idTipo = trim((string)($params['idTipoPendenza'] ?? ''));
        if ($batchId === '' || $idTipo === '') {
            return $response->withStatus(400);
        }
        $valid = $_SESSION['massive_valid_rows'][$batchId] ?? [];
        if (!is_array($valid) || count($valid) === 0) {
            return $response->withStatus(400);
        }
        $repo = new MassivePendenzeRepository();
        $riga = 0;
        $operatore = PendenzeController::getCurrentOperatorString() ?? 'Operatore Sconosciuto';
        foreach ($valid as $v) {
            $riga = (int)($v['_row'] ?? ++$riga);
            $payload = self::rowToPayload($v, $idTipo, $batchId, $operatore);
            foreach (self::expandRate($payload, $v) as $p) {
                $repo->insertPending($batchId, $riga, $p, null);
            }
        }
        // redirect alla lista dettagli batch
        return $response->withHeader('Location', '/pendenze/massivo/dettaglio?batch=' . urlencode($batchId) . '&from=inserimento')->withStatus(302);
    }

    public function dettaglio(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        $params = $request->getQueryParams();
        $batchId = $params['batch'] ?? '';
        $from = $params['from'] ?? 'inserimento';
        if ($batchId === '') return $response->withStatus(400);
        $repo = new MassivePendenzeRepository();
        $rows = $repo->listByBatch($batchId, null, 1, 500);
        $exportJob = (new MassivoExportRepository())->findLatestByBatch($batchId);
        return $this->twig->render($response, 'pendenze/massivo_dettaglio.html.twig', [
            'batch_id' => $batchId,
            'rows' => $rows,
            'from' => $from,
            'success_count' => $repo->countByBatch($batchId, 'SUCCESS'),
            'export_job' => $exportJob ? self::jobView($exportJob) : null,
        ]);
    }

    public function storico(Request $request, Response $response): Response
    {
        $this->exposeCurrentUser();
        $repo = new MassivePendenzeRepository();
        
        // Raccogliamo i lotti distinti e contiamo gli stati per ognuno
        $pdo = \App\Database\Connection::getPDO();
        $stmt = $pdo->query('
            SELECT file_batch_id, 
                   SUM(CASE WHEN stato="PENDING" THEN 1 ELSE 0 END) as pending,
                   SUM(CASE WHEN stato="PROCESSING" THEN 1 ELSE 0 END) as processing,
                   SUM(CASE WHEN stato="SUCCESS" THEN 1 ELSE 0 END) as success,
                   SUM(CASE WHEN stato="ERROR" THEN 1 ELSE 0 END) as error,
                   SUM(CASE WHEN stato="PAUSED" THEN 1 ELSE 0 END) as paused,
                   SUM(CASE WHEN stato="CANCELLED" THEN 1 ELSE 0 END) as cancelled,
                   COUNT(*) as totale,
                   MIN(created_at) as data_creazione
            FROM pendenze_massive
            GROUP BY file_batch_id
            ORDER BY data_creazione DESC
        ');
        $batchList = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        
        return $this->twig->render($response, 'pendenze/massivo_storico.html.twig', [
            'batch_list' => $batchList
        ]);
    }

    public function azioneBatch(Request $request, Response $response, string $batchId, string $azione): Response
    {
        $repo = new MassivePendenzeRepository();
        $msg = '';
        $type = 'success';
        
        switch ($azione) {
            case 'PAUSE':
                $mod = $repo->updateBatchStatus($batchId, 'PENDING', 'PAUSED');
                $msg = "Batch messo in pausa. $mod righe bloccate.";
                break;
            case 'RESUME':
                $mod = $repo->updateBatchStatus($batchId, 'PAUSED', 'PENDING');
                $msg = "Batch ripreso. $mod righe reinserite in coda.";
                break;
            case 'DELETE':
                // Check if it's safe to delete (only pending or paused)
                $safe = ($repo->countByBatch($batchId, 'PROCESSING') === 0 && 
                         $repo->countByBatch($batchId, 'SUCCESS') === 0 && 
                         $repo->countByBatch($batchId, 'ERROR') === 0);
                if ($safe) {
                    $repo->deleteBatch($batchId);
                    $msg = "Batch eliminato completamente dal sistema.";
                } else {
                    $type = 'error';
                    $msg = "Impossibile eliminare: il batch ha già righe elaborate.";
                }
                break;
            case 'CANCEL':
                // Annulla tutte le pendenze in stato SUCCESS mettendole in coda per il demone
                $mod = $repo->updateBatchStatus($batchId, 'SUCCESS', 'CANCEL_PENDING');
                if ($mod === 0) {
                    $type = 'warning';
                    $msg = "Nessuna pendenza processata da annullare.";
                } else {
                    $msg = "Richiesta ricevuta: $mod pendenze messe in coda per l'annullamento in background.";
                }
                break;
        }
        
        $_SESSION['flash'][] = ['type' => $type, 'text' => $msg];
        return $response->withHeader('Location', '/pendenze/massivo/storico')->withStatus(302);
    }

    /** Tracciato di output del batch: righe CSV di input + numeri avviso (anche per rata). */
    public function tracciato(Request $request, Response $response, string $batchId): Response
    {
        if ($batchId === '') return $response->withStatus(400);
        $rows = (new MassivePendenzeRepository())->listAllByBatch($batchId);
        [$header, $lines] = MassivoExportService::buildTracciato($rows);
        $response->getBody()->write(MassivoExportService::toCsv($header, $lines));
        $safe = preg_replace('/[^A-Za-z0-9\-_]/', '_', $batchId);
        return $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                        ->withHeader('Content-Disposition', 'attachment; filename="tracciato_' . $safe . '.csv"');
    }

    /**
     * Avvia (o riusa, se già in corso) il job async che genera lo ZIP avvisi PDF del batch.
     * Il worker scripts/export_avvisi_massivo.php gira in background, fuori dalla request.
     */
    public function avviaExportAvvisi(Request $request, Response $response, string $batchId): Response
    {
        if ($batchId === '') return $this->json($response, ['ok' => false, 'error' => 'Batch mancante'], 400);
        if ((new MassivePendenzeRepository())->countByBatch($batchId, 'SUCCESS') === 0) {
            return $this->json($response, ['ok' => false, 'error' => 'Nessuna pendenza creata in questo batch'], 409);
        }
        $repo = new MassivoExportRepository();
        $last = $repo->findLatestByBatch($batchId);
        if ($last && in_array($last['stato'], ['PENDING', 'RUNNING'], true) && !self::isJobStale($last)) {
            return $this->json($response, ['ok' => true, 'job' => self::jobView($last)]);
        }
        $operatore = PendenzeController::getCurrentOperatorString() ?? 'Operatore Sconosciuto';
        $jobId = $repo->create($batchId, $operatore);
        $cmd = sprintf(
            'php %s %d < /dev/null >> %s 2>&1 &',
            escapeshellarg(self::EXPORT_SCRIPT),
            $jobId,
            escapeshellarg(self::EXPORT_LOG)
        );
        exec($cmd);
        return $this->json($response, ['ok' => true, 'job' => self::jobView($repo->find($jobId) ?? [])]);
    }

    public function statoExportAvvisi(Request $request, Response $response, string $batchId): Response
    {
        $last = (new MassivoExportRepository())->findLatestByBatch($batchId);
        return $this->json($response, ['ok' => true, 'job' => $last ? self::jobView($last) : null]);
    }

    public function downloadExportAvvisi(Request $request, Response $response, string $batchId): Response
    {
        $last = (new MassivoExportRepository())->findLatestByBatch($batchId);
        $path = (string)($last['file_path'] ?? '');
        if (!$last || $last['stato'] !== 'DONE' || $path === '' || !str_starts_with($path, self::EXPORT_DIR . '/') || !is_file($path)) {
            return $response->withStatus(404);
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) return $response->withStatus(500);
        return $response->withBody(new \Slim\Psr7\Stream($fh))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string)filesize($path))
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($path) . '"');
    }

    /** Un job PENDING/RUNNING fermo da >10 minuti è considerato morto (worker crashato) e rilanciabile. */
    private static function isJobStale(array $job): bool
    {
        $ts = strtotime((string)($job['updated_at'] ?? ''));
        return $ts !== false && $ts < time() - 600;
    }

    private static function jobView(array $job): array
    {
        return [
            'id' => (int)($job['id'] ?? 0),
            'stato' => (string)($job['stato'] ?? ''),
            'totale' => (int)($job['totale'] ?? 0),
            'processati' => (int)($job['processati'] ?? 0),
            'errori' => (int)($job['errori'] ?? 0),
            'download' => ($job['stato'] ?? '') === 'DONE' && !empty($job['file_path']),
            'messaggio' => (string)($job['messaggio'] ?? ''),
            'created_by' => (string)($job['created_by'] ?? ''),
            'updated_at' => (string)($job['updated_at'] ?? ''),
        ];
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function exposeCurrentUser(): void
    {
        // same helper as other controllers if needed (left minimal here)
    }

    /** Vedi MassivoCsvParser::cleanImportoRaw(). */
    private static function cleanImportoRaw(string $raw): string
    {
        return MassivoCsvParser::cleanImportoRaw($raw);
    }

    /** Colonne in coda al tracciato base: solo RATA_i_DATA_VALIDITA/SCADENZA (i <= MAX_RATE), senza duplicati. */
    private static function validRateHeaders(array $extra): bool
    {
        // Celle vuote finali (";" in coda aggiunti da Excel) ammesse
        while ($extra !== [] && end($extra) === '') {
            array_pop($extra);
        }
        if (count($extra) !== count(array_unique($extra))) return false;
        foreach ($extra as $h) {
            if (!preg_match(self::RATA_DATE_HEADER, $h, $m) || (int)$m[1] > MassivoRateizzazioneService::MAX_RATE) {
                return false;
            }
        }
        return true;
    }

    /** Vedi MassivoCsvParser::parse(). */
    private static function parseCsv(string $content): array
    {
        return MassivoCsvParser::parse($content);
    }

    private static function assocRow(array $header, array $row): array
    {
        $out = [];
        foreach ($header as $i => $key) { $out[$key] = $row[$i] ?? ''; }
        return $out;
    }

    private static function normalizeRow(array $r): array
    {
        $tipo = strtoupper(trim((string)($r['TIPO'] ?? 'F')));
        $ident = strtoupper(trim((string)($r['CODICE_FISCALE_PIVA'] ?? '')));
        $cogn = strtoupper(trim((string)($r['COGNOME_ANAGRAFICA'] ?? '')));
        $nome = strtoupper(trim((string)($r['NOME'] ?? '')));
        $caus = (string)($r['CAUSALE'] ?? '');
        $anno = (int)($r['ANNO_RIFERIMENTO'] ?? 0);
        $imp = (float)self::cleanImportoRaw((string)($r['IMPORTO'] ?? '0'));
        $email = trim((string)($r['EMAIL'] ?? ''));
        $dvRaw = trim((string)($r['DATA_VALIDITA'] ?? ''));
        $dsRaw = trim((string)($r['DATA_SCADENZA'] ?? ''));
        $dv = self::normalizeDate($dvRaw);
        $ds = self::normalizeDate($dsRaw);
        $rata = trim((string)($r['RATA'] ?? ''));
        $v1imp = (string)($r['VOCE_1_IMPORTO'] ?? '');
        $v1desc = (string)($r['VOCE_1_CAUSALE'] ?? '');
        $v2imp = (string)($r['VOCE_2_IMPORTO'] ?? '');
        $v2desc = (string)($r['VOCE_2_CAUSALE'] ?? '');
        $v1impProvided = trim($v1imp) !== '';
        $v1impParsed = $v1impProvided ? (float)self::cleanImportoRaw($v1imp) : $imp;
        $v2impProvided = trim($v2imp) !== '';
        $v2impParsed = $v2impProvided ? (float)self::cleanImportoRaw($v2imp) : 0.0;
        // Date per rata: indice => ['dataValidita' => ISO, 'dataScadenza' => ISO] (solo rate con almeno una data)
        $dateRate = [];
        $dateRateInvalid = [];
        foreach ($r as $col => $val) {
            $val = trim((string)$val);
            if ($val === '' || !preg_match(self::RATA_DATE_HEADER, (string)$col, $m)) continue;
            $iso = self::normalizeDate($val);
            if ($iso === '') { $dateRateInvalid[] = (string)$col; continue; }
            $dateRate[(int)$m[1]][$m[2] === 'VALIDITA' ? 'dataValidita' : 'dataScadenza'] = $iso;
        }
        ksort($dateRate);
        return [
            'tipo' => $tipo,
            'identificativo' => $ident,
            'anagrafica' => $cogn,
            'nome' => $nome,
            'causale' => $caus,
            'annoRiferimento' => $anno,
            'importo' => $imp,
            'email' => $email,
            'dataValidita' => $dv,
            'dataScadenza' => $ds,
            'rata' => $rata,
            'dateRate' => $dateRate,
            '_dateRate_invalid' => $dateRateInvalid,
            '_v1imp_provided' => $v1impProvided,
            '_v1imp_raw' => $v1imp,
            'voci' => [
                ['idVocePendenza' => '1', 'descrizione' => $v1desc !== '' ? $v1desc : $caus, 'importo' => $v1impParsed],
                ['idVocePendenza' => '2', 'descrizione' => $v2desc, 'importo' => $v2impParsed],
            ],
        ];
    }

    /**
     * Normalizza una data da vari formati ammessi ("dd/mm/YYYY", "YYYY-MM-DD", Excel serial (>=30000,<70000),
     * oppure numerico YYYYMMDD) restituendo stringa ISO YYYY-MM-DD oppure stringa vuota se non valida.
     */
    private static function normalizeDate(string $raw): string
    {
        if ($raw === '') return '';
        $rawTrim = trim($raw);
        // Già formato ISO
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTrim)) return $rawTrim;
        // Formato italiano dd/mm/YYYY
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $rawTrim, $m)) {
            [$all,$d,$mth,$y] = $m;
            if (checkdate((int)$mth,(int)$d,(int)$y)) return sprintf('%04d-%02d-%02d',(int)$y,(int)$mth,(int)$d);
        }
        // Formato compatto YYYYMMDD
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/',$rawTrim,$m)) {
            if (checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]);
        }
        // Excel serial date (assumiamo sistema 1900) - range plausibile per anni moderni
        if (preg_match('/^\d{4,5}$/',$rawTrim)) {
            $serial = (int)$rawTrim;
            if ($serial >= 30000 && $serial < 70000) { // ~1982-2091
                try {
                    // Excel erroneamente considera il 1900 come anno bisestile: si usa epoch 1899-12-30
                    $epoch = new \DateTimeImmutable('1899-12-30');
                    $date = $epoch->modify("+{$serial} days");
                    return $date->format('Y-m-d');
                } catch (\Throwable $_) {}
            }
        }
        // Fallback: lascia vuoto se non riconosciuto
        return '';
    }

    private static function validateRow(array $norm): ?string
    {
        // Required fields
        foreach (['tipo','identificativo','anagrafica','causale','annoRiferimento','importo','email'] as $f) {
            if ($f !== 'email' && (empty($norm[$f]) && $norm[$f] !== 0 && $norm[$f] !== '0')) {
                return 'Campo obbligatorio mancante: ' . $f;
            }
        }
        // Email: stessa regex applicata da GovPay lato server (SINTASSI/RICHIESTA non valida)
        // per rifiutare a monte invece di far fallire la POST /pendenze.
        if ($norm['email'] !== '' && !preg_match('/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/', $norm['email'])) {
            return 'EMAIL non valida (formato non conforme)';
        }
        // Causale length
        if (!ValidationService::validateCausaleLength($norm['causale'])) return 'Causale oltre 140 caratteri';
        // Tipo
        if (!in_array($norm['tipo'], ['F','G'], true)) return 'TIPO non valido (F/G)';
        // Identificativo
        if ($norm['tipo'] === 'F') {
            $res = ValidationService::validateCodiceFiscale($norm['identificativo'], $norm['nome'] ?: null, $norm['anagrafica'] ?: null);
            if (!$res['format_ok'] || !$res['check_ok']) {
                return $res['message'] ?: 'Codice fiscale non valido';
            } elseif (!$res['name_match']) {
                return $res['message'] ?: 'Codice fiscale non coerente con nome e cognome indicati';
            }
        } else {
            $res = ValidationService::validatePartitaIva($norm['identificativo']);
            if (!$res['valid']) return $res['message'] ?: 'Partita IVA non valida';
        }
        // Importo
        if (!is_numeric((string)$norm['importo']) || $norm['importo'] <= 0) return 'Importo non valido';
        // RATA = numero di rate in cui suddividere la pendenza (vuota o 1 = pendenza unica)
        if ($norm['rata'] !== '') {
            if (!ctype_digit($norm['rata']) || (int)$norm['rata'] < 1 || (int)$norm['rata'] > MassivoRateizzazioneService::MAX_RATE) {
                return 'RATA non valida (intero da 1 a ' . MassivoRateizzazioneService::MAX_RATE . ')';
            }
            if ((int)round(((float)$norm['importo']) * 100) < (int)$norm['rata']) {
                return 'Importo troppo basso per il numero di rate indicato';
            }
        }
        // Date per rata (colonne facoltative RATA_i_DATA_VALIDITA/RATA_i_DATA_SCADENZA)
        if (!empty($norm['_dateRate_invalid'])) {
            return $norm['_dateRate_invalid'][0] . ' non valida (formato gg/mm/aaaa)';
        }
        $dateRate = is_array($norm['dateRate'] ?? null) ? $norm['dateRate'] : [];
        $numRate = (int)$norm['rata'];
        if ($dateRate !== []) {
            if ($numRate <= 1) return 'Date per rata indicate ma RATA non è maggiore di 1';
            $maxIdx = max(array_keys($dateRate));
            if ($maxIdx > $numRate) return "Date indicate per la rata {$maxIdx} ma RATA = {$numRate}";
            $conScadenza = array_filter($dateRate, static fn($d) => !empty($d['dataScadenza']));
            if ($conScadenza !== [] && count($conScadenza) !== $numRate) {
                return 'RATA_i_DATA_SCADENZA va indicata per tutte le rate (o per nessuna)';
            }
        }
        // Se VOCE_1_IMPORTO è stato fornito dall'utente, validalo come numerico (virgola ammessa)
        if (!empty($norm['_v1imp_provided'])) {
            $raw = (string)($norm['_v1imp_raw'] ?? '');
            $numOk = $raw !== '' && is_numeric(self::cleanImportoRaw($raw));
            if (!$numOk) return 'VOCE_1_IMPORTO non valido';
        }
        // Voci sum
        $sum = 0.0; foreach ($norm['voci'] as $v) { $sum += (float)($v['importo'] ?? 0); }
        if ((int)round($sum * 100) !== (int)round(((float)$norm['importo']) * 100)) return 'Somma voci diversa da importo';
        // Date validità/scadenza: se fornite, non possono essere nel passato
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (!empty($norm['dataValidita']) && $norm['dataValidita'] < $today) {
            return 'DATA_VALIDITA non può essere nel passato';
        }
        if (!empty($norm['dataScadenza']) && $norm['dataScadenza'] <= $today) {
            return 'DATA_SCADENZA non può essere nel passato o oggi';
        }
        if ($numRate > 1) {
            $prevData = '';
            foreach (MassivoRateizzazioneService::schedule($numRate, self::firstDue($norm), $dateRate) as $k => $d) {
                $i = $k + 1;
                // Senza scadenze (né per rata né DATA_SCADENZA) ogni rata deve avere almeno la validità
                if ($d['dataValidita'] === '') return "RATA_{$i}_DATA_VALIDITA mancante (senza scadenze va indicata la validità di ogni rata)";
                if ($d['dataValidita'] < $today) return "RATA_{$i}_DATA_VALIDITA non può essere nel passato";
                if ($d['dataScadenza'] !== '') {
                    if ($d['dataScadenza'] <= $today) return "RATA_{$i}_DATA_SCADENZA non può essere nel passato o oggi";
                    if ($d['dataValidita'] > $d['dataScadenza']) return "RATA_{$i}_DATA_VALIDITA successiva alla scadenza della rata";
                }
                $data = $d['dataScadenza'] ?: $d['dataValidita'];
                if ($data <= $prevData) return "Date della rata {$i} non successive a quelle della rata precedente";
                $prevData = $data;
            }
        }
        return null;
    }

    /**
     * RATA = N > 1: espande il payload in N rate collegate da un documento GovPay
     * (prima scadenza = DATA_SCADENZA, oppure oggi + 1 mese, poi mensile; le colonne
     * RATA_i_DATA_VALIDITA/RATA_i_DATA_SCADENZA, se valorizzate, sostituiscono le date calcolate).
     * Con sole validità per rata e DATA_SCADENZA vuota le rate non hanno scadenza.
     *
     * @return list<array<string,mixed>>
     */
    private static function expandRate(array $payload, array $norm): array
    {
        $numRate = (int)($norm['rata'] ?? 0);
        if ($numRate <= 1) {
            return [$payload];
        }
        $hex = bin2hex(random_bytes(14));
        return MassivoRateizzazioneService::expand(
            $payload,
            $numRate,
            self::firstDue($norm),
            'RATA-' . substr($hex, 0, 12),
            'GIL-' . substr($hex, 12, 16),
            is_array($norm['dateRate'] ?? null) ? $norm['dateRate'] : []
        );
    }

    /** Scadenza rata 1 da cui calcolare le mensili; null = date per rata indicate senza DATA_SCADENZA (nessuna scadenza calcolata). */
    private static function firstDue(array $norm): ?\DateTimeImmutable
    {
        if (!empty($norm['dataScadenza'])) {
            return new \DateTimeImmutable((string)$norm['dataScadenza']);
        }
        return empty($norm['dateRate']) ? (new \DateTimeImmutable('today'))->modify('+1 month') : null;
    }

    private static function rowToPayload(array $norm, string $idTipoPendenza, string $batchId, string $operatore): array
    {
        $payload = [
            'idTipoPendenza' => $idTipoPendenza,
            'idDominio' => SettingsRepository::get('entity', 'id_dominio', ''),
            'causale' => $norm['causale'],
            'importo' => (float)$norm['importo'],
            'annoRiferimento' => (int)$norm['annoRiferimento'],
            'soggettoPagatore' => [
                'tipo' => $norm['tipo'],
                'identificativo' => $norm['identificativo'],
                'anagrafica' => ($norm['tipo']==='F' && !empty($norm['nome'])) ? (trim($norm['nome'] . ' ' . $norm['anagrafica'])) : $norm['anagrafica'],
            ],
            'voci' => array_values(array_filter($norm['voci'], fn($v) => (float)($v['importo'] ?? 0) > 0)),
        ];
        if (!empty($norm['email'])) $payload['soggettoPagatore']['email'] = $norm['email'];
        if (!empty($norm['dataValidita'])) $payload['dataValidita'] = $norm['dataValidita'];
        if (!empty($norm['dataScadenza'])) $payload['dataScadenza'] = $norm['dataScadenza'];
        $datiAllegati = [
            'sorgente' => 'GIL-Massivo',
            'batchId' => $batchId,
            'operatore' => $operatore,
            'storico' => [
                [
                    'data' => date('c'),
                    'operazione' => 'Creazione Pendenza Massiva',
                    'dettaglio' => 'Caricamento batch ' . $batchId,
                    'operatore' => $operatore,
                ]
            ]
        ];
        $payload['datiAllegati'] = $datiAllegati;
        
        return $payload;
    }
}
