<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Run;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Time, for the time budget and the pause between requests.
 *
 * Behind an interface so that a test can run a whole crawl in no time.
 */
interface Clock
{
	/**
	 * Seconds, with fractions, from an arbitrary starting point.
	 */
	public function seconds(): float;

	/**
	 * The current date and time in UTC.
	 */
	public function now(): \DateTimeImmutable;

	public function sleep(int $milliseconds): void;
}
