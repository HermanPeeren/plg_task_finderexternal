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
 * One HTTP response: status, headers by lower-case name, body.
 */
final class HttpResponse
{
	/**
	 * @param   array<string, string>  $headers  Lower-case header names; repeated headers joined with ", ".
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body
	) {
	}

	public function header(string $name): string
	{
		return $this->headers[strtolower($name)] ?? '';
	}

	public function isHtml(): bool
	{
		$type = strtolower($this->header('content-type'));

		return $type === '' || str_contains($type, 'text/html') || str_contains($type, 'application/xhtml');
	}
}
