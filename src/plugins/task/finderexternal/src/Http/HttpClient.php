<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Http;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * GET one URL. Redirects are not followed: the crawler decides what a redirect means.
 */
interface HttpClient
{
	/**
	 * @param   array<string, string>  $headers  Request headers, e.g. User-Agent, If-None-Match.
	 *
	 * @throws  HttpFailure  When there is no response at all (DNS, timeout, refused connection).
	 */
	public function get(string $url, array $headers, int $timeout): HttpResponse;
}
