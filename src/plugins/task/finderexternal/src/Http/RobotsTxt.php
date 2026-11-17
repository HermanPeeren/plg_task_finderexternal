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
 * The rules of one robots.txt that apply to this crawler (RFC 9309).
 *
 * The group for our own product token is used when there is one, otherwise the `*`
 * group. Of the rules that match a path the longest wins; on a tie Allow wins.
 * `*` matches any characters and a trailing `$` anchors the end.
 */
final class RobotsTxt
{
	/**
	 * @param   list<array{allow: bool, pattern: string}>  $rules
	 */
	private function __construct(private readonly array $rules)
	{
	}

	public static function allowAll(): self
	{
		return new self([]);
	}

	public static function parse(string $text, string $userAgent): self
	{
		$token = strtolower(trim(explode('/', trim($userAgent))[0]));

		// Per group: its user agents, and its rules.
		/** @var array<int, list<string>> $agents */
		$agents = [];
		/** @var array<int, list<array{allow: bool, pattern: string}>> $rules */
		$rules   = [];
		$current = -1;
		$inRules = false;

		foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
			$line = trim(explode('#', $line, 2)[0]);

			if ($line === '' || !str_contains($line, ':')) {
				continue;
			}

			[$field, $value] = array_map('trim', explode(':', $line, 2));
			$field           = strtolower($field);

			if ($field === 'user-agent') {
				// A user-agent line after rules starts a new group.
				if ($current === -1 || $inRules) {
					$current++;
					$agents[$current] = [];
					$rules[$current]  = [];
					$inRules          = false;
				}

				$agents[$current][] = strtolower($value);

				continue;
			}

			if (($field === 'allow' || $field === 'disallow') && $current !== -1) {
				$inRules = true;

				// "Disallow:" with nothing means: everything is allowed.
				if ($value !== '') {
					$rules[$current][] = ['allow' => $field === 'allow', 'pattern' => $value];
				}
			}
		}

		$own = [];
		$any = [];

		foreach ($agents as $group => $names) {
			foreach ($names as $agent) {
				if ($agent === '*') {
					array_push($any, ...$rules[$group]);
				} elseif ($token !== '' && str_starts_with($token, $agent)) {
					array_push($own, ...$rules[$group]);
				}
			}
		}

		return new self($own !== [] ? $own : $any);
	}

	public function allows(string $url): bool
	{
		$parts = parse_url($url);
		$path  = ($parts['path'] ?? '') === '' ? '/' : (string) $parts['path'];

		if (isset($parts['query'])) {
			$path .= '?' . $parts['query'];
		}

		$best   = null;
		$length = -1;

		foreach ($this->rules as $rule) {
			if (!self::matches($rule['pattern'], $path)) {
				continue;
			}

			$ruleLength = \strlen($rule['pattern']);

			if ($ruleLength > $length || ($ruleLength === $length && $rule['allow'])) {
				$best   = $rule['allow'];
				$length = $ruleLength;
			}
		}

		return $best ?? true;
	}

	private static function matches(string $pattern, string $path): bool
	{
		$anchored = str_ends_with($pattern, '$');

		if ($anchored) {
			$pattern = substr($pattern, 0, -1);
		}

		$regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . ($anchored ? '$' : '') . '#';

		return preg_match($regex, $path) === 1;
	}
}
