<?php
declare(strict_types=1);

namespace Tests\Services;

use App\Services\RendicontazioneRouter;
use PHPUnit\Framework\TestCase;

final class RendicontazioneRouterTest extends TestCase
{
    public function testGilPrefixSenzaGruppoAssociatoAutoGestito(): void
    {
        $decision = RendicontazioneRouter::decide('GIL-001', '000001', 'GIL', null, []);
        $this->assertSame('GESTITO', $decision->stato);
        $this->assertSame('GIL_MANUALE', $decision->handler);
    }

    public function testGilPrefixConGruppoSoloNotificaRestaAutoGestito(): void
    {
        $decision = RendicontazioneRouter::decide(
            'GIL-001',
            '000001',
            'GIL',
            ['modalita' => 'SOLO_NOTIFICA'],
            []
        );
        $this->assertSame('GESTITO', $decision->stato);
        $this->assertSame('GIL_MANUALE', $decision->handler);
    }

    public function testGilPrefixConGruppoNotificaESmarcaturaVaInAttesa(): void
    {
        $decision = RendicontazioneRouter::decide(
            'GIL-001',
            '000001',
            'GIL',
            ['modalita' => 'NOTIFICA_E_SMARCATURA'],
            []
        );
        $this->assertSame('IN_ATTESA_CONFERMA', $decision->stato);
        $this->assertNull($decision->handler);
    }

    public function testNonGilConRegolaGeriMatchata(): void
    {
        $decision = RendicontazioneRouter::decide(
            '',
            '3012024000000001',
            'GIL',
            null,
            [['pattern_tipo' => 'IUV_PREFIX', 'pattern_valore' => '301', 'handler' => 'GERI']]
        );
        $this->assertSame('GESTITO', $decision->stato);
        $this->assertSame('GERI', $decision->handler);
    }

    public function testNonGilSenzaRegoleMatchateAutoEsterno(): void
    {
        $decision = RendicontazioneRouter::decide('', '9992024000000001', 'GIL', null, []);
        $this->assertSame('GESTITO', $decision->stato);
        $this->assertSame('AUTO_ESTERNO', $decision->handler);
    }

    public function testNonGilLongestPrefixVince(): void
    {
        $decision = RendicontazioneRouter::decide(
            '',
            '30112024000000001',
            'GIL',
            null,
            [
                ['pattern_tipo' => 'IUV_PREFIX', 'pattern_valore' => '3', 'handler' => 'DILAZIONE'],
                ['pattern_tipo' => 'IUV_PREFIX', 'pattern_valore' => '301', 'handler' => 'GERI'],
            ]
        );
        $this->assertSame('GERI', $decision->handler);
    }

    public function testNonGilConGruppoNotificaESmarcaturaVaInAttesa(): void
    {
        $decision = RendicontazioneRouter::decide(
            '',
            '06120000257919431',
            'GIL',
            ['modalita' => 'NOTIFICA_E_SMARCATURA'],
            []
        );
        $this->assertSame('IN_ATTESA_CONFERMA', $decision->stato);
        $this->assertNull($decision->handler);
    }
}
