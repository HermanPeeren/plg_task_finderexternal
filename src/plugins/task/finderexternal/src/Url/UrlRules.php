<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Url;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Which URLs the crawler may visit and which of those go into the index.
 *
 * - A URL is only ever visited on one of the hosts of the task's sources: the crawler
 *   never wanders off to other sites, whatever the patterns say.
 * - Scope: when there are scope patterns, a URL must match one of them to be visited.
 * - Exclude: a URL matching one of these is never visited.
 * - Index: when there are index patterns, only visited URLs matching one of them are
 *   indexed; the rest are only read for their links.
 *
 * Patterns are regular expressions without delimiters, matched against the whole URL
 * (`^` and `$` anchor it), case-insensitively.
 */
final class UrlRules
{
	/**
	 * @param   list<string>  $hosts    Lower-case host names.
	 * @param   list<string>  $scope    Compiled patterns, see compile().
	 * @param   list<string>  $exclude  Compiled patterns.
	 * @param   list<string>  $index    Compiled patterns.
	 */
	public function __construct(
		private readonly array $hosts,
		private readonly array $scope = [],
		private readonly array $exclude = [],
		private readonly array $index = []
	) {
	}

	/**
	 * Turn a pattern as typed in the task form into a PCRE pattern.
	 *
	 * @throws  \InvalidArgumentException  When it is not a valid regular expression.
	 */
	public static function compile(string $pattern): string
	{
		// Delimited by a control character, as Joomla's FormRule does: no pattern can close the
		// delimiter and add modifiers of its own.
		$compiled = "\x01" . $pattern . "\x01iu";

		if (str_contains($pattern, "\x01") || @preg_match($compiled, '') === false) {
			throw new \InvalidArgumentException(\sprintf('"%s" is not a valid regular expression.', $pattern));
		}

		return $compiled;
	}

	/**
	 * The host of a URL, lower case, or '' when it has none.
	 */
	public static function host(string $url): string
	{
		return strtolower((string) parse_url($url, PHP_URL_HOST));
	}

	/**
	 * On one of the task's hosts. Start pages only need this: they are read for their
	 * links even when the scope patterns leave them out.
	 */
	public function onHost(string $url): bool
	{
		return \in_array(self::host($url), $this->hosts, true);
	}

	public function inScope(string $url): bool
	{
		if (!$this->onHost($url)) {
			return false;
		}

		if (self::matchesAny($this->exclude, $url)) {
			return false;
		}

		return $this->scope === [] || self::matchesAny($this->scope, $url);
	}

	public function shouldIndex(string $url): bool
	{
		return $this->inScope($url) && ($this->index === [] || self::matchesAny($this->index, $url));
	}

	/**
	 * Make an absolute, comparable URL out of a link.
	 *
	 * Resolves it against the page's (or `<base>`'s) URL, drops the fragment and the
	 * default port, lower-cases scheme and host, removes `.` and `..` segments. Returns
	 * null for what is not a web page address: mailto:, javascript:, tel:, data:, a bare
	 * fragment, another scheme.
	 */
	public static function normalize(string $href, string $base): ?string
	{
		$href = str_replace(' ', '%20', trim($href));

		if ($href === '' || $href[0] === '#') {
			return null;
		}

		if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $href, $match) === 1) {
			$scheme = strtolower($match[1]);

			if ($scheme !== 'http' && $scheme !== 'https') {
				return null;
			}

			$absolute = $href;
		} else {
			$baseParts = parse_url($base);

			if (!\is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
				return null;
			}

			$origin = $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
			$path   = $baseParts['path'] ?? '/';

			if (str_starts_with($href, '//')) {
				$absolute = $baseParts['scheme'] . ':' . $href;
			} elseif ($href[0] === '/') {
				$absolute = $origin . $href;
			} elseif ($href[0] === '?') {
				$absolute = $origin . $path . $href;
			} else {
				$directory = substr($path, 0, (int) strrpos($path, '/') + 1);
				$absolute  = $origin . ($directory === '' ? '/' : $directory) . $href;
			}
		}

		$parts = parse_url($absolute);

		if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
			return null;
		}

		$scheme = strtolower($parts['scheme']);

		if ($scheme !== 'http' && $scheme !== 'https') {
			return null;
		}

		$port = $parts['port'] ?? null;

		if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
			$port = null;
		}

		$host = strtolower($parts['host']);

		// A host name or an IPv6 literal; anything else is not an address to fetch.
		if (preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?\.?)+$|^\[[0-9a-f:.]+\]$/', $host) !== 1) {
			return null;
		}

		$url = $scheme . '://' . $host . ($port !== null ? ':' . $port : '')
			. self::encode(self::removeDotSegments($parts['path'] ?? '/'));

		if (isset($parts['query']) && $parts['query'] !== '') {
			$url .= '?' . self::encode($parts['query']);
		}

		return $url;
	}

	/**
	 * Percent-encode what may not stand unencoded in a URL.
	 *
	 * The URL ends up in href attributes of the search results, and core layouts print it
	 * as it is: a quote, `<`, `>` or a space taken from an external page could otherwise
	 * end the attribute. A `%` stays, so an encoded URL is not encoded twice.
	 */
	private static function encode(string $part): string
	{
		return (string) preg_replace_callback(
			"/[^A-Za-z0-9\\-._~!$&()*+,;=:@\\/?%]/",
			static fn (array $match): string => rawurlencode($match[0]),
			$part
		);
	}

	/**
	 * @param   list<string>  $patterns
	 */
	private static function matchesAny(array $patterns, string $url): bool
	{
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $url) === 1) {
				return true;
			}
		}

		return false;
	}

	private static function removeDotSegments(string $path): string
	{
		if ($path === '') {
			return '/';
		}

		$output = [];

		foreach (explode('/', $path) as $segment) {
			if ($segment === '.') {
				continue;
			}

			if ($segment === '..') {
				if (\count($output) > 1) {
					array_pop($output);
				}

				continue;
			}

			$output[] = $segment;
		}

		// A path ending in /. or /.. is a directory.
		if (preg_match('#/\.\.?$#', $path) === 1) {
			$output[] = '';
		}

		$result = implode('/', $output);

		return $result === '' || $result[0] !== '/' ? '/' . $result : $result;
	}
}
