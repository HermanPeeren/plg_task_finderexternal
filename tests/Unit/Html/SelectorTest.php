<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Yepr\Plugin\Task\FinderExternal\Html\Selector;

final class SelectorTest extends TestCase
{
    private const HTML = <<<'HTML'
        <html><body>
        <main id="content">
          <article class="post featured"><h1 class="title">One</h1><div class="body"><p>Text</p></div></article>
          <article class="post"><h1>Two</h1><div><div class="body"><p>Deep</p></div></div></article>
        </main>
        <aside data-role="ad" lang="en-GB"><p>Ad</p></aside>
        </body></html>
        HTML;

    /**
     * @return  iterable<string, array{string, list<string>}>
     */
    public static function selectors(): iterable
    {
        yield 'tag' => ['h1', ['One', 'Two']];
        yield 'class' => ['.title', ['One']];
        yield 'two classes' => ['.post.featured h1', ['One']];
        yield 'id and descendant' => ['#content .body', ['Text', 'Deep']];
        yield 'child only' => ['article > .body', ['Text']];
        yield 'group' => ['.title, aside', ['One', 'Ad']];
        yield 'attribute present' => ['[data-role]', ['Ad']];
        yield 'attribute equals' => ['aside[data-role="ad"]', ['Ad']];
        yield 'attribute starts with' => ["[lang^='en']", ['Ad']];
        yield 'attribute contains' => ['[class*=feat] p', ['Text']];
        yield 'attribute word' => ['[class~=post] h1', ['One', 'Two']];
        yield 'universal' => ['main > * > h1', ['One', 'Two']];
    }

    /**
     * @param   list<string>  $expected
     */
    #[DataProvider('selectors')]
    public function testSelects(string $css, array $expected): void
    {
        $document = new \DOMDocument();
        $document->loadHTML(self::HTML, LIBXML_NOERROR);
        $nodes = (new \DOMXPath($document))->query(Selector::toXPath($css));

        self::assertNotFalse($nodes);

        $texts = [];

        foreach ($nodes as $node) {
            $texts[] = $node instanceof \DOMNode ? trim($node->textContent) : '';
        }

        self::assertSame($expected, $texts);
    }

    public function testRelativeStartsAtTheContextNode(): void
    {
        self::assertSame('.//p | .//li', Selector::toXPath('p, li', true));
    }

    /**
     * @return  iterable<string, array{string}>
     */
    public static function unsupported(): iterable
    {
        yield 'pseudo-class' => ['a:hover'];
        yield 'sibling' => ['h1 + p'];
        yield 'general sibling' => ['h1 ~ p'];
        yield 'empty part' => ['h1,'];
        yield 'starts with combinator' => ['> p'];
    }

    #[DataProvider('unsupported')]
    public function testRefusesWhatItCannotDo(string $css): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Selector::toXPath($css);
    }
}
