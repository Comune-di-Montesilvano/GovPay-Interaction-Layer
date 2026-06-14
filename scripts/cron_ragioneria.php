<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\FlussiRendicontazioniRepository;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

if (file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}
if (!getenv('DB_HOST')) {
    putenv('DB_HOST=db');
}

set_time_limit(0);

$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    flush();
};

$pidFile    = '/tmp/cron-ragioneria.pid';
$stopFile   = '/tmp/cron-stop-ragioneria';
$rescanFile = '/tmp/cron-rescan-ragioneria';

if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $cmdline = @file_get_contents('/proc/' . $existingPid . '/cmdline');
        if ($cmdline !== false && strpos($cmdline, 'cron_ragioneria.php') !== false) {
            $log("Istanza già attiva (PID $existingPid). Uscita.");
            exit(0);
        }
    }
}

file_put_contents($pidFile, (string)getmypid());
@unlink($stopFile);
register_shutdown_function(static function () use ($pidFile): void {
    @unlink($pidFile);
});

$checkStop = static function () use ($stopFile, $log): void {
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $log('Segnale di stop ricevuto. Uscita.');
        exit(0);
    }
};

// Returns true and resets rolling window if a rescan signal was received.
$checkRescan = static function () use ($rescanFile, $log, &$rollingFrom): bool {
    if (file_exists($rescanFile)) {
        @unlink($rescanFile);
        $rollingFrom = null;
        try {
            SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', null);
        } catch (Throwable $e) {
            $log('Errore reset progress rolling from nel DB: ' . $e->getMessage());
        }
        $log('Segnale rescan ricevuto: prossimo ciclo scan completo da data configurazione.');
        return true;
    }
    return false;
};

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(15, static function () use ($pidFile, $log): void {
        $log('SIGTERM ricevuto. Uscita.');
        @unlink($pidFile);
        exit(0);
    });
}

$dbReady = false;
for ($i = 0; $i < 60; $i++) {
    try {
        Connection::getPDO()->query('SELECT 1');
        $dbReady = true;
        break;
    } catch (Throwable $_) {
        sleep(1);
    }
}
if (!$dbReady) {
    $log('DB non raggiungibile dopo 60s. Uscita.');
    exit(1);
}

$idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
if ($idDominio === '') {
    $log('ERRORE: id_dominio non configurato.');
    exit(1);
}

$idA2A = (string)SettingsRepository::get('entity', 'id_a2a', '');

$backofficeUrl = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');
if ($backofficeUrl === '') {
    $log('ERRORE: govpay.backoffice_url non configurato.');
    exit(1);
}

$client = buildGovPayClient();
$repo = new FlussiRendicontazioniRepository();

$log('Daemon ragioneria avviato.');

// Ripristina progresso scansione in caso di riavvio container
$savedScanDa = SettingsRepository::get('backoffice', 'ragioneria_progress_scan_da');
$savedRollingFrom = SettingsRepository::get('backoffice', 'ragioneria_progress_rolling_from');

$scanDaConfig = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDaConfig)) {
    $scanDaConfig = date('Y-01-01', strtotime('-1 year'));
}

if ($savedScanDa === $scanDaConfig && $savedRollingFrom !== null && $savedRollingFrom !== '') {
    $rollingFrom = $savedRollingFrom;
    $log("Ripristinato progresso scansione: rollingFrom={$rollingFrom} per scan_da={$scanDaConfig}");
} else {
    $rollingFrom = null;
    $log("Nessun progresso ripristinabile o data configurazione cambiata. Avvio con scan completo da {$scanDaConfig}");
}

while (true) {
    $checkStop();
    $checkRescan();

    $scanDaConfig = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDaConfig)) {
        $scanDaConfig = date('Y-01-01', strtotime('-1 year'));
    }

    // Se la data configurazione è cambiata rispetto al progresso salvato, resetta la finestra scorrevole
    $savedScanDa = SettingsRepository::get('backoffice', 'ragioneria_progress_scan_da');
    if ($savedScanDa !== null && $savedScanDa !== '' && $savedScanDa !== $scanDaConfig) {
        $rollingFrom = null;
        SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', $scanDaConfig);
        SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', null);
        $log("Data configurazione modificata rilevata in esecuzione. Reset rolling window.");
    }

    // Use rolling window when available; fall back to full config date.
    $scanDa = $rollingFrom ?? $scanDaConfig;

    $log("Ciclo scan da {$scanDa}" . ($rollingFrom !== null ? ' [finestra scorrevole]' : ' [scan completo]') . '...');

    $newRows = 0;
    $cycleRowsTotal = 0;
    $cycleGovPayYes = 0;
    $page = 1;
    $flussiCount = 0;
    $flussiToScan = [];

    while (true) {
        $checkStop();

        $query = [
            'pagina' => $page,
            'risultatiPerPagina' => 100,
            'metadatiPaginazione' => 'true',
            'maxRisultati' => 'true',
            'idDominio' => $idDominio,
            'dataDa' => $scanDa,
        ];

        try {
            $resp = $client->request('GET', $backofficeUrl . '/flussiRendicontazione', ['query' => $query]);
            $payload = json_decode((string)$resp->getBody(), true);
        } catch (Throwable $e) {
            $log('Errore lista flussi pagina ' . $page . ': ' . $e->getMessage());
            break;
        }

        $risultati = is_array($payload['risultati'] ?? null) ? $payload['risultati'] : [];
        if ($risultati === []) {
            break;
        }

        $pageCount = count($risultati);
        $flussiCount += $pageCount;
        $firstFlusso = is_array($risultati[0] ?? null) ? (string)(($risultati[0]['idFlusso'] ?? '')) : '';
        $lastFlusso = is_array($risultati[$pageCount - 1] ?? null) ? (string)(($risultati[$pageCount - 1]['idFlusso'] ?? '')) : '';
        $log('Pagina ' . $page . ': ' . $pageCount . ' flussi [' . $firstFlusso . ' .. ' . $lastFlusso . ']');

        foreach ($risultati as $flussoItem) {
            if (is_array($flussoItem)) {
                $flussiToScan[] = $flussoItem;
            }
        }

        $nextPage = extractNextPage((string)($payload['prossimiRisultati'] ?? ''));
        if ($nextPage === null || $nextPage <= $page) {
            break;
        }
        $page = $nextPage;
    }

    if ($flussiToScan !== []) {
        usort($flussiToScan, static function (array $a, array $b): int {
            $da = (string)($a['dataFlusso'] ?? '');
            $db = (string)($b['dataFlusso'] ?? '');
            if ($da === $db) {
                return strcmp((string)($a['idFlusso'] ?? ''), (string)($b['idFlusso'] ?? ''));
            }
            return strcmp($da, $db);
        });

        $oldest = (string)($flussiToScan[0]['idFlusso'] ?? '-');
        $newest = (string)($flussiToScan[count($flussiToScan) - 1]['idFlusso'] ?? '-');
        $log('Ordine scansione cronologico (vecchio -> nuovo): [' . $oldest . ' .. ' . $newest . ']');
    }

    $existingFlussi = loadExistingFlussiIds($repo->getPdo(), $idDominio);
    $cutoffDate = date('Y-m-d', strtotime('-15 days'));
    $skipped = 0;

    $scanIndex = 0;
    foreach ($flussiToScan as $flussoItem) {
        $checkStop();

        $scanIndex++;

        $idFlusso = (string)($flussoItem['idFlusso'] ?? '');
        $dominioFlusso = (string)($flussoItem['dominio']['idDominio'] ?? $flussoItem['idDominio'] ?? $idDominio);
        $dataFlussoScan = (string)($flussoItem['dataFlusso'] ?? '');
        if ($idFlusso === '' || $dominioFlusso === '') {
            continue;
        }

        // Skip flussi already in DB that are old enough to be immutable.
        if (isset($existingFlussi[$idFlusso]) && $dataFlussoScan !== '' && $dataFlussoScan < $cutoffDate) {
            $skipped++;
            continue;
        }

        if ($scanIndex === 1 || $scanIndex % 25 === 0) {
            $log(sprintf(
                '  [scan] flusso %d/%d id=%s dominio=%s data=%s',
                $scanIndex,
                $flussiCount,
                $idFlusso,
                $dominioFlusso,
                $dataFlussoScan
            ));
        }

        try {
            $detailUrl = $backofficeUrl . '/flussiRendicontazione/' . rawurlencode($dominioFlusso) . '/' . rawurlencode($idFlusso);
            $detailResp = $client->request('GET', $detailUrl);
            $detail = json_decode((string)$detailResp->getBody(), true);
        } catch (Throwable $e) {
            $log('Errore dettaglio flusso ' . $idFlusso . ': ' . $e->getMessage());
            continue;
        }

        if (!is_array($detail)) {
            continue;
        }

        $rows = mapFlussoRows($detail, $dominioFlusso, $idFlusso, $idA2A);
        if ($rows === []) {
            if ($scanIndex === 1 || $scanIndex % 25 === 0) {
                $log('  [scan] flusso ' . $idFlusso . ': nessuna rendicontazione utile');
            }
            continue;
        }

        $flussoRowsTotal = count($rows);
        $flussoGovPayYes = 0;
        foreach ($rows as $mappedRow) {
            if ((int)($mappedRow['is_govpay'] ?? 0) === 1) {
                $flussoGovPayYes++;
            }
        }
        $cycleRowsTotal += $flussoRowsTotal;
        $cycleGovPayYes += $flussoGovPayYes;

        $affected = $repo->upsertBatch($rows);
        $newRows += max(0, $affected);
        $log(sprintf(
            '  [scan] flusso %s: rendicontazioni=%d govpay_si=%d govpay_no=%d upsert_affected=%d',
            $idFlusso,
            $flussoRowsTotal,
            $flussoGovPayYes,
            max(0, $flussoRowsTotal - $flussoGovPayYes),
            $affected
        ));

        // Salvataggio periodico del progresso (ogni 25 flussi processati)
        if ($dataFlussoScan !== '' && $scanIndex % 25 === 0) {
            $progressDate = date('Y-m-d', strtotime($dataFlussoScan . ' -2 days'));
            if ($progressDate < $scanDaConfig) {
                $progressDate = $scanDaConfig;
            }
            $savedRollingFrom = SettingsRepository::get('backoffice', 'ragioneria_progress_rolling_from');
            if ($savedRollingFrom === null || $savedRollingFrom === '' || $progressDate > $savedRollingFrom) {
                SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', $scanDaConfig);
                SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', $progressDate);
            }
        }
    }

    $log(sprintf(
        'Ciclo completato. Flussi letti=%d skippati=%d, righe_parse_totali=%d, govpay_si=%d, govpay_no=%d, righe_upsert=%d',
        $flussiCount,
        $skipped,
        $cycleRowsTotal,
        $cycleGovPayYes,
        max(0, $cycleRowsTotal - $cycleGovPayYes),
        $newRows
    ));

    // Update rolling window: max synced date - 3 days (overlap to catch late-arriving flussi).
    $maxDate = getMaxSyncedFlussoDate($repo->getPdo(), $idDominio);
    if ($maxDate !== null) {
        $rollingFrom = date('Y-m-d', strtotime($maxDate . ' -15 days'));
        SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', $scanDaConfig);
        SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', $rollingFrom);
    }

    if ($newRows > 0) {
        $log("Nuovi dati trovati, prossimo ciclo da {$rollingFrom} [finestra scorrevole].");
        continue;
    }

    $log('Nessun dato nuovo, sleep 30 minuti...');
    for ($s = 0; $s < 1800; $s += 10) {
        $checkStop();
        sleep(10);
    }
}

/**
 * Returns the most recent data_flusso stored in DB for the given domain, or null if none.
 */
function getMaxSyncedFlussoDate(\PDO $pdo, string $idDominio): ?string
{
    $stmt = $pdo->prepare('SELECT MAX(data_flusso) FROM flussi_rendicontazioni WHERE id_dominio = :id_dominio');
    $stmt->execute([':id_dominio' => $idDominio]);
    $value = $stmt->fetchColumn();
    return (is_string($value) && $value !== '') ? substr($value, 0, 10) : null;
}

/**
 * Returns a set (id_flusso => true) of flusso IDs already present in DB for the given domain.
 */
function loadExistingFlussiIds(\PDO $pdo, string $idDominio): array
{
    $stmt = $pdo->prepare('SELECT DISTINCT id_flusso FROM flussi_rendicontazioni WHERE id_dominio = :id_dominio');
    $stmt->execute([':id_dominio' => $idDominio]);
    $ids = [];
    while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
        $ids[(string)$row[0]] = true;
    }
    return $ids;
}

function buildGovPayClient(): Client
{
    $opts = [
        'headers' => ['Accept' => 'application/json'],
        'connect_timeout' => 10,
        'timeout' => 30,
    ];

    $username = (string)SettingsRepository::get('govpay', 'user', '');
    $password = (string)SettingsRepository::get('govpay', 'password', '');
    if ($username !== '' && $password !== '') {
        $opts['auth'] = [$username, $password];
    }

    return \App\Services\GovPayClientFactory::makeBackofficeClient($opts);
}

function extractNextPage(string $prossimiRisultati): ?int
{
    if ($prossimiRisultati === '') {
        return null;
    }

    $query = parse_url($prossimiRisultati, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return null;
    }

    parse_str($query, $params);
    $page = (int)($params['pagina'] ?? 0);
    return $page > 0 ? $page : null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function mapFlussoRows(array $detail, string $idDominio, string $idFlusso, string $idA2A = ''): array
{
    $dataFlussoRaw = (string)($detail['dataFlusso'] ?? '');
    $dataRegolamentoRaw = (string)($detail['dataRegolamento'] ?? '');

    $dataFlusso = normalizeDate($dataFlussoRaw);
    $dataRegolamento = normalizeDate($dataRegolamentoRaw);
    $dataIncasso = $dataRegolamento;

    $anno = (int)substr(($dataIncasso ?? date('Y-m-d')), 0, 4);
    $mese = (int)substr(($dataIncasso ?? date('Y-m-d')), 5, 2);
    if ($anno < 2020 || $anno > 2100) {
        $anno = (int)date('Y');
        $mese = (int)date('n');
    }

    $rendicontazioni = is_array($detail['rendicontazioni'] ?? null) ? $detail['rendicontazioni'] : [];
    $rows = [];

    foreach ($rendicontazioni as $rend) {
        if (!is_array($rend)) {
            continue;
        }

        $iur = trim((string)($rend['iur'] ?? ''));
        if ($iur === '') {
            continue;
        }

        $risc = is_array($rend['riscossione'] ?? null) ? $rend['riscossione'] : [];
        $voce = is_array($risc['vocePendenza'] ?? null) ? $risc['vocePendenza'] : [];
        $isMultiBeneficiario = deriveIsMultiBeneficiario($detail, $rend, $risc);
        $idPendenza = extractIdPendenza($rend, $risc, $voce);
        // GovPay only for pendenze registered by THIS application (matching id_a2a).
        // If idA2A not present in payload (older GovPay), fall back to idPendenza presence.
        $payloadA2A = extractIdA2A($rend, $risc, $voce);
        $isGovPay   = $idPendenza !== '' && ($payloadA2A === '' || $idA2A === '' || $payloadA2A === $idA2A);

        $rows[] = [
            'id_dominio' => $idDominio,
            'id_flusso' => $idFlusso,
            'data_flusso' => $dataFlusso,
            'data_regolamento' => $dataRegolamento,
            'trn' => (string)($detail['trn'] ?? ''),
            'id_psp' => (string)($detail['psp']['idPsp'] ?? $detail['idPsp'] ?? ''),
            'ragione_psp' => (string)($detail['psp']['ragioneSociale'] ?? $detail['ragionePsp'] ?? ''),
            'anno' => $anno,
            'mese' => $mese,
            'iur' => $iur,
            'iuv' => (string)($rend['iuv'] ?? ''),
            'importo' => (float)($rend['importo'] ?? 0),
            'esito' => isset($rend['esito']) ? (int)$rend['esito'] : null,
            'stato_rend' => (string)($rend['stato'] ?? $rend['statoRendicontazione'] ?? ''),
            'indice' => isset($rend['indice']) ? (int)$rend['indice'] : 1,
            'data_pagamento' => $dataIncasso,
            'cod_entrata' => (function() use ($voce): string {
                $cod = (string)($voce['codEntrata'] ?? '');
                if ($cod === '') {
                    $pend = is_array($voce['pendenza'] ?? null) ? $voce['pendenza'] : [];
                    $tipo = is_array($pend['tipoPendenza'] ?? null) ? $pend['tipoPendenza'] : [];
                    $cod = (string)($tipo['idTipoPendenza'] ?? '');
                }
                return $cod;
            })(),
            'descrizione_entrata' => (string)($voce['descrizione'] ?? ''),
            'id_pendenza' => $idPendenza,
            'is_govpay' => $isGovPay,
            'is_multibeneficiario' => $isMultiBeneficiario,
        ];
    }

    return $rows;
}

function normalizeDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (strlen($value) >= 10) {
        return substr($value, 0, 10);
    }

    if (strlen($value) === 7) {
        return $value . '-01';
    }

    return null;
}

function deriveIsMultiBeneficiario(array $detail, array $rend, array $risc): ?bool
{
    $candidates = [
        $rend['isMultibeneficiario'] ?? null,
        $rend['is_multi_beneficiario'] ?? null,
        $rend['multiBeneficiario'] ?? null,
        $rend['multibeneficiario'] ?? null,
        $risc['isMultibeneficiario'] ?? null,
        $risc['is_multi_beneficiario'] ?? null,
        $risc['multiBeneficiario'] ?? null,
        $risc['multibeneficiario'] ?? null,
        $detail['isMultibeneficiario'] ?? null,
        $detail['is_multi_beneficiario'] ?? null,
        $detail['multiBeneficiario'] ?? null,
        $detail['multibeneficiario'] ?? null,
    ];

    foreach ($candidates as $value) {
        if ($value === null || $value === '') {
            continue;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    $datiVersamento = $detail['rpt']['json']['datiVersamento'] ?? null;
    if (is_array($datiVersamento)) {
        $tipoVersamento = strtoupper(trim((string)($datiVersamento['tipoVersamento'] ?? '')));
        if ($tipoVersamento === 'MPO') {
            return true;
        }

        $singoli = $datiVersamento['datiSingoloVersamento'] ?? null;
        if (is_array($singoli)) {
            return count($singoli) > 1;
        }
    }

    return null;
}

function extractIdPendenza(array $rend, array $risc, array $voce): string
{
    $candidates = [
        $voce['idPendenza'] ?? null,
        is_array($voce['pendenza'] ?? null) ? ($voce['pendenza']['idPendenza'] ?? null) : null,
        $risc['idPendenza'] ?? null,
        is_array($risc['pendenza'] ?? null) ? ($risc['pendenza']['idPendenza'] ?? null) : null,
        is_array($rend['pendenza'] ?? null) ? ($rend['pendenza']['idPendenza'] ?? null) : null,
    ];

    foreach ($candidates as $value) {
        $id = trim((string)$value);
        if ($id !== '') {
            return $id;
        }
    }

    return '';
}

function extractIdA2A(array $rend, array $risc, array $voce): string
{
    $candidates = [
        is_array($voce['pendenza'] ?? null) ? ($voce['pendenza']['idA2A'] ?? null) : null,
        is_array($risc['pendenza'] ?? null) ? ($risc['pendenza']['idA2A'] ?? null) : null,
        is_array($rend['pendenza'] ?? null) ? ($rend['pendenza']['idA2A'] ?? null) : null,
    ];

    foreach ($candidates as $value) {
        $id = trim((string)$value);
        if ($id !== '') {
            return $id;
        }
    }

    return '';
}

