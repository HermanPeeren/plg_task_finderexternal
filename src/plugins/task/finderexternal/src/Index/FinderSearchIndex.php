<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Index;

use Joomla\Component\Finder\Administrator\Indexer\Helper;
use Joomla\Component\Finder\Administrator\Indexer\Indexer;
use Joomla\Component\Finder\Administrator\Indexer\Result;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * SearchIndex on Joomla's own Smart Search indexer.
 *
 * Writes through Indexer::index(), exactly as a finder plugin does, so the page gets
 * terms, weights, taxonomy and a stored Result like any local article. The only
 * difference: url and route are the external address. Route::_() leaves a URL that
 * does not start with "index.php" alone, so the result links straight to the other site.
 *
 * The text goes in with the "txt" parser: it is already plain text, and the HTML
 * parser would strip code samples such as "<div>" as if they were tags.
 */
final class FinderSearchIndex implements SearchIndex
{
	/**
	 * The result layout: com_finder's search view looks for default_external.php.
	 */
	public const LAYOUT = 'external';

	private ?Indexer $indexer = null;

	public function __construct(private readonly DatabaseInterface $db)
	{
	}

	public function add(IndexEntry $entry): int
	{
		$item = new Result();

		$item->url         = $entry->url;
		$item->route       = $entry->url;
		$item->title       = DisplayText::safe($entry->title);
		$item->language    = $entry->language;
		$item->access      = $entry->access;
		$item->state       = 1;
		$item->published   = 1;
		$item->type_id     = (int) Helper::addContentType($entry->type);

		// Elements Smart Search's default instructions already index: summary and body
		// as text, meta as metadata.
		$item->setElement('summary', DisplayText::safe($entry->description));
		$item->setElement('body', $entry->body);
		$item->setElement('meta', DisplayText::safe($entry->author));

		// The search view shows a result with layout "external" with default_external.php when a
		// template has one (see overrides/), and with its own default_result.php otherwise.
		$item->setElement('layout', self::LAYOUT);

		// Core's highlight plugin adds ?highlight=<search terms> to the link of every result
		// without a mime type. On an external link that would hand our visitors' searches to
		// the other site (which does nothing with them), so the result gets one.
		$item->setElement('mime', 'text/html');

		if ($entry->date !== '') {
			$item->publish_start_date = $entry->date;
			$item->start_date         = $entry->date;
		}

		if ($entry->image !== '') {
			// Shown beside the result when the search menu item has "Show image" on.
			$item->setElement('imageUrl', $entry->image);
			$item->setElement('imageAlt', '');
		}

		$item->addTaxonomy('Type', $entry->type);

		if ($entry->author !== '') {
			$item->addTaxonomy('Author', DisplayText::safe($entry->author));
		}

		if ($entry->category !== '') {
			$item->addTaxonomy('Category', DisplayText::safe($entry->category));
		}

		if ($entry->language !== '*') {
			$item->addTaxonomy('Language', $entry->language);
		}

		return (int) $this->indexer()->index($item, 'txt');
	}

	public function remove(int $linkId): void
	{
		if ($linkId > 0) {
			$this->indexer()->remove($linkId);
		}
	}

	public function missing(array $linkIds): array
	{
		$missing = [];

		foreach (array_chunk(array_values(array_unique($linkIds)), 500) as $chunk) {
			$query = $this->db->createQuery()
				->select($this->db->quoteName('link_id'))
				->from($this->db->quoteName('#__finder_links'))
				->whereIn($this->db->quoteName('link_id'), $chunk, ParameterType::INTEGER);

			$present = array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []);

			array_push($missing, ...array_values(array_diff($chunk, $present)));
		}

		return $missing;
	}

	/**
	 * Made on first use: the indexer reads com_finder's options and session state.
	 */
	private function indexer(): Indexer
	{
		return $this->indexer ??= new Indexer($this->db);
	}
}
