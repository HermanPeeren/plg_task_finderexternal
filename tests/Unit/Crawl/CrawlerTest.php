<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Crawl;

use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Crawl\Crawler;
use Yepr\Plugin\Task\FinderExternal\Html\PageExtractor;
use Yepr\Plugin\Task\FinderExternal\Run\RoutineFactory;
use Yepr\Plugin\Task\FinderExternal\Run\RunOutcome;
use Yepr\Plugin\Task\FinderExternal\Source\FeedReader;
use Yepr\Plugin\Task\FinderExternal\Source\SitemapReader;
use Yepr\Plugin\Task\FinderExternal\Store\UrlRecord;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\FakeClock;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\FakeHttpClient;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\MemoryCycleStore;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\MemorySearchIndex;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\MemoryUrlStore;

/**
 * Whole crawls against a web in memory, through RoutineFactory as the plugin does it.
 */
final class CrawlerTest extends TestCase
{
    private const TASK = 7;

    private FakeClock $clock;

    private FakeHttpClient $http;

    private MemoryUrlStore $urls;

    private MemoryCycleStore $cycles;

    private MemorySearchIndex $index;

    /**
     * @var list<array{string, string}>
     */
    private array $log = [];

    protected function setUp(): void
    {
        $this->clock  = new FakeClock();
        $this->http   = new FakeHttpClient($this->clock);
        $this->urls   = new MemoryUrlStore();
        $this->cycles = new MemoryCycleStore();
        $this->index  = new MemorySearchIndex();
    }

    // ---------------------------------------------------------------- A documentation site with a sitemap

    /**
     * @return  array<string, int|string>
     */
    private function docsParams(): array
    {
        return [
            'site_label'       => 'Documentation',
            'sitemaps'         => 'https://docs.example.org/sitemap.xml',
            'exclude'          => "// old versions\n/docs/(next|\\d+\\.\\d+)/",
            'index_only'       => '^https://docs\.example\.org/docs/.+',
            'content_selector' => '.markdown',
            'title_selector'   => '.markdown h1',
            'request_delay'    => 0,
        ];
    }

    private function docsPage(string $title, string $text = 'Some text about it.'): string
    {
        return '<html><head><meta name="description" content="About ' . $title . '"></head><body><nav><a href="/docs/">Docs</a></nav>'
            . '<main><div class="markdown"><h1>' . $title . '</h1><p>' . $text . '</p></div></main></body></html>';
    }

    /**
     * @param   list<string>  $paths
     */
    private function docsSitemap(array $paths): void
    {
        $xml = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($paths as $path) {
            $xml .= '<url><loc>' . (str_starts_with($path, 'http') ? $path : 'https://docs.example.org' . $path) . '</loc></url>';
        }

        $this->http->page('https://docs.example.org/sitemap.xml', $xml . '</urlset>', 200, ['content-type' => 'application/xml']);
    }

    private function docsSite(): void
    {
        $this->http->page('https://docs.example.org/robots.txt', "User-agent: *\nDisallow: /search\n", 200, ['content-type' => 'text/plain']);
        $this->docsSitemap(['/docs/', '/docs/intro/', '/docs/install/', '/docs/5.4/intro/', '/search', 'https://elsewhere.example.com/docs/x/']);
        $this->http
            ->page('https://docs.example.org/docs/', $this->docsPage('Overview'))
            ->page('https://docs.example.org/docs/intro/', $this->docsPage('Introduction'), 200, ['content-type' => 'text/html', 'etag' => '"i1"'])
            ->page('https://docs.example.org/docs/install/', $this->docsPage('Installation'));
    }

    /**
     * @param   array<string, int|string>  $params
     */
    private function runOnce(array $params): RunOutcome
    {
        $factory = new RoutineFactory(
            $this->urls,
            $this->cycles,
            $this->index,
            $this->http,
            new PageExtractor(),
            new SitemapReader(),
            new FeedReader(),
            $this->clock,
            'JoomlaSmartSearchExternal/1.0 (+https://www.example.org/)'
        );

        return $factory->crawler((object) $params, function (string $message, string $priority): void {
            $this->log[] = [$priority, $message];
        })->run(self::TASK);
    }

    /**
     * @param   array<string, int|string>  $params
     */
    private function runUntilDone(array $params, int $maxRuns = 50): int
    {
        for ($runs = 1; $runs <= $maxRuns; $runs++) {
            if ($this->runOnce($params)->finished) {
                return $runs;
            }
        }

        self::fail("Not done after {$maxRuns} runs.");
    }

    public function testIndexesTheSitemapPagesWithinScopeAndRules(): void
    {
        $this->docsSite();

        self::assertSame(1, $this->runUntilDone($this->docsParams()));

        self::assertSame(['https://docs.example.org/docs/install/', 'https://docs.example.org/docs/intro/'], $this->index->urls());

        $entry = $this->index->entry('https://docs.example.org/docs/intro/');
        self::assertNotNull($entry);
        self::assertSame('Introduction', $entry->title);
        self::assertSame('About Introduction', $entry->description);
        self::assertSame("Introduction\nSome text about it.", $entry->body);
        self::assertSame('Documentation', $entry->type);
        self::assertSame('*', $entry->language);
        self::assertSame(1, $entry->access);

        $requested = $this->http->requestedUrls();
        self::assertNotContains('https://docs.example.org/docs/5.4/intro/', $requested, 'Excluded.');
        self::assertNotContains('https://docs.example.org/search', $requested, 'Disallowed by robots.txt.');
        self::assertNotContains('https://elsewhere.example.com/docs/x/', $requested, 'Another host.');
        self::assertSame(UrlRecord::FOLLOWED, $this->urls->record('https://docs.example.org/docs/')?->status, 'Visited, not to be indexed.');

        self::assertSame('JoomlaSmartSearchExternal/1.0 (+https://www.example.org/)', $this->http->requests[0]['headers']['User-Agent']);
        self::assertStringContainsString('Cycle 1 all done: 2 pages of Documentation in the index', $this->lastLog());
    }

    public function testANewCycleOnlyRereadsTheSourcesUntilPagesAreDueAgain(): void
    {
        $this->docsSite();
        $this->runUntilDone($this->docsParams());
        $this->http->requests = [];
        $writes               = $this->index->writes;

        $this->runUntilDone($this->docsParams());

        self::assertSame(['https://docs.example.org/robots.txt', 'https://docs.example.org/sitemap.xml'], $this->http->requestedUrls());

        // A week and a day later every page is checked again; nothing changed.
        $this->clock->days(8);
        $this->http->requests = [];
        $this->runUntilDone($this->docsParams());

        self::assertContains('https://docs.example.org/docs/intro/', $this->http->requestedUrls());
        $intro = array_values(array_filter(
            $this->http->requests,
            static fn (array $r): bool => $r['url'] === 'https://docs.example.org/docs/intro/'
        ));
        self::assertSame('"i1"', $intro[0]['headers']['If-None-Match'] ?? null, 'Revalidated with its ETag.');
        self::assertSame($writes, $this->index->writes, 'Unchanged pages are not written to the index again.');
    }

    public function testAChangedPageIsIndexedAgain(): void
    {
        $this->docsSite();
        $this->runUntilDone($this->docsParams());

        $this->http->page('https://docs.example.org/docs/install/', $this->docsPage('Installation', 'New steps.'));
        $this->clock->days(8);
        $this->runUntilDone($this->docsParams());

        self::assertSame("Installation\nNew steps.", $this->index->entry('https://docs.example.org/docs/install/')?->body);
        self::assertCount(2, $this->index->entries);
    }

    public function testPagesThatLeaveTheSitemapOrAreGoneAreRemoved(): void
    {
        $this->docsSite();
        $this->runUntilDone($this->docsParams());

        $this->docsSitemap(['/docs/', '/docs/intro/']);
        $this->runUntilDone($this->docsParams());

        self::assertSame(['https://docs.example.org/docs/intro/'], $this->index->urls());
        self::assertNull($this->urls->record('https://docs.example.org/docs/install/'));

        $this->http->forget('https://docs.example.org/docs/intro/');
        $this->clock->days(8);
        $this->runUntilDone($this->docsParams());

        self::assertSame([], $this->index->urls());
        self::assertSame(UrlRecord::GONE, $this->urls->record('https://docs.example.org/docs/intro/')?->status);
    }

    public function testAFailingSitemapRemovesNothing(): void
    {
        $this->docsSite();
        $this->runUntilDone($this->docsParams());

        $this->http->fail('https://docs.example.org/sitemap.xml', 'Connection timed out');
        $this->runUntilDone($this->docsParams());

        self::assertCount(2, $this->index->entries);
        self::assertContains(['warning', 'https://docs.example.org/sitemap.xml: Connection timed out'], $this->log);
    }

    public function testAfterClearIndexThePagesComeBack(): void
    {
        $this->docsSite();
        $this->runUntilDone($this->docsParams());

        $this->index->clear();
        $this->runUntilDone($this->docsParams());

        self::assertCount(2, $this->index->entries);
        self::assertContains(['warning', '2 pages were no longer in the Smart Search index; they are indexed again.'], $this->log);
    }

    public function testWorksInPortionsWithinTheTimeBudget(): void
    {
        $paths = [];

        for ($i = 1; $i <= 30; $i++) {
            $paths[] = '/docs/page-' . $i . '/';
            $this->http->page('https://docs.example.org/docs/page-' . $i . '/', $this->docsPage('Page ' . $i));
        }

        $this->docsSitemap($paths);

        // 1 second per request, 5 seconds per run, of which 2 are kept free for a slow answer.
        $this->http = new FakeHttpClient($this->clock, 1.0);
        $this->docsSitemap($paths);

        for ($i = 1; $i <= 30; $i++) {
            $this->http->page('https://docs.example.org/docs/page-' . $i . '/', $this->docsPage('Page ' . $i));
        }

        $params = ['time_budget' => 5, 'respect_robots' => 0] + $this->docsParams();
        $runs   = 0;

        do {
            $start   = $this->clock->seconds();
            $outcome = $this->runOnce($params);
            $runs++;

            self::assertLessThanOrEqual(5.0, $this->clock->seconds() - $start, 'A run stays within its budget.');
        } while (!$outcome->finished && $runs < 40);

        self::assertTrue($outcome->finished);
        self::assertGreaterThan(5, $runs);
        self::assertCount(30, $this->index->entries);
        self::assertMatchesRegularExpression('/^Cycle 1 \(process\): \d+ checked, \d+ indexed, .*; \d+ URLs still to check\.$/', $this->log[1][1]);
    }

    public function testThePauseBetweenRequests(): void
    {
        $this->docsSite();
        $start = $this->clock->seconds();

        $this->runUntilDone(['request_delay' => 1000] + $this->docsParams());

        // robots.txt, sitemap and 3 pages: 5 requests of 0.1 s, 4 pauses of 1 s between them.
        self::assertEqualsWithDelta(4.5, $this->clock->seconds() - $start, 0.001);
    }

    private function lastLog(): string
    {
        return $this->log === [] ? '' : $this->log[\count($this->log) - 1][1];
    }

    // ---------------------------------------------------------------- A blog crawled through its links

    /**
     * @return  array<string, int|string>
     */
    private function blogParams(): array
    {
        return [
            'site_label'         => 'Blog',
            'start_urls'         => 'https://blog.example.org/',
            'feeds'              => 'https://blog.example.org/?format=feed&type=rss',
            'max_depth'          => 5,
            'exclude'            => "format=feed\n/tags/",
            'index_only'         => '^https://blog\.example\.org/issues/\d{4}/[^/?]+/[^/?]+$',
            'content_selector'   => '.article-body',
            'title_selector'     => '.page-header h1',
            'description_source' => 'content',
            'request_delay'      => 0,
        ];
    }

    private function article(string $title, string $extra = ''): string
    {
        return '<html><head>' . $extra . '</head><body><div class="page-header"><h1>' . $title . '</h1></div>'
            . '<div class="article-body"><p>This is the text of the article called ' . $title . '.</p></div>'
            . '<a href="/tags/css">css</a> <a href="https://twitter.com/share">Share</a></body></html>';
    }

    /**
     * @param   list<string>  $hrefs
     */
    private function links(array $hrefs): string
    {
        $anchors = array_map(static fn (string $href): string => '<a href="' . $href . '">link</a>', $hrefs);

        return '<html><body>' . implode(' ', $anchors) . '</body></html>';
    }

    private function blogSite(): void
    {
        $base = 'https://blog.example.org';
        $this->http
            ->page($base . '/robots.txt', "User-agent: *\nDisallow: /component/\n", 200, ['content-type' => 'text/plain'])
            ->page(
                $base . '/?format=feed&type=rss',
                '<rss><channel><item><link>' . $base . '/issues/2026/november/fresh</link></item></channel></rss>',
                200,
                ['content-type' => 'application/rss+xml']
            )
            ->page($base . '/', $this->links(['/issues', '/component/users/login', '/issues/2026/november/fresh']))
            ->page($base . '/issues', $this->links(['/issues/2025/october']))
            ->page($base . '/issues/2025/october', $this->links(['/issues/2025/october/one', '?start=10']))
            ->page($base . '/issues/2025/october?start=10', $this->links(['/issues/2025/october/two', '/issues/2025/october/old-name']))
            ->page($base . '/issues/2025/october/one', $this->article('One'))
            ->page($base . '/issues/2025/october/two', $this->article('Two', '<link rel="canonical" href="' . $base . '/issues/2025/october/two">'))
            ->redirect($base . '/issues/2025/october/old-name', $base . '/issues/2025/october/new-name')
            ->page($base . '/issues/2025/october/new-name', $this->article('Renamed'))
            ->page($base . '/issues/2026/november/fresh', $this->article('Fresh'));
    }

    public function testFollowsLinksAndIndexesOnlyArticles(): void
    {
        $this->blogSite();

        $this->runUntilDone($this->blogParams());

        self::assertSame([
            'https://blog.example.org/issues/2025/october/new-name',
            'https://blog.example.org/issues/2025/october/one',
            'https://blog.example.org/issues/2025/october/two',
            'https://blog.example.org/issues/2026/november/fresh',
        ], $this->index->urls());

        self::assertSame(
            'This is the text of the article called One.',
            $this->index->entry('https://blog.example.org/issues/2025/october/one')?->description
        );

        $requested = $this->http->requestedUrls();
        self::assertNotContains('https://blog.example.org/component/users/login', $requested);
        self::assertNotContains('https://blog.example.org/tags/css', $requested);
        self::assertNotContains('https://twitter.com/share', $requested);
        self::assertSame(UrlRecord::MOVED, $this->urls->record('https://blog.example.org/issues/2025/october/old-name')?->status);
    }

    public function testTheLinkDepthIsALimit(): void
    {
        $this->blogSite();

        // Home (0) -> issues (1) -> October (2): the articles at depth 3 are not reached;
        // the feed's article is depth 1.
        $this->runUntilDone(['max_depth' => 2] + $this->blogParams());

        self::assertSame(['https://blog.example.org/issues/2026/november/fresh'], $this->index->urls());
    }

    public function testNoindexAndCanonicalElsewhere(): void
    {
        $this->blogSite();
        $this->http
            ->page('https://blog.example.org/issues/2025/october/one', $this->article('One', '<meta name="robots" content="noindex">'))
            ->page(
                'https://blog.example.org/issues/2025/october/two',
                $this->article('Two', '<link rel="canonical" href="/issues/2026/november/fresh">')
            );

        $this->runUntilDone($this->blogParams());

        self::assertSame([
            'https://blog.example.org/issues/2025/october/new-name',
            'https://blog.example.org/issues/2026/november/fresh',
        ], $this->index->urls());
    }

    public function testAnOversizedPageIsNotRead(): void
    {
        $this->blogSite();
        $this->http->page(
            'https://blog.example.org/issues/2025/october/one',
            $this->article('One') . str_repeat(' ', Crawler::MAX_HTML)
        );

        $this->runUntilDone($this->blogParams());

        self::assertNotContains('https://blog.example.org/issues/2025/october/one', $this->index->urls());
        self::assertContains('https://blog.example.org/issues/2025/october/two', $this->index->urls());
    }

    public function testStartPagesAreReadEveryCycleForNewLinks(): void
    {
        $this->blogSite();
        $this->runUntilDone($this->blogParams());

        $this->http
            ->page('https://blog.example.org/', $this->links(['/issues/2026/november/newer']))
            ->page('https://blog.example.org/issues/2026/november/newer', $this->article('Newer'));
        $this->runUntilDone($this->blogParams());

        self::assertContains('https://blog.example.org/issues/2026/november/newer', $this->index->urls());
        self::assertCount(5, $this->index->entries, 'Pages no longer linked stay until they are gone.');
    }
}
