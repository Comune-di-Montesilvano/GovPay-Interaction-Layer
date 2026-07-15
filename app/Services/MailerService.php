<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services;

use App\Config\SettingsRepository;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * MailerService — wrapper su Symfony Mailer configurato da variabili d'ambiente.
 *
 * Uso minimale:
 *   $mailer = MailerService::forSuite('backoffice');
 *   $mailer->send($email);
 *
 * Per il reset password:
 *   $mailer->sendResetPassword($email, $name, $url, $appName);
 */
class MailerService
{
    private Mailer $mailer;
    private Address $from;
    private string $suite;

    private function __construct(Mailer $mailer, Address $from, string $suite)
    {
        $this->mailer = $mailer;
        $this->from   = $from;
        $this->suite  = $suite;
    }

    /**
     * Crea un'istanza configurata per la suite indicata (backoffice | frontoffice).
     * Legge le variabili d'ambiente:
     *   {SUITE}_MAILER_DSN, {SUITE}_MAILER_FROM_ADDRESS, {SUITE}_MAILER_FROM_NAME
     */
    public static function forSuite(string $suite = 'backoffice'): self
    {
        $dsn      = SettingsRepository::get('backoffice', 'mailer_dsn', 'null://null');
        $fromAddr = SettingsRepository::get('backoffice', 'mailer_from_address', 'noreply@example.com');
        $fromName = SettingsRepository::get('backoffice', 'mailer_from_name', '')
                    ?: SettingsRepository::get('entity', 'name', 'GIL');

        $transport = Transport::fromDsn($dsn);
        $mailer    = new Mailer($transport);
        $from      = new Address($fromAddr, $fromName);

        return new self($mailer, $from, $suite);
    }

    /**
     * Invia un messaggio Email già composto.
     */
    public function send(Email $email): void
    {
        if (!$email->getFrom()) {
            $email->from($this->from);
        }
        $this->mailer->send($email);
    }

    /**
     * Invia l'email di reset password con template HTML inline.
     */
    public function sendResetPassword(
        string $toEmail,
        string $toName,
        string $resetUrl,
        string $appName = '',
        int    $expiresMinutes = 60
    ): void {
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }

        $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $greeting = $safeName !== '' ? 'Ciao, <strong>' . $safeName . '</strong>,' : 'Ciao,';
        $logoPath = $this->resolveLogoPath();

        $fromName = $this->from->getName();
        $htmlIntro = "<div style=\"font-size:13px;color:#6b7280;margin-bottom:10px;\">Questa email è stata inviata da: <strong>" . htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8') . "</strong></div>";
        $plainIntro = "Mittente: $fromName\n\n";
        $htmlBody = $htmlIntro . $this->renderAccountAccessEmail(
          $safeAppName,
          $greeting,
          'Reimposta la tua password',
          'Abbiamo ricevuto una richiesta di reset password per il tuo account. Usa il pulsante qui sotto per impostare una nuova password.',
          'Reimposta password',
          $safeUrl,
          "Il link e valido per {$expiresMinutes} minuti ed e monouso. Se non hai richiesto il reset, puoi ignorare questa email."
        );
        $textBody = $plainIntro . $this->renderAccountAccessEmailPlain(
          $toName,
          $appName,
          "Abbiamo ricevuto una richiesta di reset password per il tuo account su {$appName}.",
          'Usa il link qui sotto per impostare una nuova password',
          $resetUrl,
          $expiresMinutes
        );

        $email = (new Email())
          ->from($this->from)
          ->to(new Address($toEmail, $toName))
          ->subject("[$appName] Reimposta la tua password")
          ->html($htmlBody)
          ->text($textBody);

        if ($logoPath !== '' && is_file($logoPath)) {
            $email->embedFromPath($logoPath, 'logo');
        }

        $this->mailer->send($email);
    }

    public function sendUserInvitation(
        string $toEmail,
        string $toName,
        string $resetUrl,
        string $appName = '',
        int $expiresMinutes = 60
    ): void {
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }

        $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $greeting = $safeName !== '' ? 'Ciao, <strong>' . $safeName . '</strong>,' : 'Ciao,';
        $logoPath = $this->resolveLogoPath();

        $fromName = $this->from->getName();
        $htmlIntro = "<div style=\"font-size:13px;color:#6b7280;margin-bottom:10px;\">Questa email è stata inviata da: <strong>" . htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8') . "</strong></div>";
        $plainIntro = "Mittente: $fromName\n\n";
        $email = (new Email())
          ->from($this->from)
          ->to(new Address($toEmail, $toName))
          ->subject("[{$appName}] Il tuo account e pronto")
          ->html($htmlIntro . $this->renderAccountAccessEmail(
            $safeAppName,
            $greeting,
            'Il tuo account e pronto',
            "Ti hanno creato un'utenza su {$safeAppName}. Per completare l'attivazione, imposta la tua password dal pulsante qui sotto.",
            'Imposta password',
            $safeUrl,
            "Il link e valido per {$expiresMinutes} minuti ed e monouso."
          ))
          ->text($plainIntro . $this->renderAccountAccessEmailPlain(
            $toName,
            $appName,
            "Ti hanno creato un'utenza su {$appName}.",
            'Usa il link qui sotto per impostare la tua password',
            $resetUrl,
            $expiresMinutes
          ));

        if ($logoPath !== '' && is_file($logoPath)) {
            $email->embedFromPath($logoPath, 'logo');
        }

        $this->mailer->send($email);
    }

    public function sendTestEmail(string $toEmail, string $appName = ''): void
    {
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }

        $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $logoPath = $this->resolveLogoPath();
        $email = (new Email())
            ->from($this->from)
            ->to(new Address($toEmail))
            ->subject("[{$appName}] Verifica configurazione email")
            ->html(<<<HTML
            <!DOCTYPE html>
            <html lang="it">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1.0">
            </head>
            <body style="margin:0;padding:24px;background:#f4f7fa;font-family:Arial,sans-serif;color:#1f2937;">
              <div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #dbe3ec;border-radius:10px;overflow:hidden;">
                <div style="background:#0b3d91;padding:28px 32px;text-align:center;">
                  <img src="cid:logo" alt="{$safeAppName}" style="max-height:56px;max-width:220px;display:block;margin:0 auto 16px;" onerror="this.style.display='none'">
                  <h1 style="margin:0;color:#fff;font-size:22px;">{$safeAppName}</h1>
                </div>
                <div style="padding:32px;">
                  <p style="margin:0 0 14px;">Questa e una email di test inviata dal backoffice.</p>
                  <p style="margin:0 0 14px;">Se la ricevi correttamente, la configurazione SMTP e il mittente sono operativi.</p>
                  <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-top:20px;">
                    <p style="margin:0 0 8px;font-weight:bold;">Controlli consigliati</p>
                    <ul style="margin:0;padding-left:18px;">
                      <li>logo visibile nell'intestazione</li>
                      <li>mittente corretto</li>
                      <li>layout leggibile</li>
                    </ul>
                  </div>
                </div>
              </div>
            </body>
            </html>
            HTML)
            ->text("Questa e una email di test inviata dal backoffice di {$appName}.");

        if ($logoPath !== '' && is_file($logoPath)) {
            $email->embedFromPath($logoPath, 'logo');
        }

        $this->mailer->send($email);
    }

    /**
     * Invia l'email di notifica per la creazione di una pendenza.
     * 
     * @param string $toEmail Email del destinatario
     * @param string $toName Nome del destinatario
     * @param array $pendenzaData Dati della pendenza (causale, importo, idPendenza, numeroAvviso, idDominio)
     * @param string $appName Nome dell'applicazione
     * @param string $pdfUrl URL per scaricare il PDF dell'avviso
     * @param string $paymentUrl URL per avviare il pagamento
     * @param string $logoPath Path del file logo da allegare (opzionale)
     * @return array Info sulla notifica inviata ['timestamp', 'esito', 'destinatario']
     */
    public function sendPendenzaCreatedNotification(
        string $toEmail,
        string $toName,
        array  $pendenzaData,
        string $appName = '',
        string $pdfUrl = '',
        string $paymentUrl = '',
        string $logoPath = ''
    ): array {
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }

        $timestamp = date('Y-m-d H:i:s');

        try {
          $hasEmbeddedLogo = ($logoPath !== '' && file_exists($logoPath));
          $logoSrc = SettingsRepository::get('ui', 'logo_src', '');
          $htmlBody = $this->renderPendenzaCreatedTemplate($toName, $pendenzaData, $appName, $pdfUrl, $paymentUrl, $hasEmbeddedLogo, $logoSrc);
            $textBody = $this->renderPendenzaCreatedTemplatePlain($toName, $pendenzaData, $appName, $pdfUrl, $paymentUrl);

            $causale = $pendenzaData['causale'] ?? 'Nuova pendenza';
            $email = (new Email())
                ->from($this->from)
                ->to(new Address($toEmail, $toName))
                ->subject("Pendenza Pagopa - \"$causale\"")
                ->html($htmlBody)
                ->text($textBody);
            
            // Allega il logo se specificato
            if ($hasEmbeddedLogo) {
                $email->embedFromPath($logoPath, 'logo');
            }

            $this->mailer->send($email);

            return [
                'timestamp' => $timestamp,
                'esito' => 'OK',
                'destinatario' => $toEmail,
                'canale' => 'email',
            ];
        } catch (\Throwable $e) {
            return [
                'timestamp' => $timestamp,
                'esito' => 'ERRORE',
                'destinatario' => $toEmail,
                'canale' => 'email',
                'errore' => $e->getMessage(),
            ];
        }
    }

    /**
     * Invia l'email di notifica per la ricevuta di pagamento.
     */
    public function sendPendenzaReceiptNotification(
        string $toEmail,
        string $toName,
        array  $pendenzaData,
        string $iuv,
        string $tipologia,
        string $dataPagamento,
        string $receiptUrl = '',
        string $appName = '',
        string $logoPath = ''
    ): array {
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }

        $timestamp = date('Y-m-d H:i:s');

        try {
            $hasEmbeddedLogo = ($logoPath !== '' && file_exists($logoPath));
            $logoSrc = SettingsRepository::get('ui', 'logo_src', '');
            $htmlBody = $this->renderPendenzaReceiptTemplate($toName, $pendenzaData, $iuv, $tipologia, $dataPagamento, $appName, $receiptUrl, $hasEmbeddedLogo, $logoSrc);
            $textBody = $this->renderPendenzaReceiptTemplatePlain($toName, $pendenzaData, $iuv, $tipologia, $dataPagamento, $appName, $receiptUrl);

            $causale = $pendenzaData['causale'] ?? 'Ricevuta di pagamento';
            $email = (new Email())
                ->from($this->from)
                ->to(new Address($toEmail, $toName))
                ->subject("Ricevuta di pagamento PagoPA - \"$causale\"")
                ->html($htmlBody)
                ->text($textBody);
            
            if ($hasEmbeddedLogo) {
                $email->embedFromPath($logoPath, 'logo');
            }

            $this->mailer->send($email);

            return [
                'timestamp' => $timestamp,
                'esito' => 'OK',
                'destinatario' => $toEmail,
                'canale' => 'email',
            ];
        } catch (\Throwable $e) {
            return [
                'timestamp' => $timestamp,
                'esito' => 'ERRORE',
                'destinatario' => $toEmail,
                'canale' => 'email',
                'errore' => $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Template inline (HTML + testo)
    // -------------------------------------------------------------------------

    private function renderResetTemplate(
        string $toName,
        string $resetUrl,
        string $appName,
        int    $expiresMinutes
    ): string {
        $safeToName  = htmlspecialchars($toName,   ENT_QUOTES, 'UTF-8');
        $safeAppName = htmlspecialchars($appName,  ENT_QUOTES, 'UTF-8');
        $safeMins    = (int)$expiresMinutes;
        $safeUrl     = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $greeting    = $safeToName !== ''
            ? 'Ciao, <strong>' . $safeToName . '</strong>,'
            : 'Ciao,';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="it">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Reimposta la tua password - {$safeAppName}</title>
          <style>
            body { margin:0; padding:0; background:#f4f7fa; font-family: 'Helvetica Neue', Arial, sans-serif; color:#333; }
            .wrapper { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
            .header { background:#0b3d91; padding:28px 32px; text-align:center; }
            .header h1 { margin:0; color:#fff; font-size:20px; font-weight:600; letter-spacing:.5px; }
            .body { padding:32px; }
            .body p { line-height:1.6; margin:0 0 16px; }
            .btn { display:inline-block; margin:8px 0 24px; padding:14px 32px; background:#0b3d91; color:#fff; text-decoration:none; border-radius:6px; font-weight:600; font-size:15px; }
            .notice { font-size:13px; color:#777; border-top:1px solid #eee; margin-top:24px; padding-top:16px; }
            .footer { background:#f4f7fa; padding:18px 32px; text-align:center; font-size:12px; color:#999; }
          </style>
        </head>
        <body>
          <div class="wrapper">
            <div class="header">
              <h1>{$safeAppName}</h1>
            </div>
            <div class="body">
              <p>{$greeting}</p>
              <p>abbiamo ricevuto una richiesta di reset password per il tuo account. Clicca il pulsante qui sotto per impostare una nuova password:</p>
              <p style="text-align:center;">
                <a href="{$safeUrl}" class="btn">Reimposta password</a>
              </p>
              <p>Se il pulsante non funziona, copia e incolla questo link nel browser:</p>
              <p style="word-break:break-all; font-size:13px; color:#555;">{$safeUrl}</p>
              <div class="notice">
                <p>Questo link è valido per <strong>{$safeMins} minuti</strong> ed è monouso.
                Se non hai richiesto il reset, puoi ignorare questa email — il tuo account non subirà modifiche.</p>
              </div>
            </div>
            <div class="footer">
              &copy; {$safeAppName} · Email generata automaticamente, non rispondere.
            </div>
          </div>
        </body>
        </html>
        HTML;
    }

    private function renderResetTemplatePlain(
        string $toName,
        string $resetUrl,
        string $appName,
        int    $expiresMinutes
    ): string {
        $greeting = $toName !== '' ? "Ciao, $toName," : "Ciao,";
        return <<<TEXT
        {$greeting}

        abbiamo ricevuto una richiesta di reset password per il tuo account su {$appName}.

        Usa il link qui sotto per impostare una nuova password (valido {$expiresMinutes} minuti):

        {$resetUrl}

        Se non hai richiesto il reset, ignora questa email.

        -- {$appName}
        TEXT;
    }

    private function renderAccountAccessEmail(
        string $safeAppName,
        string $greeting,
        string $title,
        string $intro,
        string $buttonLabel,
        string $safeUrl,
        string $notice
    ): string {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="it">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>{$title} - {$safeAppName}</title>
        </head>
        <body style="margin:0;padding:24px;background:#eef3f8;font-family:Arial,sans-serif;color:#1f2937;">
          <div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #dbe3ec;border-radius:12px;overflow:hidden;">
            <div style="background:#0b3d91;padding:28px 32px;text-align:center;">
              <img src="cid:logo" alt="{$safeAppName}" style="max-height:56px;max-width:220px;display:block;margin:0 auto 16px;" onerror="this.style.display='none'">
              <div style="color:#ffffff;font-size:22px;font-weight:700;">{$safeAppName}</div>
            </div>
            <div style="padding:32px;">
              <div style="font-size:22px;line-height:1.25;font-weight:700;color:#111827;margin:0 0 18px;">{$title}</div>
              <p style="margin:0 0 14px;line-height:1.6;">{$greeting}</p>
              <p style="margin:0 0 18px;line-height:1.6;color:#374151;">{$intro}</p>
              <div style="text-align:center;margin:28px 0;">
                <a href="{$safeUrl}" style="display:inline-block;padding:14px 28px;background:#0b3d91;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:700;">{$buttonLabel}</a>
              </div>
              <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin:0 0 18px;">
                <div style="font-size:13px;font-weight:700;color:#111827;margin:0 0 8px;">Link diretto</div>
                <div style="word-break:break-all;font-size:13px;color:#475569;">{$safeUrl}</div>
              </div>
              <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">{$notice}</p>
            </div>
          </div>
        </body>
        </html>
        HTML;
    }

    private function renderAccountAccessEmailPlain(
        string $toName,
        string $appName,
        string $intro,
        string $cta,
        string $resetUrl,
        int $expiresMinutes
    ): string {
        $greeting = $toName !== '' ? "Ciao, {$toName}," : 'Ciao,';

        return <<<TEXT
        {$greeting}

        {$intro}

        {$cta} (valido {$expiresMinutes} minuti):

        {$resetUrl}

        -- {$appName}
        TEXT;
    }

    // -------------------------------------------------------------------------
    // Template notifica creazione pendenza (HTML + testo)
    // -------------------------------------------------------------------------

    private function renderPendenzaCreatedTemplate(
        string $toName,
        array  $pendenzaData,
        string $appName,
        string $pdfUrl,
      string $paymentUrl,
      bool $hasEmbeddedLogo = false,
      string $logoSrc = ''
    ): string {
        $safeToName  = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $causale     = htmlspecialchars($pendenzaData['causale'] ?? 'Nuova posizione debitoria', ENT_QUOTES, 'UTF-8');
        $importo     = number_format((float)($pendenzaData['importo'] ?? 0.0), 2, ',', '.');
        $noticeCode  = htmlspecialchars($this->resolveNoticeCode($pendenzaData), ENT_QUOTES, 'UTF-8');
        // dataValidita fallback (fix 2)
        $dataScadenza = '';
        if (!empty($pendenzaData['dataScadenza'])) {
            $dataScadenza = htmlspecialchars($pendenzaData['dataScadenza'], ENT_QUOTES, 'UTF-8');
        } elseif (!empty($pendenzaData['dataValidita'])) {
            $dataScadenza = htmlspecialchars($pendenzaData['dataValidita'], ENT_QUOTES, 'UTF-8');
        }

        $greeting = $safeToName !== '' ? 'Gentile <strong>' . $safeToName . '</strong>,' : 'Gentile Interessato,';

        $actionButtons = '';
        if ($paymentUrl !== '' || $pdfUrl !== '') {
            $safePdfUrl = htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8');
            $safePaymentUrl = htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8');

            $pdfButton = $pdfUrl !== '' ? "<a href=\"{$safePdfUrl}\" class=\"btn btn-secondary\" style=\"background:#6c757d; margin-right:8px;\">Scarica PDF avviso</a>" : '';
            $payButton = $paymentUrl !== '' ? "<a href=\"{$safePaymentUrl}\" class=\"btn\">Paga ora</a>" : '';

            $actionButtons = <<<HTML
              <p style="text-align:center; margin:24px 0;">
                {$pdfButton}{$payButton}
              </p>
HTML;
        }

        $scadenzaInfo = '';
        if ($dataScadenza !== '') {
            $scadenzaInfo = "<p><strong>Data scadenza:</strong> {$dataScadenza}</p>";
        }

        $iuvInfo = $noticeCode !== '' ? "<p><strong>IUV:</strong> {$noticeCode}</p>" : '';

        // Tipologia pendenza (fix 7)
        $tipologiaInfo = !empty($pendenzaData['idTipoPendenza'])
            ? '<p><strong>Tipologia:</strong> ' . htmlspecialchars((string)$pendenzaData['idTipoPendenza'], ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        // Logo: preferisci embed cid:logo, fallback a src configurato, poi titolo testuale.
        $safeLogoSrc = htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8');
        if ($hasEmbeddedLogo) {
          $logoHtml = '<img src="cid:logo" alt="Logo ente" style="max-width:120px; height:auto; margin-bottom:12px; display:block; margin-left:auto; margin-right:auto;">'
            . '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        } elseif ($safeLogoSrc !== '') {
          $logoHtml = '<img src="' . $safeLogoSrc . '" alt="Logo ente" style="max-width:120px; height:auto; margin-bottom:12px; display:block; margin-left:auto; margin-right:auto;">'
            . '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        } else {
          $logoHtml = '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="it">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Pendenza Pagopa - {$causale}</title>
          <style>
            body { margin:0; padding:0; background:#f4f7fa; font-family: 'Helvetica Neue', Arial, sans-serif; color:#333; }
            .wrapper { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
            .header { background:#0b3d91; padding:28px 32px; text-align:center; }
            .header h1 { margin:0; color:#fff; font-size:20px; font-weight:600; letter-spacing:.5px; }
            .header img { max-width:120px; height:auto; margin-bottom:12px; display:block; margin-left:auto; margin-right:auto; }
            .body { padding:32px; }
            .body p { line-height:1.6; margin:0 0 16px; }
            .info-box { background:#f8f9fa; border-left:4px solid #0b3d91; padding:16px; margin:16px 0; border-radius:4px; }
            .info-box p { margin:8px 0; }
            .btn { display:inline-block; margin:8px 4px; padding:14px 32px; background:#0b3d91; color:#fff; text-decoration:none; border-radius:6px; font-weight:600; font-size:15px; }
            .btn-secondary { background:#6c757d; }
            .footer { background:#f4f7fa; padding:18px 32px; text-align:center; font-size:12px; color:#999; }
          </style>
        </head>
        <body>
          <div class="wrapper">
            <div class="header">
              {$logoHtml}
            </div>
            <div class="body">
              <p>{$greeting}</p>
              <p>è stata creata una nuova posizione debitoria a tuo carico. Di seguito i dettagli:</p>
              <div class="info-box">
                <p><strong>Causale:</strong> {$causale}</p>
                <p><strong>Importo:</strong> &euro; {$importo}</p>
                {$tipologiaInfo}
                {$iuvInfo}
                {$scadenzaInfo}
              </div>
              {$actionButtons}
              <p style="font-size:13px; color:#666;">Utilizza i pulsanti qui sopra per scaricare l'avviso o procedere direttamente al pagamento online.</p>
            </div>
            <div class="footer">
              &copy; {$safeAppName} · Email generata automaticamente, non rispondere.
            </div>
          </div>
        </body>
        </html>
        HTML;
    }

    private function renderPendenzaCreatedTemplatePlain(
        string $toName,
        array  $pendenzaData,
        string $appName,
        string $pdfUrl,
        string $paymentUrl
    ): string {
        $greeting = $toName !== '' ? "Gentile $toName," : "Gentile Interessato,";
        $causale = $pendenzaData['causale'] ?? 'Nuova posizione debitoria';
        $importo = number_format((float)($pendenzaData['importo'] ?? 0.0), 2, ',', '.');
        $iuv = $this->resolveNoticeCode($pendenzaData);
        $dataScadenza = $pendenzaData['dataScadenza'] ?? '';
        
        $scadenzaLine = $dataScadenza !== '' ? "\nData scadenza: $dataScadenza" : '';
        $iuvLine = $iuv !== '' ? "\nIUV: $iuv" : '';
        $pdfLine = $pdfUrl !== '' ? "\n\nScarica PDF avviso:\n$pdfUrl" : '';
        $paymentLine = $paymentUrl !== '' ? "\n\nPaga ora:\n$paymentUrl" : '';
        
        return <<<TEXT
        {$greeting}

        è stata creata una nuova posizione debitoria a tuo carico. Di seguito i dettagli:

        Causale: {$causale}
        Importo: € {$importo}{$iuvLine}{$scadenzaLine}{$pdfLine}{$paymentLine}

        Utilizza i link qui sopra per scaricare l'avviso o procedere direttamente al pagamento online.

        -- {$appName}
        TEXT;
    }

    private function renderPendenzaReceiptTemplate(
        string $toName,
        array  $pendenzaData,
        string $iuv,
        string $tipologia,
        string $dataPagamento,
        string $appName,
        string $receiptUrl,
        bool   $hasEmbeddedLogo = false,
        string $logoSrc = ''
    ): string {
        $safeToName  = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $causale     = htmlspecialchars($pendenzaData['causale'] ?? 'Ricevuta pagamento', ENT_QUOTES, 'UTF-8');
        $importo     = number_format((float)($pendenzaData['importo'] ?? 0.0), 2, ',', '.');
        $safeIuv     = htmlspecialchars($iuv, ENT_QUOTES, 'UTF-8');
        $safeTipologia = htmlspecialchars($tipologia, ENT_QUOTES, 'UTF-8');
        $safeDataPagamento = htmlspecialchars($dataPagamento, ENT_QUOTES, 'UTF-8');

        $greeting = $safeToName !== '' ? 'Gentile <strong>' . $safeToName . '</strong>,' : 'Gentile Interessato,';

        $actionButtons = '';
        if ($receiptUrl !== '') {
            $safeReceiptUrl = htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8');
            $actionButtons = <<<HTML
              <p style="text-align:center; margin:24px 0;">
                <a href="{$safeReceiptUrl}" class="btn" style="background:#0b3d91; color:#fff; text-decoration:none; padding:14px 32px; border-radius:6px; font-weight:600; font-size:15px; display:inline-block;">Scarica Ricevuta</a>
              </p>
HTML;
        }

        $tipologiaInfo = $safeTipologia !== '' ? "<p><strong>Tipologia:</strong> {$safeTipologia}</p>" : '';
        $iuvInfo = $safeIuv !== '' ? "<p><strong>IUV:</strong> {$safeIuv}</p>" : '';
        $dataPagamentoInfo = $safeDataPagamento !== '' ? "<p><strong>Data pagamento:</strong> {$safeDataPagamento}</p>" : '';

        $safeLogoSrc = htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8');
        if ($hasEmbeddedLogo) {
          $logoHtml = '<img src="cid:logo" alt="Logo ente" style="max-width:120px; height:auto; margin-bottom:12px; display:block; margin-left:auto; margin-right:auto;">'
            . '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        } elseif ($safeLogoSrc !== '') {
          $logoHtml = '<img src="' . $safeLogoSrc . '" alt="Logo ente" style="max-width:120px; height:auto; margin-bottom:12px; display:block; margin-left:auto; margin-right:auto;">'
            . '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        } else {
          $logoHtml = '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="it">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Ricevuta PagoPA - {$causale}</title>
          <style>
            body { margin:0; padding:0; background:#f4f7fa; font-family: 'Helvetica Neue', Arial, sans-serif; color:#333; }
            .wrapper { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
            .header { background:#0b3d91; padding:28px 32px; text-align:center; }
            .header h1 { margin:0; color:#fff; font-size:20px; font-weight:600; letter-spacing:.5px; }
            .header img { max-width:120px; height:auto; margin-bottom:12px; display:block; margin-left:auto; margin-right:auto; }
            .body { padding:32px; }
            .body p { line-height:1.6; margin:0 0 16px; }
            .info-box { background:#f8f9fa; border-left:4px solid #0b3d91; padding:16px; margin:16px 0; border-radius:4px; }
            .info-box p { margin:8px 0; }
            .btn { display:inline-block; margin:8px 4px; padding:14px 32px; background:#0b3d91; color:#fff; text-decoration:none; border-radius:6px; font-weight:600; font-size:15px; }
            .footer { background:#f4f7fa; padding:18px 32px; text-align:center; font-size:12px; color:#999; }
          </style>
        </head>
        <body>
          <div class="wrapper">
            <div class="header">
              {$logoHtml}
            </div>
            <div class="body">
              <p>{$greeting}</p>
              <p>abbiamo ricevuto e registrato il pagamento relativo alla pendenza in oggetto. Di seguito i dettagli della ricevuta:</p>
              <div class="info-box">
                <p><strong>Causale:</strong> {$causale}</p>
                <p><strong>Importo:</strong> &euro; {$importo}</p>
                {$tipologiaInfo}
                {$iuvInfo}
                {$dataPagamentoInfo}
              </div>
              {$actionButtons}
              <p style="font-size:13px; color:#666;">Utilizza il pulsante qui sopra per scaricare la ricevuta di pagamento.</p>
            </div>
            <div class="footer">
              &copy; {$safeAppName} · Email generata automaticamente, non rispondere.
            </div>
          </div>
        </body>
        </html>
        HTML;
    }

    private function renderPendenzaReceiptTemplatePlain(
        string $toName,
        array  $pendenzaData,
        string $iuv,
        string $tipologia,
        string $dataPagamento,
        string $appName,
        string $receiptUrl
    ): string {
        $causale = $pendenzaData['causale'] ?? 'Ricevuta pagamento';
        $importo = number_format((float)($pendenzaData['importo'] ?? 0.0), 2, ',', '.');
        $greeting = $toName !== '' ? "Gentile {$toName}," : "Gentile Interessato,";

        $text = "{$greeting}\n\n";
        $text .= "Ti confermiamo che abbiamo ricevuto e registrato il pagamento relativo alla pendenza in oggetto.\n\n";
        $text .= "Dettagli:\n";
        $text .= "- Causale: {$causale}\n";
        $text .= "- Importo: € {$importo}\n";
        if ($tipologia !== '') {
            $text .= "- Tipologia: {$tipologia}\n";
        }
        if ($iuv !== '') {
            $text .= "- IUV: {$iuv}\n";
        }
        if ($dataPagamento !== '') {
            $text .= "- Data pagamento: {$dataPagamento}\n";
        }
        if ($receiptUrl !== '') {
            $text .= "\nPuoi scaricare la ricevuta a questo link: {$receiptUrl}\n";
        }
        $text .= "\n---\n{$appName} - Email generata automaticamente, non rispondere.";

        return $text;
    }

    /**
     * Invia un'unica email di riepilogo per un piano di rateizzazione.
     *
     * @param string $toEmail    Email del destinatario
     * @param string $toName     Nome del destinatario
     * @param array  $data       ['causale', 'importo_totale', 'rates' => [['indice','importo','dataScadenza','numeroAvviso']]]
     * @param string $appName    Nome dell'applicazione
     * @param string $logoPath   Path del file logo da allegare (opzionale)
     * @return array ['timestamp', 'esito', 'destinatario', 'canale', 'errore']
     */
    public function sendRateizzazioneNotification(
        string $toEmail,
        string $toName,
        array  $data,
        string $appName = '',
        string $logoPath = ''
    ): array {
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }

        $timestamp = date('Y-m-d H:i:s');

        try {
            $hasEmbeddedLogo = ($logoPath !== '' && file_exists($logoPath));
            $logoSrc = SettingsRepository::get('ui', 'logo_src', '');
            $htmlBody = $this->renderRateizzazioneTemplate($toName, $data, $appName, $hasEmbeddedLogo, $logoSrc);
            $textBody = $this->renderRateizzazioneTemplatePlain($toName, $data, $appName);

            $causale = $data['causale'] ?? 'Piano di rateizzazione';
            $numRate = count($data['rates'] ?? []);
            $email = (new Email())
                ->from($this->from)
                ->to(new Address($toEmail, $toName))
                ->subject("Piano di rateizzazione PagoPA - \"$causale\" ($numRate rate)")
                ->html($htmlBody)
                ->text($textBody);

            if ($hasEmbeddedLogo) {
                $email->embedFromPath($logoPath, 'logo');
            }

            $this->mailer->send($email);

            return [
                'timestamp'    => $timestamp,
                'esito'        => 'OK',
                'destinatario' => $toEmail,
                'canale'       => 'email',
            ];
        } catch (\Throwable $e) {
            return [
                'timestamp'    => $timestamp,
                'esito'        => 'ERRORE',
                'destinatario' => $toEmail,
                'canale'       => 'email',
                'errore'       => $e->getMessage(),
            ];
        }
    }

    /** @param string[] $destinatari @param array<int,array> $righeDaConfermare @param array<int,array> $righeInformative */
    public function sendRendicontazioneOperatoreDigest(
        array  $destinatari,
        string $gruppoNome,
        array  $righeDaConfermare,
        array  $righeInformative,
        string $baseUrlVista,
        string $appName = ''
    ): array {
        if (empty($destinatari) || (empty($righeDaConfermare) && empty($righeInformative))) {
            return ['esito' => 'SKIPPED'];
        }
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }

        $logoPath = $this->resolveLogoPath();
        $hasLogo  = ($logoPath !== '' && is_file($logoPath));
        $logoSrc  = SettingsRepository::get('ui', 'logo_src', '');

        $html = $this->renderRendicontazioneOperatoreTemplate($gruppoNome, $righeDaConfermare, $righeInformative, $baseUrlVista, $appName, $hasLogo, $logoSrc);
        $text = $this->renderRendicontazioneOperatorePlain($gruppoNome, $righeDaConfermare, $righeInformative, $baseUrlVista);

        foreach ($destinatari as $to) {
            $email = (new Email())
                ->from($this->from)
                ->to(new Address($to))
                ->subject("Nuovi pagamenti — {$gruppoNome} (PagoPA GIL)")
                ->html($html)
                ->text($text);
            if ($hasLogo) {
                $email->embedFromPath($logoPath, 'logo');
            }
            $this->mailer->send($email);
        }

        return ['esito' => 'OK'];
    }

    /** @param string[] $destinatari @param array<int,array> $righeGestite */
    public function sendRendicontazioneAdminDigest(array $destinatari, array $righeGestite, string $appName = ''): array
    {
        if (empty($destinatari) || empty($righeGestite)) {
            return ['esito' => 'SKIPPED'];
        }
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }

        $logoPath = $this->resolveLogoPath();
        $hasLogo  = ($logoPath !== '' && is_file($logoPath));
        $logoSrc  = SettingsRepository::get('ui', 'logo_src', '');

        $html = $this->renderRendicontazioneAdminTemplate($righeGestite, $appName, $hasLogo, $logoSrc);
        $text = $this->renderRendicontazioneAdminPlain($righeGestite);
        $oggi = date('d/m/Y');

        foreach ($destinatari as $to) {
            $email = (new Email())
                ->from($this->from)
                ->to(new Address($to))
                ->subject("Riepilogo rendicontazione automatica GovPay — {$oggi} (PagoPA GIL)")
                ->html($html)
                ->text($text);
            if ($hasLogo) {
                $email->embedFromPath($logoPath, 'logo');
            }
            $this->mailer->send($email);
        }

        return ['esito' => 'OK'];
    }

    private function getBackofficeBaseUrl(): string
    {
        return rtrim((string)SettingsRepository::get('backoffice', 'public_base_url', ''), '/');
    }

    private function renderRendicontazioneOperatoreTemplate(
        string $gruppoNome,
        array  $righeDaConfermare,
        array  $righeInformative,
        string $baseUrlVista,
        string $appName,
        bool   $hasLogo = false,
        string $logoSrc = ''
    ): string {
        $safeGruppo = htmlspecialchars($gruppoNome, ENT_QUOTES, 'UTF-8');

        $backofficeBaseUrl = $this->getBackofficeBaseUrl();

        // Se baseUrlVista non è assoluto, prefissa con il base URL del backoffice (se disponibile)
        if ($backofficeBaseUrl !== '' && !str_starts_with($baseUrlVista, 'http://') && !str_starts_with($baseUrlVista, 'https://')) {
            $baseUrlVista = $backofficeBaseUrl . '/' . ltrim($baseUrlVista, '/');
        }

        $rowsHtml = function (array $righe) use ($backofficeBaseUrl): string {
            $out = '';
            foreach ($righe as $r) {
                $iuv = htmlspecialchars((string)($r['iuv'] ?? ''), ENT_QUOTES, 'UTF-8');
                $importo = number_format((float)($r['importo'] ?? 0), 2, ',', '.');
                $dataRaw = (string)($r['data_pagamento'] ?? '');
                $data = $dataRaw !== '' ? htmlspecialchars(date('d/m/Y', strtotime($dataRaw)), ENT_QUOTES, 'UTF-8') : '';
                
                $causale = htmlspecialchars((string)($r['causale'] ?? $r['descrizione_entrata'] ?? ''), ENT_QUOTES, 'UTF-8');
                $nomDebitore = htmlspecialchars((string)($r['nominativo_debitore'] ?? ''), ENT_QUOTES, 'UTF-8');
                $cfDebitore = htmlspecialchars((string)($r['cf_debitore'] ?? ''), ENT_QUOTES, 'UTF-8');
                
                $debitoreHtml = '';
                if ($nomDebitore !== '' || $cfDebitore !== '') {
                    $debitoreHtml = "<div style=\"font-size:11px;color:#718096;margin-top:2px;\">Debitore: <strong style=\"color:#4a5568;\">{$nomDebitore}</strong> ({$cfDebitore})</div>";
                }
                
                $linkHtml = '';
                if (!empty($r['id_pendenza']) && $backofficeBaseUrl !== '') {
                    $detailUrl = $backofficeBaseUrl . '/pendenze/dettaglio/' . rawurlencode((string)$r['id_pendenza']);
                    $linkHtml = " <a href=\"{$detailUrl}\" style=\"color:#0066cc;text-decoration:none;font-size:11px;font-weight:600;margin-left:8px;padding:2px 6px;background:#ebf8ff;border-radius:4px;display:inline-block;\">Vedi dettaglio</a>";
                }
                
                $out .= "<tr>"
                      . "<td style=\"padding:12px 8px;border-bottom:1px solid #edf2f7;\">"
                      . "<div style=\"font-size:13px;\">IUV: <strong style=\"font-family:Consolas,Monaco,monospace;color:#1a202c;letter-spacing:0.3px;\">{$iuv}</strong>{$linkHtml}</div>"
                      . "<div style=\"font-size:12px;color:#2d3748;margin-top:4px;line-height:1.4;\">{$causale}</div>"
                      . $debitoreHtml
                      . "</td>"
                      . "<td style=\"padding:12px 8px;text-align:right;border-bottom:1px solid #edf2f7;font-weight:600;color:#1a202c;white-space:nowrap;font-size:13px;vertical-align:middle;\">&euro; {$importo}</td>"
                      . "<td style=\"padding:12px 8px;text-align:center;border-bottom:1px solid #edf2f7;color:#4a5568;white-space:nowrap;font-size:12px;vertical-align:middle;\">{$data}</td>"
                      . "</tr>\n";
            }
            return $out;
        };

        $sezioneDaConfermare = '';
        if (!empty($righeDaConfermare)) {
            $safeUrl = htmlspecialchars($baseUrlVista, ENT_QUOTES, 'UTF-8');
            $sezioneDaConfermare = '<h3 style="font-size:15px;font-weight:700;color:#1a202c;margin:24px 0 12px;text-transform:uppercase;letter-spacing:0.5px;">Da confermare</h3>'
                . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-family:\'Helvetica Neue\',Arial,sans-serif;">'
                . '<thead>'
                . '<tr style="border-bottom:2px solid #e2e8f0;color:#718096;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">'
                . '<th style="padding:10px 8px;text-align:left;font-weight:600;">Dettaglio / Debitore</th>'
                . '<th style="padding:10px 8px;text-align:right;font-weight:600;width:100px;">Importo</th>'
                . '<th style="padding:10px 8px;text-align:center;font-weight:600;width:90px;">Data</th>'
                . '</tr>'
                . '</thead>'
                . '<tbody>' . $rowsHtml($righeDaConfermare) . '</tbody>'
                . '</table>'
                . "<p style=\"margin-top:20px;text-align:center;\"><a href=\"{$safeUrl}\" style=\"display:inline-block;padding:12px 28px;background:#0066cc;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;box-shadow:0 4px 6px rgba(0,102,204,0.15);\">Gestisci pendenze</a></p>";
        }

        $sezioneInformativa = '';
        if (!empty($righeInformative)) {
            $sezioneInformativa = '<h3 style="font-size:15px;font-weight:700;color:#1a202c;margin:24px 0 12px;text-transform:uppercase;letter-spacing:0.5px;">Registrati automaticamente</h3>'
                . '<table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-family:\'Helvetica Neue\',Arial,sans-serif;">'
                . '<thead>'
                . '<tr style="border-bottom:2px solid #e2e8f0;color:#718096;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">'
                . '<th style="padding:10px 8px;text-align:left;font-weight:600;">Dettaglio / Debitore</th>'
                . '<th style="padding:10px 8px;text-align:right;font-weight:600;width:100px;">Importo</th>'
                . '<th style="padding:10px 8px;text-align:center;font-weight:600;width:90px;">Data</th>'
                . '</tr>'
                . '</thead>'
                . '<tbody>' . $rowsHtml($righeInformative) . '</tbody>'
                . '</table>';
        }

        $body = "<div style=\"background-color:#f7fafc;border-left:4px solid #0066cc;padding:16px;margin-bottom:24px;border-radius:4px;\">"
              . "<p style=\"margin:0;font-size:13px;line-height:1.5;color:#4a5568;\">"
              . "Comunicazione automatica dal modulo <strong>Rendicontazione PagoPA</strong> di GIL (GovPay Interaction Layer) &mdash; gruppo <strong>{$safeGruppo}</strong>."
              . "</p>"
              . "</div>"
              . $sezioneDaConfermare
              . $sezioneInformativa;

        return $this->renderEmailBase($body, $appName, "Nuovi pagamenti — {$gruppoNome}", $hasLogo, $logoSrc);
    }

    private function renderRendicontazioneOperatorePlain(string $gruppoNome, array $righeDaConfermare, array $righeInformative, string $baseUrlVista): string
    {
        $publicBaseUrl = $this->getBackofficeBaseUrl();
        $lines = ["Nuovi pagamenti — {$gruppoNome}", ''];
        
        $renderRighe = function(array $righe) use ($publicBaseUrl) {
            $out = [];
            foreach ($righe as $r) {
                $dataRaw = (string)($r['data_pagamento'] ?? '');
                $data = $dataRaw !== '' ? date('d/m/Y', strtotime($dataRaw)) : '';
                $causale = (string)($r['causale'] ?? $r['descrizione_entrata'] ?? '');
                $nom = (string)($r['nominativo_debitore'] ?? '');
                $cf = (string)($r['cf_debitore'] ?? '');
                $line = "- IUV " . ($r['iuv'] ?? '') . ' € ' . number_format((float)($r['importo'] ?? 0), 2, ',', '.') . ' (' . $data . ')';
                if ($causale !== '') {
                    $line .= "\n  Causale: " . $causale;
                }
                if ($nom !== '' || $cf !== '') {
                    $line .= "\n  Debitore: " . $nom . ' (' . $cf . ')';
                }
                if (!empty($r['id_pendenza']) && $publicBaseUrl !== '') {
                    $line .= "\n  Dettaglio: " . $publicBaseUrl . '/pendenze/dettaglio/' . rawurlencode((string)$r['id_pendenza']);
                }
                $out[] = $line;
            }
            return $out;
        };

        if (!empty($righeDaConfermare)) {
            $lines[] = 'Da confermare:';
            $lines = array_merge($lines, $renderRighe($righeDaConfermare));
            $lines[] = 'Gestisci pendenze: ' . $baseUrlVista;
            $lines[] = '';
        }
        if (!empty($righeInformative)) {
            $lines[] = 'Registrati automaticamente:';
            $lines = array_merge($lines, $renderRighe($righeInformative));
        }
        return implode("\n", $lines);
    }

    private function renderRendicontazioneAdminTemplate(array $righeGestite, string $appName, bool $hasLogo = false, string $logoSrc = ''): string
    {
        $rows = '';
        foreach ($righeGestite as $r) {
            $iuv = htmlspecialchars((string)($r['iuv'] ?? ''), ENT_QUOTES, 'UTF-8');
            $handler = htmlspecialchars((string)($r['rendicontazione_handler'] ?? ''), ENT_QUOTES, 'UTF-8');
            $stato = (string)($r['rendicontazione_stato'] ?? '');
            $color = $stato === 'ERRORE' ? '#dc3545' : '#1a202c';
            $statoSafe = htmlspecialchars($stato, ENT_QUOTES, 'UTF-8');
            $importo = number_format((float)($r['importo'] ?? 0), 2, ',', '.');

            $badgeStyle = '';
            if ($stato === 'ERRORE') {
                $badgeStyle = 'background:#fde8e8;color:#9b1c1c;padding:2px 8px;border-radius:4px;font-weight:600;font-size:11px;display:inline-block;';
            } else {
                $badgeStyle = 'background:#def7ec;color:#03543f;padding:2px 8px;border-radius:4px;font-weight:600;font-size:11px;display:inline-block;';
            }

            $rows .= "<tr>"
                   . "<td style=\"padding:12px 8px;font-family:Consolas,Monaco,monospace;font-size:12px;border-bottom:1px solid #edf2f7;color:#1a202c;\">{$iuv}</td>"
                   . "<td style=\"padding:12px 8px;border-bottom:1px solid #edf2f7;color:#4a5568;\">{$handler}</td>"
                   . "<td style=\"padding:12px 8px;border-bottom:1px solid #edf2f7;\"><span style=\"{$badgeStyle}\">{$statoSafe}</span></td>"
                   . "<td style=\"padding:12px 8px;text-align:right;border-bottom:1px solid #edf2f7;font-weight:600;color:#1a202c;white-space:nowrap;font-size:13px;vertical-align:middle;\">&euro; {$importo}</td>"
                   . "</tr>\n";
        }

        $oggi = htmlspecialchars(date('d/m/Y'), ENT_QUOTES, 'UTF-8');
        $body = "<div style=\"background-color:#f7fafc;border-left:4px solid #0066cc;padding:16px;margin-bottom:24px;border-radius:4px;\">"
              . "<p style=\"margin:0;font-size:13px;line-height:1.5;color:#4a5568;\">"
              . "Comunicazione automatica dal modulo <strong>Rendicontazione PagoPA</strong> di GIL (GovPay Interaction Layer) &mdash; riepilogo del <strong>{$oggi}</strong>."
              . "</p>"
              . "</div>"
              . '<table style="width:100%;border-collapse:collapse;font-family:\'Helvetica Neue\',Arial,sans-serif;font-size:13px;">'
              . '<thead>'
              . '<tr style="border-bottom:2px solid #e2e8f0;color:#718096;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;">'
              . '<th style="padding:10px 8px;text-align:left;font-weight:600;">IUV</th>'
              . '<th style="padding:10px 8px;text-align:left;font-weight:600;">Handler</th>'
              . '<th style="padding:10px 8px;text-align:left;font-weight:600;">Stato</th>'
              . '<th style="padding:10px 8px;text-align:right;font-weight:600;width:100px;">Importo</th>'
              . '</tr>'
              . '</thead>'
              . '<tbody>' . $rows . '</tbody>'
              . '</table>';

        return $this->renderEmailBase($body, $appName, 'Riepilogo rendicontazione automatica', $hasLogo, $logoSrc);
    }

    private function renderRendicontazioneAdminPlain(array $righeGestite): string
    {
        $lines = ['Riepilogo rendicontazione automatica GovPay', ''];
        foreach ($righeGestite as $r) {
            $lines[] = '- IUV ' . ($r['iuv'] ?? '') . ' handler=' . ($r['rendicontazione_handler'] ?? '')
                . ' stato=' . ($r['rendicontazione_stato'] ?? '') . ' € ' . number_format((float)($r['importo'] ?? 0), 2, ',', '.');
        }
        return implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Template notifica rateizzazione (HTML + testo)
    // -------------------------------------------------------------------------

    private function renderRateizzazioneTemplate(
        string $toName,
        array  $data,
        string $appName,
        bool   $hasEmbeddedLogo = false,
        string $logoSrc = ''
    ): string {
        $safeToName   = htmlspecialchars($toName,  ENT_QUOTES, 'UTF-8');
        $safeAppName  = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $causale      = htmlspecialchars($data['causale'] ?? 'Piano di rateizzazione', ENT_QUOTES, 'UTF-8');
        $importoTot   = number_format((float)($data['importo_totale'] ?? 0.0), 2, ',', '.');
        $rates        = $data['rates'] ?? [];
        $numRate      = count($rates);
        $tipologia    = htmlspecialchars($data['tipologia'] ?? '', ENT_QUOTES, 'UTF-8');
        $tipologiaInfo = $tipologia !== '' ? "<p><strong>Tipologia:</strong> {$tipologia}</p>" : '';

        $greeting = $safeToName !== '' ? 'Gentile <strong>' . $safeToName . '</strong>,' : 'Gentile Interessato,';

        $safeLogoSrc = htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8');
        if ($hasEmbeddedLogo) {
            $logoHtml = '<img src="cid:logo" alt="Logo ente" style="max-width:120px; height:auto; margin-bottom:12px; display:block; margin-left:auto; margin-right:auto;">'
                . '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        } elseif ($safeLogoSrc !== '') {
            $logoHtml = '<img src="' . $safeLogoSrc . '" alt="Logo ente" style="max-width:120px; height:auto; margin-bottom:12px; display:block; margin-left:auto; margin-right:auto;">'
                . '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        } else {
            $logoHtml = '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';
        }

        // Multi-rate PDF download button (fix 3)
        $multiratePdfUrl = htmlspecialchars($data['multiratePdfUrl'] ?? '', ENT_QUOTES, 'UTF-8');
        $allRatesBtn = '';
        if ($multiratePdfUrl !== '') {
            $allRatesBtn = '<p style="text-align:center; margin:16px 0;">'
                . '<a href="' . $multiratePdfUrl . '" style="display:inline-block; padding:10px 24px; background:#0b3d91; color:#fff; text-decoration:none; border-radius:4px; font-size:14px; font-weight:600;">'
                . 'Scarica avviso unico (tutte le rate)</a></p>';
        }

        // Card-style rate list — mobile-friendly (fix 4)
        $rateCards = '';
        foreach ($rates as $rate) {
            $idx    = (int)($rate['indice'] ?? 0);
            $imp    = number_format((float)(str_replace(',', '.', (string)($rate['importo'] ?? 0))), 2, ',', '.');
            // dataValidita fallback (fix 2)
            $scadRaw = ($rate['dataScadenza'] ?? null) ?: ($rate['dataValidita'] ?? null);
            $scad    = ($scadRaw !== null && $scadRaw !== '') ? htmlspecialchars((string)$scadRaw, ENT_QUOTES, 'UTF-8') : null;
            $avviso  = htmlspecialchars((string)($rate['numeroAvviso'] ?? ''), ENT_QUOTES, 'UTF-8');
            $pdfUrl  = htmlspecialchars((string)($rate['pdfUrl'] ?? ''), ENT_QUOTES, 'UTF-8');
            $payUrl  = htmlspecialchars((string)($rate['paymentUrl'] ?? ''), ENT_QUOTES, 'UTF-8');

            $scadLine  = $scad !== null
                ? '<div style="font-size:13px; color:#555; margin-bottom:4px;">Scadenza: ' . $scad . '</div>'
                : '';
            $avvisoLine = $avviso !== ''
                ? '<div style="font-size:11px; font-family:monospace; color:#888; margin-bottom:8px;">N. Avviso: ' . $avviso . '</div>'
                : '';

            $btns = '';
            if ($pdfUrl !== '') {
                $btns .= '<a href="' . $pdfUrl . '" style="display:inline-block; margin:2px 4px 2px 0; padding:6px 12px; background:#6c757d; color:#fff; text-decoration:none; border-radius:4px; font-size:12px; font-weight:600;">Scarica PDF</a>';
            }
            if ($payUrl !== '') {
                $btns .= '<a href="' . $payUrl . '" style="display:inline-block; margin:2px 0; padding:6px 12px; background:#0b3d91; color:#fff; text-decoration:none; border-radius:4px; font-size:12px; font-weight:600;">Paga ora</a>';
            }
            $btnsDiv = $btns !== '' ? '<div>' . $btns . '</div>' : '';

            $rateCards .= '<div style="border:1px solid #ddd; border-radius:6px; padding:12px 16px; margin-bottom:12px;">'
                        . '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">'
                        . '<span style="font-weight:700; color:#0b3d91;">Rata ' . $idx . '</span>'
                        . '<span style="font-weight:700; font-size:16px;">&euro; ' . $imp . '</span>'
                        . '</div>'
                        . $scadLine
                        . $avvisoLine
                        . $btnsDiv
                        . '</div>' . "\n";
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="it">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Piano di rateizzazione - {$causale}</title>
          <style>
            body { margin:0; padding:0; background:#f4f7fa; font-family: 'Helvetica Neue', Arial, sans-serif; color:#333; }
            .wrapper { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
            .header { background:#0b3d91; padding:28px 32px; text-align:center; }
            .body { padding:32px; }
            .body p { line-height:1.6; margin:0 0 16px; }
            .info-box { background:#f8f9fa; border-left:4px solid #0b3d91; padding:16px; margin:16px 0; border-radius:4px; }
            .info-box p { margin:8px 0; }
            .footer { background:#f4f7fa; padding:18px 32px; text-align:center; font-size:12px; color:#999; }
          </style>
        </head>
        <body>
          <div class="wrapper">
            <div class="header">{$logoHtml}</div>
            <div class="body">
              <p>{$greeting}</p>
              <p>è stato creato un piano di rateizzazione a tuo carico. Di seguito il riepilogo con i link per scaricare ogni avviso e avviare il pagamento:</p>
              <div class="info-box">
                <p><strong>Causale:</strong> {$causale}</p>
                <p><strong>Importo totale:</strong> &euro; {$importoTot}</p>
                <p><strong>Numero rate:</strong> {$numRate}</p>
                {$tipologiaInfo}
              </div>
              {$allRatesBtn}
              {$rateCards}
            </div>
            <div class="footer">&copy; {$safeAppName} · Email generata automaticamente, non rispondere.</div>
          </div>
        </body>
        </html>
        HTML;
    }

    private function renderRateizzazioneTemplatePlain(
        string $toName,
        array  $data,
        string $appName
    ): string {
        $greeting        = $toName !== '' ? "Gentile $toName," : "Gentile Interessato,";
        $causale         = $data['causale'] ?? 'Piano di rateizzazione';
        $tipologia       = (string)($data['tipologia'] ?? '');
        $importoTot      = number_format((float)($data['importo_totale'] ?? 0.0), 2, ',', '.');
        $rates           = $data['rates'] ?? [];
        $numRate         = count($rates);
        $multiratePdfUrl = (string)($data['multiratePdfUrl'] ?? '');

        $rateLines = '';
        foreach ($rates as $rate) {
            $idx     = (int)($rate['indice'] ?? 0);
            $imp     = number_format((float)(str_replace(',', '.', (string)($rate['importo'] ?? 0))), 2, ',', '.');
            // dataValidita fallback (fix 2)
            $scadRaw = ($rate['dataScadenza'] ?? null) ?: ($rate['dataValidita'] ?? null);
            $scad    = ($scadRaw !== null && $scadRaw !== '') ? (string)$scadRaw : '—';
            $avviso  = (string)($rate['numeroAvviso'] ?? '—');
            $rateLines .= "  Rata {$idx}: € {$imp} | Scadenza: {$scad} | N. Avviso: {$avviso}\n";
            if (!empty($rate['pdfUrl'])) {
                $rateLines .= "    Scarica PDF: " . $rate['pdfUrl'] . "\n";
            }
            if (!empty($rate['paymentUrl'])) {
                $rateLines .= "    Paga ora:    " . $rate['paymentUrl'] . "\n";
            }
            $rateLines .= "\n";
        }

        $tipologiaLine    = $tipologia !== '' ? "Tipologia: {$tipologia}\n" : '';
        $allRatesPdfLine  = $multiratePdfUrl !== '' ? "Scarica avviso unico (tutte le rate): {$multiratePdfUrl}\n\n" : '';

        return <<<TEXT
        {$greeting}

        è stato creato un piano di rateizzazione a tuo carico.

        Causale: {$causale}
        {$tipologiaLine}Importo totale: € {$importoTot}
        Numero rate: {$numRate}

        {$allRatesPdfLine}Dettaglio rate:
        {$rateLines}
        -- {$appName}
        TEXT;
    }

      /**
       * Preferisce il codice avviso completo (18 cifre) quando disponibile.
       */
      private function resolveNoticeCode(array $pendenzaData): string
      {
        $candidates = [
          (string)($pendenzaData['numeroAvviso'] ?? ''),
          (string)($pendenzaData['numero_avviso'] ?? ''),
          (string)($pendenzaData['iuvAvviso'] ?? ''),
          (string)($pendenzaData['iuv_avviso'] ?? ''),
          (string)($pendenzaData['noticeNumber'] ?? ''),
          (string)($pendenzaData['notice_number'] ?? ''),
          (string)($pendenzaData['iuv'] ?? ''),
        ];

        foreach ($candidates as $value) {
          $normalized = trim($value);
          if ($normalized !== '') {
            return $normalized;
          }
        }

        return '';
      }

      private function resolveLogoPath(): string
      {
        foreach ([
          '/var/www/html/public/img/stemma_ente.png',
          '/var/www/html/public/img/stemma_ente.jpg',
          '/var/www/html/public/img/stemma_ente.jpeg',
        ] as $candidate) {
          if (is_file($candidate)) {
            return $candidate;
          }
        }

        return '';
      }

    // -------------------------------------------------------------------------
    // Base template frontoffice-style (riusabile per tutte le tipologie)
    // -------------------------------------------------------------------------

    /**
     * Shell HTML completa per email istituzionali.
     * $bodyContent = HTML del contenuto centrale (tra header e footer).
     */
    private function renderEmailBase(
        string $bodyContent,
        string $appName,
        string $title = '',
        bool   $hasLogo = false,
        string $logoSrc = ''
    ): string {
        $safeApp   = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $safeTitle = htmlspecialchars($title !== '' ? $title : $appName, ENT_QUOTES, 'UTF-8');
        $year      = date('Y');

        if ($hasLogo) {
            $logoHtml = '<img src="cid:logo" alt="' . $safeApp . '" style="max-height:52px;max-width:180px;display:block;margin:0 auto 12px;" onerror="this.style.display=\'none\'">';
        } elseif ($logoSrc !== '') {
            $safeLogoSrc = htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8');
            $logoHtml = '<img src="' . $safeLogoSrc . '" alt="' . $safeApp . '" style="max-height:52px;max-width:180px;display:block;margin:0 auto 12px;">';
        } else {
            $logoHtml = '';
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="it">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width,initial-scale=1.0">
          <title>{$safeTitle} – {$safeApp}</title>
        </head>
        <body style="margin:0;padding:0;background:#edf2f7;font-family:'Helvetica Neue',Arial,sans-serif;color:#1a202c;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#edf2f7;">
            <tr><td align="center" style="padding:32px 16px;">
              <table role="presentation" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.10);">
                <!-- Header -->
                <tr>
                  <td style="background:#0066cc;padding:28px 32px;text-align:center;">
                    {$logoHtml}
                    <div style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:.2px;">{$safeApp}</div>
                  </td>
                </tr>
                <!-- Body -->
                <tr>
                  <td style="padding:32px;">
                    {$bodyContent}
                  </td>
                </tr>
                <!-- pagoPA strip -->
                <tr>
                  <td style="background:#004a99;padding:14px 32px;text-align:center;">
                    <span style="color:rgba(255,255,255,.6);font-size:11px;text-transform:uppercase;letter-spacing:.08em;">Pagamenti tramite</span>
                    <strong style="color:#ffffff;font-size:13px;display:block;margin-top:2px;letter-spacing:.4px;">pagoPA</strong>
                  </td>
                </tr>
                <!-- Footer -->
                <tr>
                  <td style="background:#f7fafc;padding:16px 32px;text-align:center;border-top:1px solid #e2e8f0;">
                    <p style="margin:0;font-size:11px;color:#a0aec0;">&copy; {$year} {$safeApp} &middot; Messaggio generato automaticamente, non rispondere.</p>
                  </td>
                </tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    // -------------------------------------------------------------------------
    // Notifica avviso spontaneo
    // -------------------------------------------------------------------------

    /**
     * Invia notifica all'utente dopo la generazione di un avviso spontaneo.
     *
     * @param array $avviso Keys: causale, importo, numeroAvviso, data_scadenza,
     *                      download_url, checkout_url
     * @return array ['timestamp', 'esito', 'destinatario', 'canale'] + 'errore' on failure
     */
    public function sendSpontaneoAvviso(
        string $toEmail,
        string $toName,
        array  $avviso,
        string $appName = ''
    ): array {
        if ($appName === '') {
            $appName = SettingsRepository::get('entity', 'name', 'GIL') ?: 'GIL';
        }
        $timestamp = date('Y-m-d H:i:s');

        try {
            $logoPath = $this->resolveLogoPath();
            $hasLogo  = ($logoPath !== '' && is_file($logoPath));
            $logoSrc  = SettingsRepository::get('ui', 'logo_src', '');

            $causale = (string)($avviso['causale'] ?? 'Avviso pagoPA');
            $content  = $this->renderSpontaneoContent($toName, $avviso, $appName);
            $htmlBody = $this->renderEmailBase($content, $appName, $causale, $hasLogo, $logoSrc);
            $textBody = $this->renderSpontaneoPlain($toName, $avviso, $appName);

            $email = (new Email())
                ->from($this->from)
                ->to(new Address($toEmail, $toName))
                ->subject("Avviso pagoPA generato — {$causale}")
                ->html($htmlBody)
                ->text($textBody);

            if ($hasLogo) {
                $email->embedFromPath($logoPath, 'logo');
            }

            $this->mailer->send($email);

            return ['timestamp' => $timestamp, 'esito' => 'OK', 'destinatario' => $toEmail, 'canale' => 'email'];
        } catch (\Throwable $e) {
            return ['timestamp' => $timestamp, 'esito' => 'ERRORE', 'destinatario' => $toEmail, 'canale' => 'email', 'errore' => $e->getMessage()];
        }
    }

    private function renderSpontaneoContent(string $toName, array $avviso, string $appName): string
    {
        $safeApp     = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $safeName    = htmlspecialchars($toName,  ENT_QUOTES, 'UTF-8');
        $causale     = htmlspecialchars((string)($avviso['causale'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $importo     = number_format((float)($avviso['importo'] ?? 0), 2, ',', '.');
        $iuv         = htmlspecialchars((string)($avviso['numeroAvviso'] ?? ''), ENT_QUOTES, 'UTF-8');
        $scadRaw     = (string)($avviso['data_scadenza'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $scadRaw)) {
            try { $scadRaw = (new \DateTimeImmutable($scadRaw))->format('d/m/Y'); } catch (\Throwable $e) {}
        }
        $scadenza    = htmlspecialchars($scadRaw, ENT_QUOTES, 'UTF-8');
        $downloadUrl = htmlspecialchars((string)($avviso['download_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $checkoutUrl = htmlspecialchars((string)($avviso['checkout_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $greeting    = $safeName !== '' ? 'Gentile <strong>' . $safeName . '</strong>,' : 'Gentile cittadino,';

        $rowStyle   = 'padding:10px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:top;';
        $keyStyle   = $rowStyle . 'width:40%;color:#64748b;';
        $valStyle   = $rowStyle . 'color:#0f172a;font-weight:500;';

        $iuvRow = $iuv !== '' ? "
          <tr>
            <td style=\"{$keyStyle}\">Codice avviso (IUV)</td>
            <td style=\"{$valStyle} font-family:monospace;letter-spacing:.05em;\">{$iuv}</td>
          </tr>" : '';

        $scadRow = $scadenza !== '' ? "
          <tr>
            <td style=\"{$keyStyle}\">Scadenza</td>
            <td style=\"{$valStyle}\">{$scadenza}</td>
          </tr>" : '';

        $pdfLabel = str_contains($downloadUrl, '/avviso-bollo') ? 'Visualizza bollettino' : 'Scarica PDF avviso';
        $pdfBtn = $downloadUrl !== '' ? "
              <td style=\"padding:0 6px 0 0;\">
                <a href=\"{$downloadUrl}\" style=\"display:inline-block;padding:12px 22px;background:#f1f5f9;color:#0f172a;text-decoration:none;border-radius:8px;font-size:14px;font-weight:600;border:1px solid #cbd5e1;\">{$pdfLabel}</a>
              </td>" : '';

        $payBtn = $checkoutUrl !== '' ? "
              <td style=\"padding:0;\">
                <a href=\"{$checkoutUrl}\" style=\"display:inline-block;padding:12px 22px;background:#0066cc;color:#ffffff;text-decoration:none;border-radius:8px;font-size:14px;font-weight:700;\">Paga subito</a>
              </td>" : '';

        $buttons = ($pdfBtn !== '' || $payBtn !== '') ? "
          <table role=\"presentation\" cellpadding=\"0\" cellspacing=\"0\" style=\"margin:0 auto 24px;\">
            <tr>{$pdfBtn}{$payBtn}</tr>
          </table>" : '';

        return <<<HTML
        <p style="margin:0 0 4px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#64748b;">Avviso pagoPA generato</p>
        <h2 style="margin:0 0 20px;font-size:22px;font-weight:700;color:#0f172a;">Pagamento spontaneo</h2>
        <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">
          {$greeting} il tuo avviso pagoPA è stato emesso con successo.
          Puoi procedere al pagamento online oppure stampare l'avviso per pagare presso un PSP fisico.
        </p>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;margin:0 0 24px;">
          <tr>
            <td colspan="2" style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:10px 16px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="font-size:12px;color:#64748b;">Ente creditore &nbsp;<strong style="color:#0f172a;">{$safeApp}</strong></td>
                  <td align="right"><span style="background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;white-space:nowrap;">&bull; Da pagare</span></td>
                </tr>
              </table>
            </td>
          </tr>
          {$iuvRow}
          <tr>
            <td style="{$keyStyle}">Causale</td>
            <td style="{$valStyle}">{$causale}</td>
          </tr>
          <tr>
            <td style="{$keyStyle}">Importo</td>
            <td style="{$valStyle} font-size:15px;font-weight:700;">&euro;&nbsp;{$importo}</td>
          </tr>
          {$scadRow}
        </table>

        {$buttons}

        <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.55;">
          Per scaricare la ricevuta dopo il pagamento o recuperare l'avviso in futuro, torna su <strong>{$safeApp}</strong>
          con il codice avviso e il codice fiscale del debitore.
        </p>
        HTML;
    }

    private function renderSpontaneoPlain(string $toName, array $avviso, string $appName): string
    {
        $greeting = $toName !== '' ? "Gentile {$toName}," : 'Gentile cittadino,';
        $causale  = (string)($avviso['causale'] ?? '—');
        $importo  = number_format((float)($avviso['importo'] ?? 0), 2, ',', '.');
        $iuv      = (string)($avviso['numeroAvviso'] ?? '');
        $scadRaw  = (string)($avviso['data_scadenza'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $scadRaw)) {
            try { $scadRaw = (new \DateTimeImmutable($scadRaw))->format('d/m/Y'); } catch (\Throwable $e) {}
        }
        $scadenza = $scadRaw;
        $pdfUrl   = (string)($avviso['download_url'] ?? '');
        $payUrl   = (string)($avviso['checkout_url'] ?? '');

        $iuvLine   = $iuv !== ''      ? "\nCodice avviso (IUV): {$iuv}"  : '';
        $scadLine  = $scadenza !== '' ? "\nScadenza: {$scadenza}"         : '';
        $pdfLabel2 = str_contains($pdfUrl, '/avviso-bollo') ? 'Visualizza bollettino' : 'Scarica PDF avviso';
        $pdfLine   = $pdfUrl !== ''   ? "\n\n{$pdfLabel2}:\n{$pdfUrl}"  : '';
        $payLine   = $payUrl !== ''   ? "\n\nPaga subito:\n{$payUrl}"         : '';

        return <<<TEXT
        {$greeting}

        il tuo avviso pagoPA è stato generato con successo.

        Causale: {$causale}
        Importo: € {$importo}{$iuvLine}{$scadLine}{$pdfLine}{$payLine}

        Per recuperare l'avviso in futuro, accedi a {$appName} con il codice avviso e il codice fiscale del debitore.

        -- {$appName}
        TEXT;
    }
}
