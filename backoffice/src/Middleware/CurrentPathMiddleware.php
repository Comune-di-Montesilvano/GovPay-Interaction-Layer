<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Views\Twig;
use App\Auth\UserRepository;

class CurrentPathMiddleware implements MiddlewareInterface
{
    private Twig $twig;

    public function __construct(Twig $twig)
    {
        $this->twig = $twig;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $path = $request->getUri()->getPath();
        // Espone il percorso corrente come variabile globale Twig
        // Enrich current_user per request (session is started by SessionMiddleware)
        // Assicura che la sessione sia disponibile anche se l'ordine dei middleware cambia
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user'])) {
            $sessionUser = $_SESSION['user'];
            try {
                if (empty($sessionUser['first_name']) || empty($sessionUser['last_name'])) {
                    $repo = new UserRepository();
                    $dbUser = $repo->findById((int)($sessionUser['id'] ?? 0));
                    if ($dbUser) {
                        $sessionUser['first_name'] = $dbUser['first_name'] ?? '';
                        $sessionUser['last_name'] = $dbUser['last_name'] ?? '';
                        $_SESSION['user'] = $sessionUser;
                    }
                }
            } catch (\Throwable $e) {
                // ignore and fallback to session data
            }
            $this->twig->getEnvironment()->addGlobal('current_user', $sessionUser);

            // Calcola il conteggio delle smarcature pendenti per l'operatore/superadmin
            try {
                $idDominio = (string)\App\Config\SettingsRepository::get('entity', 'id_dominio', '');
                if ($idDominio !== '') {
                    $idEntrate = [];
                    $role = $sessionUser['role'] ?? '';
                    $isAdminOrSuper = in_array($role, ['admin', 'superadmin'], true);
                    if ($isAdminOrSuper) {
                        $pdo = \App\Database\Connection::getPDO();
                        $stmt = $pdo->prepare("SELECT id_entrata FROM entrate_tipologie WHERE id_dominio = :dom");
                        $stmt->execute([':dom' => $idDominio]);
                        $idEntrate = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'id_entrata');
                    } else {
                        $groupRepo = new \App\Database\UserGroupRepository();
                        foreach ($groupRepo->getMemberGroupIds((int)$sessionUser['id']) as $groupId) {
                            foreach ($groupRepo->getRendicontazioneTipologie($groupId, $idDominio) as $t) {
                                if ($t['modalita'] === 'NOTIFICA_E_SMARCATURA') {
                                    $idEntrate[] = $t['id_entrata'];
                                }
                            }
                        }
                        $idEntrate = array_values(array_unique($idEntrate));
                    }
                    
                    $count = 0;
                    if ($role !== 'admin' && !empty($idEntrate)) {
                        $repo = new \App\Database\RendicontazioneRepository();
                        $count = $repo->countDaConfermarePerTipologie($idDominio, $idEntrate);
                    }
                    $this->twig->getEnvironment()->addGlobal('da_confermare_count', $count);
                }
            } catch (\Throwable $_) {
                // ignore
            }
        }
        $this->twig->getEnvironment()->addGlobal('current_path', $path);
        return $handler->handle($request);
    }
}
