<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Html;

use Yepr\Plugin\Task\FinderExternal\Url\UrlRules;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Reads an HTML page: title, description, main text, date, author, category, image,
 * canonical URL, robots meta, and every link on the page.
 *
 * The text is plain text with one line per block (paragraph, heading, list item, code
 * block), so that Smart Search gets words, not markup. Code samples stay as they are:
 * `<div>` in a code block is text, not a tag.
 */
final class PageExtractor
{
	/**
	 * Most characters of body text kept per page; past this a page is a dump, not an article.
	 */
	public const MAX_BODY = 200000;

	/**
	 * Most characters of the description.
	 */
	public const MAX_DESCRIPTION = 300;

	/**
	 * Elements that are never text.
	 */
	private const NOT_TEXT = '//script | //style | //noscript | //template | //svg | //iframe | //form | //button | //select | //textarea';

	/**
	 * Elements after which a new line starts.
	 */
	private const BLOCKS = [
		'address' => true, 'article' => true, 'aside' => true, 'blockquote' => true, 'dd' => true, 'details' => true,
		'div' => true, 'dl' => true, 'dt' => true, 'figcaption' => true, 'figure' => true, 'footer' => true,
		'h1' => true, 'h2' => true, 'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true, 'header' => true,
		'hr' => true, 'li' => true, 'main' => true, 'nav' => true, 'ol' => true, 'p' => true, 'pre' => true,
		'section' => true, 'summary' => true, 'table' => true, 'tr' => true, 'ul' => true,
	];

	public function extract(string $html, string $url, ExtractionRules $rules): ExtractedPage
	{
		$document = new \DOMDocument();
		$errors   = libxml_use_internal_errors(true);

		try {
			// The XML declaration makes libxml read the bytes as UTF-8 instead of Latin-1.
			$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($errors);
		}

		$xpath = new \DOMXPath($document);
		$base  = $this->base($xpath, $url);

		// Links first: navigation is removed below, and that is where many links are.
		$links  = $this->links($xpath, $base);
		$robots = strtolower($this->attribute($xpath, '//meta[translate(@name, "ROBTS", "robts")="robots"]/@content'));

		$canonicalHref = $this->attribute($xpath, '//link[translate(@rel, "CANONICAL", "canonical")="canonical"]/@href');
		$canonical     = $canonicalHref === '' ? '' : (UrlRules::normalize($canonicalHref, $base) ?? '');

		$title = $rules->title === '' ? '' : $this->firstText($xpath, $rules->title);
		$title = $title !== '' ? $title : $this->attribute($xpath, '//meta[@property="og:title"]/@content');
		$title = $title !== '' ? $title : $this->firstText($xpath, '//title');

		$metaDescription = $this->attribute($xpath, '//meta[translate(@name, "DESCRIPTON", "descripton")="description"]/@content');
		$metaDescription = $metaDescription !== '' ? $metaDescription : $this->attribute($xpath, '//meta[@property="og:description"]/@content');

		$date   = $this->date($xpath, $rules);
		$author = $rules->author === '' ? '' : $this->firstText($xpath, $rules->author);
		$author = $author !== '' ? $author : $this->attribute($xpath, '//meta[@name="author"]/@content');

		$category = $rules->category === '' ? '' : $this->lastText($xpath, $rules->category);
		$imageUrl = $this->attribute($xpath, '//meta[@property="og:image"]/@content');
		$image    = $imageUrl === '' ? '' : (UrlRules::normalize($imageUrl, $base) ?? '');

		foreach (array_merge([self::NOT_TEXT], $rules->remove) as $expression) {
			$this->removeAll($xpath, $expression);
		}

		$body      = '';
		$paragraph = '';
		$used      = [];

		foreach ($this->nodes($xpath, $rules->content) as $node) {
			// "main, article" matches an article inside main twice; read it once.
			if ($this->insideAny($node, $used)) {
				continue;
			}

			$used[] = $node;
			$body  .= "\n" . $this->text($node);

			if ($paragraph === '') {
				$paragraph = $this->firstParagraph($xpath, $node);
			}
		}

		$body = $this->clean($body);

		if (mb_strlen($body) > self::MAX_BODY) {
			$body = mb_substr($body, 0, self::MAX_BODY);
		}

		$description = $rules->descriptionSource === ExtractionRules::DESCRIPTION_CONTENT
			? ($paragraph !== '' ? $paragraph : $metaDescription)
			: ($metaDescription !== '' ? $metaDescription : $paragraph);

		return new ExtractedPage(
			$body !== '',
			$this->line($title),
			self::shorten($this->line($description), self::MAX_DESCRIPTION),
			$body,
			$date,
			$this->line($author),
			$this->line($category),
			$image,
			$links,
			$canonical,
			str_contains($robots, 'noindex') || str_contains($robots, 'none'),
			str_contains($robots, 'nofollow') || str_contains($robots, 'none')
		);
	}

	/**
	 * Cut text at a word boundary, with an ellipsis when cut.
	 */
	public static function shorten(string $text, int $length): string
	{
		if (mb_strlen($text) <= $length) {
			return $text;
		}

		$cut   = mb_substr($text, 0, $length);
		$space = mb_strrpos($cut, ' ');

		return rtrim($space !== false && $space > $length / 2 ? mb_substr($cut, 0, $space) : $cut, " ,.;:") . '…';
	}

	private function base(\DOMXPath $xpath, string $url): string
	{
		$href = $this->attribute($xpath, '//base/@href');

		return $href === '' ? $url : (UrlRules::normalize($href, $url) ?? $url);
	}

	/**
	 * @return  list<string>
	 */
	private function links(\DOMXPath $xpath, string $base): array
	{
		$links = [];

		foreach ($this->nodes($xpath, '//a[@href]') as $anchor) {
			if (!$anchor instanceof \DOMElement) {
				continue;
			}

			$rel = strtolower($anchor->getAttribute('rel'));

			if (str_contains($rel, 'nofollow')) {
				continue;
			}

			$link = UrlRules::normalize($anchor->getAttribute('href'), $base);

			if ($link !== null) {
				$links[$link] = true;
			}
		}

		return array_keys($links);
	}

	private function date(\DOMXPath $xpath, ExtractionRules $rules): string
	{
		$candidates = [];

		if ($rules->date !== '') {
			foreach ($this->nodes($xpath, $rules->date) as $node) {
				if ($node instanceof \DOMElement) {
					$candidates[] = $node->getAttribute('datetime');
					$candidates[] = $node->getAttribute('content');
				}

				$candidates[] = $this->line($node->textContent);
				break;
			}
		}

		$candidates[] = $this->attribute($xpath, '//meta[@property="article:published_time"]/@content');
		$candidates[] = $this->attribute($xpath, '//meta[@property="article:modified_time"]/@content');

		foreach ($candidates as $candidate) {
			if ($candidate === '') {
				continue;
			}

			try {
				$date = new \DateTimeImmutable($candidate, new \DateTimeZone('UTC'));
			} catch (\Exception) {
				continue;
			}

			return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
		}

		return '';
	}

	private function firstParagraph(\DOMXPath $xpath, \DOMNode $context): string
	{
		foreach ($this->nodes($xpath, './/p', $context) as $paragraph) {
			$text = $this->line($paragraph->textContent);

			// Skip captions, bylines and other scraps.
			if (mb_strlen($text) >= 40) {
				return $text;
			}
		}

		return '';
	}

	private function text(\DOMNode $node): string
	{
		$text = '';

		foreach ($node->childNodes as $child) {
			if ($child instanceof \DOMText) {
				$text .= $child->data;

				continue;
			}

			if (!$child instanceof \DOMElement) {
				continue;
			}

			$name = strtolower($child->nodeName);

			if ($name === 'br') {
				$text .= "\n";

				continue;
			}

			if ($name === 'pre') {
				$text .= "\n" . $child->textContent . "\n";

				continue;
			}

			$block = isset(self::BLOCKS[$name]);
			$text .= ($block ? "\n" : '') . $this->text($child) . ($block ? "\n" : '');

			if ($name === 'td' || $name === 'th') {
				$text .= ' ';
			}
		}

		return $text;
	}

	/**
	 * One line per block, single spaces, no empty lines.
	 */
	private function clean(string $text): string
	{
		$lines = [];

		foreach (preg_split('/\R/u', $text) ?: [] as $line) {
			$line = $this->line($line);

			if ($line !== '') {
				$lines[] = $line;
			}
		}

		return implode("\n", $lines);
	}

	private function line(string $text): string
	{
		return trim((string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', ' ', $text));
	}

	private function firstText(\DOMXPath $xpath, string $expression): string
	{
		foreach ($this->nodes($xpath, $expression) as $node) {
			$text = $this->line($node->textContent);

			if ($text !== '') {
				return $text;
			}
		}

		return '';
	}

	private function lastText(\DOMXPath $xpath, string $expression): string
	{
		$last = '';

		foreach ($this->nodes($xpath, $expression) as $node) {
			$text = $this->line($node->textContent);

			if ($text !== '') {
				$last = $text;
			}
		}

		return $last;
	}

	private function attribute(\DOMXPath $xpath, string $expression): string
	{
		foreach ($this->nodes($xpath, $expression) as $node) {
			$value = trim((string) $node->nodeValue);

			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param   list<\DOMNode>  $ancestors
	 */
	private function insideAny(\DOMNode $node, array $ancestors): bool
	{
		for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
			if (\in_array($parent, $ancestors, true)) {
				return true;
			}
		}

		return false;
	}

	private function removeAll(\DOMXPath $xpath, string $expression): void
	{
		foreach ($this->nodes($xpath, $expression) as $node) {
			$node->parentNode?->removeChild($node);
		}
	}

	/**
	 * @return  list<\DOMNode>
	 */
	private function nodes(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): array
	{
		$result = @$xpath->query($expression, $context);

		if ($result === false) {
			return [];
		}

		$nodes = [];

		foreach ($result as $node) {
			if ($node instanceof \DOMNode) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}
}
