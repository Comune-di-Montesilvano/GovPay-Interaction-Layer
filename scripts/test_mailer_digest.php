<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\SettingsRepository;
use App\Services\MailerService;
use Dotenv\Dotenv;

if (class_exists(\Dotenv\Dotenv::class) && file_exists(dirname(__DIR__) . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
}
if (!getenv('DB_HOST')) {
    putenv('DB_HOST=db');
}

$recipient = $argv[1] ?? null;
if (!$recipient) {
    echo "Uso: php scripts/test_mailer_digest.php <email_destinatario>\n";
    exit(1);
}

try {
    $configuredAdminEmails = SettingsRepository::get('rendicontazione', 'admin_emails', '');
    echo "Configurazione DB rendicontazione.admin_emails: '$configuredAdminEmails'\n";

    $mailer = MailerService::forSuite('backoffice');
    
    echo "1. Invio Digest Operatore di test a: $recipient...\n";
    $resOp = $mailer->sendRendicontazioneOperatoreDigest(
        [$recipient],
        'Ufficio Tributi (Test Operatore)',
        [
            [
                'iuv' => '00000000000479353',
                'importo' => 2600.00,
                'data_pagamento' => date('Y-m-d'),
                'causale' => 'Test concessione loculo 1 fila',
                'nominativo_debitore' => 'ANTONELLA PIERSANTE',
                'cf_debitore' => 'PRSNNL65C61F765S',
                'id_pendenza' => 123
            ]
        ],
        [],
        '/rendicontazione/da-confermare'
    );
    echo "Esito Operatore: " . json_encode($resOp) . "\n\n";

    echo "2. Invio Digest Admin di test a: $recipient...\n";
    $resAdmin = $mailer->sendRendicontazioneAdminDigest(
        [$recipient],
        [
            [
                'iuv' => '00000000000479353',
                'importo' => 2600.00,
                'rendicontazione_handler' => 'AUTO_ESTERNO',
                'rendicontazione_stato' => 'OK'
            ],
            [
                'iuv' => '00000000000479252',
                'importo' => 2400.00,
                'rendicontazione_handler' => 'GERI',
                'rendicontazione_stato' => 'ERRORE'
            ]
        ]
    );
    echo "Esito Admin: " . json_encode($resAdmin) . "\n";

} catch (\Throwable $e) {
    echo "ERRORE: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
