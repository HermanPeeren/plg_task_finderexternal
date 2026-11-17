<?php

/**
 * @package     FinderExternal
 *
 * @copyright   Copyright (C) 2026 Yepr, Herman Peeren. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Yepr\Plugin\Task\FinderExternal\Extension\FinderExternal;
use Yepr\Plugin\Task\FinderExternal\Html\PageExtractor;
use Yepr\Plugin\Task\FinderExternal\Http\HttpClient;
use Yepr\Plugin\Task\FinderExternal\Http\JoomlaHttpClient;
use Yepr\Plugin\Task\FinderExternal\Index\FinderSearchIndex;
use Yepr\Plugin\Task\FinderExternal\Index\SearchIndex;
use Yepr\Plugin\Task\FinderExternal\Run\Clock;
use Yepr\Plugin\Task\FinderExternal\Run\RoutineFactory;
use Yepr\Plugin\Task\FinderExternal\Run\SystemClock;
use Yepr\Plugin\Task\FinderExternal\Source\FeedReader;
use Yepr\Plugin\Task\FinderExternal\Source\SitemapReader;
use Yepr\Plugin\Task\FinderExternal\Store\CycleStore;
use Yepr\Plugin\Task\FinderExternal\Store\DatabaseCycleStore;
use Yepr\Plugin\Task\FinderExternal\Store\DatabaseUrlStore;
use Yepr\Plugin\Task\FinderExternal\Store\UrlStore;

/**
 * The composition root: the only place the plugin's services are put together.
 *
 * Joomla hands each extension a child container, so what is registered here stays the
 * plugin's own. Every service is shared and built on first use, and the plugin itself
 * is a lazy proxy: a page that runs no task builds none of it.
 */
return new class () implements ServiceProviderInterface {
	public function register(Container $container): void
	{
		$container->share(
			UrlStore::class,
			static fn (Container $container): UrlStore => new DatabaseUrlStore($container->get(DatabaseInterface::class))
		);

		$container->share(
			CycleStore::class,
			static fn (Container $container): CycleStore => new DatabaseCycleStore($container->get(DatabaseInterface::class))
		);

		$container->share(
			SearchIndex::class,
			static fn (Container $container): SearchIndex => new FinderSearchIndex($container->get(DatabaseInterface::class))
		);

		// Redirects are the crawler's business (moved pages, other hosts), so the client must not follow them.
		$container->share(
			HttpClient::class,
			static fn (): HttpClient => new JoomlaHttpClient()
		);

		$container->share(PageExtractor::class, static fn (): PageExtractor => new PageExtractor());
		$container->share(SitemapReader::class, static fn (): SitemapReader => new SitemapReader());
		$container->share(FeedReader::class, static fn (): FeedReader => new FeedReader());
		$container->share(Clock::class, static fn (): Clock => new SystemClock());

		$container->share(
			RoutineFactory::class,
			static fn (Container $container): RoutineFactory => new RoutineFactory(
				$container->get(UrlStore::class),
				$container->get(CycleStore::class),
				$container->get(SearchIndex::class),
				$container->get(HttpClient::class),
				$container->get(PageExtractor::class),
				$container->get(SitemapReader::class),
				$container->get(FeedReader::class),
				$container->get(Clock::class),
				// Tells the other site who is crawling and where to complain.
				'JoomlaSmartSearchExternal/1.0 (+' . rtrim(Uri::root(), '/') . '/)'
			)
		);

		$container->set(
			PluginInterface::class,
			$container->lazy(FinderExternal::class, function (Container $container) {
				$plugin = new FinderExternal(
					(array) PluginHelper::getPlugin('task', 'finderexternal'),
					$container->get(RoutineFactory::class)
				);
				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			})
		);
	}
};
