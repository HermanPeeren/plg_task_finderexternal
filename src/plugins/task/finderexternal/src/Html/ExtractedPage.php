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
 * What was read from one HTML page: the text for the index and the links to follow.
 */
final class ExtractedPage
{
	/**
	 * @param   boolean       $contentFound  The content selector matched something with text.
	 * @param   string        $date          Publication date as 'Y-m-d H:i:s' UTC, or ''.
	 * @param   list<string>  $links         Absolute URLs of the page's links, in order, without duplicates.
	 * @param   string        $canonical     The absolute canonical URL, or '' when the page names none.
	 */
	public function __construct(
		public readonly bool $contentFound,
		public readonly string $title,
		public readonly string $description,
		public readonly string $body,
		public readonly string $date,
		public readonly string $author,
		public readonly string $category,
		public readonly string $image,
		public readonly array $links,
		public readonly string $canonical,
		public readonly bool $noindex,
		public readonly bool $nofollow
	) {
	}
}
