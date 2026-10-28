<?php
declare(strict_types=1);

namespace App\Services\Jobs;

use App\Auth\UserRepository;
use App\Config\Config;
use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\RendicontazioneRepository;
use App\Database\UserGroupRepository;
use App\Services\MailerService;
use App\Services\RendicontazioneEngineService;

/**
 * Una iterazione del demone rendicontazione GovPay (corpo del loop di
 * cron_rendicontazione_govpay.php): processaCiclo sulle righe PENDING/ERRORE nella finestra
 * max_giorni_retry, sweep di regolarizzazione dei flussi completi, digest email dopo N cicli
 * consecutivi senza righe nuove.
 *
 * Contatore dei cicli "quieti": prima era una variabile in memoria dello script (azzerata a
 * ogni riavvio del processo); ora e' persistito in settings (rendicontazione.scansioni_senza_novita)
 * per sopravvivere al modello worker. E' l'unica differenza di comportamento voluta.
 *
 * Pausa tra i cicli, stop file, bootstrap e check entity.id_dominio/id_a2a restano nello script.
 * Con govpay.backoffice_url vuoto la iterazione non fa nulla (nessuna variazione del contatore).
 * Il job non rilancia le eccezioni dei sotto-passi (come il loop originale): le segnala in
 * errorDetails (solo id/fase, mai testi upstream).
 */
final class RendicontazioneJob
{
    public const COUNTER_SECTION = 'rendicontazione';
    public const COUNTER_KEY = 'scansioni_senza_novita';

    private \PDO $pdo;

    /** @var callable():RendicontazioneEngineService */
    private $engineFactory;

    /**
     * @param callable():RendicontazioneEngineService $engineFactory creato a ogni iterazione
     *        (come lo script originale: il client GovPay rilegge le credenziali dai settings)
     */
    public function __construct(
        private readonly RendicontazioneRepository $repo,
        callable $engineFactory,
        private readonly UserGroupRepository $groupRepo,
        private readonly UserRepository $userRepo,
        private readonly MailerService $mailer,
        private readonly string $idDominio,
        private readonly string $idA2A,
        ?\PDO $pdo = null
    ) {
        $this->engineFactory = $engineFactory;
        $this->pdo = $pdo ?? Connection::getPDO();
    }

    public function runOnce(JobContext $ctx): JobResult
    {
        $backofficeUrl = (string)SettingsRepository::get('govpay', 'backoffice_url', '');
        $soglia = max(1, (int)SettingsRepository::get('rendicontazione', 'scansioni_quiete_soglia', '3'));
        $maxGiorniRetry = max(1, (int)SettingsRepository::get('rendicontazione', 'max_giorni_retry', '14'));
        $minDataPagamento = date('Y-m-d', strtotime("-{$maxGiorniRetry} days"));
        $geriMaxTentativi = max(1, (int)SettingsRepository::get('rendicontazione', 'geri_max_tentativi', '3'));

        if ($backofficeUrl === '') {
            $ctx->debug('govpay.backoffice_url non configurato.');
            return new JobResult(0, 0, 0, 0, false);
        }

        $details = [];
        $processate = 0;
        $counterStart = $this->readCounter();
        $counter = $counterStart;

        try {
            $engine = ($this->engineFactory)();
            $result = $engine->processaCiclo($this->idDominio, $this->idA2A, $backofficeUrl, 2000, $minDataPagamento, $geriMaxTentativi);
            $processate = (int)$result['processate'];
            $ctx->debug(sprintf('Ciclo: processate=%d nuove=%d', $result['processate'], $result['nuove']));

            // Sweep per tentare/ritentare la regolarizzazione di tutti i flussi completi non ancora regolarizzati
            try {
                $flussiDaRegolarizzare = $this->repo->getFlussiDaRegolarizzare($this->idDominio);
                if (!empty($flussiDaRegolarizzare)) {
                    $ctx->debug(sprintf('Trovati %d flussi completati da regolarizzare in sweep...', count($flussiDaRegolarizzare)));
                    foreach ($flussiDaRegolarizzare as $flussoId) {
                        if ($ctx->shouldStop()) {
                            return $this->result($processate, $details);
                        }
                        $rigaFlusso = $this->repo->getUnaRigaPerFlusso($this->idDominio, $flussoId);
                        if ($rigaFlusso) {
                            $engine->controllaERegolarizzaFlussoPerRiga($this->idDominio, (int)$rigaFlusso['id'], $backofficeUrl);
                        }
                    }
                }
            } catch (\Throwable $e) {
                \Sentry\captureException($e);
                $ctx->debug('ERRORE sweep regolarizzazione flussi: ' . $e->getMessage());
                $details[] = ['ref' => 'sweep', 'reason' => 'Errore sweep regolarizzazione flussi'];
            }

            if ($result['nuove'] > 0) {
                $counter = 0;
            } else {
                $counter++;
            }
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            $ctx->debug('ERRORE ciclo processaCiclo: ' . $e->getMessage());
            $details[] = ['ref' => 'ciclo', 'reason' => 'Errore ciclo processaCiclo'];
            $counter++;
        }

        if ($ctx->shouldStop()) {
            $this->writeCounter($counter, $counterStart);
            return $this->result($processate, $details);
        }

        if ($counter >= $soglia) {
            try {
                $this->sendDigest($ctx, $details);
            } catch (\Throwable $e) {
                \Sentry\captureException($e);
                $ctx->debug('ERRORE invio digest: ' . $e->getMessage());
                $details[] = ['ref' => 'digest', 'reason' => 'Errore invio digest'];
            }
            $counter = 0;
        }

        $this->writeCounter($counter, $counterStart);
        return $this->result($processate, $details);
    }

    /** @param list<array{ref:string,reason:string}> $details */
    private function result(int $processate, array $details): JobResult
    {
        return new JobResult($processate, $processate, 0, count($details), $processate > 0, $details);
    }

    /** @param list<array{ref:string,reason:string}> $details */
    private function sendDigest(JobContext $ctx, array &$details): void
    {
        $nonNotificate = $this->repo->getNonNotificate($this->idDominio);
        if (empty($nonNotificate)) {
            return;
        }

        $daConfermare = array_values(array_filter($nonNotificate, static fn($r) => $r['rendicontazione_stato'] === 'IN_ATTESA_CONFERMA'));
        $gestite      = array_values(array_filter($nonNotificate, static fn($r) => $r['rendicontazione_stato'] !== 'IN_ATTESA_CONFERMA'));

        $righeOperatoreInviate = 0;
        // Id delle righe effettivamente incluse in un digest INVIATO (operatore o admin).
        // Solo queste vengono marcate rendicontazione_notificato=1: una riga il cui
        // cod_entrata non matcha alcun gruppo (o GESTITO con notifica_admin_auto=false)
        // resta non marcata e ricompare a ogni ciclo finche' la configurazione non la copre.
        $idsNotificati = [];

        $baseUrlVista = rtrim((string)Config::get('BACKOFFICE_PUBLIC_BASE_URL', ''), '/') . '/rendicontazione/da-confermare';

        // Digest per gruppo: si assume una risoluzione gruppo->tipologie già a carico
        // della UI (Task 9); qui si notificano tutti i destinatari via query diretta.
        $globalEmails = $this->userRepo->getGlobalNotificationEmails();

        $stmtGruppi = $this->pdo->prepare(
            'SELECT DISTINCT group_id FROM rendicontazione_gruppo_tipologie WHERE id_dominio = :dom'
        );
        $stmtGruppi->execute([':dom' => $this->idDominio]);
        foreach ($stmtGruppi->fetchAll(\PDO::FETCH_ASSOC) as $g) {
            $groupId = (int)$g['group_id'];
            $tipologie = $this->groupRepo->getRendicontazioneTipologie($groupId, $this->idDominio);
            $entrateSoloNotifica = array_column(array_filter($tipologie, static fn($t) => $t['modalita'] === 'SOLO_NOTIFICA'), 'id_entrata');
            $entrateSmarcatura   = array_column(array_filter($tipologie, static fn($t) => $t['modalita'] === 'NOTIFICA_E_SMARCATURA'), 'id_entrata');

            $righeDaConfermareGruppo = array_values(array_filter($daConfermare, static fn($r) => in_array((string)($r['cod_entrata'] ?? ''), $entrateSmarcatura, true)));
            $righeInformativeGruppo  = array_values(array_filter($gestite, static fn($r) => in_array((string)($r['cod_entrata'] ?? ''), $entrateSoloNotifica, true)));

            if (empty($righeDaConfermareGruppo) && empty($righeInformativeGruppo)) {
                continue;
            }

            $emails = $this->groupRepo->getMemberEmails($groupId);
            if (!empty($globalEmails)) {
                $emails = array_unique(array_merge($emails, $globalEmails));
            }
            $groupInfo = $this->groupRepo->findById($groupId);
            $esitoOperatore = $this->mailer->sendRendicontazioneOperatoreDigest(
                $emails,
                (string)($groupInfo['nome'] ?? "Gruppo {$groupId}"),
                $righeDaConfermareGruppo,
                $righeInformativeGruppo,
                $baseUrlVista
            );
            if (($esitoOperatore['esito'] ?? '') === 'OK') {
                $righeOperatoreInviate += count($righeDaConfermareGruppo) + count($righeInformativeGruppo);
                foreach (array_merge($righeDaConfermareGruppo, $righeInformativeGruppo) as $r) {
                    $idsNotificati[(int)$r['id']] = true;
                }
            } elseif (($esitoOperatore['esito'] ?? '') === 'ERRORE') {
                $ctx->debug('ERRORE invio digest operatore: ' . ($esitoOperatore['errore'] ?? 'Errore sconosciuto'));
                $details[] = ['ref' => 'gruppo-' . $groupId, 'reason' => 'Invio digest operatore fallito'];
            }
        }

        $notificaAdmin = (string)SettingsRepository::get('rendicontazione', 'notifica_admin_auto', 'false') === 'true';
        $righeAdminInviate = 0;
        if ($notificaAdmin) {
            $rawAdminEmails = (string)SettingsRepository::get('rendicontazione', 'admin_emails', '');
            $adminEmails = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $rawAdminEmails))));
            if (!empty($adminEmails) && !empty($gestite)) {
                $esitoAdmin = $this->mailer->sendRendicontazioneAdminDigest(array_values($adminEmails), $gestite);
                if (($esitoAdmin['esito'] ?? '') === 'OK') {
                    $righeAdminInviate = count($gestite);
                    foreach ($gestite as $r) {
                        $idsNotificati[(int)$r['id']] = true;
                    }
                } elseif (($esitoAdmin['esito'] ?? '') === 'ERRORE') {
                    $ctx->debug('ERRORE invio digest admin: ' . ($esitoAdmin['errore'] ?? 'Errore sconosciuto'));
                    $details[] = ['ref' => 'admin', 'reason' => 'Invio digest admin fallito'];
                }
            }
        }

        $this->repo->marcaNotificate(array_keys($idsNotificati));
        $this->pdo->prepare(
            'INSERT INTO rendicontazione_digest_log (righe_operatore, righe_admin) VALUES (:ro, :ra)'
        )->execute([':ro' => $righeOperatoreInviate, ':ra' => $righeAdminInviate]);

        $ctx->debug(sprintf('Digest inviato: operatore=%d admin=%d', $righeOperatoreInviate, $righeAdminInviate));
    }

    /** Letto direttamente da DB (non dalla cache di SettingsRepository, stale in un processo longevo). */
    private function readCounter(): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE section = :s AND key_name = :k');
            $stmt->execute([':s' => self::COUNTER_SECTION, ':k' => self::COUNTER_KEY]);
            $v = $stmt->fetchColumn();
            return $v === false ? 0 : max(0, (int)$v);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function writeCounter(int $value, int $previous): void
    {
        if ($value === $previous) {
            return;
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO settings (section, key_name, value, encrypted) VALUES (:s, :k, :v, 0)
                 ON DUPLICATE KEY UPDATE value = :v2'
            )->execute([':s' => self::COUNTER_SECTION, ':k' => self::COUNTER_KEY, ':v' => (string)$value, ':v2' => (string)$value]);
        } catch (\Throwable $e) {
            // Best-effort come la lettura: un errore DB sul contatore non deve interrompere il ciclo.
            \App\Logger::getInstance()->warning('Rendicontazione: salvataggio contatore cicli quieti fallito', ['error' => $e->getMessage()]);
        }
    }
}
