<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
namespace App\Controllers;

use App\Auth\UserRepository;
use App\Config\SettingsRepository;
use App\Logger;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class UsersController
{
    private UserRepository $users;

    public function __construct()
    {
        $this->users = new UserRepository();
    }

    public function profile(Request $request, Response $response, array $args)
    {
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }
        // Dati utente corrente
        $request = $request->withAttribute('profile', $user);
        return $request;
    }

    private function assertAdmin(): void
    {
        $u = $_SESSION['user'] ?? null;
        if (!$u || !in_array($u['role'], ['admin','superadmin'], true) || !empty($u['is_disabled'])) {
            throw new \RuntimeException('Forbidden');
        }
    }

    public function new(Request $request, Response $response, array $args)
    {
        $this->assertAdmin();
        return $request;
    }

    public function create(Request $request, Response $response, array $args)
    {
        $this->assertAdmin();
        $data = (array)($request->getParsedBody() ?? []);
        $email = trim($data['email'] ?? '');
        $password = trim($data['password'] ?? '');
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $role = in_array(($data['role'] ?? 'user'), ['user','admin','superadmin'], true) ? $data['role'] : 'user';
        if ($email === '' || $password === '') {
            return $request->withAttribute('error', 'Email e password sono obbligatorie');
        }
        if ($this->users->findByEmail($email)) {
            return $request->withAttribute('error', 'Email già in uso');
        }
        $this->users->insertUser($email, $password, $role, $firstName, $lastName);
        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Utente creato con successo'];
        return $response->withHeader('Location', $this->usersHome())->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args)
    {
        $this->assertAdmin();
        $id = (int)($args['id'] ?? 0);
        $user = $this->users->findById($id);
        if (!$user) {
            throw new \RuntimeException('Not Found');
        }
        // Prevent plain admins from editing superadmin accounts
        $current = $_SESSION['user'] ?? null;
        if (($current['role'] ?? '') === 'admin' && ($user['role'] ?? '') === 'superadmin') {
            // Return request with an error attribute so the route can render an appropriate message
            $request = $request->withAttribute('error', 'Non puoi modificare un account superadmin')->withAttribute('edit_user', null);
            return $request;
        }

        // Determine if a superadmin editing their own account can demote themselves
        $canDemoteSelf = true;
        if ($current && isset($current['id']) && (int)$current['id'] === $id && ($current['role'] ?? '') === 'superadmin') {
            $count = $this->users->countByRole('superadmin', false);
            $canDemoteSelf = ($count > 1);
        }

        $request = $request->withAttribute('edit_user', $user)->withAttribute('can_demote_self', $canDemoteSelf);
        return $request;
    }

    public function update(Request $request, Response $response, array $args)
    {
        $this->assertAdmin();
        $id = (int)($args['id'] ?? 0);
        // Prevent admins from updating superadmin accounts
        $target = $this->users->findById($id);
        $current = $_SESSION['user'] ?? null;
        if ($target && ($target['role'] ?? '') === 'superadmin' && ($current['role'] ?? '') === 'admin') {
            $request = $request->withAttribute('error', 'Non puoi modificare un account superadmin')->withAttribute('edit_user', $target);
            return $request;
        }
        $data = (array)($request->getParsedBody() ?? []);
        $email = trim($data['email'] ?? '');
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        // Handle submitted role
        $submittedRole = in_array(($data['role'] ?? 'user'), ['user','admin','superadmin'], true) ? $data['role'] : 'user';

        // Prevent an admin from changing their own role (no escalation/demotion)
        if ($current && isset($current['id']) && (int)$current['id'] === $id && ($current['role'] ?? '') === 'admin') {
            // Ignore submitted role change and keep target's existing role
            $role = $target['role'] ?? 'admin';
        } elseif ($current && isset($current['id']) && (int)$current['id'] === $id && ($current['role'] ?? '') === 'superadmin' && $submittedRole !== 'superadmin') {
            // Superadmin attempting to declass themselves: allow only if there is another superadmin
            $count = $this->users->countByRole('superadmin', false);
            if ($count <= 1) {
                // Block and return an informative error
                Logger::getInstance()->warning('Attempt to self-demote last superadmin blocked', ['current_id' => $current['id'] ?? null, 'target_id' => $id]);
                return $request->withAttribute('error', 'Devi mantenere almeno un altro superadmin prima di declassarti')->withAttribute('edit_user', $target);
            }
            $role = $submittedRole;
        } else {
            $role = $submittedRole;
        }
        $password = trim($data['password'] ?? '');
        if ($email === '') {
            $user = $this->users->findById($id);
            return $request->withAttribute('error', 'Email obbligatoria')
                           ->withAttribute('edit_user', $user);
        }
        $this->users->updateUser($id, $email, $role, $firstName, $lastName);
        if ($password !== '') {
            $this->users->updatePasswordById($id, $password);
        }
        // If the current logged-in user updated their own profile, refresh session data
        if ($current && isset($current['id']) && (int)$current['id'] === $id) {
            $fresh = $this->users->findById($id);
            if ($fresh) {
                $_SESSION['user'] = [
                    'id' => $fresh['id'],
                    'email' => $fresh['email'],
                    'role' => $fresh['role'],
                    'first_name' => $fresh['first_name'] ?? '',
                    'last_name' => $fresh['last_name'] ?? '',
                    'is_disabled' => !empty($fresh['is_disabled']),
                ];
            }
        }
        // Return request with success flag; route handler will save tipologies and redirect
        return $request->withAttribute('success', true);
    }

    public function disable(Request $request, Response $response, array $args): Response
    {
        $this->assertAdmin();
        $id = (int)($args['id'] ?? 0);
        $target = $this->users->findById($id);
        $current = $_SESSION['user'] ?? null;

        if (!$target) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Utente non trovato'];
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        if ($current && isset($current['id']) && (int)$current['id'] === $id) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Non puoi disabilitare il tuo account'];
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        if (($target['role'] ?? '') === 'superadmin' && ($current['role'] ?? '') === 'admin') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Non puoi disabilitare un account superadmin'];
            Logger::getInstance()->warning('Blocked disable by admin on superadmin', ['current_id' => $current['id'] ?? null, 'target_id' => $id]);
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        if (($target['role'] ?? '') === 'superadmin') {
            $count = $this->users->countByRole('superadmin', false);
            if ($count <= 1) {
                $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Impossibile disabilitare l\'ultimo superadmin attivo.'];
                Logger::getInstance()->warning('Attempt to disable last superadmin blocked', ['current_id' => $current['id'] ?? null, 'target_id' => $id]);
                return $response->withHeader('Location', $this->usersHome())->withStatus(302);
            }
        }

        if (!empty($target['is_disabled'])) {
            $_SESSION['flash'][] = ['type' => 'info', 'text' => 'L\'utente è già disabilitato'];
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        $this->users->setDisabled($id, true);
        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Utente disabilitato'];
        return $response->withHeader('Location', $this->usersHome())->withStatus(302);
    }

    public function enable(Request $request, Response $response, array $args): Response
    {
        $this->assertAdmin();
        $id = (int)($args['id'] ?? 0);
        $target = $this->users->findById($id);
        $current = $_SESSION['user'] ?? null;
        if (!$target) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Utente non trovato'];
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        if (($target['role'] ?? '') === 'superadmin' && ($current['role'] ?? '') === 'admin') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Non puoi riabilitare un account superadmin'];
            Logger::getInstance()->warning('Blocked enable by admin on superadmin', ['current_id' => $current['id'] ?? null, 'target_id' => $id]);
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        if (empty($target['is_disabled'])) {
            $_SESSION['flash'][] = ['type' => 'info', 'text' => 'L\'utente è già attivo'];
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        $this->users->setDisabled($id, false);
        $_SESSION['flash'][] = ['type' => 'success', 'text' => 'Utente riabilitato'];
        return $response->withHeader('Location', $this->usersHome())->withStatus(302);
    }

    public function sendPasswordResetLink(Request $request, Response $response, array $args): Response
    {
        $this->assertAdmin();
        $id = (int)($args['id'] ?? 0);
        $target = $this->users->findById($id);
        $current = $_SESSION['user'] ?? null;

        if (!$target) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Utente non trovato'];
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        // Se l'utente è un superadmin e chi preme è un semplice admin, blocca
        if (($target['role'] ?? '') === 'superadmin' && ($current['role'] ?? '') === 'admin') {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Non puoi inviare reset password a un account superadmin'];
            Logger::getInstance()->warning('Blocked send-reset by admin on superadmin', ['current_id' => $current['id'] ?? null, 'target_id' => $id]);
            return $response->withHeader('Location', $this->usersHome())->withStatus(302);
        }

        $email = $target['email'];
        $toName = trim(($target['first_name'] ?? '') . ' ' . ($target['last_name'] ?? ''));
        
        try {
            $token = $this->users->createPasswordResetToken($email, 60);
            
            // Per generare l'URL corretto, riusiamo la logica del PasswordResetController o la iniettiamo?
            // Qui costruiamo l'URL basandoci sulla logica già definita
            $baseUrl = SettingsRepository::get('backoffice', 'public_base_url', '');
            if ($baseUrl !== '') {
                $baseUrl = rtrim($baseUrl, '/');
            } else {
                $uri = $request->getUri();
                $scheme = $request->getHeaderLine('X-Forwarded-Proto') ?: $uri->getScheme();
                $host = $request->getHeaderLine('X-Forwarded-Host') ?: $uri->getHost();
                $port = $uri->getPort();
                $baseUrl = $scheme . '://' . $host;
                if ($port && !in_array($port, ($scheme === 'https' ? [443] : [80]), true)) {
                    $baseUrl .= ':' . $port;
                }
            }
            $resetUrl = $baseUrl . '/reset-password?token=' . urlencode($token);
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL Backoffice';

            $mailer = \App\Services\MailerService::forSuite('backoffice');
            $mailer->sendResetPassword($email, $toName, $resetUrl, $appName, 60);

            $_SESSION['flash'][] = ['type' => 'success', 'text' => "Email di reset inviata correttamente a $email"];
            Logger::getInstance()->info('Manual password reset link sent by admin', ['admin_id' => $current['id'] ?? null, 'target_id' => $id, 'target_email' => $email]);
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'text' => 'Errore durante l\'invio dell\'email'];
            Logger::getInstance()->error('Error sending manual password reset', ['error' => $e->getMessage(), 'target_id' => $id]);
        }

        return $response->withHeader('Location', $this->usersHome())->withStatus(302);
    }

    private function usersHome(): string
    {
        return '/impostazioni?tab=utenti';
    }
}
