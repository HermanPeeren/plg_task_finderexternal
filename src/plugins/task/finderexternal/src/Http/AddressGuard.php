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
 * Only public internet addresses: no requests to the server itself or its network.
 *
 * The crawler fetches what a task names and what the pages of that site link to. Without
 * this check a task (or a DNS name of the external site that points inward) could make
 * the web server read pages on localhost, the local network or a cloud metadata address
 * and put their text in the public search index: server-side request forgery.
 *
 * The host is resolved here; the request is then pinned to the addresses that were
 * checked (curl's RESOLVE option), so a second, different DNS answer cannot slip
 * through between the check and the request.
 */
final class AddressGuard
{
	/** @var \Closure(string): list<string> */
	private readonly \Closure $resolve;

	/**
	 * @param   (callable(string): list<string>)|null  $resolve  Host name to IP addresses; DNS when null.
	 */
	public function __construct(?callable $resolve = null)
	{
		$this->resolve = $resolve !== null ? \Closure::fromCallable($resolve) : self::dns(...);
	}

	/**
	 * The public addresses of the host.
	 *
	 * @return  list<string>
	 *
	 * @throws  HttpFailure  When it does not resolve, or resolves to a private or reserved address.
	 */
	public function addresses(string $host): array
	{
		$host = trim($host, '[]');

		$addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);

		if ($addresses === []) {
			throw new HttpFailure(\sprintf('The host %s does not resolve.', $host));
		}

		foreach ($addresses as $address) {
			if (!self::isPublic($address)) {
				throw new HttpFailure(\sprintf('The host %s is a private or reserved address (%s); it is not fetched.', $host, $address));
			}
		}

		return $addresses;
	}

	public static function isPublic(string $address): bool
	{
		return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
	}

	/**
	 * @return  list<string>
	 */
	private static function dns(string $host): array
	{
		$addresses = [];
		$records   = @dns_get_record($host, DNS_A | DNS_AAAA);

		foreach (\is_array($records) ? $records : [] as $record) {
			$address = $record['ip'] ?? $record['ipv6'] ?? null;

			if (\is_string($address)) {
				$addresses[] = $address;
			}
		}

		if ($addresses === []) {
			$addresses = gethostbynamel($host) ?: [];
		}

		return array_values(array_unique($addresses));
	}
}
