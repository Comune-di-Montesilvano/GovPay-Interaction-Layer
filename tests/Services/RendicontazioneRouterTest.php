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

    public function testNonGilConGruppoNotificaESmarcaturaVaInAutoEsterno(): void
    {
        $decision = RendicontazioneRouter::decide(
            '',
            '06120000257919431',
            'GIL',
            ['modalita' => 'NOTIFICA_E_SMARCATURA'],
            []
        );
        $this->assertSame('GESTITO', $decision->stato);
        $this->assertSame('AUTO_ESTERNO', $decision->handler);
    }

    public function testNonGilConRegolaRegexValida(): void
    {
        $decision = RendicontazioneRouter::decide(
            '',
            '3012024000000001',
            'GIL',
            null,
            [['pattern_tipo' => 'REGEX', 'pattern_valore' => '^301', 'handler' => 'GERI']]
        );
        $this->assertSame('GESTITO', $decision->stato);
        $this->assertSame('GERI', $decision->handler);
    }

    /** IUV 17 cifre (forma in flussi_rendicontazioni): codice segregazione(2) + id applicazione in indice 2 */
    public function testIdAppAgidSuIuv17CifreMatchaDilazione(): void
    {
        $decision = RendicontazioneRouter::decide(
            '300607000005051850',
            '00607000005051850',
            'GIL',
            null,
            [
                ['pattern_tipo' => 'ID_APP_AGID', 'pattern_valore' => '6', 'handler' => 'DILAZIONE'],
                ['pattern_tipo' => 'ID_APP_AGID', 'pattern_valore' => '1', 'handler' => 'GERI'],
            ]
        );
        $this->assertSame('DILAZIONE', $decision->handler);
    }

    public function testIdAppAgidSuIuv17CifreMatchaGeri(): void
    {
        $decision = RendicontazioneRouter::decide(
            '',
            '00107000005051850',
            'GIL',
            null,
            [
                ['pattern_tipo' => 'ID_APP_AGID', 'pattern_valore' => '6', 'handler' => 'DILAZIONE'],
                ['pattern_tipo' => 'ID_APP_AGID', 'pattern_valore' => '1', 'handler' => 'GERI'],
            ]
        );
        $this->assertSame('GERI', $decision->handler);
    }

    /** Numero avviso 18 cifre: cifra ausiliaria + IUV, id applicazione in indice 3 */
    public function testIdAppAgidSuNumeroAvviso18CifreMatchaDilazione(): void
    {
        $decision = RendicontazioneRouter::decide(
            '',
            '300607000005051850',
            'GIL',
            null,
            [['pattern_tipo' => 'ID_APP_AGID', 'pattern_valore' => '6', 'handler' => 'DILAZIONE']]
        );
        $this->assertSame('DILAZIONE', $decision->handler);
    }

    public function testIdAppAgidNonMatchataAutoEsterno(): void
    {
        $decision = RendicontazioneRouter::decide(
            'e1b1620716924248add74cd8485af08e',
            '00000000000624416',
            'GIL',
            null,
            [
                ['pattern_tipo' => 'ID_APP_AGID', 'pattern_valore' => '6', 'handler' => 'DILAZIONE'],
                ['pattern_tipo' => 'ID_APP_AGID', 'pattern_valore' => '1', 'handler' => 'GERI'],
            ]
        );
        $this->assertSame('AUTO_ESTERNO', $decision->handler);
    }

    public function testNonGilConRegolaRegexInvalidaNonLanciaEccezione(): void
    {
        $decision = RendicontazioneRouter::decide(
            '',
            '3012024000000001',
            'GIL',
            null,
            [['pattern_tipo' => 'REGEX', 'pattern_valore' => '301}', 'handler' => 'GERI']]
        );
        $this->assertSame('GESTITO', $decision->stato);
        $this->assertSame('AUTO_ESTERNO', $decision->handler);
    }
}
