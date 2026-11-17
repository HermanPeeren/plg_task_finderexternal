<?php

declare(strict_types=1);

namespace Yepr\Plugin\Task\FinderExternal\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The automated part of the security review (docs/security-review.md).
 *
 * These are rules the code keeps, checked on every run, so a later change cannot quietly
 * undo them. The review itself is still done by hand before a release.
 */
final class SecurityTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';

    /**
     * @return  iterable<string, array{string}>
     */
    public static function phpFiles(): iterable
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $path = str_replace('\\', '/', (string) $file->getRealPath());

                yield substr($path, (int) strpos($path, '/src/') + 1) => [$path];
            }
        }
    }

    /**
     * No file can be called directly from the web.
     */
    #[DataProvider('phpFiles')]
    public function testEveryFileIsOnlyRunInsideJoomla(string $file): void
    {
        self::assertMatchesRegularExpression("/\\\\?defined\\('_JEXEC'\\) or die/", (string) file_get_contents($file));
    }

    /**
     * No code execution, no unserialize of data, no raw superglobals (input comes from the
     * task form through Joomla, or from external sites through the readers).
     */
    #[DataProvider('phpFiles')]
    public function testNoDangerousCalls(string $file): void
    {
        $code = (string) file_get_contents($file);

        $functions = ['eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'unserialize', 'assert'];

        foreach ($functions as $function) {
            self::assertDoesNotMatchRegularExpression('/(?<![\w>:$])' . $function . '\s*\(/', $code, $function . '() in ' . basename($file));
        }

        self::assertDoesNotMatchRegularExpression('/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES)\b/', $code, 'superglobal in ' . basename($file));
        $withoutTextAndComments = preg_replace('/(["\']).*?(?<!\\\\)\1|\/\/.*|\/\*.*?\*\/|#.*/s', '', $code) ?? '';

        self::assertStringNotContainsString('`', $withoutTextAndComments, 'shell backticks in ' . basename($file));
    }

    /**
     * Values go into queries as bound parameters; quote() only ever gets a literal.
     */
    #[DataProvider('phpFiles')]
    public function testQueriesBindTheirValues(string $file): void
    {
        $code = (string) file_get_contents($file);

        self::assertDoesNotMatchRegularExpression('/->quote\(\s*\$/', $code, 'quote() of a variable in ' . basename($file));
        self::assertDoesNotMatchRegularExpression('/->escape\(/', $code, 'escape() instead of bind() in ' . basename($file));
        self::assertDoesNotMatchRegularExpression(
            '/->(where|set|having)\([^\n]*?\'\s*\.\s*\$(?!this->db)/',
            $code,
            'value concatenated into a query in ' . basename($file)
        );
    }

    /**
     * XML and HTML from other sites are parsed without network access or entity expansion.
     */
    public function testExternalXmlAndHtmlAreParsedSafely(): void
    {
        $readers = [
            'Source/SitemapReader.php',
            'Source/FeedReader.php',
            'Html/PageExtractor.php',
        ];

        foreach ($readers as $reader) {
            $code = (string) file_get_contents(self::SRC . '/plugins/task/finderexternal/src/' . $reader);

            self::assertStringContainsString('LIBXML_NONET', $code, $reader);

            foreach (['LIBXML_NOENT', 'LIBXML_DTDLOAD', 'LIBXML_DTDATTR', 'LIBXML_PARSEHUGE', 'LIBXML_XINCLUDE'] as $flag) {
                self::assertStringNotContainsString($flag, $code, $flag . ' in ' . $reader);
            }
        }
    }

    /**
     * Every field of the task form has a server-side filter; raw only where a regular
     * expression or a CSS selector needs its characters, and those are validated in code.
     */
    public function testEveryFormFieldIsFiltered(): void
    {
        $form = simplexml_load_file(self::SRC . '/plugins/task/finderexternal/forms/index.xml');

        self::assertInstanceOf(\SimpleXMLElement::class, $form);

        $raw = [
            'scope', 'exclude', 'index_only',
            'content_selector', 'title_selector', 'remove_selectors', 'date_selector', 'author_selector', 'category_selector',
        ];

        foreach ($form->xpath('//field') ?: [] as $field) {
            $name = (string) $field['name'];
            $type = (string) $field['type'];

            if (\in_array($type, ['note', 'contentlanguage'], true)) {
                continue;
            }

            self::assertNotSame('', (string) $field['filter'], $name . ' has no filter');

            if ((string) $field['filter'] === 'raw') {
                self::assertContains($name, $raw, $name . ' is raw but not validated as a pattern or selector');
            }
        }
    }

    /**
     * The result layout override prints an external URL escaped.
     */
    public function testTheOverrideEscapesTheShownUrl(): void
    {
        $layout = (string) file_get_contents(__DIR__ . '/../../overrides/html/com_finder/search/default_external.php');

        self::assertStringContainsString('$this->escape($cleanUrl)', $layout);
    }

    /**
     * Text shown on the results page is made safe, and results carry a mime type, so core's
     * highlight plugin doesn't add our visitors' search terms to the external links.
     */
    public function testTheIndexStoresSafeTextAndNoSearchTermsInLinks(): void
    {
        $index = (string) file_get_contents(self::SRC . '/plugins/task/finderexternal/src/Index/FinderSearchIndex.php');

        self::assertStringContainsString("setElement('mime', 'text/html')", $index);

        foreach (['$entry->title', '$entry->description', '$entry->author', '$entry->category'] as $text) {
            self::assertStringContainsString('DisplayText::safe(' . $text . ')', $index, $text);
        }
    }
}
