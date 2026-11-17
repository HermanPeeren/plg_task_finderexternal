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
 * The Smart Search index, as far as the crawler uses it.
 */
interface SearchIndex
{
	/**
	 * Add or update a page; returns its link id.
	 */
	public function add(IndexEntry $entry): int;

	public function remove(int $linkId): void;

	/**
	 * Which of these link ids are no longer in the index, e.g. after "Clear Index".
	 *
	 * @param   list<int>  $linkIds
	 *
	 * @return  list<int>
	 */
	public function missing(array $linkIds): array;
}
