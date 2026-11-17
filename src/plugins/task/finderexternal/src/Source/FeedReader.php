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
 * The item links of an RSS 2.0 or Atom feed.
 *
 * RSS: `<item><link>`. Atom: `<entry><link href>` with rel "alternate" or no rel.
 * Links of the channel or feed itself are left out.
 */
final class FeedReader
{
	/**
	 * @return  list<string>
	 *
	 * @throws  \UnexpectedValueException  When it is not XML.
	 */
	public function links(string $xml): array
	{
		$links  = [];
		$reader = new \XMLReader();
		$errors = libxml_use_internal_errors(true);

		try {
			if (trim($xml) === '' || !$reader->XML($xml, null, LIBXML_NONET)) {
				throw new \UnexpectedValueException('The feed is empty or not XML.');
			}

			$depthOfItem = null;

			while (@$reader->read()) {
				if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depthOfItem) {
					$depthOfItem = null;

					continue;
				}

				if ($reader->nodeType !== \XMLReader::ELEMENT) {
					continue;
				}

				$name = strtolower($reader->localName);

				if ($name === 'item' || $name === 'entry') {
					$depthOfItem = $reader->isEmptyElement ? null : $reader->depth;

					continue;
				}

				if ($name !== 'link' || $depthOfItem === null) {
					continue;
				}

				$href = $reader->getAttribute('href');

				if ($href !== null) {
					$rel = $reader->getAttribute('rel');

					if ($rel === null || $rel === 'alternate') {
						$links[] = trim($href);
					}
				} else {
					$text = trim($reader->readString());

					if ($text !== '') {
						$links[] = $text;
					}
				}
			}

			if (libxml_get_errors() !== [] && $links === []) {
				throw new \UnexpectedValueException('The feed is not valid XML.');
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors($errors);
		}

		return array_values(array_unique($links));
	}
}
