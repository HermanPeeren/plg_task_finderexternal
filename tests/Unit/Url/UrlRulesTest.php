<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Url;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Url\UrlRules;

final class UrlRulesTest extends TestCase
{
    /**
     * @return  iterable<string, array{string, string, ?string}>
     */
    public static function links(): iterable
    {
        $base = 'https://www.example.org/docs/guide/page';

        yield 'absolute' => ['https://www.example.org/a', $base, 'https://www.example.org/a'];
        yield 'root relative' => ['/b/c', $base, 'https://www.example.org/b/c'];
        yield 'relative' => ['other', $base, 'https://www.example.org/docs/guide/other'];
        yield 'parent' => ['../intro/', $base, 'https://www.example.org/docs/intro/'];
        yield 'too many parents' => ['../../../../x', $base, 'https://www.example.org/x'];
        yield 'dot' => ['./same', $base, 'https://www.example.org/docs/guide/same'];
        yield 'query only' => ['?start=10', $base, 'https://www.example.org/docs/guide/page?start=10'];
        yield 'protocol relative' => ['//cdn.example.org/x', $base, 'https://cdn.example.org/x'];
        yield 'fragment dropped' => ['/a#part', $base, 'https://www.example.org/a'];
        yield 'host lower case, default port dropped' => ['HTTPS://WWW.Example.ORG:443/A', $base, 'https://www.example.org/A'];
        yield 'other port kept' => ['http://localhost:8080/x', $base, 'http://localhost:8080/x'];
        yield 'empty path' => ['https://www.example.org', $base, 'https://www.example.org/'];
        yield 'space encoded' => ['/a b', $base, 'https://www.example.org/a%20b'];
        yield 'bare fragment' => ['#top', $base, null];
        yield 'mailto' => ['mailto:a@example.org', $base, null];
        yield 'javascript' => ['javascript:void(0)', $base, null];
        yield 'ftp' => ['ftp://example.org/file', $base, null];
        yield 'empty' => ['  ', $base, null];
        yield 'quote and brackets encoded' => ['/a"onmouseover=x<b>\'c', $base, 'https://www.example.org/a%22onmouseover=x%3Cb%3E%27c'];
        yield 'encoded stays' => ['/a%20b?q=%3C', $base, 'https://www.example.org/a%20b?q=%3C'];
        yield 'non-ASCII encoded' => ['/café', $base, 'https://www.example.org/caf%C3%A9'];
        yield 'credentials dropped' => ['https://user:pw@www.example.org/x', $base, 'https://www.example.org/x'];
        yield 'invalid host' => ['https://exa"mple.org/', $base, null];
    }

    #[DataProvider('links')]
    public function testNormalize(string $href, string $base, ?string $expected): void
    {
        self::assertSame($expected, UrlRules::normalize($href, $base));
    }

    public function testOnlyTheTasksHostsAreInScope(): void
    {
        $rules = new UrlRules(['docs.example.org']);

        self::assertTrue($rules->inScope('https://docs.example.org/anything'));
        self::assertFalse($rules->inScope('https://github.com/docs.example.org'));
    }

    public function testScopeExcludeAndIndex(): void
    {
        $rules = new UrlRules(
            ['docs.example.org'],
            [UrlRules::compile('^https://docs\.example\.org/(docs|updates)/')],
            [UrlRules::compile('/docs/(next|\d+\.\d+)/'), UrlRules::compile('#hash-in-pattern')],
            [UrlRules::compile('/docs/.+')]
        );

        self::assertTrue($rules->inScope('https://docs.example.org/docs/'));
        self::assertTrue($rules->inScope('https://DOCS.example.org/Docs/intro/'), 'Case-insensitive.');
        self::assertFalse($rules->inScope('https://docs.example.org/blog/'));
        self::assertFalse($rules->inScope('https://docs.example.org/docs/5.4/intro/'));
        self::assertFalse($rules->inScope('https://docs.example.org/docs/next/intro/'));

        self::assertTrue($rules->shouldIndex('https://docs.example.org/docs/intro/'));
        self::assertFalse($rules->shouldIndex('https://docs.example.org/docs/'), 'An overview: visited, not indexed.');
        self::assertFalse($rules->shouldIndex('https://docs.example.org/updates/x/'));
        self::assertFalse($rules->shouldIndex('https://docs.example.org/docs/5.4/intro/'));
    }

    public function testABrokenPatternIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"(unclosed" is not a valid regular expression.');

        UrlRules::compile('(unclosed');
    }
}
