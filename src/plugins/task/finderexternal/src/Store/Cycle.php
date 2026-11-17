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
 * Where one task is in its current pass over the site, kept between runs.
 *
 * A cycle goes through three phases: discover (read robots.txt, sitemaps, feeds; queue
 * start pages), process (check every due URL), clean up (remove pages that left the
 * sitemap). Then it is done, and the next run starts a new cycle.
 */
final class Cycle
{
	public const DISCOVER = 'discover';
	public const PROCESS  = 'process';
	public const CLEANUP  = 'cleanup';
	public const DONE     = 'done';

	/** robots.txt, sitemap, feed or start page */
	public const SOURCE_ROBOTS  = 'robots';
	public const SOURCE_SITEMAP = 'sitemap';
	public const SOURCE_FEED    = 'feed';
	public const SOURCE_START   = 'start';

	/**
	 * @param   list<array{type: string, url: string}>  $sources  Sources still to read in the discover phase.
	 * @param   array<string, string>                   $robots   robots.txt text per host.
	 * @param   array<string, int>                      $counts   Totals for this cycle.
	 */
	public function __construct(
		public readonly int $taskId,
		public readonly int $number,
		public string $phase,
		public array $sources,
		public array $robots,
		public array $counts,
		public readonly string $started,
		public string $finished = ''
	) {
	}

	/**
	 * @param   list<array{type: string, url: string}>  $sources
	 */
	public static function start(int $taskId, int $number, array $sources, \DateTimeImmutable $now): self
	{
		return new self($taskId, $number, self::DISCOVER, $sources, [], [], $now->format('Y-m-d H:i:s'));
	}

	public function add(string $counter, int $by = 1): void
	{
		$this->counts[$counter] = ($this->counts[$counter] ?? 0) + $by;
	}

	public function count(string $counter): int
	{
		return $this->counts[$counter] ?? 0;
	}
}
