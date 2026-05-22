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

$pidFile = '/tmp/cron-ragioneria.pid';
$stopFile = '/tmp/cron-stop-ragioneria';

if (file_exists($pidFile)) {
    $existingPid = (int)file_get_contents($pidFile);
    if ($existingPid > 0 && file_exists('/proc/' . $existingPid)) {
        $log("Istanza già attiva (PID $existingPid). Uscita.");
        exit(0);
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

$backofficeUrl = rtrim((string)SettingsRepository::get('govpay', 'backoffice_url', ''), '/');
if ($backofficeUrl === '') {
    $log('ERRORE: govpay.backoffice_url non configurato.');
    exit(1);
}

$client = buildGovPayClient();
$repo = new FlussiRendicontazioniRepository();

$log('Daemon ragioneria avviato.');

while (true) {
    $checkStop();

    $scanDa = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDa)) {
        $scanDa = date('Y-01-01', strtotime('-1 year'));
    }

    $log("Ciclo scan da {$scanDa}... ");

    $newRows = 0;
    $page = 1;
    $flussiCount = 0;
    $scanIndex = 0;

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
            $checkStop();
            if (!is_array($flussoItem)) {
                continue;
            }

            $scanIndex++;

            $idFlusso = (string)($flussoItem['idFlusso'] ?? '');
            $dominioFlusso = (string)($flussoItem['dominio']['idDominio'] ?? $flussoItem['idDominio'] ?? $idDominio);
            $dataFlussoScan = (string)($flussoItem['dataFlusso'] ?? '');
            if ($idFlusso === '' || $dominioFlusso === '') {
                continue;
            }

            if ($scanIndex === 1 || $scanIndex % 25 === 0) {
                $log(sprintf(
                    '  [scan] flusso %d/%d pagina=%d id=%s dominio=%s data=%s',
                    $scanIndex,
                    $flussiCount,
                    $page,
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

            $rows = mapFlussoRows($detail, $dominioFlusso, $idFlusso);
            if ($rows === []) {
                if ($scanIndex === 1 || $scanIndex % 25 === 0) {
                    $log('  [scan] flusso ' . $idFlusso . ': nessuna rendicontazione utile');
                }
                continue;
            }

            $affected = $repo->upsertBatch($rows);
            $newRows += max(0, $affected);
            if ($scanIndex === 1 || $scanIndex % 25 === 0) {
                $log(sprintf(
                    '  [scan] flusso %s: rendicontazioni=%d upsert_affected=%d',
                    $idFlusso,
                    count($rows),
                    $affected
                ));
            }
        }

        $nextPage = extractNextPage((string)($payload['prossimiRisultati'] ?? ''));
        if ($nextPage === null || $nextPage <= $page) {
            break;
        }
        $page = $nextPage;
    }

    $log('Ciclo completato. Flussi letti=' . $flussiCount . ', righe upsert=' . $newRows);

    if ($newRows > 0) {
        continue;
    }

    $log('Nessun dato nuovo, sleep 30 minuti...');
    for ($s = 0; $s < 1800; $s += 10) {
        $checkStop();
        sleep(10);
    }
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

    $authMethod = strtolower((string)SettingsRepository::get('govpay', 'authentication_method', ''));
    if (in_array($authMethod, ['ssl', 'sslheader'], true)) {
        $cert = (string)SettingsRepository::get('govpay', 'tls_cert_path', '');
        $key = (string)SettingsRepository::get('govpay', 'tls_key_path', '');
        $keyPass = SettingsRepository::get('govpay', 'tls_key_password');
        if ($cert !== '' && $key !== '') {
            $opts['cert'] = $cert;
            $opts['ssl_key'] = $keyPass ? [$key, (string)$keyPass] : $key;
        }
    }

    return new Client($opts);
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
function mapFlussoRows(array $detail, string $idDominio, string $idFlusso): array
{
    $dataFlussoRaw = (string)($detail['dataFlusso'] ?? '');
    $dataRegolamentoRaw = (string)($detail['dataRegolamento'] ?? '');

    $dataFlusso = normalizeDate($dataFlussoRaw);
    $dataRegolamento = normalizeDate($dataRegolamentoRaw);

    $anno = (int)substr(($dataFlusso ?? date('Y-m-d')), 0, 4);
    $mese = (int)substr(($dataFlusso ?? date('Y-m-d')), 5, 2);
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

        $dataPagamento = normalizeDate((string)($risc['data'] ?? ''));

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
            'indice' => isset($rend['indice']) ? (int)$rend['indice'] : null,
            'data_pagamento' => $dataPagamento,
            'cod_entrata' => (string)($voce['codEntrata'] ?? ''),
            'descrizione_entrata' => (string)($voce['descrizione'] ?? ''),
            'id_pendenza' => (string)($voce['idPendenza'] ?? $risc['idPendenza'] ?? ''),
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
