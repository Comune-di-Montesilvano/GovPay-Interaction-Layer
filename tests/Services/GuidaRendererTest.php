<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\GuidaRenderer;
use PHPUnit\Framework\TestCase;

final class GuidaRendererTest extends TestCase
{
    private static function guida(): string
    {
        $md = file_get_contents(GuidaRenderer::defaultPath());
        self::assertIsString($md, 'backoffice/guida/guida.md mancante');
        return $md;
    }

    public function testSlugSegueRegolaGithub(): void
    {
        self::assertSame('ruoli-e-permessi', GuidaRenderer::slug('Ruoli e permessi'));
        self::assertSame('rendicontazione--errori-solo-superadmin', GuidaRenderer::slug('Rendicontazione — Errori (solo superadmin)'));
        self::assertSame('lo-stato-è-non_eseguita', GuidaRenderer::slug('Lo stato è NON_ESEGUITA'));
    }

    public function testGuidaSenzaTitoliRipetuti(): void
    {
        $ids = array_column(GuidaRenderer::sections(self::guida()), 'id');
        self::assertNotEmpty($ids);
        self::assertSame($ids, array_values(array_unique($ids)), 'titoli ripetuti: ancore ambigue');
    }

    public function testGuidaSenzaHtmlGrezzo(): void
    {
        $inCode = false;
        foreach (preg_split('/\R/', self::guida()) ?: [] as $n => $line) {
            if (str_starts_with($line, '```')) {
                $inCode = !$inCode;
            }
            if ($inCode) {
                continue;
            }
            $plain = (string)preg_replace('/`[^`]*`/', '', $line);
            self::assertDoesNotMatchRegularExpression('/<\/?[a-zA-Z][^>]*>/', $plain, 'HTML alla riga ' . ($n + 1) . ' (viene scartato in rendering)');
        }
    }

    public function testRenderAssegnaIdAiTitoliDellIndice(): void
    {
        $out = GuidaRenderer::render(self::guida());
        foreach ($out['sections'] as $s) {
            self::assertStringContainsString('id="' . $s['id'] . '"', $out['html'], 'ancora mancante: ' . $s['title']);
        }
    }

    public function testRenderScartaHtmlELinkNonSicuri(): void
    {
        $out = GuidaRenderer::render("## Titolo\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))\n\n| a | b |\n| --- | --- |\n| 1 | 2 |\n");
        self::assertStringNotContainsString('<script', $out['html']);
        self::assertStringNotContainsString('javascript:', $out['html']);
        self::assertStringContainsString('<h2 id="titolo">Titolo</h2>', $out['html']);
        self::assertStringContainsString('<table>', $out['html']);
        self::assertSame([['id' => 'titolo', 'title' => 'Titolo', 'level' => 2]], $out['sections']);
    }
}
