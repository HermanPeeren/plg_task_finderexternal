<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Html;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The CSS selectors a site administrator needs to point at parts of a page, as XPath.
 *
 * Supported: `tag`, `*`, `#id`, `.class`, `[attr]`, `[attr=value]`, `[attr^=value]`,
 * `[attr*=value]`, `[attr~=value]`, the descendant (space) and child (`>`) combinators,
 * and groups separated by commas. Anything else (pseudo-classes, `+`, `~`) is refused
 * with a message, rather than silently matching something else.
 */
final class Selector
{
	/**
	 * @param   boolean  $relative  Relative to a context node (".//") instead of the document ("//").
	 *
	 * @throws  \InvalidArgumentException  When the selector uses something not supported.
	 */
	public static function toXPath(string $css, bool $relative = false): string
	{
		$groups = [];

		foreach (explode(',', $css) as $group) {
			$group = trim($group);

			if ($group === '') {
				throw new \InvalidArgumentException(\sprintf('The selector "%s" has an empty part.', $css));
			}

			$groups[] = ($relative ? '.' : '') . self::group($group, $css);
		}

		return implode(' | ', $groups);
	}

	private static function group(string $group, string $css): string
	{
		$xpath    = '';
		$position = 0;
		$length   = \strlen($group);
		$axis     = '//';

		while ($position < $length) {
			$compound = '/\G(?<tag>[a-zA-Z][a-zA-Z0-9-]*|\*)?(?<rest>(?:#[\w-]+|\.[\w-]+|\[[^\]]+\])*)/';

			if (preg_match($compound, $group, $match, 0, $position) !== 1 || $match[0] === '') {
				throw self::unsupported($css, substr($group, $position));
			}

			$position += \strlen($match[0]);
			$xpath    .= $axis . ($match['tag'] === '' ? '*' : strtolower($match['tag'])) . self::predicates($match['rest'], $css);

			if ($position >= $length) {
				break;
			}

			if (preg_match('/\G\s*(>)?\s*/', $group, $combinator, 0, $position) !== 1 || $combinator[0] === '') {
				throw self::unsupported($css, substr($group, $position));
			}

			$position += \strlen($combinator[0]);
			$axis      = ($combinator[1] ?? '') === '>' ? '/' : '//';
		}

		return $xpath;
	}

	private static function unsupported(string $css, string $near): \InvalidArgumentException
	{
		return new \InvalidArgumentException(\sprintf('The selector "%s" uses something that is not supported near "%s".', $css, $near));
	}

	private static function predicates(string $rest, string $css): string
	{
		$predicates = '';

		preg_match_all('/#([\w-]+)|\.([\w-]+)|\[([^\]]+)\]/', $rest, $parts, PREG_SET_ORDER);

		foreach ($parts as $part) {
			if (($part[1] ?? '') !== '') {
				$predicates .= '[@id=' . self::literal($part[1], $css) . ']';
			} elseif (($part[2] ?? '') !== '') {
				$predicates .= '[contains(concat(" ", normalize-space(@class), " "), ' . self::literal(' ' . $part[2] . ' ', $css) . ')]';
			} else {
				$predicates .= self::attribute($part[3] ?? '', $css);
			}
		}

		return $predicates;
	}

	private static function attribute(string $expression, string $css): string
	{
		if (preg_match('/^\s*([\w:-]+)\s*(?:([\^*~]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\']+))\s*)?$/', $expression, $match) !== 1) {
			throw new \InvalidArgumentException(\sprintf('The selector "%s" has an attribute test that is not supported: [%s].', $css, $expression));
		}

		$name     = '@' . $match[1];
		$operator = $match[2] ?? '';

		if ($operator === '') {
			return '[' . $name . ']';
		}

		$value = self::literal(($match[3] ?? '') . ($match[4] ?? '') . ($match[5] ?? ''), $css);

		return match ($operator) {
			'='     => '[' . $name . '=' . $value . ']',
			'^='    => '[starts-with(' . $name . ', ' . $value . ')]',
			'*='    => '[contains(' . $name . ', ' . $value . ')]',
			default => '[contains(concat(" ", normalize-space(' . $name . '), " "), concat(" ", ' . $value . ', " "))]',
		};
	}

	private static function literal(string $value, string $css): string
	{
		if (!str_contains($value, '"')) {
			return '"' . $value . '"';
		}

		if (!str_contains($value, "'")) {
			return "'" . $value . "'";
		}

		throw new \InvalidArgumentException(\sprintf('The selector "%s" has a value with both kinds of quotes.', $css));
	}
}
