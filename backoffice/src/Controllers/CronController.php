<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\BizRepository;
use App\Database\FlussiRendicontazioniRepository;
use App\Database\TefaRepository;
use App\Logger;
use App\Services\Jobs\JobControl;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Pagina Cron / Servizi: i lavori periodici girano nel container `worker` (gil_jobs). Qui solo
 * flag abilitazione, "esegui ora", storico esecuzioni, rescan ragioneria, riprova massive.
 */
class CronController
{
    private const JOBS = [
        'biz' => [
            'label'       => 'Biz: Scanner ricevute',
            'description' => 'Accoda pendenze non-GovPay da flussi ragioneria, chiama Biz Events e salva descrizione/soggetto pagante/trasferimenti in biz_ricevute. Funziona per qualsiasi ente con Biz Events configurato.',
            'icon'        => 'fa-receipt',
        ],
        'tefa' => [
            'label'       => 'TEFA: Classificatore',
            'description' => 'Accoda IUR da biz_ricevute (PROCESSED) e li classifica come TEFA/non-TEFA. Richiede il servizio Biz attivo.',
            'icon'        => 'fa-building-columns',
        ],
        'ragioneria' => [
            'label'       => 'Ragioneria: sincronizza flussi',
            'description' => 'Scarica i flussi FDR da GovPay dalla data impostata e li salva in DB. Report ragioneria e TEFA leggono da qui.',
            'icon'        => 'fa-database',
        ],
        'pendenze-massive' => [
            'label'       => 'Pendenze massive: elabora batch',
            'description' => 'Prende in carico le pendenze massive in stato PENDING e le invia a GovPay (3 nuovi tentativi sugli errori temporanei). In pausa le righe già prese tornano in coda senza chiamate GovPay.',
            'icon'        => 'fa-list-check',
        ],
        'mapping' => [
            'label'       => 'Ragioneria: Mapping L1 (fornitore)',
            'description' => 'Scopre i prefissi IUV (ogni 60 s) e assegna il fornitore alle pendenze esterne (Livello 1). Solo pattern con ≥5 transazioni sono usati. Deve precedere il servizio Vocab.',
            'icon'        => 'fa-route',
        ],
        'vocab' => [
            'label'       => 'Ragioneria: Vocab L2 (tipologia)',
            'description' => 'Classifica le pendenze già identificate da L1 tramite vocabolario di keyword per assegnare la tipologia contabile (cod_entrata). Richiede il servizio Mapping L1 attivo.',
            'icon'        => 'fa-book-open',
        ],
        'govpay-debitore' => [
            'label'       => 'GovPay: Scanner debitore',
            'description' => 'Recupera dati debitore (CF/nominativo, causale) da GovPay Backoffice API per le pendenze interne (is_govpay=1) e li salva in biz_ricevute, per il CSV ragioneria.',
            'icon'        => 'fa-user-check',
        ],
        'rendicontazione-govpay' => [
            'label'       => 'Rendicontazione: motore GovPay',
            'description' => 'Instrada le pendenze GovPay dei flussi non rendicontati (smarcatura automatica, manuale operatore, o handoff Geri/Dilazione via bridge legacy) e invia i digest mail.',
            'icon'        => 'fa-scale-balanced',
        ],
    ];

    private ?JobControl $control;

    public function __construct(private readonly Twig $twig, ?JobControl $control = null)
    {
        $this->control = $control;
    }

    private function control(): JobControl
    {
        return $this->control ??= JobControl::default();
    }

    public static function getJobs(): array
    {
        return self::JOBS;
    }

    /**
     * Compatibilita' (tab Impostazioni, mapping pendenze, report TEFA): "attivo" = lavoro
     * abilitato in gil_jobs (lo esegue il container worker), non piu' un processo con PID.
     */
    public static function isDaemonRunning(string $jobKey): bool
    {
        if (!isset(self::JOBS[$jobKey])) {
            return false;
        }
        try {
            return JobControl::default()->isEnabled($jobKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Stato dei lavori per pagina Cron e Services Hub; vuoto (tutti in pausa) se il DB non
     * risponde o le tabelle gil_jobs/lock_keys mancano ancora.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function jobStatuses(?JobControl $control = null): array
    {
        try {
            return ($control ?? JobControl::default())->statuses();
        } catch (\Throwable $e) {
            Logger::getInstance()->warning('Stato lavori non disponibile: ' . $e->getMessage());
            return [];
        }
    }

    public function index(Request $request, Response $response): Response
    {
        $this->requireAuth();
        $this->exposeCurrentUser();

        $idDominio = SettingsRepository::get('entity', 'id_dominio', '');

        $ragioneriaScanDa = SettingsRepository::get(
            'backoffice',
            'ragioneria_scan_da',
            date('Y-01-01', strtotime('-1 year'))
        );

        return $this->twig->render($response, 'funzioni-avanzate/cron.html.twig', [
            'jobs' => self::JOBS,
            'job_status' => self::jobStatuses($this->control()),
            'ragioneria_scan_da' => $ragioneriaScanDa,
            'id_dominio' => $idDominio,
        ]);
    }

    /** "Esegui ora": accoda un'esecuzione manuale al worker. */
    public function run(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrSuperadmin();

        $jobKey = (string)($args['job'] ?? '');
        if (!isset(self::JOBS[$jobKey])) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Job non trovato'], 404);
        }

        try {
            $reason = $this->control()->runNow($jobKey);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Esegui ora fallito per ' . $jobKey . ': ' . $e->getMessage());
            return $this->jsonResponse($response, ['ok' => false, 'error' => "Impossibile accodare l'esecuzione (database non raggiungibile?)."], 500);
        }
        if ($reason !== null) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => $reason], 409);
        }

        return $this->jsonResponse($response, [
            'ok' => true,
            'message' => 'Esecuzione richiesta: il worker la avvia entro pochi secondi (se un giro è già in corso, parte al suo termine).',
        ]);
    }

    /** Abilita (enabled=1) o mette in pausa (enabled=0) il lavoro. */
    public function setEnabled(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrSuperadmin();

        $jobKey = (string)($args['job'] ?? '');
        if (!isset(self::JOBS[$jobKey])) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Job non trovato'], 404);
        }
        $body = (array)($request->getParsedBody() ?? []);
        $raw = (string)($body['enabled'] ?? '');
        if ($raw !== '0' && $raw !== '1') {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Parametro enabled mancante (0 o 1).'], 400);
        }
        return $this->applyEnabled($response, $jobKey, $raw === '1');
    }

    /** Compatibilita': il vecchio "Ferma" = pausa. */
    public function stop(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrSuperadmin();

        $jobKey = (string)($args['job'] ?? '');
        if (!isset(self::JOBS[$jobKey])) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Job non trovato'], 404);
        }
        return $this->applyEnabled($response, $jobKey, false);
    }

    private function applyEnabled(Response $response, string $jobKey, bool $enabled): Response
    {
        try {
            $this->control()->setEnabled($jobKey, $enabled);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Cambio stato fallito per ' . $jobKey . ': ' . $e->getMessage());
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Impossibile salvare lo stato del servizio.'], 500);
        }
        return $this->jsonResponse($response, [
            'ok' => true,
            'enabled' => $enabled,
            'message' => $enabled
                ? 'Servizio attivato: parte entro pochi secondi.'
                : 'Servizio in pausa: un giro in corso termina dopo il batch corrente.',
        ]);
    }

    public function setScanDate(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $body = (array)($request->getParsedBody() ?? []);
        $date = trim((string)($body['ragioneria_scan_da'] ?? ''));

        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            SettingsRepository::set('backoffice', 'ragioneria_scan_da', $date);
        }
        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Data inizio scansione salvata.'];

        return $response
            ->withHeader('Location', '/funzioni-avanzate/cron')
            ->withStatus(302);
    }

    public function resetDateRange(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $body = (array)($request->getParsedBody() ?? []);
        $dataDa = trim((string)($body['reset_data_da'] ?? ''));
        $dataA = trim((string)($body['reset_data_a'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDa) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataA)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Date non valide. Usa formato YYYY-MM-DD.'];
            return $response->withHeader('Location', '/funzioni-avanzate/cron')->withStatus(302);
        }

        if ($dataDa > $dataA) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Intervallo non valido: la data Da deve essere <= data A.'];
            return $response->withHeader('Location', '/funzioni-avanzate/cron')->withStatus(302);
        }

        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        if ($idDominio === '') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'id_dominio non configurato.'];
            return $response->withHeader('Location', '/funzioni-avanzate/cron')->withStatus(302);
        }

        $flussiRepo = new FlussiRendicontazioniRepository();
        $tefaRepo   = new TefaRepository();
        $bizRepo    = new BizRepository();

        $deletedBiz        = $bizRepo->deleteByDateRange($idDominio, $dataDa, $dataA);
        $deletedTefa       = $tefaRepo->deleteByDateRange($idDominio, $dataDa, $dataA);
        $deletedRagioneria = $flussiRepo->deleteByDateRange($idDominio, $dataDa, $dataA);

        $_SESSION['flash'][] = [
            'type' => 'success',
            'text' => sprintf(
                'Reset completato (%s -> %s). Cancellate %d righe Biz, %d righe TEFA e %d righe Ragioneria.',
                $dataDa,
                $dataA,
                $deletedBiz,
                $deletedTefa,
                $deletedRagioneria
            ),
        ];

        return $response
            ->withHeader('Location', '/funzioni-avanzate/cron')
            ->withStatus(302);
    }

    public function forceRescan(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();

        try {
            $reason = $this->control()->requestRagioneriaRescan();
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Richiesta rescan ragioneria fallita: ' . $e->getMessage());
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Impossibile registrare la richiesta di rescan.'], 500);
        }
        if ($reason !== null) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => $reason], 400);
        }

        return $this->jsonResponse($response, ['ok' => true, 'message' => 'Rescan richiesto: il prossimo giro (entro pochi secondi, o al termine di quello in corso) riscansionerà tutti i flussi dalla data configurata.']);
    }

    /** Storico esecuzioni (riepilogo + primi errori) da gil_job_runs / gil_job_run_errors. */
    public function log(Request $request, Response $response, array $args): Response
    {
        $this->requireAuth();

        $jobKey = (string)($args['job'] ?? '');
        if (!isset(self::JOBS[$jobKey])) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Job non trovato'], 404);
        }

        try {
            return $this->jsonResponse($response, ['ok' => true, 'content' => $this->control()->logText($jobKey)]);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Lettura storico lavori fallita per ' . $jobKey . ': ' . $e->getMessage());
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Storico esecuzioni non disponibile.'], 500);
        }
    }

    /** "Riprova" di una riga pendenze massive in errore. */
    public function retryMassiveRow(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrSuperadmin();

        $rowId = (int)($args['rowId'] ?? 0);
        try {
            $result = $this->control()->retryMassiveRow($rowId);
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Riprova riga massiva ' . $rowId . ' fallita: ' . $e->getMessage());
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Impossibile rimettere in coda la riga.'], 500);
        }
        if (!$result['ok']) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => $result['message']], 409);
        }
        return $this->jsonResponse($response, ['ok' => true, 'message' => $result['message']]);
    }

    private function requireAuth(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['user'])) {
            header('Location: /login');
            exit;
        }
    }

    private function requireAdminOrSuperadmin(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            header('Location: /login');
            exit;
        }
        if (!in_array($user['role'] ?? '', ['admin', 'superadmin'], true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Accesso negato — richiesto ruolo amministrativo']);
            exit;
        }
    }

    private function exposeCurrentUser(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (isset($_SESSION['user'])) {
            $this->twig->getEnvironment()->addGlobal('current_user', $_SESSION['user']);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response = $response->withStatus($status)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
