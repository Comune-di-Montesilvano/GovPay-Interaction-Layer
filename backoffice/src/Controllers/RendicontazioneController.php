<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\RendicontazioneRepository;
use App\Database\UserGroupRepository;
use App\Logger;
use App\Services\GovPayClientFactory;
use App\Services\LegacyRendicontazioneBridgeClient;
use App\Services\RendicontazioneEngineService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class RendicontazioneController
{
    public function __construct(private readonly Twig $twig)
    {
    }

    public function daConfermare(Request $request, Response $response): Response
    {
        $user = $this->requireAuth();
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $repo = new RendicontazioneRepository();

        $idEntrateView = $this->getAuthorizedIdEntrateForView($user, $idDominio);
        $idEntrateAction = $this->getAuthorizedIdEntrateForAction($user, $idDominio);

        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = 25;

        $righe = empty($idEntrateView) ? [] : $repo->getDaConfermarePerTipologie($idDominio, $idEntrateView, $page, $perPage);
        $totale = empty($idEntrateView) ? 0 : $repo->countDaConfermarePerTipologie($idDominio, $idEntrateView);

        $backofficeUrl = SettingsRepository::get('govpay', 'backoffice_url', '');
        $idA2A = SettingsRepository::get('entity', 'id_a2a', '');
        $client = $this->buildGovPayClient();

        foreach ($righe as &$r) {
            // Formatta lo IUV: se minore di 18 cifre, aggiunge zeri a sinistra e imposta il 3 come prima cifra
            $r['iuv_completo'] = (strlen($r['iuv']) < 18) ? '3' . str_pad($r['iuv'], 17, '0', STR_PAD_LEFT) : $r['iuv'];

            $r['debitore_nome'] = 'N/D';
            $r['debitore_cf'] = 'N/D';

            $idPendenza = $r['id_pendenza'] ?? '';
            if ($r['is_govpay'] == 1 && $idPendenza !== '' && $backofficeUrl !== '' && $idA2A !== '') {
                try {
                    $url = rtrim($backofficeUrl, '/') . '/pendenze/' . rawurlencode($idA2A) . '/' . rawurlencode($idPendenza);
                    $res = $client->request('GET', $url);
                    $pendenza = json_decode((string)$res->getBody(), true);
                    if (is_array($pendenza)) {
                        $soggetto = $pendenza['soggettoPagatore'] ?? $pendenza['soggettoDebitore'] ?? null;
                        if ($soggetto) {
                            $nome = trim(($soggetto['anagrafica'] ?? '') . ' ' . ($soggetto['cognome'] ?? '') . ' ' . ($soggetto['nome'] ?? ''));
                            if ($nome === '') {
                                $nome = $soggetto['denominazione'] ?? '';
                            }
                            $r['debitore_nome'] = $nome !== '' ? $nome : 'N/D';
                            $r['debitore_cf'] = $soggetto['identificativoUnivoco'] ?? 'N/D';
                        }
                    }
                } catch (\Throwable $e) {
                    Logger::getInstance()->warning('Errore fetch debitore per smarcatura manuale', [
                        'id_pendenza' => $idPendenza,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
        unset($r);

        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $this->twig->render($response, 'rendicontazione/da-confermare.html.twig', [
            'righe'          => $righe,
            'totale'         => $totale,
            'page'           => $page,
            'per_page'       => $perPage,
            'csrf_token'     => $this->generateCsrf(),
            'flash'          => $flash,
            'current_user'   => $user,
            'action_entrate' => $idEntrateAction,
        ]);
    }

    public function conferma(Request $request, Response $response): Response
    {
        $user = $this->requireAuth();

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: token CSRF non valido.'];
            return $response->withHeader('Location', '/rendicontazione/da-confermare')->withStatus(302);
        }

        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $data = (array)($request->getParsedBody() ?? []);
        $ids = array_map('intval', (array)($data['riga_ids'] ?? []));

        if (!empty($ids)) {
            $idEntrate = $this->getAuthorizedIdEntrateForAction($user, $idDominio);
            $repo = new RendicontazioneRepository();
            $confermateIds = empty($idEntrate) ? [] : $repo->confermaRigheScoped($ids, $idEntrate, (int)$user['id']);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => count($confermateIds) . ' pagamenti confermati'];

            // Notifica App IO best-effort: mai deve impedire il completamento della conferma
            // (gia' avvenuta sopra) ne' essere mostrata come errore all'operatore.
            if (!empty($confermateIds)) {
                try {
                    $engine = new RendicontazioneEngineService(
                        $repo,
                        new LegacyRendicontazioneBridgeClient(),
                        $this->buildGovPayClient()
                    );
                    $backofficeUrl = (string)SettingsRepository::get('govpay', 'backoffice_url', '');
                    foreach ($confermateIds as $confermataId) {
                        $engine->tentaNotificaAppIoPerRiga((int)$confermataId);
                        $engine->controllaERegolarizzaFlussoPerRiga($idDominio, (int)$confermataId, $backofficeUrl);
                    }
                } catch (\Throwable $e) {
                    Logger::getInstance()->warning('Errore notifica App IO/regolarizzazione su conferma manuale rendicontazione', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } else {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Nessuna riga selezionata'];
        }

        return $response->withHeader('Location', '/rendicontazione/da-confermare')->withStatus(302);
    }

    /** Stessa costruzione client usata da scripts/cron_rendicontazione_govpay.php::buildGovPayClientForRendicontazione(). */
    private function buildGovPayClient(): \GuzzleHttp\Client
    {
        $opts = ['headers' => ['Accept' => 'application/json'], 'connect_timeout' => 10, 'timeout' => 30];
        $username = (string)SettingsRepository::get('govpay', 'user', '');
        $password = (string)SettingsRepository::get('govpay', 'password', '');
        if ($username !== '' && $password !== '') {
            $opts['auth'] = [$username, $password];
        }
        return GovPayClientFactory::makeBackofficeClient($opts);
    }

    public function impostazioniTab(Request $request, Response $response): Response
    {
        $user = $this->requireAuth();
        $this->requireAdminOrAbove($user);
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $repo = new RendicontazioneRepository();

        return $this->twig->render($response, 'impostazioni/tab-rendicontazione.html.twig', [
            'iuv_prefix_gil'          => SettingsRepository::get('rendicontazione', 'iuv_prefix_gil', 'GIL'),
            'scan_interval_minuti'    => SettingsRepository::get('rendicontazione', 'scan_interval_minuti', '15'),
            'scansioni_quiete_soglia' => SettingsRepository::get('rendicontazione', 'scansioni_quiete_soglia', '3'),
            'max_giorni_retry'       => SettingsRepository::get('rendicontazione', 'max_giorni_retry', '7'),
            'geri_max_tentativi'     => SettingsRepository::get('rendicontazione', 'geri_max_tentativi', '3'),
            'notifica_admin_auto'    => SettingsRepository::get('rendicontazione', 'notifica_admin_auto', 'false'),
            'admin_emails'           => SettingsRepository::get('rendicontazione', 'admin_emails', ''),
            'bridge_url'             => SettingsRepository::get('rendicontazione', 'bridge_url', ''),
            'regole_esterne'         => $repo->getRegoleEsterne($idDominio),
            'csrf_token'             => $this->generateCsrf(),
            'is_superadmin'          => (($user['role'] ?? '') === 'superadmin'),
        ]);
    }

    public function salvaImpostazioni(Request $request, Response $response): Response
    {
        $user = $this->requireAuth();
        $this->requireSuperadmin($user);

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: token CSRF non valido.'];
            return $response->withHeader('Location', '/impostazioni?tab=rendicontazione')->withStatus(302);
        }

        $data = (array)($request->getParsedBody() ?? []);

        SettingsRepository::set('rendicontazione', 'iuv_prefix_gil', trim((string)($data['iuv_prefix_gil'] ?? 'GIL')) ?: 'GIL', false, (string)$user['id']);
        SettingsRepository::set('rendicontazione', 'scan_interval_minuti', (string)max(1, (int)($data['scan_interval_minuti'] ?? 15)), false, (string)$user['id']);
        SettingsRepository::set('rendicontazione', 'scansioni_quiete_soglia', (string)max(1, (int)($data['scansioni_quiete_soglia'] ?? 3)), false, (string)$user['id']);
        SettingsRepository::set('rendicontazione', 'max_giorni_retry', (string)max(1, (int)($data['max_giorni_retry'] ?? 7)), false, (string)$user['id']);
        SettingsRepository::set('rendicontazione', 'geri_max_tentativi', (string)max(1, (int)($data['geri_max_tentativi'] ?? 3)), false, (string)$user['id']);
        SettingsRepository::set('rendicontazione', 'notifica_admin_auto', !empty($data['notifica_admin_auto']) ? 'true' : 'false', false, (string)$user['id']);
        SettingsRepository::set('rendicontazione', 'admin_emails', trim((string)($data['admin_emails'] ?? '')), false, (string)$user['id']);
        SettingsRepository::set('rendicontazione', 'bridge_url', trim((string)($data['bridge_url'] ?? '')), false, (string)$user['id']);
        if (!empty($data['bridge_token'])) {
            SettingsRepository::set('rendicontazione', 'bridge_token', (string)$data['bridge_token'], true, (string)$user['id']);
        }

        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Impostazioni rendicontazione salvate'];
        return $response->withHeader('Location', '/impostazioni?tab=rendicontazione')->withStatus(302);
    }

    public function aggiungiRegolaEsterna(Request $request, Response $response): Response
    {
        $user = $this->requireAuth();
        $this->requireSuperadmin($user);

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: token CSRF non valido.'];
            return $response->withHeader('Location', '/impostazioni?tab=rendicontazione')->withStatus(302);
        }

        $data = (array)($request->getParsedBody() ?? []);
        $idDominio = (string)SettingsRepository::get('entity', 'id_dominio', '');
        $patternTipo = in_array($data['pattern_tipo'] ?? '', ['IUV_PREFIX', 'ID_APP_AGID'], true) ? $data['pattern_tipo'] : 'IUV_PREFIX';
        $handler = in_array($data['handler'] ?? '', ['GERI', 'DILAZIONE'], true) ? $data['handler'] : 'GERI';
        $patternValore = trim((string)($data['pattern_valore'] ?? ''));

        if ($patternValore !== '') {
            (new RendicontazioneRepository())->addRegolaEsterna($idDominio, $patternTipo, $patternValore, $handler);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Regola aggiunta'];
        } else {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Pattern obbligatorio'];
        }

        return $response->withHeader('Location', '/impostazioni?tab=rendicontazione')->withStatus(302);
    }

    public function eliminaRegolaEsterna(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireAuth();
        $this->requireSuperadmin($user);

        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Errore: token CSRF non valido.'];
            return $response->withHeader('Location', '/impostazioni?tab=rendicontazione')->withStatus(302);
        }

        (new RendicontazioneRepository())->deleteRegolaEsterna((int)($args['id'] ?? 0));
        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Regola eliminata'];
        return $response->withHeader('Location', '/impostazioni?tab=rendicontazione')->withStatus(302);
    }

    /** @return string[] */
    private function getAuthorizedIdEntrateForView(array $user, string $idDominio): array
    {
        $isAdminOrSuper = in_array($user['role'] ?? '', ['admin', 'superadmin'], true);
        if ($isAdminOrSuper) {
            $pdo = \App\Database\Connection::getPDO();
            $stmt = $pdo->prepare("SELECT id_entrata FROM entrate_tipologie WHERE id_dominio = :dom");
            $stmt->execute([':dom' => $idDominio]);
            return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id_entrata');
        }

        // Operatore vede solo le sue
        $groupRepo = new UserGroupRepository();
        $idEntrate = [];
        foreach ($groupRepo->getMemberGroupIds((int)$user['id']) as $groupId) {
            foreach ($groupRepo->getRendicontazioneTipologie($groupId, $idDominio) as $t) {
                if ($t['modalita'] === 'NOTIFICA_E_SMARCATURA') {
                    $idEntrate[] = $t['id_entrata'];
                }
            }
        }
        return array_values(array_unique($idEntrate));
    }

    /** @return string[] */
    private function getAuthorizedIdEntrateForAction(array $user, string $idDominio): array
    {
        $role = $user['role'] ?? '';

        // Superadmin può agire su tutto
        if ($role === 'superadmin') {
            $pdo = \App\Database\Connection::getPDO();
            $stmt = $pdo->prepare("SELECT id_entrata FROM entrate_tipologie WHERE id_dominio = :dom");
            $stmt->execute([':dom' => $idDominio]);
            return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id_entrata');
        }

        // Admin non può agire su nulla per smarcatura manuale (solo visualizzazione)
        if ($role === 'admin') {
            return [];
        }

        // Operatore può agire solo su quelle dei suoi gruppi
        $groupRepo = new UserGroupRepository();
        $idEntrate = [];
        foreach ($groupRepo->getMemberGroupIds((int)$user['id']) as $groupId) {
            foreach ($groupRepo->getRendicontazioneTipologie($groupId, $idDominio) as $t) {
                if ($t['modalita'] === 'NOTIFICA_E_SMARCATURA') {
                    $idEntrate[] = $t['id_entrata'];
                }
            }
        }
        return array_values(array_unique($idEntrate));
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

    private function requireSuperadmin(array $user): void
    {
        if (($user['role'] ?? '') !== 'superadmin') {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso riservato al superadmin.'];
            header('Location: /impostazioni?tab=rendicontazione');
            exit;
        }
    }

    private function requireAdminOrAbove(array $user): void
    {
        $role = $user['role'] ?? '';
        if (!in_array($role, ['admin', 'superadmin'], true)) {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Accesso negato: permessi insufficienti'];
            header('Location: /');
            exit;
        }
    }

    private function generateCsrf(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (empty($_SESSION['rendicontazione_csrf'])) {
            $_SESSION['rendicontazione_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['rendicontazione_csrf'];
    }

    private function validateCsrf(Request $request): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $expected = (string)($_SESSION['rendicontazione_csrf'] ?? '');
        $body = (array)($request->getParsedBody() ?? []);
        $provided = (string)($body['csrf_token'] ?? '');

        if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
            return false;
        }
        return true;
    }
}
