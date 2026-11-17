<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Html\ExtractionRules;
use Yepr\Plugin\Task\FinderExternal\Html\PageExtractor;
use Yepr\Plugin\Task\FinderExternal\Index\DisplayText;

final class DisplayTextTest extends TestCase
{
    public function testMarkupBecomesText(): void
    {
        self::assertSame('Use &lt;jdoc:include&gt; &amp; more', DisplayText::safe('Use <jdoc:include> &amp; more'));
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', DisplayText::safe('<script>alert(1)</script>'));
        self::assertSame('Q&A "quoted"', DisplayText::safe("Q&A \"quoted\"\x00\x1B"));
    }

    /**
     * An entity in an external title is a character after reading; it must not come out as markup.
     */
    public function testAnEscapedTitleOnAnExternalPageStaysText(): void
    {
        $page = (new PageExtractor())->extract(
            '<html><head><title>x</title></head><body><main><h1>Using &lt;img src=x onerror=alert(1)&gt;</h1>'
            . '<p>Some text that is long enough to be the first paragraph of the page.</p></main></body></html>',
            'https://www.example.org/a',
            ExtractionRules::fromCss('main', 'h1', [], 'content', '', '', '')
        );

        self::assertSame('Using <img src=x onerror=alert(1)>', $page->title);
        self::assertSame('Using &lt;img src=x onerror=alert(1)&gt;', DisplayText::safe($page->title));
    }
}
