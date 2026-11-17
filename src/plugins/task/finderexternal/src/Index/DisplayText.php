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
 * Text from an external page, made safe for the places Smart Search shows it.
 *
 * Joomla's search result layout prints the title, the description and the taxonomy
 * titles (author, category) as they are, because local content was filtered when it
 * was saved. Text read from another site was not: a title such as
 * `Using &lt;script&gt;` is `Using <script>` after reading, and would be markup on the
 * results page. So `<` and `>` become entities, which the browser shows as the
 * characters again. Other characters stay, so the admin views, which escape titles
 * themselves, show them as they are. Control characters are dropped.
 */
final class DisplayText
{
	public static function safe(string $text): string
	{
		$text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

		return str_replace(['<', '>'], ['&lt;', '&gt;'], $text);
	}
}
