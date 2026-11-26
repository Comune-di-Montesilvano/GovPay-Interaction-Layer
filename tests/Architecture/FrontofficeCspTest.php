<?php
/**
 * SPDX-License-Identifier: EUPL-1.2
 * License: European Union Public Licence v1.2 (EUPL-1.2)
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * La CSP del frontoffice (apache/frontoffice-csp.conf) non ha 'unsafe-inline' in script-src:
 * uno script inline o un handler on*= nei template verrebbe bloccato dal browser e la pagina
 * smetterebbe di funzionare senza errori lato server. Il JS sta in
 * frontoffice/public/assets/js/fo/, i dati passano da partials/fo-data.html.twig.
 */
final class FrontofficeCspTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../frontoffice/templates';
    private const JS = __DIR__ . '/../../frontoffice/public/assets/js/fo';

    /** @return list<string> */
    private static function files(string $dir, string $ext): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), $ext)) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }

    public function testNessunoScriptInlineNeiTemplate(): void
    {
        $violations = [];
        foreach (self::files(self::TEMPLATES, '.twig') as $file) {
            $src = (string) file_get_contents($file);
            // Commenti Twig esclusi: documentano proprio cosa non fare.
            $src = (string) preg_replace('/\{#.*?#\}/s', '', $src);
            preg_match_all('/<script\b([^>]*)>/i', $src, $m);
            foreach ($m[1] as $attrs) {
                $esterno = preg_match('/\bsrc\s*=/i', $attrs) === 1;
                $dati = preg_match('/\btype\s*=\s*["\']application\/json["\']/i', $attrs) === 1;
                if (!$esterno && !$dati) {
                    $violations[] = basename(dirname($file)) . '/' . basename($file) . ': <script' . $attrs . '>';
                }
            }
            if (preg_match_all('/<[a-z][^>]*\s(on[a-z]+)\s*=/i', $src, $h)) {
                foreach ($h[1] as $handler) {
                    $violations[] = basename(dirname($file)) . '/' . basename($file) . ": attributo $handler=";
                }
            }
            if (preg_match('/\bhref\s*=\s*["\']\s*javascript:/i', $src)) {
                $violations[] = basename($file) . ': href="javascript:..."';
            }
        }
        $this->assertSame([], $violations, "Script inline bloccati dalla CSP del frontoffice: spostarli in assets/js/fo/");
    }

    public function testNessunaEspressioneTwigNeiFileJs(): void
    {
        $violations = [];
        foreach (self::files(self::JS, '.js') as $file) {
            if (preg_match('/\{\{|\{%/', (string) file_get_contents($file)) && !str_contains($file, 'vendor')) {
                $violations[] = basename($file);
            }
        }
        $this->assertSame([], $violations, 'I .js sono statici: dati e traduzioni da GovPayUi.data/t/th');
    }

    /** Ogni GovPayUi.t/th("...") dei .js deve avere la traduzione nel blocco fo-data. */
    public function testOgniTestoDeiJsEInFoData(): void
    {
        $partial = (string) file_get_contents(self::TEMPLATES . '/partials/fo-data.html.twig');
        $mancanti = [];
        foreach (self::files(self::JS, '.js') as $file) {
            preg_match_all('/GovPayUi\.th?\(\s*"((?:[^"\\\\]|\\\\.)*)"\s*\)/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $testo) {
                $chiave = '"' . $testo . '": "' . $testo . '"|trans';
                if (!str_contains($partial, $chiave)) {
                    $mancanti[] = basename($file) . ': ' . $testo;
                }
            }
        }
        $this->assertSame([], array_values(array_unique($mancanti)));
    }

    /** Il blocco fo-data e' JSON valido e un valore con </script> non chiude il tag. */
    public function testFoDataJsonSicuro(): void
    {
        $twig = new Environment(new FilesystemLoader(self::TEMPLATES), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFilter(new TwigFilter('trans', static fn (string $s): string => $s === 'Rimuovi' ? "Rim'uovi <b>&" : $s));
        $twig->addFunction(new TwigFunction('asset', static fn (string $p): string => $p));

        $qr = 'PAGOPA|002|000000000000000001|00000000000|100</script><script>alert(1)</script>';
        $html = $twig->render('partials/fo-data.html.twig', ['csrf_token' => 'tok"en', 'fo_qr' => $qr]);

        $this->assertSame(2, substr_count($html, '</script>'), 'solo le chiusure dei due tag del partial');
        $this->assertMatchesRegularExpression('/<script type="application\/json" id="fo-data">(.*?)<\/script>/s', $html);
        preg_match('/<script type="application\/json" id="fo-data">(.*?)<\/script>/s', $html, $m);
        $json = trim((string) preg_replace('/\{#.*?#\}/s', '', $m[1]));
        $this->assertDoesNotMatchRegularExpression('/[<>&\']/', $json);

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('tok"en', $data['csrf']);
        $this->assertSame($qr, $data['qr']);
        $this->assertSame("Rim'uovi <b>&", $data['t']['Rimuovi']);
        $this->assertSame('Nel carrello ✓', $data['t']['Nel carrello ✓']);
    }

    /** Senza csrf_token/fo_qr nel contesto (pagine pubbliche) il partial rende comunque. */
    public function testFoDataSenzaVariabiliOpzionali(): void
    {
        $twig = new Environment(new FilesystemLoader(self::TEMPLATES), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $s): string => $s));
        $twig->addFunction(new TwigFunction('asset', static fn (string $p): string => $p));
        preg_match('/id="fo-data">(.*?)<\/script>/s', $twig->render('partials/fo-data.html.twig'), $m);
        $data = json_decode(trim((string) preg_replace('/\{#.*?#\}/s', '', $m[1])), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('', $data['csrf']);
        $this->assertNull($data['qr']);
    }
}
