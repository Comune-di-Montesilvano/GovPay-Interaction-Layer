<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\MailerService;
use PHPUnit\Framework\TestCase;

final class MailerServiceRateizzazioneTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function data(bool $conRataUnica): array
    {
        $d = [
            'causale' => 'Istanza di prova',
            'importo_totale' => 300.0,
            'rates' => [
                ['indice' => 1, 'importo' => 150.0, 'dataScadenza' => '2026-12-31', 'numeroAvviso' => '300000000000000001'],
                ['indice' => 2, 'importo' => 150.0, 'dataScadenza' => '2027-01-31', 'numeroAvviso' => '300000000000000002'],
            ],
        ];
        if ($conRataUnica) {
            $d['rataUnica'] = [
                'importo' => 300.0, 'dataScadenza' => '2026-12-31', 'numeroAvviso' => '300000000000000009',
                'pdfUrl' => 'https://fo.example/pdf-ru', 'paymentUrl' => 'https://fo.example/paga-ru',
            ];
        }
        return $d;
    }

    private static function render(string $method, array $data): string
    {
        $m = new \ReflectionMethod(MailerService::class, $method);
        $args = $method === 'renderRateizzazioneTemplate'
            ? ['Mario', $data, 'Ente', false, '']
            : ['Mario', $data, 'Ente'];
        return (string)$m->invokeArgs(MailerService::forSuite('backoffice'), $args);
    }

    public function testHtmlConRataUnica(): void
    {
        $html = self::render('renderRateizzazioneTemplate', self::data(true));
        $this->assertStringContainsString('Unica soluzione', $html);
        $this->assertStringContainsString('300000000000000009', $html);
        $this->assertStringContainsString('https://fo.example/paga-ru', $html);
        $this->assertStringContainsString('l&#039;altra opzione verrà annullata', $html);
    }

    public function testPlainConRataUnica(): void
    {
        $txt = self::render('renderRateizzazioneTemplatePlain', self::data(true));
        $this->assertStringContainsString('Unica soluzione: € 300,00', $txt);
        $this->assertStringContainsString('300000000000000009', $txt);
        $this->assertStringContainsString('https://fo.example/paga-ru', $txt);
    }

    public function testSenzaRataUnicaInvariato(): void
    {
        $this->assertStringNotContainsString('Unica soluzione', self::render('renderRateizzazioneTemplate', self::data(false)));
        $this->assertStringNotContainsString('Unica soluzione', self::render('renderRateizzazioneTemplatePlain', self::data(false)));
    }
}
