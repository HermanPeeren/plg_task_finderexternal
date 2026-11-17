<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Crawl;

use Yepr\Plugin\Task\FinderExternal\Html\PageExtractor;
use Yepr\Plugin\Task\FinderExternal\Http\HttpClient;
use Yepr\Plugin\Task\FinderExternal\Http\HttpFailure;
use Yepr\Plugin\Task\FinderExternal\Http\HttpResponse;
use Yepr\Plugin\Task\FinderExternal\Http\RobotsTxt;
use Yepr\Plugin\Task\FinderExternal\Index\IndexEntry;
use Yepr\Plugin\Task\FinderExternal\Index\SearchIndex;
use Yepr\Plugin\Task\FinderExternal\Run\Clock;
use Yepr\Plugin\Task\FinderExternal\Run\RunOutcome;
use Yepr\Plugin\Task\FinderExternal\Source\FeedReader;
use Yepr\Plugin\Task\FinderExternal\Source\SitemapReader;
use Yepr\Plugin\Task\FinderExternal\Store\Cycle;
use Yepr\Plugin\Task\FinderExternal\Store\CycleStore;
use Yepr\Plugin\Task\FinderExternal\Store\UrlRecord;
use Yepr\Plugin\Task\FinderExternal\Store\UrlStore;
use Yepr\Plugin\Task\FinderExternal\Url\UrlRules;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * One run of the task: as much of the current cycle as fits in the time budget.
 *
 * The cycle's state is in the CycleStore and the URLs in the UrlStore, so the next run
 * goes on where this one stopped. A run never starts a request that could end after
 * the budget, sleeps the configured delay between requests, and logs one line with
 * what it did.
 */
final class Crawler
{
	/**
	 * URLs fetched from the store at a time.
	 */
	private const BATCH = 10;

	/**
	 * Most bytes of robots.txt kept (Google reads 500 KiB).
	 */
	private const MAX_ROBOTS = 512000;

	/**
	 * Largest HTML page read. Its DOM tree takes several times its size in memory, and no
	 * real article comes near this.
	 */
	public const MAX_HTML = 5 * 1024 * 1024;

	private float $deadline = 0.0;

	private bool $requested = false;

	/**
	 * @var array<string, int>
	 */
	private array $run = [];

	/**
	 * @var array<string, RobotsTxt>
	 */
	private array $robots = [];

	/**
	 * @var callable(string, string): void
	 */
	private $log;

	/**
	 * @param   callable(string, string): void  $log  Message and priority ("info", "warning", "error").
	 */
	public function __construct(
		private readonly SiteSettings $site,
		private readonly UrlStore $urls,
		private readonly CycleStore $cycles,
		private readonly SearchIndex $index,
		private readonly HttpClient $http,
		private readonly PageExtractor $extractor,
		private readonly SitemapReader $sitemapReader,
		private readonly FeedReader $feedReader,
		private readonly Clock $clock,
		callable $log
	) {
		$this->log = $log;
	}

	public function run(int $taskId): RunOutcome
	{
		$this->deadline  = $this->clock->seconds() + $this->site->budget - $this->site->timeout();
		$this->requested = false;
		$this->run       = [];
		$this->robots    = [];

		$cycle = $this->cycles->load($taskId);

		if ($cycle === null || $cycle->phase === Cycle::DONE) {
			$cycle = $this->startCycle($taskId, $cycle === null ? 1 : $cycle->number + 1);
		}

		try {
			$this->work($cycle);
		} finally {
			$this->cycles->save($cycle);
		}

		$this->logRun($cycle);

		if ($cycle->phase !== Cycle::DONE) {
			return new RunOutcome(false);
		}

		($this->log)(
			\sprintf(
				'Cycle %d all done: %d pages of %s in the index; this cycle %d checked, %d indexed, %d unchanged, %d removed, %d errors.',
				$cycle->number,
				\count($this->urls->linkIds($taskId)),
				$this->site->label,
				$cycle->count('checked'),
				$cycle->count('indexed'),
				$cycle->count('unchanged'),
				$cycle->count('removed'),
				$cycle->count('errors')
			),
			'info'
		);

		return new RunOutcome(true);
	}

	private function startCycle(int $taskId, int $number): Cycle
	{
		$sources = [];

		if ($this->site->respectRobots) {
			foreach ($this->site->hosts as $host) {
				$scheme    = 'https';
				$candidate = array_merge($this->site->sitemaps, $this->site->feeds, $this->site->startUrls);

				// The scheme of the first source on that host.
				foreach ($candidate as $url) {
					if (UrlRules::host($url) === $host) {
						$scheme = (string) parse_url($url, PHP_URL_SCHEME);
						break;
					}
				}

				$sources[] = ['type' => Cycle::SOURCE_ROBOTS, 'url' => $scheme . '://' . $host . '/robots.txt'];
			}
		}

		foreach ($this->site->sitemaps as $url) {
			$sources[] = ['type' => Cycle::SOURCE_SITEMAP, 'url' => $url];
		}

		foreach ($this->site->feeds as $url) {
			$sources[] = ['type' => Cycle::SOURCE_FEED, 'url' => $url];
		}

		foreach ($this->site->startUrls as $url) {
			$sources[] = ['type' => Cycle::SOURCE_START, 'url' => $url];
		}

		// After "Clear Index" (or another plugin's purge) the store still thinks pages
		// are indexed. Those are fetched and indexed again in this cycle.
		$missing = $this->index->missing($this->urls->linkIds($taskId));

		if ($missing !== []) {
			$this->urls->forgetLinks($taskId, $missing);
			($this->log)(\sprintf('%d pages were no longer in the Smart Search index; they are indexed again.', \count($missing)), 'warning');
		}

		($this->log)(\sprintf('Cycle %d of %s started.', $number, $this->site->label), 'info');

		return Cycle::start($taskId, $number, $sources, $this->clock->now());
	}

	private function work(Cycle $cycle): void
	{
		while (!$this->timeUp()) {
			switch ($cycle->phase) {
				case Cycle::DISCOVER:
					$source = array_shift($cycle->sources);

					if ($source === null) {
						$cycle->phase = Cycle::PROCESS;
					} else {
						$this->discover($cycle, $source);
					}

					break;

				case Cycle::PROCESS:
					$batch = $this->urls->due($cycle->taskId, $cycle->number, $this->recheckBefore(), self::BATCH);

					if ($batch === []) {
						$cycle->phase = Cycle::CLEANUP;
					}

					foreach ($batch as $record) {
						if ($this->timeUp()) {
							break;
						}

						$this->process($cycle, $record);
					}

					break;

				case Cycle::CLEANUP:
					if (!$this->cleanup($cycle)) {
						$cycle->phase    = Cycle::DONE;
						$cycle->finished = $this->clock->now()->format('Y-m-d H:i:s');
					}

					break;

				default:
					return;
			}
		}
	}

	/**
	 * @param   array{type: string, url: string}  $source
	 */
	private function discover(Cycle $cycle, array $source): void
	{
		$url  = $source['url'];
		$type = $source['type'];

		if ($type === Cycle::SOURCE_START) {
			$this->found($cycle, $this->urls->add($cycle->taskId, [$url], 0, false, $cycle->number));

			return;
		}

		$response = $this->fetchSource($url, $type === Cycle::SOURCE_ROBOTS ? 'info' : 'warning');

		if ($type === Cycle::SOURCE_ROBOTS) {
			$cycle->robots[UrlRules::host($url)] = $response !== null && $response->status === 200
				? substr($response->body, 0, self::MAX_ROBOTS)
				: '';

			return;
		}

		if ($response === null || $response->status !== 200) {
			if ($type === Cycle::SOURCE_SITEMAP) {
				// Without the sitemap, "not in the sitemap" means nothing: no cleanup this cycle.
				$cycle->add('sitemap_failed');
			}

			return;
		}

		try {
			if ($type === Cycle::SOURCE_SITEMAP) {
				$read = $this->sitemapReader->read($response->body);

				foreach (array_reverse($read['sitemaps']) as $child) {
					$child = UrlRules::normalize($child, $url);

					if ($child !== null && $this->site->rules->onHost($child)) {
						array_unshift($cycle->sources, ['type' => Cycle::SOURCE_SITEMAP, 'url' => $child]);
					}
				}

				$links = $read['urls'];
			} else {
				$links = $this->feedReader->links($response->body);
			}
		} catch (\UnexpectedValueException $e) {
			($this->log)(\sprintf('%s: %s', $url, $e->getMessage()), 'warning');

			if ($type === Cycle::SOURCE_SITEMAP) {
				$cycle->add('sitemap_failed');
			}

			return;
		}

		$accepted = $this->acceptable($cycle, $links, $url);

		$this->found($cycle, $this->urls->add($cycle->taskId, $accepted, 1, $type === Cycle::SOURCE_SITEMAP, $cycle->number));
	}

	private function process(Cycle $cycle, UrlRecord $record): void
	{
		$url = $record->url;
		$this->count($cycle, 'checked');

		$allowed = $record->depth === 0 ? $this->site->rules->onHost($url) : $this->site->rules->inScope($url);

		if (!$allowed || !$this->robotsAllow($cycle, $url)) {
			$this->unindex($cycle, $record);
			$this->finish($cycle, $record, UrlRecord::EXCLUDED, $allowed ? 'disallowed by robots.txt' : 'out of scope');

			return;
		}

		$headers = ['User-Agent' => $this->site->userAgent];

		if ($record->canRevalidate()) {
			if ($record->etag !== '') {
				$headers['If-None-Match'] = $record->etag;
			}

			if ($record->lastModified !== '') {
				$headers['If-Modified-Since'] = $record->lastModified;
			}
		}

		try {
			$response = $this->request($url, $headers);
		} catch (HttpFailure $e) {
			$this->count($cycle, 'errors');
			$this->finish($cycle, $record, UrlRecord::ERROR, $e->getMessage());

			return;
		}

		if ($response->status === 304) {
			$this->count($cycle, 'unchanged');
			$this->finish($cycle, $record, $record->status, '');

			return;
		}

		if (\in_array($response->status, [301, 302, 303, 307, 308], true)) {
			$target = UrlRules::normalize($response->header('location'), $url);
			$this->unindex($cycle, $record);

			if ($target !== null && $target !== $url && $this->site->rules->inScope($target)) {
				$this->found($cycle, $this->urls->add($cycle->taskId, [$target], $record->depth, false, $cycle->number));
			}

			$this->finish($cycle, $record, UrlRecord::MOVED, 'redirects to ' . ($target ?? '?'));

			return;
		}

		if ($response->status === 404 || $response->status === 410) {
			$this->unindex($cycle, $record);
			$this->finish($cycle, $record, UrlRecord::GONE, 'HTTP ' . $response->status);

			return;
		}

		if ($response->status !== 200) {
			$this->count($cycle, 'errors');
			$this->finish($cycle, $record, UrlRecord::ERROR, 'HTTP ' . $response->status);

			return;
		}

		if (!$response->isHtml()) {
			$this->unindex($cycle, $record);
			$this->finish($cycle, $record, UrlRecord::EXCLUDED, 'not HTML: ' . $response->header('content-type'));

			return;
		}

		if (\strlen($response->body) > self::MAX_HTML) {
			$this->unindex($cycle, $record);
			$this->finish($cycle, $record, UrlRecord::EXCLUDED, 'larger than ' . (self::MAX_HTML / 1048576) . ' MB');

			return;
		}

		$record->etag         = $response->header('etag');
		$record->lastModified = $response->header('last-modified');

		$page = $this->extractor->extract($response->body, $url, $this->site->extraction);

		if (!$page->nofollow && $record->depth < $this->site->maxDepth) {
			$links = $this->acceptable($cycle, $page->links, $url);
			$this->found($cycle, $this->urls->add($cycle->taskId, $links, $record->depth + 1, false, $cycle->number));
		}

		if ($page->canonical !== '' && $page->canonical !== $url && $this->site->rules->inScope($page->canonical)) {
			$this->unindex($cycle, $record);
			$this->found($cycle, $this->urls->add($cycle->taskId, [$page->canonical], $record->depth, false, $cycle->number));
			$this->finish($cycle, $record, UrlRecord::MOVED, 'canonical is ' . $page->canonical);

			return;
		}

		$reason = match (true) {
			!$this->site->rules->shouldIndex($url) => 'not to be indexed',
			$page->noindex                         => 'robots meta noindex',
			!$page->contentFound                   => 'no content found',
			$page->title === ''                    => 'no title found',
			default                                => '',
		};

		if ($reason !== '') {
			$this->unindex($cycle, $record);
			$this->finish($cycle, $record, UrlRecord::FOLLOWED, $reason);

			return;
		}

		$entry = new IndexEntry(
			$url,
			$page->title,
			$page->description,
			$page->body,
			$this->site->label,
			$this->site->language,
			$this->site->access,
			$page->date,
			$page->author,
			$page->category,
			$page->image
		);

		$hash = $entry->hash();

		if ($hash === $record->contentHash && $record->linkId > 0) {
			$this->count($cycle, 'unchanged');
			$this->finish($cycle, $record, UrlRecord::INDEXED, '');

			return;
		}

		$record->linkId      = $this->index->add($entry);
		$record->contentHash = $hash;
		$this->count($cycle, 'indexed');
		$this->finish($cycle, $record, UrlRecord::INDEXED, '');
	}

	/**
	 * Remove pages that left the sitemap. Returns false when there is nothing left to do.
	 */
	private function cleanup(Cycle $cycle): bool
	{
		if ($cycle->count('sitemap_failed') > 0) {
			return false;
		}

		$dropped = $this->urls->droppedFromSitemap($cycle->taskId, $cycle->number, 50);

		foreach ($dropped as $record) {
			$this->unindex($cycle, $record);
			$this->urls->delete($record->id);
		}

		return $dropped !== [];
	}

	/**
	 * The links worth queueing: on the site, in scope, allowed by robots.txt.
	 *
	 * @param   list<string>  $links
	 *
	 * @return  list<string>
	 */
	private function acceptable(Cycle $cycle, array $links, string $base): array
	{
		$accepted = [];

		foreach ($links as $link) {
			$link = UrlRules::normalize($link, $base);

			if ($link !== null && $this->site->rules->inScope($link) && $this->robotsAllow($cycle, $link)) {
				$accepted[] = $link;
			}
		}

		return $accepted;
	}

	private function unindex(Cycle $cycle, UrlRecord $record): void
	{
		if ($record->linkId > 0) {
			$this->index->remove($record->linkId);
			$this->count($cycle, 'removed');
		}

		$record->linkId      = 0;
		$record->contentHash = '';
	}

	private function finish(Cycle $cycle, UrlRecord $record, string $status, string $message): void
	{
		$record->status  = $status;
		$record->message = $message;
		$this->urls->checked($record, $cycle->number, $this->clock->now());
	}

	private function fetchSource(string $url, string $failurePriority): ?HttpResponse
	{
		try {
			$response = $this->request($url, ['User-Agent' => $this->site->userAgent]);
		} catch (HttpFailure $e) {
			($this->log)(\sprintf('%s: %s', $url, $e->getMessage()), $failurePriority);

			return null;
		}

		if ($response->status !== 200) {
			($this->log)(\sprintf('%s: HTTP %d', $url, $response->status), $failurePriority);
		}

		return $response;
	}

	/**
	 * @param   array<string, string>  $headers
	 */
	private function request(string $url, array $headers): HttpResponse
	{
		if ($this->requested) {
			$this->clock->sleep($this->site->delay);
		}

		$this->requested = true;

		return $this->http->get($url, $headers, $this->site->timeout());
	}

	private function robotsAllow(Cycle $cycle, string $url): bool
	{
		if (!$this->site->respectRobots) {
			return true;
		}

		$host = UrlRules::host($url);

		$this->robots[$host] ??= isset($cycle->robots[$host])
			? RobotsTxt::parse($cycle->robots[$host], $this->site->userAgent)
			: RobotsTxt::allowAll();

		return $this->robots[$host]->allows($url);
	}

	private function recheckBefore(): \DateTimeImmutable
	{
		return $this->clock->now()->modify(\sprintf('-%d days', $this->site->recheckDays));
	}

	private function timeUp(): bool
	{
		return $this->clock->seconds() >= $this->deadline;
	}

	private function found(Cycle $cycle, int $new): void
	{
		$this->count($cycle, 'found', $new);
	}

	private function count(Cycle $cycle, string $counter, int $by = 1): void
	{
		$cycle->add($counter, $by);
		$this->run[$counter] = ($this->run[$counter] ?? 0) + $by;
	}

	private function logRun(Cycle $cycle): void
	{
		$due = $cycle->phase === Cycle::DONE
			? 0
			: $this->urls->countDue($cycle->taskId, $cycle->number, $this->recheckBefore());

		($this->log)(
			\sprintf(
				'Cycle %d (%s): %d checked, %d indexed, %d unchanged, %d removed, %d errors, %d new URLs found; %d URLs still to check%s.',
				$cycle->number,
				$cycle->phase,
				$this->run['checked'] ?? 0,
				$this->run['indexed'] ?? 0,
				$this->run['unchanged'] ?? 0,
				$this->run['removed'] ?? 0,
				$this->run['errors'] ?? 0,
				$this->run['found'] ?? 0,
				$due,
				$cycle->sources === [] ? '' : \sprintf(', %d sources to read', \count($cycle->sources))
			),
			'info'
		);
	}
}
