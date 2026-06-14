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
        private readonly FlussiRendicontazioniRepository $flussiRepo
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
                $this->bizRepo->insertGovPayProcessed($this->baseRecord($row));
                return ['status' => 'SKIPPED', 'reason' => 'Pendenza non trovata in GovPay (404)'];
            }
            return ['status' => 'ERROR', 'reason' => "HTTP $code: " . mb_substr($e->getMessage(), 0, 200)];
        } catch (\Throwable $e) {
            return ['status' => 'ERROR', 'reason' => mb_substr($e->getMessage(), 0, 200)];
        }

        if (!is_array($data)) {
            return ['status' => 'ERROR', 'reason' => 'Risposta GovPay non valida (non JSON array)'];
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
