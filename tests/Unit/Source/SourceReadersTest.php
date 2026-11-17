<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Source;

use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Source\FeedReader;
use Yepr\Plugin\Task\FinderExternal\Source\SitemapReader;

final class SourceReadersTest extends TestCase
{
    public function testASitemap(): void
    {
        $read = (new SitemapReader())->read(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
              <url><loc>https://docs.example.org/docs/</loc><changefreq>weekly</changefreq></url>
              <url><loc> https://docs.example.org/docs/intro/ </loc><xhtml:link rel="alternate" href="https://docs.example.org/nl/docs/intro/"/></url>
            </urlset>
            XML);

        self::assertSame(['https://docs.example.org/docs/', 'https://docs.example.org/docs/intro/'], $read['urls']);
        self::assertSame([], $read['sitemaps']);
    }

    public function testASitemapIndex(): void
    {
        $read = (new SitemapReader())->read(
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . '<sitemap><loc>https://www.example.org/sitemap-1.xml</loc></sitemap></sitemapindex>'
        );

        self::assertSame([], $read['urls']);
        self::assertSame(['https://www.example.org/sitemap-1.xml'], $read['sitemaps']);
    }

    public function testNotASitemap(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new SitemapReader())->read('<!DOCTYPE html><html><body>404 Not found');
    }

    public function testAnRssFeed(): void
    {
        $links = (new FeedReader())->links(<<<'XML'
            <?xml version="1.0" encoding="utf-8"?>
            <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
              <channel>
                <title>Blog</title>
                <link>https://blog.example.org/</link>
                <atom:link href="https://blog.example.org/?format=feed&amp;type=rss" rel="self"/>
                <item><title>One</title><link>https://blog.example.org/issues/2026/one</link></item>
                <item><title>Two</title><link>https://blog.example.org/issues/2026/two</link><guid>x</guid></item>
              </channel>
            </rss>
            XML);

        self::assertSame(['https://blog.example.org/issues/2026/one', 'https://blog.example.org/issues/2026/two'], $links);
    }

    public function testAnAtomFeed(): void
    {
        $links = (new FeedReader())->links(<<<'XML'
            <feed xmlns="http://www.w3.org/2005/Atom">
              <link rel="self" href="https://blog.example.org/feed"/>
              <entry><link rel="alternate" href="https://blog.example.org/a"/><link rel="edit" href="https://blog.example.org/edit/a"/></entry>
              <entry><link href="https://blog.example.org/b"/></entry>
            </feed>
            XML);

        self::assertSame(['https://blog.example.org/a', 'https://blog.example.org/b'], $links);
    }
}
