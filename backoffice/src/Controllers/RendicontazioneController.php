<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\SettingsRepository;
use App\Database\RendicontazioneRepository;
use App\Database\UserGroupRepository;
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

        $idEntrate = $this->getAuthorizedIdEntrate($user, $idDominio);

        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = 25;

        $righe = empty($idEntrate) ? [] : $repo->getDaConfermarePerTipologie($idDominio, $idEntrate, $page, $perPage);
        $totale = empty($idEntrate) ? 0 : $repo->countDaConfermarePerTipologie($idDominio, $idEntrate);

        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $this->twig->render($response, 'rendicontazione/da-confermare.html.twig', [
            'righe'       => $righe,
            'totale'      => $totale,
            'page'        => $page,
            'per_page'    => $perPage,
            'csrf_token'  => $this->generateCsrf(),
            'flash'       => $flash,
            'current_user'=> $user,
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
            $idEntrate = $this->getAuthorizedIdEntrate($user, $idDominio);
            $repo = new RendicontazioneRepository();
            $confermate = empty($idEntrate) ? 0 : $repo->confermaRigheScoped($ids, $idEntrate, (int)$user['id']);
            $_SESSION['flash'][] = ['type' => 'success', 'text' => "{$confermate} pagamenti confermati"];
        } else {
            $_SESSION['flash'][] = ['type' => 'error', 'text' => 'Nessuna riga selezionata'];
        }

        return $response->withHeader('Location', '/rendicontazione/da-confermare')->withStatus(302);
    }

    /** @return string[] */
    private function getAuthorizedIdEntrate(array $user, string $idDominio): array
    {
        $isSuperadmin = ($user['role'] ?? '') === 'superadmin';
        $idEntrate = [];
        if (!$isSuperadmin) {
            $groupRepo = new UserGroupRepository();
            foreach ($groupRepo->getMemberGroupIds((int)$user['id']) as $groupId) {
                foreach ($groupRepo->getRendicontazioneTipologie($groupId, $idDominio) as $t) {
                    if ($t['modalita'] === 'NOTIFICA_E_SMARCATURA') {
                        $idEntrate[] = $t['id_entrata'];
                    }
                }
            }
            $idEntrate = array_values(array_unique($idEntrate));
        } else {
            $pdo = \App\Database\Connection::getPDO();
            $stmt = $pdo->prepare("SELECT id_entrata FROM entrate_tipologie WHERE id_dominio = :dom");
            $stmt->execute([':dom' => $idDominio]);
            $idEntrate = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id_entrata');
        }
        return $idEntrate;
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
