# Smart Search: external sites

A Joomla 6.1+ task plugin that adds pages of **other websites** to your site's Smart
Search index. Visitors who search your site find those pages too, mixed with your own
results. A click takes them to the page on its own site; nothing is copied or published on
yours.

Written for guide.joomla.org, so that a search there also finds the
[Joomla Programmers Manual](https://manual.joomla.org) and the
[Joomla Community Magazine](https://magazine.joomla.org), and usable on any Joomla 6.1 or
later site, for any site that can be crawled.

## What it does

The plugin adds a task type **Smart Search: index external site** to
**System > Scheduled Tasks**. Each task indexes one external site:

- **Where to find the pages.** XML sitemaps (sitemap indexes too), RSS/Atom feeds, and
  start pages whose links are followed up to a depth you choose.
- **Which pages.** Regular expressions limit the URLs that are followed (*scope*), skip
  URLs (*exclude*), and choose which of the followed pages are indexed (*index only*), so
  list and overview pages can be crawled for their links without being indexed themselves.
- **What to index.** CSS selectors for the main content, the title, parts to remove, and
  optionally the date, the author and the category. The description comes from the meta
  description or from the first paragraph.
- **How it shows.** Each site gets its own content type (the *site label*), so visitors
  can filter on it, and results carry the language and access level you set.

It behaves as a polite crawler:
- it respects robots.txt and `noindex`/`nofollow`, and pauses between requests;
- it sends conditional requests (ETag / Last-Modified) and re-reads a page only when its
  recheck period has passed;
- it follows redirects and canonical links;
- it removes pages from the index when they are gone, redirect away, or leave the sitemap.

Like every task plugin it works in short runs: 20 seconds by default. A run ends with
"will resume" while work is left, and the scheduler picks it up where it stopped.

## Using it

1. Install the package. It enables itself.
2. Create a task **Smart Search: index external site**. Fill in a site label, at least one
   sitemap, feed or start page, and the selectors for the site's markup. Ready-made
   profiles for manual.joomla.org and magazine.joomla.org are in [sites/](sites/).
3. Run it (from the command line or web cron preferably: see *Known limits*) until the
   log says "all done", and search.
4. To show external results with their own address (core shows the local host in front of
   it), copy [overrides/html/com_finder/search/default_external.php](overrides/html/com_finder/search/default_external.php)
   to `templates/<your template>/html/com_finder/search/`. Only results of this plugin use
   it.

## Known limits

- **Run the tasks one after another, not side by side.** Smart Search's indexer uses
  shared work tables. Two indexing runs at the same moment can spoil each other's results,
  and an editor saving an article counts as one. The command line (`scheduler:run`) or
  web cron runs tasks one at a time; the lazy scheduler may start them in parallel.
- **No sites on your own network.** The plugin refuses to fetch private, loopback and
  reserved addresses (see [docs/security-review.md](docs/security-review.md)).
- **Deleting a task** leaves its pages in the index until *Clear Index* or until you
  uninstall. Uninstalling removes them.

## Requirements

Joomla 6.1 or later (the plugin is loaded lazily, which Joomla 6.0 cannot do), PHP 8.3
with curl, MySQL, MariaDB or PostgreSQL.

## Development

See [docs/development.md](docs/development.md). The design is in
[docs/plan.md](docs/plan.md), and the security review, which is a release step, is in
[docs/security-review.md](docs/security-review.md).

## Licence

GNU General Public License version 3 or later; see [LICENSE](LICENSE).
