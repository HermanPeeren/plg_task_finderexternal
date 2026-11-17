<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Index;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One external page as it goes into Smart Search.
 */
final class IndexEntry
{
	/**
	 * @param   string  $url       The page's own absolute URL: Smart Search's key and the result link.
	 * @param   string  $type      The content type and Type filter, e.g. "Documentation".
	 * @param   string  $date      'Y-m-d H:i:s' UTC, or ''.
	 */
	public function __construct(
		public readonly string $url,
		public readonly string $title,
		public readonly string $description,
		public readonly string $body,
		public readonly string $type,
		public readonly string $language,
		public readonly int $access,
		public readonly string $date = '',
		public readonly string $author = '',
		public readonly string $category = '',
		public readonly string $image = ''
	) {
	}

	/**
	 * A hash of everything that is indexed: equal hashes, nothing to update.
	 */
	public function hash(): string
	{
		return sha1(serialize(get_object_vars($this)));
	}
}
