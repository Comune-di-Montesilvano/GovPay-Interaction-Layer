<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\Connection;
use App\Database\MappingPendenzeRepository;
use App\Controllers\CronController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class MappingPendenzeController
{
    public function __construct(private readonly Twig $twig)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->requireAuth();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $repo = new MappingPendenzeRepository();

        try {
            $repo->discoverPatterns($idDominio);
        } catch (\Throwable $_) {}

        $patterns = [];
        try {
            $patterns = $repo->getRules($idDominio);
        } catch (\Throwable $_) {}

        $stats = [
            'PENDING'   => ['count' => 0, 'amount' => 0.0],
            'PROCESSED' => ['count' => 0, 'amount' => 0.0],
            'NO_MATCH'  => ['count' => 0, 'amount' => 0.0],
            'total'     => ['count' => 0, 'amount' => 0.0],
            'vocab'     => [
                'PENDING'   => ['count' => 0, 'amount' => 0.0],
                'PROCESSED' => ['count' => 0, 'amount' => 0.0],
                'NO_MATCH'  => ['count' => 0, 'amount' => 0.0],
            ],
        ];
        try {
            $stats = $repo->getMappingStats($idDominio);
        } catch (\Throwable $_) {}

        $tipologieList = [];
        try {
            $pdo  = Connection::getPDO();
            $stmt = $pdo->prepare("SELECT id_entrata, descrizione FROM entrate_tipologie WHERE id_dominio = :dom ORDER BY descrizione ASC");
            $stmt->execute([':dom' => $idDominio]);
            $tipologieList = $stmt->fetchAll() ?: [];
        } catch (\Throwable $_) {}

        $tipologieCustom = [];
        try {
            $tipologieCustom = $repo->getCustomTipologie($idDominio);
        } catch (\Throwable $_) {}

        $daemonRunning      = CronController::isDaemonRunning('mapping');
        $daemonVocabRunning = CronController::isDaemonRunning('vocab');

        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $this->twig->render($response, 'pagamenti/mapping_pendenze.html.twig', [
            'id_dominio'          => $idDominio,
            'patterns'            => $patterns,
            'stats'               => $stats,
            'tipologie_list'      => $tipologieList,
            'tipologie_custom'    => $tipologieCustom,
            'daemon_running'      => $daemonRunning,
            'daemon_vocab_running'=> $daemonVocabRunning,
            'flash'               => $flash,
            'current_user'        => $user,
        ]);
    }

    public function addRule(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $body       = (array)($request->getParsedBody() ?? []);
        $patternIuv = strtoupper(trim((string)($body['pattern_iuv'] ?? '')));
        $fornitore  = trim((string)($body['fornitore'] ?? ''));
        $codEntrata = trim((string)($body['cod_entrata'] ?? ''));

        if ($patternIuv === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore: il prefisso IUV è obbligatorio.'];
            return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
        }
        if ($fornitore === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore: specificare il fornitore.'];
            return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
        }

        $repo = new MappingPendenzeRepository();
        try {
            $repo->savePatternRule($idDominio, $patternIuv, $fornitore, $codEntrata !== '' ? $codEntrata : null, 1);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => "Regola prefisso IUV '{$patternIuv}' salvata con successo."];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore nel salvataggio della regola: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    public function addVocabRule(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $body       = (array)($request->getParsedBody() ?? []);
        $patternIuv = strtoupper(trim((string)($body['pattern_iuv'] ?? '')));
        $keyword    = trim((string)($body['keyword'] ?? ''));
        $codEntrata = trim((string)($body['cod_entrata'] ?? ''));
        $priorita   = max(0, min(255, (int)($body['priorita'] ?? 10)));

        if ($patternIuv === '' || $keyword === '' || $codEntrata === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore: prefisso IUV, keyword e tipologia sono obbligatori.'];
            return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
        }

        $repo = new MappingPendenzeRepository();
        try {
            $repo->addVocabRule($idDominio, $patternIuv, $keyword, $codEntrata, $priorita);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => "Keyword vocabolario '{$keyword}' aggiunta al pattern '{$patternIuv}'."];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore nel salvataggio della keyword: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    public function deleteRule(Request $request, Response $response, array $args): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $params = $request->getQueryParams();
        $type   = (string)($params['type'] ?? '');
        $repo   = new MappingPendenzeRepository();

        try {
            if ($type === 'pattern') {
                $patternIuv = (string)($params['pattern_iuv'] ?? '');
                if ($patternIuv !== '') {
                    $repo->deletePatternRule($patternIuv, $idDominio);
                    $_SESSION['flash'][] = ['type' => 'success', 'text' => "Pattern IUV '{$patternIuv}' rimosso con successo."];
                }
            } elseif ($type === 'vocab') {
                $id = (int)($params['id'] ?? 0);
                if ($id > 0) {
                    $repo->deleteVocabRule($id, $idDominio);
                    $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Keyword vocabolario rimossa con successo.'];
                }
            }
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore nell\'eliminazione: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    public function applyMappings(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $repo = new MappingPendenzeRepository();
        try {
            // Reset completo prima di riapplicare (idempotente)
            $repo->resetAllMappings($idDominio);

            // Aggiorna transazioni_count prima di filtrare per >= 5
            try { $repo->discoverPatterns($idDominio); } catch (\Throwable $_) {}

            // L1: patterns ordinati longest-first da getRules
            $rules = $repo->getRules($idDominio);
            $activeRules = array_filter($rules, fn(array $r): bool =>
                (!empty($r['fornitore']) && ((bool)($r['is_custom'] ?? false) || (int)($r['transazioni_count'] ?? 0) >= 5)) ||
                (!empty($r['accorpato_a']) && !empty($r['fornitore']))
            );

            $l1Assigned = 0;
            foreach ($activeRules as $rule) {
                $l1Assigned += $repo->bulkAssignL1($idDominio, (string)$rule['pattern_iuv'], (string)$rule['fornitore']);
            }
            $l1NoMatch = $repo->bulkSetL1NoMatch($idDominio);

            // L2: keyword bulk updates poi fallback default per ogni pattern
            $vocabIndex = $repo->getVocabRules($idDominio);
            $l2Assigned = 0;
            foreach ($vocabIndex as $patternIuv => $patternData) {
                foreach ($patternData['keywords'] ?? [] as $kw) {
                    $keyword    = trim((string)($kw['keyword'] ?? ''));
                    $codEntrata = trim((string)($kw['cod_entrata'] ?? ''));
                    if ($keyword !== '' && $codEntrata !== '') {
                        $l2Assigned += $repo->bulkAssignVocabKeyword($idDominio, (string)$patternIuv, $keyword, $codEntrata);
                    }
                }
                $defaultCod = $patternData['default_cod_entrata'] ?? null;
                if ($defaultCod !== null && $defaultCod !== '') {
                    $l2Assigned += $repo->bulkAssignVocabDefault($idDominio, (string)$patternIuv, $defaultCod);
                }
            }
            $l2NoMatch = $repo->bulkSetVocabNoMatch($idDominio);

            $_SESSION['flash'][] = [
                'type' => 'success',
                'text' => "Mapping applicato. L1: {$l1Assigned} assegnate, {$l1NoMatch} NO_MATCH. L2: {$l2Assigned} classificate, {$l2NoMatch} NO_MATCH.",
            ];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore durante l\'applicazione del mapping: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    public function resetMappings(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $repo = new MappingPendenzeRepository();
        try {
            $count = $repo->resetAllMappings($idDominio);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => "Reset completato (L1 + L2). Messa in coda la rielaborazione di {$count} pendenze esterne."];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore durante il reset delle mappature: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    public function accorpaRule(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $body       = (array)($request->getParsedBody() ?? []);
        $patternIuv = strtoupper(trim((string)($body['pattern_iuv'] ?? '')));
        $accorpatoA = strtoupper(trim((string)($body['accorpato_a'] ?? '')));

        if ($patternIuv === '' || $accorpatoA === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore: specificare sia il pattern sorgente che quello di destinazione.'];
            return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
        }

        if ($patternIuv === $accorpatoA) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore: non puoi accorpare un pattern a se stesso.'];
            return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
        }

        $repo = new MappingPendenzeRepository();
        try {
            $repo->accorpaPattern($idDominio, $patternIuv, $accorpatoA);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => "Pattern '{$patternIuv}' accorpato con successo a '{$accorpatoA}'."];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore durante l\'accorpamento: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    public function disunisciRule(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $params     = $request->getQueryParams();
        $patternIuv = strtoupper(trim((string)($params['pattern_iuv'] ?? '')));

        if ($patternIuv === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore: specificare il pattern.'];
            return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
        }

        $repo = new MappingPendenzeRepository();
        try {
            $repo->accorpaPattern($idDominio, $patternIuv, null);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => "Accorpamento rimosso per il pattern '{$patternIuv}'."];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore nella rimozione dell\'accorpamento: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    public function addCustomTipologia(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');

        $body       = (array)($request->getParsedBody() ?? []);
        $codEntrata = strtoupper(trim((string)($body['cod_entrata'] ?? '')));
        $descrizione = trim((string)($body['descrizione'] ?? ''));

        if ($codEntrata === '' || $descrizione === '') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Codice e descrizione sono obbligatori.'];
            return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
        }

        $repo = new MappingPendenzeRepository();
        try {
            $repo->addCustomTipologia($idDominio, $codEntrata, $descrizione);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => "Tipologia personalizzata '{$codEntrata}' aggiunta."];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    public function deleteCustomTipologia(Request $request, Response $response): Response
    {
        $this->requireAdminOrSuperadmin();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $id = (int)(($request->getQueryParams())['id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'ID non valido.'];
            return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
        }

        $repo = new MappingPendenzeRepository();
        try {
            $repo->deleteCustomTipologia($idDominio, $id);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Tipologia personalizzata eliminata.'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore: ' . $e->getMessage()];
        }

        return $response->withHeader('Location', '/funzioni-avanzate/mapping-pendenze')->withStatus(302);
    }

    private function requireAuth(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            header('Location: /login');
            exit;
        }
        return $user;
    }

    private function requireAdminOrSuperadmin(): array
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
            echo "Accesso negato — richiesto ruolo amministrativo.";
            exit;
        }
        return $user;
    }
}
