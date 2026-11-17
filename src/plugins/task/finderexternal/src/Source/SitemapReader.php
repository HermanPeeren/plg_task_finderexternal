<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

namespace Yepr\Plugin\Task\FinderExternal\Source;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The page addresses in an XML sitemap, and the child sitemaps of a sitemap index.
 *
 * Read with XMLReader, one element at a time, so a large sitemap is never held as a
 * tree. A sitemap is at most 50 MB uncompressed by the protocol; the string itself is
 * still in memory, as the HTTP client returns it.
 */
final class SitemapReader
{
	/**
	 * @return  array{urls: list<string>, sitemaps: list<string>}
	 *
	 * @throws  \UnexpectedValueException  When it is not XML.
	 */
	public function read(string $xml): array
	{
		$result = ['urls' => [], 'sitemaps' => []];
		$reader = new \XMLReader();
		$errors = libxml_use_internal_errors(true);

		try {
			if (trim($xml) === '' || !$reader->XML($xml, null, LIBXML_NONET)) {
				throw new \UnexpectedValueException('The sitemap is empty or not XML.');
			}

			$container = '';

			while (@$reader->read()) {
				if ($reader->nodeType !== \XMLReader::ELEMENT) {
					continue;
				}

				$name = strtolower($reader->localName);

				if ($name === 'url' || $name === 'sitemap') {
					$container = $name;

					continue;
				}

				if ($name === 'loc' && $container !== '') {
					$loc = trim($reader->readString());

					if ($loc !== '') {
						$result[$container === 'url' ? 'urls' : 'sitemaps'][] = $loc;
					}
				}
			}

			if (libxml_get_errors() !== [] && $result['urls'] === [] && $result['sitemaps'] === []) {
				throw new \UnexpectedValueException('The sitemap is not valid XML.');
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($errors);
		}

		return $result;
	}
}
