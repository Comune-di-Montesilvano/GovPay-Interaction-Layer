<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Auth\UserRepository;
use App\Config\SettingsRepository;
use App\Services\MailerService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

/**
 * Gestisce il flusso "Hai dimenticato la password?":
 *   1. showForgot  — form inserimento email
 *   2. sendReset   — genera token e invia email
 *   3. showReset   — form nuova password (con token dall'URL)
 *   4. doReset     — valida token e aggiorna password
 */
class PasswordResetController
{
    public function __construct(private readonly Twig $twig) {}

    // -------------------------------------------------------------------------
    // Passo 1: mostra form "Inserisci la tua email"
    // -------------------------------------------------------------------------

    public function showForgot(Request $request, Response $response): Response
    {
        return $this->twig->render($response, 'auth/forgot_password.html.twig', [
            'success' => false,
            'error'   => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Passo 2: genera token e invia email (risposta sempre generica → anti-enumeration)
    // -------------------------------------------------------------------------

    public function sendReset(Request $request, Response $response): Response
    {
        $data  = (array)($request->getParsedBody() ?? []);
        $email = strtolower(trim($data['email'] ?? ''));

        // Risposta generica (non rivelano se l'email esiste)
        $genericSuccess = $this->twig->render($response, 'auth/forgot_password.html.twig', [
            'success' => true,
            'error'   => null,
        ]);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->twig->render($response, 'auth/forgot_password.html.twig', [
                'success' => false,
                'error'   => 'Inserisci un indirizzo email valido.',
            ]);
        }

        $repo = new UserRepository();
        $user = $repo->findByEmail($email);

        // Se l'utente non esiste o è disabilitato, rispondi lo stesso (anti-enumeration)
        if (!$user || !empty($user['is_disabled'])) {
            return $genericSuccess;
        }

        try {
            $token    = $repo->createPasswordResetToken($email, 60);
            $resetUrl = $this->buildResetUrl($request, $token);

            $toName   = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            $appName  = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL Backoffice';

            $mailer = MailerService::forSuite('backoffice');
            $mailer->sendResetPassword($email, $toName, $resetUrl, $appName, 60);
        } catch (\Throwable $e) {
            error_log('[PasswordReset] Errore invio email: ' . $e->getMessage());
            // Non rivelare l'errore all'utente, ma logga
        }

        return $genericSuccess;
    }

    // -------------------------------------------------------------------------
    // Passo 3: mostra form "Imposta nuova password" (token dall'URL)
    // -------------------------------------------------------------------------

    public function showReset(Request $request, Response $response): Response
    {
        $token = (string)($request->getQueryParams()['token'] ?? '');

        if ($token === '') {
            return $this->renderTokenError($response, 'Link mancante o malformato.');
        }

        $repo = new UserRepository();
        $row  = $repo->findValidResetToken($token);

        if (!$row) {
            return $this->renderTokenError($response, 'Il link è scaduto o è già stato utilizzato.');
        }

        return $this->twig->render($response, 'auth/reset_password.html.twig', [
            'token' => $token,
            'error' => null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Passo 4: valida token, aggiorna password, redirect al login
    // -------------------------------------------------------------------------

    public function doReset(Request $request, Response $response): Response
    {
        $data     = (array)($request->getParsedBody() ?? []);
        $token    = (string)($data['token'] ?? '');
        $password = (string)($data['password'] ?? '');
        $confirm  = (string)($data['password_confirm'] ?? '');

        if ($token === '') {
            return $this->renderTokenError($response, 'Token mancante.');
        }

        $repo = new UserRepository();
        $row  = $repo->findValidResetToken($token);

        if (!$row) {
            return $this->renderTokenError($response, 'Il link è scaduto o è già stato utilizzato.');
        }

        // Validazione password
        if (strlen($password) < 8) {
            return $this->twig->render($response, 'auth/reset_password.html.twig', [
                'token' => $token,
                'error' => 'La password deve essere di almeno 8 caratteri.',
            ]);
        }
        if ($password !== $confirm) {
            return $this->twig->render($response, 'auth/reset_password.html.twig', [
                'token' => $token,
                'error' => 'Le password non coincidono.',
            ]);
        }

        // Recupera utente e aggiorna password
        $user = $repo->findByEmail((string)$row['email']);
        if (!$user) {
            return $this->renderTokenError($response, 'Utente non trovato.');
        }

        $repo->updatePasswordById((int)$user['id'], $password);
        $repo->consumeResetToken($token);

        // Flash e redirect al login
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['flash'][] = [
            'type' => 'success',
            'text' => 'Password aggiornata con successo. Accedi con le nuove credenziali.',
        ];

        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function renderTokenError(Response $response, string $message): Response
    {
        // Restituisce sempre 200 per evitare che il reverse proxy intercetti errori
        return $this->twig->render($response->withStatus(200), 'auth/reset_password.html.twig', [
            'token'       => '',
            'error'       => $message,
            'token_error' => true,
        ]);
    }

    private function buildResetUrl(Request $request, string $token): string
    {
        // 1. Controlla se è configurato un URL base pubblico nell'ambiente (consigliato dietro proxy)
        $baseUrl = SettingsRepository::get('backoffice', 'public_base_url', '');

        if ($baseUrl !== '') {
            $baseUrl = rtrim($baseUrl, '/');
        } else {
            // 2. Fallback: rileva dinamicamente, rispettando eventuali header di proxy
            $uri    = $request->getUri();
            $scheme = $request->getHeaderLine('X-Forwarded-Proto') ?: $uri->getScheme();
            $host   = $request->getHeaderLine('X-Forwarded-Host') ?: $uri->getHost();
            $port   = $uri->getPort();

            $baseUrl = $scheme . '://' . $host;
            // Aggiungi porta solo se non standard per lo schema rilevato
            if ($port && !in_array($port, ($scheme === 'https' ? [443] : [80]), true)) {
                $baseUrl .= ':' . $port;
            }
        }

        return $baseUrl . '/reset-password?token=' . urlencode($token);
    }
}
