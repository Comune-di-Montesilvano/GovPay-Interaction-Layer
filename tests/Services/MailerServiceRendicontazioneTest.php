<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\MailerService;
use PHPUnit\Framework\TestCase;

final class MailerServiceRendicontazioneTest extends TestCase
{
    public function testDigestOperatoreVuotoRitornaSkipped(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendRendicontazioneOperatoreDigest([], 'Ufficio Tributi', [], [], 'http://x');
        $this->assertSame('SKIPPED', $result['esito']);
    }

    public function testDigestAdminVuotoRitornaSkipped(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendRendicontazioneAdminDigest([], []);
        $this->assertSame('SKIPPED', $result['esito']);
    }

    public function testRenderRendicontazioneOperatore(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        // We will call the send method with an empty destinatario list, but since that skips before rendering,
        // we can use reflection or pass a destination that won't send, or we can just test with a mock.
        // Wait, sendRendicontazioneOperatoreDigest returns SKIPPED if destinatari is empty.
        // So we must pass a recipient to trigger rendering, but Symfony mailer might try to send it.
        // Let's check how mailer is configured for tests.
        $result = $mailer->sendRendicontazioneOperatoreDigest(
            ['test@example.com'],
            'Ufficio Tributi',
            [
                [
                    'iuv' => '00000000000479353',
                    'importo' => 2600.00,
                    'data_pagamento' => '2026-07-14',
                    'causale' => 'concessione loculo 1 fila',
                    'nominativo_debitore' => 'ANTONELLA PIERSANTE',
                    'cf_debitore' => 'PRSNNL65C61F765S',
                    'id_pendenza' => 456
                ]
            ],
            [],
            'http://x'
        );
        $this->assertSame('OK', $result['esito']);
    }

    public function testRenderRendicontazioneAdmin(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendRendicontazioneAdminDigest(
            ['admin@example.com'],
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
        $this->assertSame('OK', $result['esito']);
    }

    public function testDigestOperatoreRitornaErroreConDestinatarioInvalido(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendRendicontazioneOperatoreDigest(
            ['invalid-email-address-without-at-symbol'],
            'Ufficio Tributi',
            [['iuv' => '123', 'importo' => 10]],
            [],
            'http://x'
        );
        $this->assertSame('ERRORE', $result['esito']);
        $this->assertNotEmpty($result['errore'] ?? '');
    }

    public function testDigestAdminRitornaErroreConDestinatarioInvalido(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendRendicontazioneAdminDigest(
            ['invalid-email-address-without-at-symbol'],
            [['iuv' => '123', 'importo' => 10, 'rendicontazione_handler' => 'OK', 'rendicontazione_stato' => 'OK']]
        );
        $this->assertSame('ERRORE', $result['esito']);
        $this->assertNotEmpty($result['errore'] ?? '');
    }
}
