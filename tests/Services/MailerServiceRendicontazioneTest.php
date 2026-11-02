<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\MailerService;
use PHPUnit\Framework\TestCase;
use Tests\Support\Synthetic;

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
                    'iuv' => Synthetic::iuv('0', 4242),
                    'importo' => 126.50,
                    'data_pagamento' => '2026-07-14',
                    'causale' => 'Servizio di prova',
                    'nominativo_debitore' => Synthetic::nome(3),
                    'cf_debitore' => Synthetic::cf(3),
                    'id_pendenza' => 456
                ]
            ],
            [],
            'http://x'
        );
        $this->assertSame('OK', $result['esito']);
    }

    public function testSollecitoSmarcaturaVuotoRitornaSkipped(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $this->assertSame('SKIPPED', $mailer->sendRendicontazioneSollecitoSmarcatura([], 'Gruppo Prova', [['iuv' => '1']], 'http://x', 7)['esito']);
        $this->assertSame('SKIPPED', $mailer->sendRendicontazioneSollecitoSmarcatura(['test@example.com'], 'Gruppo Prova', [], 'http://x', 7)['esito']);
    }

    public function testRenderRendicontazioneSollecitoSmarcatura(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendRendicontazioneSollecitoSmarcatura(
            ['test@example.com'],
            'Gruppo Prova',
            [
                [
                    'iuv' => Synthetic::iuv('0', 5151),
                    'importo' => 126.50,
                    'data_flusso' => date('Y-m-d', strtotime('-7 days')),
                    'data_pagamento' => date('Y-m-d', strtotime('-8 days')),
                    'causale' => 'Servizio di prova',
                    'nominativo_debitore' => Synthetic::nome(5),
                    'cf_debitore' => Synthetic::cf(5),
                    'id_pendenza' => 456,
                ],
            ],
            'http://x/rendicontazione/da-confermare',
            7
        );
        $this->assertSame('OK', $result['esito']);
    }

    public function testSollecitoSmarcaturaErroreSenzaMessaggioEccezione(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendRendicontazioneSollecitoSmarcatura(
            ['invalid-email-address-without-at-symbol'],
            'Gruppo Prova',
            [['iuv' => Synthetic::iuv('0', 1), 'importo' => 10]],
            'http://x',
            7
        );
        $this->assertSame('ERRORE', $result['esito']);
        // solo il nome breve della classe, mai il testo dell'eccezione (che riecheggia l'indirizzo)
        $this->assertNotEmpty($result['errore'] ?? '');
        $this->assertStringNotContainsString('invalid-email', $result['errore']);
        $this->assertStringNotContainsString(' ', $result['errore']);
    }

    public function testRenderRendicontazioneAdmin(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendRendicontazioneAdminDigest(
            ['admin@example.com'],
            [
                [
                    'iuv' => Synthetic::iuv('0', 4243),
                    'importo' => 2600.00,
                    'rendicontazione_handler' => 'AUTO_ESTERNO',
                    'rendicontazione_stato' => 'OK'
                ],
                [
                    'iuv' => Synthetic::iuv('0', 4244),
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

    public function testRenderPendenzaReceiptNotification(): void
    {
        $mailer = MailerService::forSuite('backoffice');
        $result = $mailer->sendPendenzaReceiptNotification(
            'test@example.com',
            'Mario Rossi',
            ['causale' => 'Riscaldamento scolastico', 'importo' => 120.50],
            Synthetic::iuv('0', 4243),
            'Tassa',
            '2026-07-26',
            'http://frontoffice/receipt/123',
            'GIL',
            ''
        );
        $this->assertSame('OK', $result['esito']);
    }
}
