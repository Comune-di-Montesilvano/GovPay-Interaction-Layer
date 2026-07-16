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
    $mailer = MailerService::forSuite('backoffice');
    
    echo "Invio email di test a: $recipient...\n";
    
    $result = $mailer->sendRendicontazioneOperatoreDigest(
        [$recipient],
        'Ufficio Tributi (Test)',
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
    
    echo "Esito: " . json_encode($result) . "\n";
} catch (\Throwable $e) {
    echo "ERRORE: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
