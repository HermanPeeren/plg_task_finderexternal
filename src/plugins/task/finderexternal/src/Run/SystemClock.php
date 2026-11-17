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
 * The real clock.
 */
final class SystemClock implements Clock
{
	public function seconds(): float
	{
		return microtime(true);
	}

	public function now(): \DateTimeImmutable
	{
		return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
	}

	public function sleep(int $milliseconds): void
	{
		if ($milliseconds > 0) {
			usleep($milliseconds * 1000);
		}
	}
}
