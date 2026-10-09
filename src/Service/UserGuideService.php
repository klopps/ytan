<?php

declare(strict_types=1);

namespace Ytan\Service;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\CommonMark\ConverterInterface;

/**
 * The end-user documentation (/help): one Markdown file per chapter in
 * docs/user/{locale}/NN-slug.md, sorted by file name. A chapter starts with a
 * small front matter block,
 *
 *   ---
 *   title: Routen
 *   keywords: Route, Wegpunkt, Split
 *   ---
 *
 * (title and a comma-separated keyword list; nothing else is understood).
 * The Markdown is rendered once per request into a single HTML document -
 * the online page and the printable version are the same markup - and the
 * headings get stable ids ("{chapter}-{heading}") so the table of contents,
 * the search and links from other places can point at them.
 *
 * Images are written relative in the Markdown (`![Alt](route-edit.png)`)
 * and resolved to public/images/help/ (see IMAGE_URL_PATH); the alt text
 * doubles as the figure caption. Only the locales that have a directory are
 * served - any other locale falls back to FALLBACK_LOCALE until it is
 * translated, so a missing translation shows the German guide instead of
 * an empty page.
 */
final class UserGuideService
{
    public const FALLBACK_LOCALE = 'de';
    public const IMAGE_URL_PATH = '/images/help/';

    public function __construct(
        private readonly string $guideDir,
        private readonly ConverterInterface $markdown,
    ) {
    }

    /**
     * @return array<int, array{slug: string, title: string, keywords: string[], html: string, sections: array<int, array{id: string, title: string}>}>
     */
    public function chapters(string $locale, string $baseUrl = ''): array
    {
        $dir = $this->localeDir($locale);
        $files = glob($dir . '/*.md') ?: [];
        sort($files, SORT_STRING);

        $chapters = [];
        foreach ($files as $file) {
            $chapters[] = $this->loadChapter($file, $baseUrl);
        }

        return $chapters;
    }

    /**
     * Keyword => the chapters that list it, case-insensitively merged,
     * sorted alphabetically - the data of the keyword index at the end.
     *
     * @param array<int, array{slug: string, title: string, keywords: string[]}> $chapters
     * @return array<string, array<int, array{slug: string, title: string}>>
     */
    public static function keywordIndex(array $chapters): array
    {
        $index = [];
        $display = [];
        foreach ($chapters as $chapter) {
            foreach ($chapter['keywords'] as $keyword) {
                $key = mb_strtolower($keyword);
                $display[$key] ??= $keyword;
                $index[$key][$chapter['slug']] = ['slug' => $chapter['slug'], 'title' => $chapter['title']];
            }
        }
        uksort($index, static fn (string $a, string $b): int => strcoll($a, $b));

        $result = [];
        foreach ($index as $key => $entries) {
            $result[$display[$key]] = array_values($entries);
        }

        return $result;
    }

    /** The locale whose guide is actually served for $locale (FALLBACK_LOCALE if it has none yet). */
    public function servedLocale(string $locale): string
    {
        $clean = preg_replace('/[^a-z]/i', '', $locale);
        $dir = $this->guideDir . '/' . $clean;
        if ($clean !== '' && is_dir($dir) && (glob($dir . '/*.md') ?: []) !== []) {
            return $clean;
        }

        return self::FALLBACK_LOCALE;
    }

    public function localeDir(string $locale): string
    {
        return $this->guideDir . '/' . $this->servedLocale($locale);
    }

    private function loadChapter(string $file, string $baseUrl): array
    {
        $slug = preg_replace('/^\d+-/', '', basename($file, '.md'));
        [$meta, $body] = self::splitFrontMatter((string) file_get_contents($file));
        $title = $meta['title'] ?? $slug;
        $keywords = array_values(array_filter(array_map('trim', explode(',', $meta['keywords'] ?? ''))));

        [$html, $sections] = $this->decorate((string) $this->markdown->convert($body), $slug, $baseUrl);

        return ['slug' => $slug, 'title' => $title, 'keywords' => $keywords, 'html' => $html, 'sections' => $sections];
    }

    /** @return array{0: array<string, string>, 1: string} front matter fields and the Markdown after it */
    public static function splitFrontMatter(string $text): array
    {
        $text = ltrim($text, "\xEF\xBB\xBF");
        if (!preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $text, $m)) {
            return [[], $text];
        }
        $meta = [];
        foreach (preg_split('/\R/', $m[1]) as $line) {
            if (preg_match('/^([a-z]+):\s*(.*)$/i', trim($line), $field)) {
                $meta[strtolower($field[1])] = $field[2];
            }
        }

        return [$meta, $m[2]];
    }

    /**
     * Gives h2/h3 their ids, wraps each image in a captioned <figure> and
     * points it at the help image folder, makes tables scrollable on narrow
     * screens and opens external links in a new tab.
     *
     * @return array{0: string, 1: array<int, array{id: string, title: string}>} the HTML and the h2 list (the chapter's sections)
     */
    private function decorate(string $html, string $chapterSlug, string $baseUrl): array
    {
        if (trim($html) === '') {
            return ['', []];
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="help-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);

        $sections = [];
        $usedIds = [];
        foreach ($xpath->query('//h2 | //h3') as $heading) {
            /** @var DOMElement $heading */
            $id = $chapterSlug . '-' . self::slugify($heading->textContent);
            $unique = $id;
            for ($n = 2; isset($usedIds[$unique]); $n++) {
                $unique = $id . '-' . $n;
            }
            $usedIds[$unique] = true;
            $heading->setAttribute('id', $unique);
            if ($heading->nodeName === 'h2') {
                $sections[] = ['id' => $unique, 'title' => trim($heading->textContent)];
            }
        }

        foreach (iterator_to_array($xpath->query('//img')) as $image) {
            /** @var DOMElement $image */
            $src = $image->getAttribute('src');
            if (!preg_match('#^(https?:)?//#', $src) && !str_starts_with($src, '/')) {
                $image->setAttribute('src', $baseUrl . self::IMAGE_URL_PATH . basename($src));
            }
            $image->setAttribute('loading', 'lazy');

            $figure = $doc->createElement('figure');
            $figure->setAttribute('class', 'help-figure');
            $parent = $image->parentNode;
            // A paragraph that holds nothing but the image becomes the figure.
            if ($parent instanceof DOMElement && $parent->nodeName === 'p' && trim($parent->textContent) === '' && $parent->getElementsByTagName('img')->length === 1) {
                $parent->parentNode->replaceChild($figure, $parent);
            } else {
                $parent->replaceChild($figure, $image);
            }
            $figure->appendChild($image);
            if (trim($image->getAttribute('alt')) !== '') {
                $caption = $doc->createElement('figcaption');
                $caption->appendChild($doc->createTextNode($image->getAttribute('alt')));
                $figure->appendChild($caption);
            }
        }

        foreach (iterator_to_array($xpath->query('//table')) as $table) {
            $wrap = $doc->createElement('div');
            $wrap->setAttribute('class', 'help-table-scroll');
            $table->parentNode->replaceChild($wrap, $table);
            $wrap->appendChild($table);
        }

        foreach ($xpath->query('//a[starts-with(@href, "http")]') as $link) {
            /** @var DOMElement $link */
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener');
        }

        $root = $doc->getElementById('help-root') ?? $xpath->query('//div[@id="help-root"]')->item(0);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return [$out, $sections];
    }

    public static function slugify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';

        return trim($text, '-') ?: 'abschnitt';
    }
}
