<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Crawl;

use Yepr\Plugin\Task\FinderExternal\Html\ExtractionRules;
use Yepr\Plugin\Task\FinderExternal\Url\UrlRules;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Everything one task knows about the site it indexes, read from its parameters by
 * RoutineFactory.
 */
final class SiteSettings
{
	/**
	 * @param   string        $label      Content type and Type filter in Smart Search, e.g. "Documentation".
	 * @param   list<string>  $hosts      The hosts of the sources; nothing else is ever visited.
	 * @param   list<string>  $sitemaps   Absolute URLs.
	 * @param   list<string>  $feeds      Absolute URLs.
	 * @param   list<string>  $startUrls  Absolute URLs, read every cycle for their links.
	 * @param   integer       $maxDepth   How many links deep to follow from a start page; 0: none.
	 * @param   integer       $delay      Milliseconds between two requests.
	 * @param   integer       $budget     Seconds of work per run.
	 */
	public function __construct(
		public readonly string $label,
		public readonly array $hosts,
		public readonly array $sitemaps,
		public readonly array $feeds,
		public readonly array $startUrls,
		public readonly UrlRules $rules,
		public readonly ExtractionRules $extraction,
		public readonly int $maxDepth,
		public readonly string $language,
		public readonly int $access,
		public readonly int $recheckDays,
		public readonly int $delay,
		public readonly bool $respectRobots,
		public readonly string $userAgent,
		public readonly int $budget
	) {
	}

	/**
	 * Seconds one request may take. A run stops starting requests this long before its
	 * budget is used up, so that a slow answer cannot push it past the budget.
	 */
	public function timeout(): int
	{
		return max(2, min(15, intdiv($this->budget, 3)));
	}
}
