# Design

## The question

guide.joomla.org's Smart Search only finds pages of guide.joomla.org. Related material is
on manual.joomla.org and magazine.joomla.org. Can a search on the guide also find those
pages, linking to them on their own site, without copying their content to the guide?

## Options considered

1. **A finder plugin** (`plugins/finder/...`). Smart Search's adapters index local
   content: they are called when an item is saved, and by the indexer with a list of local
   items. Without local items, external pages would first have to be stored somewhere on
   the site: a copy of the content, which is what we don't want.
2. **A task plugin that writes into the index** (chosen). Smart Search's `Indexer` takes
   any `Result`, whatever produced it. A `Result` whose `route` is an absolute external
   URL is shown and linked as it is: `Route::link()` leaves URLs that don't start with
   `index.php` alone. Only the search text (title, description, body tokens) is stored,
   which is what any search index stores. The Scheduler gives it what a crawler needs:
   regular runs, a time budget per run with "will resume", locking, a log.
3. **An external search service** (a federated search, or a shared index such as
   Elasticsearch for all Joomla sites). More infrastructure, and the results aren't in
   Smart Search's ranking and filters.

## Decisions

- **General plugin, separate profiles.** The plugin knows nothing about any particular
  site. Everything site-specific is a task parameter: sources, URL patterns, CSS selectors,
  language, access, recheck period. Per site, a profile in `sites/` holds those values.
- **One task per site.** Each has its own schedule, log, content type and state.
- **Crawl cycles in portions.** A cycle is discover → process → cleanup, stored in the
  database. Each run works within its budget (20 s by default, minus a reserve for one
  request) and returns "will resume" while work is left.
- **Polite crawling.**
  - robots.txt is followed (RFC 9309, with our own user-agent group);
  - `noindex`/`nofollow` and canonical links are honoured;
  - there is a pause between requests;
  - conditional GETs are sent;
  - every URL is checked at most once per cycle, and again only after its recheck period.
- **Redirects by hand.** curl doesn't follow them. A redirect marks the URL as moved and
  queues the target, if that is in scope.
- **Change detection.** A content hash per page skips unchanged pages, on top of the
  indexer's own signature.
- **Removal.** 404/410, a redirect, a canonical elsewhere, leaving the sitemap and
  becoming `noindex` all remove a page from the index. Cleanup by sitemap is skipped when
  the sitemap failed, so a broken sitemap doesn't empty the index.
- **Plain text.** Pages are read with DOMDocument and indexed through the "txt" parser, so
  code samples in the manual stay searchable instead of being stripped as tags.
- **Security first.** External content is untrusted input; see
  [security-review.md](security-review.md).

## Later

- A core fix for the "show URL" line in `com_finder`'s `default_result.php`, so the
  template layout isn't needed.
- A core fix for the shared token tables, so indexing processes can run side by side.
- Removing a task's pages when the task is deleted (needs an event from com_scheduler).
- Sitemap `lastmod` as a hint for what to re-read first.
