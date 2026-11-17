<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Http\RobotsTxt;

final class RobotsTxtTest extends TestCase
{
    private const AGENT = 'JoomlaSmartSearchExternal/1.0 (+https://www.example.org/)';

    public function testTheStarGroup(): void
    {
        $robots = RobotsTxt::parse(<<<'TXT'
            # Comment
            User-agent: *
            Allow: /*.css$
            Disallow: /administrator/
            Disallow: /component/
            Disallow: /*?format=feed
            Disallow: /search$
            Allow: /component/content/

            User-agent: OtherBot
            Disallow: /
            TXT, self::AGENT);

        self::assertTrue($robots->allows('https://www.example.org/issues/2025/october'));
        self::assertFalse($robots->allows('https://www.example.org/administrator/index.php'));
        self::assertFalse($robots->allows('https://www.example.org/component/users/login'));
        self::assertTrue($robots->allows('https://www.example.org/component/content/article'), 'The longer Allow wins.');
        self::assertFalse($robots->allows('https://www.example.org/issues?format=feed&type=rss'));
        self::assertFalse($robots->allows('https://www.example.org/search'));
        self::assertTrue($robots->allows('https://www.example.org/search/results'), '$ anchors the end.');
        self::assertFalse($robots->allows('https://www.example.org/administrator/x.css'), 'The longer Disallow wins.');
        self::assertTrue(
            RobotsTxt::parse("User-agent: *\nDisallow: /page\nAllow: /page\n", self::AGENT)->allows('https://www.example.org/page'),
            'Same length: Allow wins.'
        );
    }

    public function testOurOwnGroupReplacesTheStarGroup(): void
    {
        $robots = RobotsTxt::parse(<<<'TXT'
            User-agent: *
            Disallow: /

            User-agent: SomeBot
            User-agent: JoomlaSmartSearchExternal
            Disallow: /private/
            TXT, self::AGENT);

        self::assertTrue($robots->allows('https://www.example.org/docs/'));
        self::assertFalse($robots->allows('https://www.example.org/private/x'));
    }

    public function testEmptyDisallowAndNoRobotsAllowEverything(): void
    {
        self::assertTrue(RobotsTxt::parse("User-agent: *\nDisallow:\n", self::AGENT)->allows('https://www.example.org/a'));
        self::assertTrue(RobotsTxt::parse('', self::AGENT)->allows('https://www.example.org/a'));
        self::assertTrue(RobotsTxt::allowAll()->allows('https://www.example.org/'));
    }
}
