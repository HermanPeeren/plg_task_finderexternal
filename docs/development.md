# Developing the external sites plugin

## Layout

`src/` is the root of the installable package, laid out as it will be on a site:

```
src/
  finderexternal.xml                     the manifest; <files folder="plugins/task/finderexternal">
  script.php                             install script (a service provider): Joomla 6.1 / PHP 8.3 checks,
                                         enables the plugin; on uninstall removes the pages from the index
  plugins/task/finderexternal/
    services/provider.php                the composition root: registers the services, builds the plugin lazily
    forms/index.xml                      the task's parameters
    language/en-GB/                      plg_task_finderexternal(.sys).ini
    sql/                                 the two tables, mysql and postgresql, and updates/<db>/<version>.sql
    src/
      Extension/FinderExternal.php       the task plugin: parameters in, exit code out
      Run/RoutineFactory.php             validates one task's parameters into SiteSettings and builds a Crawler
      Run/Clock.php, SystemClock.php     time, for the time budget and the pause between requests
      Crawl/Crawler.php                  one run of a crawl cycle: discover, process, cleanup
      Crawl/SiteSettings.php             everything a crawl needs to know about one site
      Source/SitemapReader.php           <loc> of a sitemap or sitemap index (XMLReader)
      Source/FeedReader.php              item links of an RSS or Atom feed
      Http/HttpClient.php                interface; JoomlaHttpClient on Joomla's HTTP package over curl
      Http/AddressGuard.php              public addresses only (no SSRF); the request is pinned to them
      Http/RobotsTxt.php                 RFC 9309 rules for our user agent
      Url/UrlRules.php                   normalizing links; hosts, scope, exclude, index-only patterns
      Html/Selector.php                  the small CSS-to-XPath converter for the selector fields
      Html/PageExtractor.php             title, text, description, date, author, category, image, links, robots
      Index/FinderSearchIndex.php        writes to and removes from Smart Search through its Indexer
      Index/DisplayText.php              external text made safe for core's result layout
      Store/                             the two tables, behind UrlStore and CycleStore
overrides/html/com_finder/search/
  default_external.php                   result layout for external results (layout "external")
sites/                                   the profiles for manual.joomla.org and magazine.joomla.org
tests/
  Unit/                                  PHPUnit, no Joomla, no database, no network
  Support/                               in-memory stores and index, a fake HTTP client and clock
  Fixtures/                              sample pages
  cypress/                               specs against the development site
build/build.php                          zips src/ into build/plg_task_finderexternal-<version>.zip
tools/                                   install-local.php, add-site-task.php, phpstan-bootstrap.php
```

The namespace is `Yepr\Plugin\Task\FinderExternal`. The design and its reasons are in
[plan.md](plan.md).

## Dependency injection

Services are never built where they are used. `services/provider.php` is the only place
that wires them together:

| Service | Built from |
|---|---|
| `UrlStore`, `CycleStore` | `DatabaseUrlStore`, `DatabaseCycleStore` on the site's `DatabaseInterface` |
| `SearchIndex` | `FinderSearchIndex` on the database (Smart Search's `Indexer` is made on first use) |
| `HttpClient` | `JoomlaHttpClient`, with its `AddressGuard` |
| `PageExtractor`, `SitemapReader`, `FeedReader`, `Clock` | themselves |
| `RoutineFactory` | all of the above, and the default user agent (with the site's address) |
| `PluginInterface` | `FinderExternal`, with the factory, through `$container->lazy()` |

What differs per task comes from the task's parameters, so `RoutineFactory` makes the
`SiteSettings` and the `Crawler` per run.

## How a crawl works

A task crawls in **cycles**. A cycle has phases, and a run does as much as its time budget
allows before it returns "will resume":

1. **discover**: read robots.txt for each host, then the sitemaps (child sitemaps first),
   the feeds and the start pages, and add their URLs to `#__finderexternal_urls`.
2. **process**: check each URL that is due. A URL is due when it is new, when its recheck
   period has passed, or when it is a start page (depth 0, read every cycle for new links).
   For a page that may still be the same, a conditional GET is sent. Links are followed up
   to the maximum depth. A page is indexed when it is in the index-only patterns, is not
   `noindex`, has no canonical elsewhere, and has a title and content. Its content hash
   skips unchanged pages.
3. **cleanup**: remove pages that left the sitemap. This is skipped when a sitemap could
   not be read.

Then the cycle is done, and the next run starts a new one. At the start of a cycle, pages
that are no longer in the Smart Search index (after *Clear Index*) are indexed again.

## Database

All queries go through the query builder with bound parameters. The two tables:

- `#__finderexternal_urls`: one row per URL per task, with its depth, status, Smart Search
  link id, content hash, ETag / Last-Modified and last check.
- `#__finderexternal_cycles`: one row per task, with the cycle's phase and its state
  (sources still to read, robots.txt, counters) as JSON.

Uninstalling removes every page from the index, then drops both tables.

## The development site

`/joomla` holds Joomla 6.2.0, installed and git-ignored:

- URL: http://localhost/plg_task_finderexternal/joomla (WAMP's Apache). PHP's built-in
  server doesn't work: Joomla treats it as the command line and gets its own URL wrong.
- database `jlatest-finder-external` (user `root`, no password), table prefix `jfe_`
- the admin login is the one you install it with

To set it up again from scratch (check the latest stable release first with
`gh release view --repo joomla/joomla-cms --json tagName --jq .tagName`):

```bash
curl -sSL -o joomla.tar.gz https://github.com/joomla/joomla-cms/releases/download/6.2.0/Joomla_6.2.0-Stable-Full_Package.tar.gz
mkdir -p joomla && tar -xzf joomla.tar.gz -C joomla && rm joomla.tar.gz
php joomla/installation/joomla.php install -n --site-name="Smart Search external dev" --admin-user=Admin --admin-username=yourusername --admin-password=yourpassword --admin-email=admin@example.invalid --db-type=mysqli --db-host=localhost --db-user=root --db-pass= --db-name=jlatest-finder-external --db-prefix=jfe_
rm -rf joomla/installation
composer install-local
php tools/add-site-task.php sites/manual.joomla.org.json
php tools/add-site-task.php sites/magazine.joomla.org.json
mkdir -p joomla/templates/cassiopeia/html/com_finder/search
cp overrides/html/com_finder/search/default_external.php joomla/templates/cassiopeia/html/com_finder/search/
```

Run the tasks from the command line, one at a time:

```bash
php joomla/cli/joomla.php scheduler:list
php joomla/cli/joomla.php scheduler:run --id=<id>
```

The task log is `joomla/administrator/logs/joomla_scheduler.php`. Test with a short time
budget (5 seconds) until the runs end with "all done". Then search on the frontend:
`index.php?option=com_finder&view=search&q=...`.

To crawl a site again from scratch, delete its rows from `jfe_finderexternal_urls` and
`jfe_finderexternal_cycles` (by `task_id`) and *Clear Index*, or reset those rows'
`checked_cycle`, `last_checked` and `content_hash`.

## Commands

| Command | What it does |
|---|---|
| `composer test` | PHPUnit, including `SecurityTest` |
| `composer analyse` | PHPStan, level 8, against the Joomla in `/joomla` |
| `composer cs` | phpcs: PSR-12, with Joomla's tabs in `src/` |
| `composer cs-fix` | php-cs-fixer on tests, build and tools |
| `composer audit` | known vulnerabilities in the dependencies |
| `composer build` | the package zip in `build/` |
| `composer install-local` | build, then install into `/joomla` through Joomla's CLI |
| `npx cypress run` | the Cypress specs; copy `cypress.env.json.dist` to `cypress.env.json` first |

CI runs test, analyse, cs and audit on every push. Cypress needs a site with crawled
content, so it is run by hand on the development site.

## Known limits

- **Concurrent indexing.** Smart Search's `Indexer` empties and fills the shared
  `#__finder_tokens` tables on every item. Two indexing processes at once (two tasks
  through the lazy scheduler, or a task and an article being saved) can mix up each
  other's tokens. Run the tasks from the command line or web cron. Fixing this needs
  core.
- **Deleting a task** leaves its rows and its pages in the index. They go with *Clear
  Index* (the rows stay, harmlessly) or on uninstall.
- **Content types** ("Joomla Manual", …) stay in Smart Search after uninstall, as those of
  core's finder plugins do.
- **`tools/add-site-task.php`** writes to MySQL/MariaDB only; on PostgreSQL use the form.
- **Core shows external URLs wrongly** in its result layout (local host in front of the
  external URL): hence the `external` layout in `overrides/`. Better fixed in core
  (`default_result.php`, the "show URL" line).

## Security

The review against the manual's security chapter is in
[security-review.md](security-review.md). It is part of every release: the release
workflow refuses a tag the review doesn't name.

## Releasing

1. Bump `<version>` in `src/finderexternal.xml`.
2. Add `sql/updates/mysql/<version>.sql` and `sql/updates/postgresql/<version>.sql`. A
   comment is enough when the schema didn't change.
3. Go through the checklist in [security-review.md](security-review.md) and set its "Last
   full review" line to the new version.
4. Commit, and push a tag `v<version>`.

The release workflow then:
- checks the tag against the manifest, the schema files and the review;
- runs the checks;
- builds the package and checks its contents;
- attaches it to a GitHub release.
