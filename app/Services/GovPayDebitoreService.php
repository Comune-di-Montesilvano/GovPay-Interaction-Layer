<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\FlussiRendicontazioniRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

/**
 * Recupera dati debitore da GovPay Backoffice API per IUR is_govpay=1
 * e li salva direttamente in biz_ricevute come PROCESSED.
 * Usato da cron_govpay_debitore_scanner.php.
 */
class GovPayDebitoreService
{
    public function __construct(
        private readonly BizRepository $bizRepo,
        private readonly FlussiRendicontazioniRepository $flussiRepo,
        private readonly ?Client $http = null
    ) {}

    public function countPending(string $idDominio, ?string $minDate = null): int
    {
        return $this->flussiRepo->countUnprocessedGovPayForDebitore($idDominio, $minDate);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getBatch(string $idDominio, int $limit, ?string $minDate = null): array
    {
        return $this->flussiRepo->getUnprocessedGovPayForDebitore($idDominio, $limit, $minDate);
    }

    /**
     * Chiama GovPay Backoffice API per un singolo IUR e salva debitore in biz_ricevute.
     * @param array<string,mixed> $row  Riga da getUnprocessedGovPayForDebitore
     * @return array{status:string,reason:string}
     */
    public function enrichOne(array $row): array
    {
        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        $idA2A         = SettingsRepository::get('entity', 'id_a2a', '');
        $idPendenza    = trim((string)($row['id_pendenza'] ?? ''));

        if ($backofficeUrl === '' || $idA2A === '' || $idPendenza === '') {
            return ['status' => 'SKIP', 'reason' => 'Config mancante (backoffice_url, id_a2a o id_pendenza vuoti)'];
        }

        $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);

        try {
            $resp = $this->buildClient()->request('GET', $url, ['headers' => ['Accept' => 'application/json']]);
            $data = json_decode((string)$resp->getBody(), true);
        } catch (ClientException $e) {
            $code = $e->getResponse()->getStatusCode();
            if ($code === 404) {
                // Esito definitivo: NOT_FOUND (mai ritentato), distinto da PROCESSED con dati.
                $this->bizRepo->insertGovPayProcessed($this->baseRecord($row), 'NOT_FOUND');
                return ['status' => 'SKIPPED', 'reason' => 'Pendenza non trovata in GovPay (404)'];
            }
            return $this->fail($row, "HTTP $code: " . mb_substr($e->getMessage(), 0, 200));
        } catch (\Throwable $e) {
            return $this->fail($row, mb_substr($e->getMessage(), 0, 200));
        }

        if (!is_array($data)) {
            return $this->fail($row, 'Risposta GovPay non valida (non JSON array)');
        }

        $soggetto    = is_array($data['soggettoPagatore'] ?? null) ? $data['soggettoPagatore'] : null;
        $cfDebitore  = null;
        $nomDebitore = null;
        if ($soggetto !== null) {
            $v = trim((string)($soggetto['identificativo'] ?? ''));
            $cfDebitore = $v !== '' ? mb_substr($v, 0, 35) : null;
            $v = trim((string)($soggetto['anagrafica'] ?? ''));
            $nomDebitore = $v !== '' ? mb_substr($v, 0, 255) : null;
        }

        $causale     = trim((string)($data['causale'] ?? $data['nome'] ?? ''));
        $descrizione = $causale !== '' ? mb_substr($causale, 0, 512) : null;

        $this->bizRepo->insertGovPayProcessed([
            ...$this->baseRecord($row),
            'descrizione'         => $descrizione,
            'cf_debitore'         => $cfDebitore,
            'nominativo_debitore' => $nomDebitore,
        ]);

        return ['status' => 'PROCESSED', 'reason' => ''];
    }

    /**
     * Registra un tentativo fallito (riga biz_ricevute ERROR + timestamp): la riga esce dalla coda
     * per FlussiRendicontazioniRepository::DEBITORE_ERROR_RETRY_MINUTES, poi viene ritentata.
     * @param array<string,mixed> $row
     * @param string $safeReason motivo gia' ripulito (mai corpi/messaggi upstream)
     */
    public function recordError(array $row, string $safeReason): void
    {
        $this->bizRepo->upsertGovPayError($this->baseRecord($row), $safeReason);
    }

    /** Riduce il motivo d'errore a testo fisso/status code: mai corpi di risposta upstream. */
    public static function safeReason(string $reason): string
    {
        if (preg_match('/^HTTP (\d{3}):/', $reason, $m) === 1) {
            return 'HTTP ' . $m[1];
        }
        if (str_starts_with($reason, 'Risposta GovPay non valida')) {
            return 'Risposta GovPay non valida';
        }
        return 'Errore chiamata GovPay';
    }

    /**
     * @param array<string,mixed> $row
     * @return array{status:string,reason:string}
     */
    private function fail(array $row, string $reason): array
    {
        $this->recordError($row, self::safeReason($reason));
        return ['status' => 'ERROR', 'reason' => $reason];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function baseRecord(array $row): array
    {
        return [
            'id_dominio'          => (string)$row['id_dominio'],
            'anno'                => (int)$row['anno'],
            'mese'                => (int)$row['mese'],
            'id_flusso'           => $row['id_flusso'] ?? null,
            'iur'                 => (string)$row['iur'],
            'iuv'                 => $row['iuv'] ?? null,
            'data_pagamento'      => $row['data_pagamento'] ?? null,
            'importo'             => isset($row['importo']) ? (float)$row['importo'] : null,
            'descrizione'         => null,
            'cf_debitore'         => null,
            'nominativo_debitore' => null,
        ];
    }

    private function buildClient(): Client
    {
        // Client iniettato (test): bypassa factory/credenziali.
        if ($this->http !== null) {
            return $this->http;
        }

        $opts = [
            'connect_timeout' => 5,
            'timeout'         => 20,
        ];

        $username = SettingsRepository::get('govpay', 'user', '');
        $password = SettingsRepository::get('govpay', 'password', '');
        if ($username !== '' && $password !== '') {
            $opts['auth'] = [$username, $password];
        }

        return GovPayClientFactory::makeBackofficeClient($opts);
    }
}
