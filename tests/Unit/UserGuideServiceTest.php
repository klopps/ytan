<?php

declare(strict_types=1);

namespace Ytan\Tests\Unit;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use PHPUnit\Framework\TestCase;
use Ytan\Service\UserGuideService;

final class UserGuideServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ytan-guide-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/de', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->dir . '/*') ?: [] as $sub) {
            rmdir($sub);
        }
        rmdir($this->dir);
    }

    private function service(?string $dir = null): UserGuideService
    {
        return new UserGuideService($dir ?? $this->dir, new GithubFlavoredMarkdownConverter());
    }

    public function testSplitsFrontMatterFromTheMarkdown(): void
    {
        [$meta, $body] = UserGuideService::splitFrontMatter("---\ntitle: Routen\nkeywords: A, B\n---\nText");

        $this->assertSame(['title' => 'Routen', 'keywords' => 'A, B'], $meta);
        $this->assertSame('Text', $body);
    }

    public function testTextWithoutFrontMatterIsLeftAlone(): void
    {
        [$meta, $body] = UserGuideService::splitFrontMatter("Nur Text\n\n## Abschnitt");

        $this->assertSame([], $meta);
        $this->assertSame("Nur Text\n\n## Abschnitt", $body);
    }

    public function testSlugifyTransliteratesGermanUmlauts(): void
    {
        $this->assertSame('ueber-mich-grosse-faehre', UserGuideService::slugify('Über mich & große Fähre'));
        $this->assertSame('abschnitt', UserGuideService::slugify('!!!'));
    }

    public function testChaptersAreSortedByFileNameAndGetTheirSlugFromIt(): void
    {
        file_put_contents($this->dir . '/de/02-zweites.md', "---\ntitle: Zwei\n---\nText");
        file_put_contents($this->dir . '/de/01-erstes.md', "---\ntitle: Eins\nkeywords: Alpha, Beta\n---\nText");

        $chapters = $this->service()->chapters('de');

        $this->assertSame(['erstes', 'zweites'], array_column($chapters, 'slug'));
        $this->assertSame(['Eins', 'Zwei'], array_column($chapters, 'title'));
        $this->assertSame(['Alpha', 'Beta'], $chapters[0]['keywords']);
    }

    public function testHeadingsGetChapterScopedIdsAndTheSectionList(): void
    {
        file_put_contents(
            $this->dir . '/de/01-a.md',
            "---\ntitle: A\n---\nIntro\n\n## Erster Teil\n\n### Unterteil\n\n## Erster Teil\n"
        );

        $chapter = $this->service()->chapters('de')[0];

        $this->assertStringContainsString('<h2 id="a-erster-teil">', $chapter['html']);
        $this->assertStringContainsString('<h3 id="a-unterteil">', $chapter['html']);
        $this->assertStringContainsString('<h2 id="a-erster-teil-2">', $chapter['html'], 'a repeated heading must not repeat its id');
        $this->assertSame(
            [['id' => 'a-erster-teil', 'title' => 'Erster Teil'], ['id' => 'a-erster-teil-2', 'title' => 'Erster Teil']],
            $chapter['sections']
        );
    }

    public function testImagesBecomeCaptionedFiguresOnTheHelpImagePath(): void
    {
        file_put_contents($this->dir . '/de/01-a.md', "---\ntitle: A\n---\n![Die Karte](karte.jpg)\n");

        $html = $this->service()->chapters('de', '/ytan')[0]['html'];

        $this->assertStringContainsString('<figure class="help-figure"><img src="/ytan/images/help/karte.jpg"', $html);
        $this->assertStringContainsString('<figcaption>Die Karte</figcaption>', $html);
        $this->assertStringNotContainsString('<p><figure', $html);
    }

    public function testImagePathsCannotEscapeTheHelpImageFolder(): void
    {
        file_put_contents($this->dir . '/de/01-a.md', "---\ntitle: A\n---\n![x](../../.env)\n");

        $html = $this->service()->chapters('de')[0]['html'];

        $this->assertStringContainsString('src="/images/help/.env"', $html);
        $this->assertStringNotContainsString('..', $html);
    }

    public function testTablesAreWrappedAndExternalLinksOpenInANewTab(): void
    {
        file_put_contents($this->dir . '/de/01-a.md', "---\ntitle: A\n---\n| A | B |\n|---|---|\n| 1 | 2 |\n\n[Windy](https://www.windy.com)\n");

        $html = $this->service()->chapters('de')[0]['html'];

        $this->assertStringContainsString('<div class="help-table-scroll"><table>', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener"', $html);
    }

    public function testUnknownLocaleFallsBackToGerman(): void
    {
        file_put_contents($this->dir . '/de/01-a.md', "---\ntitle: Deutsch\n---\nText");

        $service = $this->service();

        $this->assertSame('de', $service->servedLocale('fr'));
        $this->assertSame('de', $service->servedLocale('../../etc'));
        $this->assertSame('Deutsch', $service->chapters('fr')[0]['title']);

        mkdir($this->dir . '/en');
        file_put_contents($this->dir . '/en/01-a.md', "---\ntitle: English\n---\nText");
        $this->assertSame('en', $service->servedLocale('en'));
        $this->assertSame('English', $service->chapters('en')[0]['title']);
    }

    public function testKeywordIndexMergesCaseInsensitivelyAndSorts(): void
    {
        $index = UserGuideService::keywordIndex([
            ['slug' => 'a', 'title' => 'A', 'keywords' => ['Route', 'Zelt']],
            ['slug' => 'b', 'title' => 'B', 'keywords' => ['route', 'Anleger']],
        ]);

        $this->assertSame(['Anleger', 'Route', 'Zelt'], array_keys($index));
        $this->assertSame([['slug' => 'a', 'title' => 'A'], ['slug' => 'b', 'title' => 'B']], $index['Route']);
    }

    /** The real guide: every chapter has a title and keywords, and its internal links and images resolve. */
    public function testTheRealGuideHasNoBrokenLinksOrImages(): void
    {
        $root = dirname(__DIR__, 2);
        $chapters = $this->service($root . '/docs/user')->chapters('de');
        $this->assertNotEmpty($chapters);

        $ids = ['keyword-index' => true];
        foreach ($chapters as $chapter) {
            $this->assertNotSame($chapter['slug'], $chapter['title'], $chapter['slug'] . ' needs a title in its front matter');
            $this->assertNotEmpty($chapter['keywords'], $chapter['slug'] . ' needs keywords');
            $ids['chapter-' . $chapter['slug']] = true;
            foreach ($chapter['sections'] as $section) {
                $ids[$section['id']] = true;
            }
            preg_match_all('/<h3 id="([^"]+)"/', $chapter['html'], $subs);
            foreach ($subs[1] as $id) {
                $ids[$id] = true;
            }
        }

        $problems = [];
        foreach ($chapters as $chapter) {
            preg_match_all('/href="#([^"]+)"/', $chapter['html'], $links);
            foreach ($links[1] as $target) {
                if (!isset($ids[$target])) {
                    $problems[] = $chapter['slug'] . ': link to #' . $target . ' has no target';
                }
            }
            preg_match_all('#<img src="/images/help/([^"]+)"#', $chapter['html'], $images);
            foreach ($images[1] as $image) {
                if (!is_file($root . '/public/images/help/' . $image)) {
                    $problems[] = $chapter['slug'] . ': image ' . $image . ' is missing (npm run screenshots in tests/e2e)';
                }
            }
        }

        $this->assertSame([], $problems);
    }
}
