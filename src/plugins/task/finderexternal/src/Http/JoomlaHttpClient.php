<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Http;

use Joomla\Http\Http;
use Joomla\Http\Transport\Curl;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * HttpClient on Joomla's own HTTP package, over curl.
 *
 * Per request:
 * - redirects are not followed (the crawler checks each target against the task's scope);
 * - only http and https;
 * - the host must resolve to public addresses (AddressGuard), and the connection is
 *   pinned to exactly those addresses;
 * - the response may be at most MAX_BYTES; a larger one is cut off while it downloads,
 *   so it never fills the memory.
 */
final class JoomlaHttpClient implements HttpClient
{
	/**
	 * The largest response read: well above any web page, and room for a big sitemap.
	 */
	public const MAX_BYTES = 20 * 1024 * 1024;

	public function __construct(private readonly AddressGuard $guard = new AddressGuard())
	{
	}

	public function get(string $url, array $headers, int $timeout): HttpResponse
	{
		$parts  = parse_url($url);
		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		$host   = (string) ($parts['host'] ?? '');

		if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
			throw new HttpFailure(\sprintf('%s is not an http(s) address.', $url));
		}

		$port      = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
		$addresses = $this->guard->addresses($host);

		if (!Curl::isSupported()) {
			throw new HttpFailure('The PHP curl extension is needed to read external sites.');
		}

		$pinned    = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $addresses);
		$tooLarge  = false;

		$options = [
			'follow_location' => false,
			'transport.curl'  => [
				CURLOPT_PROTOCOLS_STR      => 'http,https',
				CURLOPT_RESOLVE            => [trim($host, '[]') . ':' . $port . ':' . implode(',', $pinned)],
				CURLOPT_NOPROGRESS         => false,
				CURLOPT_XFERINFOFUNCTION   => static function ($handle, int $total, int $received) use (&$tooLarge): int {
					$tooLarge = $total > self::MAX_BYTES || $received > self::MAX_BYTES;

					// Non-zero aborts the transfer.
					return $tooLarge ? 1 : 0;
				},
			],
		];

		try {
			$response = (new Http($options, new Curl($options)))->get($url, $headers, $timeout);
		} catch (\Throwable $e) {
			$message = $tooLarge ? \sprintf('The response is larger than %d MB.', self::MAX_BYTES / 1048576) : $e->getMessage();

			throw new HttpFailure($message, 0, $e);
		}

		$names = [];

		foreach ($response->getHeaders() as $name => $values) {
			$names[strtolower((string) $name)] = implode(', ', $values);
		}

		return new HttpResponse($response->getStatusCode(), $names, (string) $response->getBody());
	}
}
