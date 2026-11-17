<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Html;

use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Html\ExtractionRules;
use Yepr\Plugin\Task\FinderExternal\Html\PageExtractor;

final class PageExtractorTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/Fixtures/' . $name);
    }

    public function testADocumentationPage(): void
    {
        $page = (new PageExtractor())->extract(
            $this->fixture('docs-page.html'),
            'https://docs.example.org/docs/general-concepts/dependency-injection/',
            ExtractionRules::fromCss(
                '.theme-doc-markdown',
                '.theme-doc-markdown h1',
                ['.hash-link', '.theme-doc-toc-mobile'],
                ExtractionRules::DESCRIPTION_META,
                '.theme-doc-footer time',
                '',
                '.theme-doc-breadcrumbs a.breadcrumbs__link'
            )
        );

        self::assertTrue($page->contentFound);
        self::assertSame('Dependency Injection', $page->title);
        self::assertSame('How services are put together in a container instead of being built where they are used.', $page->description);
        self::assertSame('2026-10-02 09:30:00', $page->date);
        self::assertSame('General Concepts', $page->category);
        self::assertSame('https://docs.example.org/docs/general-concepts/dependency-injection/', $page->canonical);
        self::assertFalse($page->noindex);

        $lines = explode("\n", $page->body);

        self::assertSame('Dependency Injection', $lines[0]);
        self::assertContains('Example', $lines, 'The hash link "#" is left out of the heading.');
        self::assertContains("echo '<div>done</div>';", $lines, 'Code stays code: <div> is text, not a tag.');
        self::assertContains('Shared services are built once.', $lines);
        self::assertContains('Method Meaning', $lines);
        self::assertStringNotContainsString('not text', $page->body, 'Scripts are not text.');
        self::assertStringNotContainsString('On this page', $page->body, 'Removed parts are gone.');
        self::assertStringNotContainsString('Edit this page', $page->body, 'Only the content is read.');
    }

    public function testLinksAreAbsoluteUniqueAndWithoutNofollow(): void
    {
        $page = (new PageExtractor())->extract(
            $this->fixture('docs-page.html'),
            'https://docs.example.org/docs/general-concepts/dependency-injection/',
            ExtractionRules::fromCss('.theme-doc-markdown')
        );

        self::assertSame([
            'https://docs.example.org/docs/',
            'https://docs.example.org/docs/5.4/general-concepts/',
            'https://github.com/example/docs',
            'https://docs.example.org/',
            'https://docs.example.org/docs/general-concepts/',
            'https://docs.example.org/docs/general-concepts/events/',
            'https://github.com/example/docs/edit/main/di.md',
        ], $page->links);
    }

    public function testABlogArticleWithABaseHref(): void
    {
        $page = (new PageExtractor())->extract(
            $this->fixture('article-page.html'),
            'https://blog.example.org/issues/2025/october-2025/css-shorts-subgrid',
            ExtractionRules::fromCss(
                '.com-content-article__body',
                '.page-header h1',
                ['.share-buttons'],
                ExtractionRules::DESCRIPTION_CONTENT,
                '.article-info time',
                '.createdby a',
                ''
            )
        );

        self::assertSame('CSS Shorts: Subgrid', $page->title);
        self::assertSame(
            'In the previous issue we started a series about CSS. This time: subgrid, and why it lines things up.',
            $page->description,
            'The first real paragraph, not the site-wide meta description or a scrap.'
        );
        self::assertSame('2025-10-20 07:00:00', $page->date);
        self::assertSame('Ann Author', $page->author);
        self::assertSame('https://blog.example.org/images/articles/12/cover.jpg', $page->image);
        self::assertStringNotContainsString('Share this', $page->body);
        self::assertContains('https://blog.example.org/issues/2025/october-2025?start=10', $page->links);
        self::assertContains('https://blog.example.org/issues/2025/october-2025/next-article', $page->links, 'Relative to <base href>.');
        self::assertNotContains('mailto:editor@example.org', $page->links);
        self::assertSame('', $page->canonical);
    }

    public function testNoContentAndRobotsMeta(): void
    {
        $page = (new PageExtractor())->extract(
            '<html><head><meta name="robots" content="noindex, nofollow"><title>List</title></head>'
            . '<body><div class="list"><a href="/a">A</a></div></body></html>',
            'https://www.example.org/list',
            ExtractionRules::fromCss('.article-body')
        );

        self::assertFalse($page->contentFound);
        self::assertSame('List', $page->title, 'Falls back to <title>.');
        self::assertTrue($page->noindex);
        self::assertTrue($page->nofollow);
    }

    public function testNestedMatchesAreReadOnce(): void
    {
        $page = (new PageExtractor())->extract(
            '<html><body><main><article><p>Only once, please.</p></article></main></body></html>',
            'https://www.example.org/',
            ExtractionRules::fromCss('')
        );

        self::assertSame('Only once, please.', $page->body);
    }

    public function testUtf8AndLongDescriptions(): void
    {
        $long = str_repeat('Zoë’s ćafé text ', 40);
        $page = (new PageExtractor())->extract(
            '<html><head><meta name="description" content="' . $long . '"></head><body><main><p>Ünïcödé</p></main></body></html>',
            'https://www.example.org/',
            ExtractionRules::fromCss('main')
        );

        self::assertSame('Ünïcödé', $page->body);
        self::assertLessThanOrEqual(PageExtractor::MAX_DESCRIPTION + 1, mb_strlen($page->description));
        self::assertStringEndsWith('…', $page->description);
        self::assertStringStartsWith('Zoë’s ćafé', $page->description);
    }
}
