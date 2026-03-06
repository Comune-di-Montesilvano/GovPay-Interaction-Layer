<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */
declare(strict_types=1);

namespace App\Services;

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
        $prefix = strtoupper($suite);

        $dsn       = (string)(getenv("{$prefix}_MAILER_DSN")       ?: 'null://null');
        $fromAddr  = (string)(getenv("{$prefix}_MAILER_FROM_ADDRESS") ?: 'noreply@example.com');
        $fromName  = (string)(getenv("{$prefix}_MAILER_FROM_NAME")    ?: 'GIL');

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
            $appName = (string)(getenv('APP_ENTITY_NAME') ?: 'GIL');
        }

        $htmlBody = $this->renderResetTemplate($toName, $resetUrl, $appName, $expiresMinutes);
        $textBody = $this->renderResetTemplatePlain($toName, $resetUrl, $appName, $expiresMinutes);

        $email = (new Email())
            ->from($this->from)
            ->to(new Address($toEmail, $toName))
            ->subject("[$appName] Reimposta la tua password")
            ->html($htmlBody)
            ->text($textBody);

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
            $appName = (string)(getenv('APP_ENTITY_NAME') ?: 'GIL');
        }

        $timestamp = date('Y-m-d H:i:s');
        
        try {
            $htmlBody = $this->renderPendenzaCreatedTemplate($toName, $pendenzaData, $appName, $pdfUrl, $paymentUrl);
            $textBody = $this->renderPendenzaCreatedTemplatePlain($toName, $pendenzaData, $appName, $pdfUrl, $paymentUrl);

            $causale = $pendenzaData['causale'] ?? 'Nuova pendenza';
            $email = (new Email())
                ->from($this->from)
                ->to(new Address($toEmail, $toName))
                ->subject("Pendenza Pagopa - \"$causale\"")
                ->html($htmlBody)
                ->text($textBody);
            
            // Allega il logo se specificato
            if ($logoPath !== '' && file_exists($logoPath)) {
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

    // -------------------------------------------------------------------------
    // Template notifica creazione pendenza (HTML + testo)
    // -------------------------------------------------------------------------

    private function renderPendenzaCreatedTemplate(
        string $toName,
        array  $pendenzaData,
        string $appName,
        string $pdfUrl,
        string $paymentUrl
    ): string {
        $safeToName  = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $causale     = htmlspecialchars($pendenzaData['causale'] ?? 'Nuova posizione debitoria', ENT_QUOTES, 'UTF-8');
        $importo     = number_format((float)($pendenzaData['importo'] ?? 0.0), 2, ',', '.');
        $iuv         = htmlspecialchars((string)($pendenzaData['iuv'] ?? $pendenzaData['numeroAvviso'] ?? ''), ENT_QUOTES, 'UTF-8');
        $dataScadenza = '';
        if (!empty($pendenzaData['dataScadenza'])) {
            $dataScadenza = htmlspecialchars($pendenzaData['dataScadenza'], ENT_QUOTES, 'UTF-8');
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

        $iuvInfo = $iuv !== '' ? "<p><strong>IUV:</strong> {$iuv}</p>" : '';

        // Logo: usa cid:logo se disponibile, altrimenti nessuna immagine
        $logoHtml = '<h1 style="margin:0; color:#fff; font-size:20px; font-weight:600;">' . $safeAppName . '</h1>';

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
                <p><strong>Importo:</strong> € {$importo}</p>
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
        $iuv = (string)($pendenzaData['iuv'] ?? $pendenzaData['numeroAvviso'] ?? '');
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
}
