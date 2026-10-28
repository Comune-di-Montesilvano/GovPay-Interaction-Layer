<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Config\SettingsRepository;
use App\Database\FlussiRendicontazioniRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Una iterazione del demone ragioneria (corpo del loop di cron_ragioneria.php): elenca i flussi di
 * rendicontazione GovPay dalla data di partenza (scan completo oppure finestra scorrevole), scarica
 * il dettaglio di ciascuno e fa l'upsert in flussi_rendicontazioni.
 *
 * Stato tra le iterazioni (in memoria sull'istanza, come le variabili dello script):
 *  - $rollingFrom: inizio della finestra scorrevole (null = scan completo da ragioneria_scan_da).
 *    Persistito anche in settings (ragioneria_progress_*) per sopravvivere ai riavvii; alla prima
 *    runOnce() viene ripristinato da li' se ragioneria_progress_scan_da coincide con la config.
 *  - flag di rescan forzato: requestRescan() lo imposta, la prossima runOnce() lo consuma.
 *
 * hadWork = almeno una riga inserita o aggiornata con modifiche in questo ciclo (righe identiche non
 * contano): se true lo script rilancia subito runOnce(), altrimenti attende 30 minuti.
 * Pausa, stop file, guardie di avvio e requestRescan da file restano nello script.
 *
 * Conteggi: processed = flussi validi esaminati (dopo i controlli su id/dominio), ok = flussi con
 * dettaglio scaricato e righe upsertate, skipped = flussi gia' in DB piu' vecchi di 15 giorni,
 * errors = errori di lista/dettaglio. Le eccezioni non di rete (DB/settings) si propagano.
 */
final class RagioneriaSyncJob
{
    private ?Client $client;

    private readonly string $backofficeUrl;

    private bool $initialized = false;

    private bool $rescanRequested = false;

    private ?string $rollingFrom = null;

    public function __construct(
        private readonly string $idDominio,
        private readonly string $idA2A,
        string $backofficeUrl,
        ?Client $client = null,
        private ?FlussiRendicontazioniRepository $repo = null
    ) {
        $this->backofficeUrl = rtrim($backofficeUrl, '/');
        $this->client = $client;
    }

    /**
     * Gate di configurazione dello script: messaggio d'errore (exit 1) oppure null se ok.
     */
    public static function configError(string $idDominio, string $backofficeUrl): ?string
    {
        if ($idDominio === '') {
            return 'ERRORE: id_dominio non configurato.';
        }
        if ($backofficeUrl === '') {
            return 'ERRORE: govpay.backoffice_url non configurato.';
        }
        return null;
    }

    /** Il prossimo ciclo ripartira' da ragioneria_scan_da (scan completo) azzerando la finestra salvata. */
    public function requestRescan(): void
    {
        $this->rescanRequested = true;
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $repo = $this->repo ??= new FlussiRendicontazioniRepository();
        $client = $this->client ??= self::buildDefaultClient();
        $idDominio = $this->idDominio;
        $backofficeUrl = $this->backofficeUrl;

        if (!$this->initialized) {
            $this->restoreProgress($ctx);
            $this->initialized = true;
        }

        if ($ctx->shouldStop()) {
            return new JobResult(0, 0, 0, 0, false);
        }
        $this->consumeRescan($ctx);

        $scanDaConfig = self::readScanDaConfig();

        // Se la data configurazione e' cambiata rispetto al progresso salvato, resetta la finestra scorrevole
        $savedScanDa = SettingsRepository::get('backoffice', 'ragioneria_progress_scan_da');
        if ($savedScanDa !== null && $savedScanDa !== '' && $savedScanDa !== $scanDaConfig) {
            $this->rollingFrom = null;
            SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', $scanDaConfig);
            SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', null);
            $ctx->debug('Data configurazione modificata rilevata in esecuzione. Reset rolling window.');
        }

        // Use rolling window when available; fall back to full config date.
        $scanDa = $this->rollingFrom ?? $scanDaConfig;

        $ctx->debug("Ciclo scan da {$scanDa}" . ($this->rollingFrom !== null ? ' [finestra scorrevole]' : ' [scan completo]') . '...');

        $newRows = 0;
        $changedRows = 0;
        $cycleRowsTotal = 0;
        $cycleGovPayYes = 0;
        $page = 1;
        $flussiCount = 0;
        $flussiToScan = [];
        $processed = 0;
        $ok = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        while (true) {
            if ($ctx->shouldStop()) {
                return new JobResult($processed, $ok, $skipped, $errors, ($newRows + $changedRows) > 0, $details);
            }

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
            } catch (\Throwable $e) {
                \Sentry\captureException($e);
                $ctx->debug('Errore lista flussi pagina ' . $page . ': ' . $e->getMessage());
                $errors++;
                $details[] = ['ref' => 'pagina ' . $page, 'reason' => self::safeReason($e)];
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
            $ctx->debug('Pagina ' . $page . ': ' . $pageCount . ' flussi [' . $firstFlusso . ' .. ' . $lastFlusso . ']');

            foreach ($risultati as $flussoItem) {
                if (is_array($flussoItem)) {
                    $flussiToScan[] = $flussoItem;
                }
            }

            $nextPage = RagioneriaFlussoMapper::extractNextPage((string)($payload['prossimiRisultati'] ?? ''));
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
            $ctx->debug('Ordine scansione cronologico (vecchio -> nuovo): [' . $oldest . ' .. ' . $newest . ']');
        }

        $existingFlussi = self::loadExistingFlussiIds($repo->getPdo(), $idDominio);
        $cutoffDate = date('Y-m-d', strtotime('-15 days'));

        $scanIndex = 0;
        foreach ($flussiToScan as $flussoItem) {
            if ($ctx->shouldStop()) {
                return new JobResult($processed, $ok, $skipped, $errors, ($newRows + $changedRows) > 0, $details);
            }

            $scanIndex++;

            $idFlusso = (string)($flussoItem['idFlusso'] ?? '');
            $dominioFlusso = (string)($flussoItem['dominio']['idDominio'] ?? $flussoItem['idDominio'] ?? $idDominio);
            $dataFlussoScan = (string)($flussoItem['dataFlusso'] ?? '');
            if ($idFlusso === '' || $dominioFlusso === '') {
                continue;
            }

            $processed++;

            // Skip flussi already in DB that are old enough to be immutable.
            if (isset($existingFlussi[$idFlusso]) && $dataFlussoScan !== '' && $dataFlussoScan < $cutoffDate) {
                $skipped++;
                continue;
            }

            if ($scanIndex === 1 || $scanIndex % 25 === 0) {
                $ctx->debug(sprintf(
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
            } catch (\Throwable $e) {
                $ctx->debug('Errore dettaglio flusso ' . $idFlusso . ': ' . $e->getMessage());
                $errors++;
                $details[] = ['ref' => $idFlusso, 'reason' => self::safeReason($e)];
                continue;
            }

            if (!is_array($detail)) {
                continue;
            }

            $rows = RagioneriaFlussoMapper::mapFlussoRows($detail, $dominioFlusso, $idFlusso, $this->idA2A);
            if ($rows === []) {
                if ($scanIndex === 1 || $scanIndex % 25 === 0) {
                    $ctx->debug('  [scan] flusso ' . $idFlusso . ': nessuna rendicontazione utile');
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

            $counts = $repo->upsertBatchCounts($rows);
            $affected = $counts['inserted'] + $counts['updated'];
            $newRows += $counts['inserted'];
            $changedRows += $counts['updated'];
            $ok++;
            $ctx->debug(sprintf(
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

        $ctx->debug(sprintf(
            'Ciclo completato. Flussi letti=%d skippati=%d, righe_parse_totali=%d, govpay_si=%d, govpay_no=%d, righe_inserite=%d, righe_aggiornate=%d',
            $flussiCount,
            $skipped,
            $cycleRowsTotal,
            $cycleGovPayYes,
            max(0, $cycleRowsTotal - $cycleGovPayYes),
            $newRows,
            $changedRows
        ));

        // Update rolling window: max synced date - 15 days (overlap to catch late-arriving flussi).
        $maxDate = self::getMaxSyncedFlussoDate($repo->getPdo(), $idDominio);
        if ($maxDate !== null) {
            $this->rollingFrom = date('Y-m-d', strtotime($maxDate . ' -15 days'));
            SettingsRepository::set('backoffice', 'ragioneria_progress_scan_da', $scanDaConfig);
            SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', $this->rollingFrom);
        }

        $hadWork = ($newRows + $changedRows) > 0;
        if ($hadWork) {
            $ctx->debug("Nuovi dati trovati, prossimo ciclo da {$this->rollingFrom} [finestra scorrevole].");
        } else {
            $ctx->debug('Nessun dato nuovo, sleep 30 minuti...');
        }

        return new JobResult($processed, $ok, $skipped, $errors, $hadWork, $details);
    }

    /** Ripristina il progresso della scansione dopo un riavvio (equivale al blocco pre-loop dello script). */
    private function restoreProgress(JobContext $ctx): void
    {
        $savedScanDa = SettingsRepository::get('backoffice', 'ragioneria_progress_scan_da');
        $savedRollingFrom = SettingsRepository::get('backoffice', 'ragioneria_progress_rolling_from');
        $scanDaConfig = self::readScanDaConfig();

        if ($savedScanDa === $scanDaConfig && $savedRollingFrom !== null && $savedRollingFrom !== '') {
            $this->rollingFrom = (string)$savedRollingFrom;
            $ctx->debug("Ripristinato progresso scansione: rollingFrom={$this->rollingFrom} per scan_da={$scanDaConfig}");
        } else {
            $this->rollingFrom = null;
            $ctx->debug("Nessun progresso ripristinabile o data configurazione cambiata. Avvio con scan completo da {$scanDaConfig}");
        }
    }

    private function consumeRescan(JobContext $ctx): void
    {
        if (!$this->rescanRequested) {
            return;
        }
        $this->rescanRequested = false;
        $this->rollingFrom = null;
        try {
            SettingsRepository::set('backoffice', 'ragioneria_progress_rolling_from', null);
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $ctx->debug('Errore reset progress rolling from nel DB: ' . $e->getMessage());
        }
        $ctx->debug('Segnale rescan ricevuto: prossimo ciclo scan completo da data configurazione.');
    }

    private static function readScanDaConfig(): string
    {
        $scanDaConfig = trim((string)SettingsRepository::get('backoffice', 'ragioneria_scan_da', ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scanDaConfig)) {
            $scanDaConfig = date('Y-01-01', strtotime('-1 year'));
        }
        return $scanDaConfig;
    }

    /** Motivo d'errore sicuro per errorDetails: status HTTP se disponibile, mai corpi di risposta upstream. */
    private static function safeReason(\Throwable $e): string
    {
        if ($e instanceof RequestException && $e->getResponse() !== null) {
            return 'HTTP ' . $e->getResponse()->getStatusCode();
        }
        return 'Errore chiamata GovPay';
    }

    /** Most recent data_flusso stored in DB for the given domain, or null if none. */
    private static function getMaxSyncedFlussoDate(\PDO $pdo, string $idDominio): ?string
    {
        $stmt = $pdo->prepare('SELECT MAX(data_flusso) FROM flussi_rendicontazioni WHERE id_dominio = :id_dominio');
        $stmt->execute([':id_dominio' => $idDominio]);
        $value = $stmt->fetchColumn();
        return (is_string($value) && $value !== '') ? substr($value, 0, 10) : null;
    }

    /**
     * Set (id_flusso => true) of flusso IDs already present in DB for the given domain.
     *
     * @return array<string,bool>
     */
    private static function loadExistingFlussiIds(\PDO $pdo, string $idDominio): array
    {
        $stmt = $pdo->prepare('SELECT DISTINCT id_flusso FROM flussi_rendicontazioni WHERE id_dominio = :id_dominio');
        $stmt->execute([':id_dominio' => $idDominio]);
        $ids = [];
        while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
            $ids[(string)$row[0]] = true;
        }
        return $ids;
    }

    private static function buildDefaultClient(): Client
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
}
