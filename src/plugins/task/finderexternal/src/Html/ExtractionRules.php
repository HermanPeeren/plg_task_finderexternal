<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Html;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Where the parts of a page are, for one site. All selectors are CSS (see Selector),
 * already converted to XPath by fromCss(), so a bad selector is reported once, when the
 * task starts, not on every page.
 */
final class ExtractionRules
{
	public const DESCRIPTION_META    = 'meta';
	public const DESCRIPTION_CONTENT = 'content';

	/**
	 * @param   list<string>  $remove  XPath expressions of the parts to leave out.
	 */
	private function __construct(
		public readonly string $content,
		public readonly string $title,
		public readonly array $remove,
		public readonly string $descriptionSource,
		public readonly string $date,
		public readonly string $author,
		public readonly string $category
	) {
	}

	/**
	 * @param   list<string>  $remove  CSS selectors.
	 *
	 * @throws  \InvalidArgumentException  When a selector is not supported.
	 */
	public static function fromCss(
		string $content,
		string $title = 'h1',
		array $remove = [],
		string $descriptionSource = self::DESCRIPTION_META,
		string $date = '',
		string $author = '',
		string $category = ''
	): self {
		$xpath = static fn (string $css): string => trim($css) === '' ? '' : Selector::toXPath($css);

		return new self(
			$xpath(trim($content) === '' ? 'main, article' : $content),
			$xpath($title),
			array_values(array_filter(array_map($xpath, $remove))),
			$descriptionSource === self::DESCRIPTION_CONTENT ? self::DESCRIPTION_CONTENT : self::DESCRIPTION_META,
			$xpath($date),
			$xpath($author),
			$xpath($category)
		);
	}
}
