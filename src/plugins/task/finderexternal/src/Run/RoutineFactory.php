<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Run;

use Yepr\Plugin\Task\FinderExternal\Crawl\Crawler;
use Yepr\Plugin\Task\FinderExternal\Crawl\SiteSettings;
use Yepr\Plugin\Task\FinderExternal\Html\ExtractionRules;
use Yepr\Plugin\Task\FinderExternal\Html\PageExtractor;
use Yepr\Plugin\Task\FinderExternal\Http\HttpClient;
use Yepr\Plugin\Task\FinderExternal\Index\SearchIndex;
use Yepr\Plugin\Task\FinderExternal\Source\FeedReader;
use Yepr\Plugin\Task\FinderExternal\Source\SitemapReader;
use Yepr\Plugin\Task\FinderExternal\Store\CycleStore;
use Yepr\Plugin\Task\FinderExternal\Store\UrlStore;
use Yepr\Plugin\Task\FinderExternal\Url\UrlRules;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns one task's parameters into SiteSettings and a Crawler.
 *
 * Every parameter is checked here, once per run, so a typo in a pattern or selector is
 * one clear error in the task log instead of a crawl that silently finds nothing.
 */
final class RoutineFactory
{
	public const DEFAULT_BUDGET = 20;
	public const MIN_BUDGET     = 5;
	public const MAX_BUDGET     = 300;

	public function __construct(
		private readonly UrlStore $urls,
		private readonly CycleStore $cycles,
		private readonly SearchIndex $index,
		private readonly HttpClient $http,
		private readonly PageExtractor $extractor,
		private readonly SitemapReader $sitemaps,
		private readonly FeedReader $feeds,
		private readonly Clock $clock,
		private readonly string $defaultUserAgent
	) {
	}

	/**
	 * @param   callable(string, string): void  $log
	 *
	 * @throws  MissingConfiguration
	 */
	public function crawler(object $params, callable $log): Crawler
	{
		return new Crawler(
			$this->settings($params),
			$this->urls,
			$this->cycles,
			$this->index,
			$this->http,
			$this->extractor,
			$this->sitemaps,
			$this->feeds,
			$this->clock,
			$log
		);
	}

	/**
	 * @throws  MissingConfiguration
	 */
	public function settings(object $params): SiteSettings
	{
		$label = $this->text($params, 'site_label');

		if ($label === '') {
			throw new MissingConfiguration('The site needs a label, e.g. "Documentation".');
		}

		$sitemaps  = $this->urlList($params, 'sitemaps');
		$feeds     = $this->urlList($params, 'feeds');
		$startUrls = $this->urlList($params, 'start_urls');

		if ($sitemaps === [] && $feeds === [] && $startUrls === []) {
			throw new MissingConfiguration('Give at least one sitemap, feed or start page.');
		}

		$hosts = array_values(array_unique(array_map(UrlRules::host(...), array_merge($sitemaps, $feeds, $startUrls))));

		try {
			$rules = new UrlRules(
				$hosts,
				$this->patterns($params, 'scope'),
				$this->patterns($params, 'exclude'),
				$this->patterns($params, 'index_only')
			);

			$extraction = ExtractionRules::fromCss(
				$this->text($params, 'content_selector'),
				$this->text($params, 'title_selector', 'h1'),
				$this->lines($params, 'remove_selectors'),
				$this->text($params, 'description_source', ExtractionRules::DESCRIPTION_META),
				$this->text($params, 'date_selector'),
				$this->text($params, 'author_selector'),
				$this->text($params, 'category_selector')
			);
		} catch (\InvalidArgumentException $e) {
			throw new MissingConfiguration($e->getMessage(), 0, $e);
		}

		$language  = $this->text($params, 'language', '*');
		// One header line: control characters (CR, LF) would let it add headers of its own.
		$userAgent = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $this->text($params, 'user_agent')));

		if ($language !== '*' && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
			throw new MissingConfiguration(\sprintf('"%s" is not a language tag.', $language));
		}

		return new SiteSettings(
			$label,
			$hosts,
			$sitemaps,
			$feeds,
			$startUrls,
			$rules,
			$extraction,
			$this->number($params, 'max_depth', 0, 0, 50),
			$language,
			$this->number($params, 'access', 1, 1, PHP_INT_MAX),
			$this->number($params, 'recheck_days', 7, 0, 365),
			$this->number($params, 'request_delay', 500, 0, 10000),
			(bool) ($params->respect_robots ?? true),
			$userAgent === '' ? $this->defaultUserAgent : $userAgent,
			$this->number($params, 'time_budget', self::DEFAULT_BUDGET, self::MIN_BUDGET, self::MAX_BUDGET)
		);
	}

	/**
	 * @return  list<string>
	 *
	 * @throws  MissingConfiguration
	 */
	private function urlList(object $params, string $name): array
	{
		$urls = [];

		foreach ($this->lines($params, $name) as $line) {
			$url = UrlRules::normalize($line, $line);

			if ($url === null || !preg_match('#^https?://#i', $line)) {
				throw new MissingConfiguration(\sprintf('"%s" is not an absolute http(s) URL.', $line));
			}

			$urls[] = $url;
		}

		return array_values(array_unique($urls));
	}

	/**
	 * @return  list<string>
	 */
	private function patterns(object $params, string $name): array
	{
		return array_map(UrlRules::compile(...), $this->lines($params, $name));
	}

	/**
	 * Non-empty lines; lines starting with "//" are comments.
	 *
	 * @return  list<string>
	 */
	private function lines(object $params, string $name): array
	{
		$lines = [];

		foreach (preg_split('/\R/', $this->text($params, $name)) ?: [] as $line) {
			$line = trim($line);

			if ($line !== '' && !str_starts_with($line, '//')) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	private function number(object $params, string $name, int $default, int $min, int $max): int
	{
		$value = $params->{$name} ?? '';
		$value = is_numeric($value) ? (int) $value : $default;

		return min($max, max($min, $value));
	}

	private function text(object $params, string $name, string $default = ''): string
	{
		$value = $params->{$name} ?? '';
		$value = \is_scalar($value) ? trim((string) $value) : '';

		return $value === '' ? $default : $value;
	}
}
