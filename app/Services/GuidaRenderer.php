<?php

declare(strict_types=1);

namespace App\Services;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Guida operatori backoffice: unica fonte in backoffice/guida/guida.md
 * (leggibile anche su GitHub), resa in HTML per la pagina /guida.
 *
 * - HTML grezzo nel Markdown scartato (html_input=strip), link non sicuri
 *   (javascript:, data:…) rimossi: la guida non deve contenere HTML.
 * - Titoli ## e ### ricevono un id (stessa regola slug di GitHub per i titoli
 *   semplici) e finiscono nell'indice laterale.
 */
final class GuidaRenderer
{
    public static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . '/backoffice/guida/guida.md';
    }

    public static function slug(string $heading): string
    {
        $s = mb_strtolower($heading, 'UTF-8');
        $s = (string)preg_replace('/[^\p{L}\p{N} _-]/u', '', $s);
        return str_replace(' ', '-', $s);
    }

    /**
     * Titoli di secondo e terzo livello, ignorando i blocchi ```.
     *
     * @return list<array{id: string, title: string, level: int}>
     */
    public static function sections(string $markdown): array
    {
        $out = [];
        $inCode = false;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (str_starts_with($line, '```')) {
                $inCode = !$inCode;
            }
            if ($inCode) {
                continue;
            }
            if (preg_match('/^(##|###) (.+)$/', $line, $m) === 1) {
                $title = trim($m[2]);
                $out[] = ['id' => self::slug($title), 'title' => $title, 'level' => strlen($m[1])];
            }
        }
        return $out;
    }

    /**
     * @return array{html: string, sections: list<array{id: string, title: string, level: int}>}
     */
    public static function render(string $markdown): array
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $html = (string)$converter->convert($markdown);

        // id sui titoli h2/h3 dal testo visibile (stessa regola di sections()).
        $html = (string)preg_replace_callback(
            '#<h([23])>(.*?)</h\1>#s',
            static function (array $m): string {
                $text = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $id = htmlspecialchars(self::slug(trim($text)), ENT_QUOTES, 'UTF-8');
                return '<h' . $m[1] . ' id="' . $id . '">' . $m[2] . '</h' . $m[1] . '>';
            },
            $html
        );

        return ['html' => $html, 'sections' => self::sections($markdown)];
    }

    /**
     * @return array{html: string, sections: list<array{id: string, title: string, level: int}>}|null
     */
    public static function renderFile(?string $path = null): ?array
    {
        $path ??= self::defaultPath();
        $md = is_file($path) ? file_get_contents($path) : false;
        return $md === false ? null : self::render($md);
    }
}
