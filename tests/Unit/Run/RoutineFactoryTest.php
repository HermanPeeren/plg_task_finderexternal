<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Html\PageExtractor;
use Yepr\Plugin\Task\FinderExternal\Run\MissingConfiguration;
use Yepr\Plugin\Task\FinderExternal\Run\RoutineFactory;
use Yepr\Plugin\Task\FinderExternal\Source\FeedReader;
use Yepr\Plugin\Task\FinderExternal\Source\SitemapReader;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\FakeClock;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\FakeHttpClient;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\MemoryCycleStore;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\MemorySearchIndex;
use Yepr\Plugin\Task\FinderExternal\Tests\Support\MemoryUrlStore;

final class RoutineFactoryTest extends TestCase
{
    private function factory(): RoutineFactory
    {
        $clock = new FakeClock();

        return new RoutineFactory(
            new MemoryUrlStore(),
            new MemoryCycleStore(),
            new MemorySearchIndex(),
            new FakeHttpClient($clock),
            new PageExtractor(),
            new SitemapReader(),
            new FeedReader(),
            $clock,
            'Default/1.0'
        );
    }

    private function profileParams(string $file): object
    {
        $profile = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($profile);
        self::assertIsArray($profile['params'] ?? null, basename($file));

        return (object) $profile['params'];
    }

    public function testDefaults(): void
    {
        $settings = $this->factory()->settings((object) [
            'site_label' => ' Docs ',
            'sitemaps'   => "https://Docs.Example.org/sitemap.xml\n\nhttps://docs.example.org/sitemap.xml",
            'start_urls' => 'https://www.example.org/',
        ]);

        self::assertSame('Docs', $settings->label);
        self::assertSame(['https://docs.example.org/sitemap.xml'], $settings->sitemaps);
        self::assertSame(['docs.example.org', 'www.example.org'], $settings->hosts);
        self::assertSame(0, $settings->maxDepth);
        self::assertSame('*', $settings->language);
        self::assertSame(1, $settings->access);
        self::assertSame(7, $settings->recheckDays);
        self::assertSame(500, $settings->delay);
        self::assertTrue($settings->respectRobots);
        self::assertSame('Default/1.0', $settings->userAgent);
        self::assertSame(20, $settings->budget);
        self::assertSame(6, $settings->timeout());
    }

    public function testTheUserAgentStaysOneHeaderLine(): void
    {
        $settings = $this->factory()->settings((object) [
            'site_label' => 'Docs',
            'feeds'      => 'https://www.example.org/feed',
            'user_agent' => "Bot/1.0\r\nX-Injected: 1",
            'language'   => 'nl-NL',
            'exclude'    => '/a#b',
        ]);

        self::assertSame('Bot/1.0 X-Injected: 1', $settings->userAgent);
        self::assertSame('nl-NL', $settings->language);
        self::assertFalse($settings->rules->inScope('https://www.example.org/a#b'));
    }

    public function testTheBudgetIsKeptWithinLimits(): void
    {
        $base = ['site_label' => 'Docs', 'feeds' => 'https://www.example.org/feed'];

        self::assertSame(5, $this->factory()->settings((object) (['time_budget' => 1] + $base))->budget);
        self::assertSame(300, $this->factory()->settings((object) (['time_budget' => 9999] + $base))->budget);
        self::assertSame(2, $this->factory()->settings((object) (['time_budget' => 5] + $base))->timeout());
    }

    /**
     * @return  iterable<string, array{array<string, string>, string}>
     */
    public static function mistakes(): iterable
    {
        $ok = ['site_label' => 'Docs', 'sitemaps' => 'https://www.example.org/sitemap.xml'];

        yield 'no label' => [['site_label' => ''] + $ok, 'needs a label'];
        yield 'no source' => [['site_label' => 'Docs'], 'at least one sitemap, feed or start page'];
        yield 'relative URL' => [['sitemaps' => '/sitemap.xml'] + $ok, '"/sitemap.xml" is not an absolute http(s) URL.'];
        yield 'other scheme' => [['feeds' => 'ftp://www.example.org/feed'] + $ok, 'is not an absolute http(s) URL'];
        yield 'broken pattern' => [['exclude' => "ok\n(broken"] + $ok, '"(broken" is not a valid regular expression.'];
        yield 'pattern with delimiter' => [['exclude' => "a#iu\x01e"] + $ok, 'is not a valid regular expression'];
        yield 'language' => [['language' => 'en"GB'] + $ok, 'is not a language tag'];
        yield 'unsupported selector' => [['content_selector' => 'main:first-child'] + $ok, 'not supported'];
    }

    /**
     * @param   array<string, string>  $params
     */
    #[DataProvider('mistakes')]
    public function testMistakesAreReportedClearly(array $params, string $message): void
    {
        $this->expectException(MissingConfiguration::class);
        $this->expectExceptionMessage($message);

        $this->factory()->settings((object) $params);
    }

    /**
     * The profiles in sites/ are valid task parameters.
     */
    public function testTheSiteProfiles(): void
    {
        $profiles = glob(\dirname(__DIR__, 3) . '/sites/*.json') ?: [];

        self::assertNotEmpty($profiles);

        foreach ($profiles as $file) {
            $settings = $this->factory()->settings($this->profileParams($file));

            self::assertNotSame('', $settings->label, basename($file));
            self::assertCount(1, $settings->hosts, basename($file) . ' stays on one site.');
        }
    }

    public function testTheManualProfileIndexesOnlyTheCurrentVersion(): void
    {
        $rules = $this->factory()->settings($this->profileParams(\dirname(__DIR__, 3) . '/sites/manual.joomla.org.json'))->rules;

        self::assertTrue($rules->shouldIndex('https://manual.joomla.org/docs/general-concepts/dependency-injection/'));
        self::assertTrue($rules->shouldIndex('https://manual.joomla.org/updates/53-54/new-features/'));
        self::assertFalse($rules->inScope('https://manual.joomla.org/docs/5.4/general-concepts/'));
        self::assertFalse($rules->inScope('https://manual.joomla.org/docs/next/general-concepts/'));
        self::assertFalse($rules->inScope('https://manual.joomla.org/blog/'));
    }

    public function testTheMagazineProfileFollowsListsAndIndexesArticles(): void
    {
        $rules = $this->factory()->settings($this->profileParams(\dirname(__DIR__, 3) . '/sites/magazine.joomla.org.json'))->rules;

        self::assertTrue($rules->inScope('https://magazine.joomla.org/issues'));
        self::assertTrue($rules->inScope('https://magazine.joomla.org/issues/2025/october-2025?start=10'));
        self::assertFalse($rules->shouldIndex('https://magazine.joomla.org/issues/2025/october-2025'));
        self::assertTrue($rules->shouldIndex('https://magazine.joomla.org/issues/2025/october-2025/css-shorts-subgrid'));
        self::assertFalse($rules->inScope('https://magazine.joomla.org/issues/2025/october-2025?format=feed&type=rss'));
        self::assertFalse($rules->inScope('https://magazine.joomla.org/authors/herman-peeren'));
        self::assertFalse($rules->inScope('https://magazine.joomla.org/component/users/login'));
    }
}
