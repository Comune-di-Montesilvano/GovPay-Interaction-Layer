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
}
