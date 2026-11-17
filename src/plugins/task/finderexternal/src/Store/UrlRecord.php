<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Store;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One known URL of an external site: bookkeeping only, never the page's content.
 */
final class UrlRecord
{
	/** Found, not visited yet. */
	public const NEW = 'new';

	/** In the index. */
	public const INDEXED = 'indexed';

	/** Visited for its links only: no content, not to be indexed, or noindex. */
	public const FOLLOWED = 'followed';

	/** Redirects elsewhere, or names another URL as canonical; that one is queued instead. */
	public const MOVED = 'moved';

	/** 404 or 410. */
	public const GONE = 'gone';

	/** Out of scope, or disallowed by robots.txt. */
	public const EXCLUDED = 'excluded';

	/** No usable answer; tried again next cycle. */
	public const ERROR = 'error';

	public function __construct(
		public readonly int $id,
		public readonly string $url,
		public readonly int $depth,
		public string $status = self::NEW,
		public int $linkId = 0,
		public string $contentHash = '',
		public string $etag = '',
		public string $lastModified = '',
		public string $message = ''
	) {
	}

	/**
	 * Visited before with an answer worth revalidating with If-None-Match / If-Modified-Since.
	 */
	public function canRevalidate(): bool
	{
		return ($this->status === self::INDEXED || $this->status === self::FOLLOWED)
			&& ($this->etag !== '' || $this->lastModified !== '');
	}
}
