<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\EntrateRepository;
use App\Database\MassivePendenzeRepository;
use App\Services\ValidationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class MassivePendenzeController
{
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
        $headers = [
            'TIPO','CODICE_FISCALE_PIVA','COGNOME_ANAGRAFICA','NOME','CAUSALE','ANNO_RIFERIMENTO','IMPORTO','EMAIL','DATA_VALIDITA','DATA_SCADENZA','RATA','VOCE_1_IMPORTO','VOCE_1_CAUSALE','VOCE_2_IMPORTO','VOCE_2_CAUSALE'
        ];
        $sample = ['F','RSSMRA80A01F205X','ROSSI','MARIO','TASSA ISCRIZIONE',date('Y'), '44.00','mario.rossi@example.com','', '', '', '44.00','Quota iscrizione','',''];
        $csv = fopen('php://temp', 'w+');
    // separatore ; con enclosure ed escape espliciti per evitare deprecazioni PHP (fputcsv richiede $escape)
    fputcsv($csv, $headers, ';', '"', '\\');
    fputcsv($csv, $sample, ';', '"', '\\');
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
        $expected = ['TIPO','CODICE_FISCALE_PIVA','COGNOME_ANAGRAFICA','NOME','CAUSALE','ANNO_RIFERIMENTO','IMPORTO','EMAIL','DATA_VALIDITA','DATA_SCADENZA','RATA','VOCE_1_IMPORTO','VOCE_1_CAUSALE','VOCE_2_IMPORTO','VOCE_2_CAUSALE'];
        if ($header !== $expected) {
            $errors[] = 'Intestazioni CSV non valide. Atteso: ' . implode(';', $expected);
            return $this->twig->render($response, 'pendenze/inserimento_massivo.html.twig', ['errors' => $errors]);
        }
        // Processa righe: valida e costruisci preview
        $valid = [];
        $invalid = [];
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // header è riga 1
            $r = self::assocRow($header, $row);
            $norm = self::normalizeRow($r);
            $err = self::validateRow($norm);
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
        $repo = new MassivePendenzeRepository();
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
            $repo->insertPending($batchId, $riga, $payload, null);
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
        return $this->twig->render($response, 'pendenze/massivo_dettaglio.html.twig', [
            'batch_id' => $batchId,
            'rows' => $rows,
            'from' => $from
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
                // Annulla tutte le pendenze in stato SUCCESS
                $rows = $repo->listByBatch($batchId, 'SUCCESS', 1, 9999);
                if (empty($rows)) {
                    $type = 'warning';
                    $msg = "Nessuna pendenza processata da annullare.";
                } else {
                    $successi = 0; $errori = 0;
                    $pendenzeCtrl = new PendenzeController($this->twig);
                    $repo->updateBatchStatus($batchId, 'SUCCESS', 'PROCESSING'); // Temporaneo per non inviare roba doppia
                    
                    foreach ($rows as $r) {
                        try {
                            $respJson = json_decode((string)$r['response_json'], true);
                            if ($respJson && isset($respJson['id_avviso'])) {
                                $idA2A = $respJson['id_a2a'] ?? SettingsRepository::get('entity', 'id_a2a', '');
                                $pendenzeCtrl->annullaPendenzaById($idA2A, $respJson['id_avviso']);
                                $repo->updateBatchStatus($batchId, 'PROCESSING', 'CANCELLED');
                                $successi++;
                            }
                        } catch (\Throwable $e) {
                            $errori++;
                            $repo->setResult((int)$r['id'], true, null, 'Errore API di Annullamento: ' . $e->getMessage()); 
                        }
                    }
                    $msg = "Operazione annullamento terminata: $successi completati, $errori falliti.";
                    
                    // Riporta in success quelli che erano falliti l'annullamento per non perdere la cronologia originale
                    $pdo = \App\Database\Connection::getPDO();
                    $pdo->prepare('UPDATE pendenze_massive SET stato="SUCCESS" WHERE file_batch_id=? AND stato="PROCESSING"')->execute([$batchId]);
                }
                break;
        }
        
        $_SESSION['flash'] = ['type' => $type, 'message' => $msg];
        return $response->withHeader('Location', '/pendenze/massivo/storico')->withStatus(302);
    }

    private function exposeCurrentUser(): void
    {
        // same helper as other controllers if needed (left minimal here)
    }

    private static function parseCsv(string $content): array
    {
        $rows = [];
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $content); rewind($fh);
        // Specifica esplicitamente enclosure ed escape per evitare warning di deprecazione
        while (($r = fgetcsv($fh, 0, ';', '"', '\\')) !== false) { $rows[] = $r; }
        fclose($fh);
        return $rows;
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
        $imp = (float)str_replace(',', '.', (string)($r['IMPORTO'] ?? '0'));
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
        $v1impParsed = $v1impProvided ? (float)str_replace(',', '.', $v1imp) : $imp;
        $v2impProvided = trim($v2imp) !== '';
        $v2impParsed = $v2impProvided ? (float)str_replace(',', '.', $v2imp) : 0.0;
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
        // Causale length
        if (!ValidationService::validateCausaleLength($norm['causale'])) return 'Causale oltre 140 caratteri';
        // Tipo
        if (!in_array($norm['tipo'], ['F','G'], true)) return 'TIPO non valido (F/G)';
        // Identificativo
        if ($norm['tipo'] === 'F') {
            $res = ValidationService::validateCodiceFiscale($norm['identificativo'], $norm['nome'] ?: null, $norm['anagrafica'] ?: null);
            if (!$res['format_ok'] || !$res['check_ok']) return $res['message'] ?: 'Codice fiscale non valido';
        } else {
            $res = ValidationService::validatePartitaIva($norm['identificativo']);
            if (!$res['valid']) return $res['message'] ?: 'Partita IVA non valida';
        }
        // Importo
        if (!is_numeric((string)$norm['importo']) || $norm['importo'] <= 0) return 'Importo non valido';
        // Se VOCE_1_IMPORTO è stato fornito dall'utente, validalo come numerico (virgola ammessa)
        if (!empty($norm['_v1imp_provided'])) {
            $raw = (string)($norm['_v1imp_raw'] ?? '');
            $numOk = $raw !== '' && is_numeric(str_replace(',', '.', $raw));
            if (!$numOk) return 'VOCE_1_IMPORTO non valido';
        }
        // Voci sum
        $sum = 0.0; foreach ($norm['voci'] as $v) { $sum += (float)($v['importo'] ?? 0); }
        if ((int)round($sum * 100) !== (int)round(((float)$norm['importo']) * 100)) return 'Somma voci diversa da importo';
        return null;
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
