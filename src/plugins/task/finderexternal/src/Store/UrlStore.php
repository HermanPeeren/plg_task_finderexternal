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
 * The URLs one task knows about.
 *
 * A URL is due in a cycle when it has not been checked in that cycle yet and either was
 * never checked, was last checked before the recheck date, or is a start page (depth 0),
 * which is read every cycle to find new links. Each URL is checked at most once per
 * cycle, so a cycle always ends.
 */
interface UrlStore
{
	/**
	 * Record URLs as seen in this cycle. Unknown ones are added at this depth; for known
	 * ones the depth is lowered when this path is shorter.
	 *
	 * @param   list<string>  $urls
	 *
	 * @return  integer  How many were new.
	 */
	public function add(int $taskId, array $urls, int $depth, bool $inSitemap, int $cycle): int;

	/**
	 * The next due URLs, start pages first, then by depth.
	 *
	 * @return  list<UrlRecord>
	 */
	public function due(int $taskId, int $cycle, \DateTimeImmutable $checkedBefore, int $limit): array;

	public function countDue(int $taskId, int $cycle, \DateTimeImmutable $checkedBefore): int;

	/**
	 * Store the outcome of a check.
	 */
	public function checked(UrlRecord $record, int $cycle, \DateTimeImmutable $now): void;

	/**
	 * URLs that came from a sitemap but were not in it this cycle.
	 *
	 * @return  list<UrlRecord>
	 */
	public function droppedFromSitemap(int $taskId, int $cycle, int $limit): array;

	public function delete(int $id): void;

	/**
	 * @return  list<int>  The link ids of this task's indexed URLs.
	 */
	public function linkIds(int $taskId): array;

	/**
	 * Forget these link ids, so the pages are fetched and indexed again.
	 *
	 * @param   list<int>  $linkIds
	 */
	public function forgetLinks(int $taskId, array $linkIds): void;
}
