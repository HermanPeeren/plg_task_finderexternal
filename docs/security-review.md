# Security review

The plugin is checked against every point of the Security section of the Joomla
Programmers Manual (<https://manual.joomla.org/docs/next/security/>), including the two
chapters it refers to: server-side form validation and Input.

This review is a **release step**: before a version is tagged, go through the checklist at
the end. The rules that can be checked automatically are tested on every run in
`tests/Unit/SecurityTest.php` (part of `composer test`, so of CI), so a later change cannot
quietly undo them.

Last full review: 0.1.0, 2026-11-17.

## What is input here

This plugin has two kinds of input, and the second kind matters most.

1. **The task form.** It is filled in by a backend user who may manage scheduled tasks.
   That user is trusted to choose sites to index, but the values are still filtered and
   validated, as for any input.
2. **Everything read from the external sites**:
   - HTML pages, sitemaps, feeds and robots.txt;
   - HTTP headers (`Location`, `ETag`, `Last-Modified`, `Content-Type`);
   - the links inside pages.

   Another party controls all of it, and it can change at any time. A site that is
   trustworthy today can be taken over tomorrow. So all of it is treated as hostile.

There are no frontend entry points:
- no controller, no own form submission, no AJAX endpoint;
- no file uploads;
- no use of `$_GET`, `$_POST`, `$_COOKIE` or `$_SERVER`.

The task runs from the Scheduler: CLI, web cron or the lazy scheduler.

## Fundamentals

### No 1: all user input is evil

| Input | Filtered / validated | Where |
|---|---|---|
| Form fields | Every field has a `filter`:<br>• `string` for label and URLs<br>• `int` for numbers<br>• `word` for the list<br>• `integer` for the switch<br>• `raw` only for patterns and selectors, which are validated in code | `forms/index.xml`; enforced by `SecurityTest::testEveryFormFieldIsFiltered` |
| Source URLs | Must be absolute `http(s)` URLs, else the task stops with a clear message | `RoutineFactory::urlList()` |
| Numbers | Clamped in code as well as in the form:<br>• depth 0–50<br>• recheck 0–365 days<br>• delay 0–10000 ms<br>• budget 5–300 s<br>• access ≥ 1 | `RoutineFactory::number()` |
| Regular expressions | Compiled and tested before use. The delimiter is `\x01` (as in Joomla's `FormRule`), so a pattern cannot close the delimiter and add modifiers | `UrlRules::compile()` |
| CSS selectors | Converted by a small whitelist converter. Anything outside the supported syntax is refused, and quotes in values are handled, so no XPath injection | `Selector::toXPath()` |
| Language | `*` or a language tag pattern, else refused | `RoutineFactory::settings()` |
| User agent | Control characters (CR/LF) replaced, so it stays one header line (no header injection) | `RoutineFactory::settings()` |
| Links in pages, redirects, canonicals, sitemap and feed entries | Resolved and normalized:<br>• only `http(s)` (no `javascript:`, `data:`, `file:` …)<br>• host checked against a host-name pattern<br>• user info dropped<br>• characters that are unsafe in a URL percent-encoded (`"`, `'`, `<`, `>`, space, non-ASCII)<br>• then checked against the task's hosts and scope | `UrlRules::normalize()`, `inScope()` |
| Page text | Read as text through the DOM (never as markup); size-limited (body 200 000 characters, description 300); dates parsed and re-formatted | `PageExtractor` |
| HTTP headers | Used only as values:<br>• `ETag` and `Last-Modified` go back as request headers, through the HTTP library<br>• `Location` goes through `normalize()` and scope | `Crawler` |
| robots.txt | At most 500 KiB is kept; rules are matched as `preg_quote`d text | `Crawler`, `RobotsTxt` |

### No 2: client-side security is no security

All checks are server-side. The form's `min`/`max`/`required` attributes are repeated in
`RoutineFactory`, which is what the task actually runs on. Even a task whose parameters
are written straight into the database (as `tools/add-site-task.php` does) gets the same
validation.

### No 3: don't do your own crypto

There is no cryptography. `sha1()` only makes a fixed-length key for a URL (an index on
`url_hash`) and a change detector for page content. Neither is a security function: a
collision would at worst make a page look unchanged.

### No 4: no security by obscurity

The protections are documented here and in the code. A reported problem is fixed in the
open: a GitHub security advisory and a release note naming the problem and the fixed
version.

### No 5: reduce the attack surface

- Core is used wherever it exists:
  - the Scheduler, for running, locking, logging and permissions;
  - Smart Search's `Indexer`, `Result`, `Helper`, for the index and content types;
  - Joomla's HTTP package, the database query builder and the Form/filter system.
- No frontend code, no own admin views, no own controllers.
- The result layout in `overrides/` is a copy of core's with one line changed, and only applies to
  results of this plugin (layout "external").

## Common vulnerabilities

### XSS

External text ends up on the frontend search results page, so this is the main risk. Core's
`default_result.php` prints the title, the description and the taxonomy titles **without
escaping**. That is safe for local content, which Joomla filtered when it was saved; it is
not safe for text from another site. A page title written as `&lt;script&gt;` is
`<script>` once it has been read.

Measures:
- **`DisplayText::safe()`** changes `<` and `>` into `&lt;` and `&gt;` in the title,
  description, author and category before they go into the index, and drops control
  characters. Browsers show the characters as they were. Other characters are left alone,
  so admin views, which escape titles themselves, don't show `&amp;`.
  Tests: `DisplayTextTest`.
- **URLs** (route, URL, image) are normalized and percent-encoded (see the input table), so
  they cannot leave a `href="…"` or `src="…"` attribute, and they are never `javascript:`.
  Tests: `UrlRulesTest`.
- **The body** is only used for search tokens. When there is no description, core builds one
  from the body with its HTML parser, which strips tags.
- **The `external` result layout** prints an external URL with `$this->escape()`. Test:
  `SecurityTest::testTheOverrideEscapesTheShownUrl`.
- Log messages are written to the scheduler's log file, which the admin log view escapes.

### SQL injection

Every query uses the query builder with:
- `bind()` for values, `ParameterType::INTEGER` for numbers;
- `whereIn()` for lists;
- `quoteName()` for names.

`quote()` is only used for the literal `''`. The tool `tools/add-site-task.php`, which is
not part of the package, uses mysqli prepared statements; the table name comes from
`configuration.php`. Checked by `SecurityTest::testQueriesBindTheirValues`.

### Unrestricted file uploads

There are none. The plugin writes no files; downloads are kept in memory, with a size limit.

### CSRF

The plugin has no forms or controllers of its own. The task form is core's
(`com_scheduler`), which carries and checks the form token and checks `core.manage`/`edit`
permissions. Running a task from the browser is core's lazy scheduler / web cron, protected
by core (a web cron key, and the lock).

## Beyond the manual: risks of a crawler

The manual's list is written for extensions that handle requests. A crawler adds its own
risks.

### Server-side request forgery (SSRF)

A task makes the web server fetch URLs. Without limits it could read localhost, the
intranet or a cloud metadata endpoint (`169.254.169.254`) and put the text in the public
search index. An external site that redirects, or a DNS name that points inward, could
cause the same.

Measures:
- **`AddressGuard`** resolves the host and refuses private, loopback, link-local and
  reserved addresses (IPv4 and IPv6) before any request is made.
- The request is then **pinned** to exactly the checked addresses (`CURLOPT_RESOLVE`), so a
  second, different DNS answer (DNS rebinding) cannot slip in between check and request.
- Only `http` and `https` (`CURLOPT_PROTOCOLS_STR`).
- Redirects are not followed by curl. The crawler checks each target against the task's
  hosts and scope, and fetches it in a later step, through the same guard.
- Links are only followed on the hosts of the task's own sources.

Tests: `AddressGuardTest`.

As a consequence, the plugin cannot index a site on the local network. That is
intentional.

### Denial of service / resource use

| Limit | Value |
|---|---|
| Response size | 20 MB, enforced while downloading (the transfer is cut off) |
| HTML page parsed | 5 MB (a DOM tree takes several times its size) |
| robots.txt kept | 500 KiB |
| Indexed body | 200 000 characters |
| Time per run | task budget 5–300 s, minus the request timeout |
| Request timeout | 2–15 s |
| Pause between requests | 0–10 s (default 0.5 s), so the plugin never hammers a site |
| Crawl depth | ≤ 50, plus the URL scope |
| Database work | URL inserts and index look-ups in chunks |

Admin regular expressions run under PCRE's backtrack limit; a runaway pattern fails to
match, it doesn't hang.

### XML attacks (XXE, entity expansion)

Sitemaps and feeds are read with `XMLReader` and `LIBXML_NONET`, and HTML with
`DOMDocument::loadHTML` and `LIBXML_NONET`. PHP 8 doesn't load external entities, and
`LIBXML_NOENT`, `LIBXML_DTDLOAD` and `LIBXML_PARSEHUGE` are never used, so libxml's
protection against entity expansion stays on. Checked by
`SecurityTest::testExternalXmlAndHtmlAreParsedSafely`.

### Direct file access

Every PHP file starts with `\defined('_JEXEC') or die;`. Checked by
`SecurityTest::testEveryFileIsOnlyRunInsideJoomla`.

### Dangerous functions

No `eval`, `exec` and similar, no `unserialize` (core's indexer serializes its own
`Result`), no backticks. Checked by `SecurityTest::testNoDangerousCalls`.

### Privacy: search terms

Core's *System - Highlight* plugin adds `?highlight=<search terms>` to the link of
every result that has no mime type. On an external link that would hand our visitors' searches
to the other site. External results carry the mime type `text/html`, which core's plugin
skips, so the links are the plain external URLs. The Cypress spec `search-results.cy.js`
checks that.

The crawler sends a user agent naming the site it indexes for (or the one set in the task),
and nothing about visitors.

### Content being shown that shouldn't be

- Pages with `<meta name="robots" content="noindex">` are not indexed.
- robots.txt is respected by default.
- Each task has an access level for its results.
- Pages that disappear (404/410, or dropped from the sitemap) are removed from the index.

## Known limits

- **Who may run the crawler.** A user who may edit scheduled tasks decides which public
  sites are indexed. Give that permission only to people you trust with the search results
  of your site.
- **Uninstalling the plugin** removes the indexed pages. **Deleting a single task** leaves
  its pages in the index until "Clear Index" (see `docs/development.md`).

## Release checklist

Before tagging a release, check the items below and update "Last full review" above.

- [ ] `composer test` passes, including `SecurityTest`.
- [ ] `composer analyse` passes.
- [ ] New input (a form field, a new kind of external data, a new HTTP header used): it has
      a server-side filter in the form **and** validation in `RoutineFactory`, or is
      normalized in the reader that handles it.
- [ ] New text shown on the frontend goes through `DisplayText::safe()` or is escaped in
      the layout.
- [ ] New URLs (from pages, headers or settings) go through `UrlRules::normalize()` and
      the task's scope.
- [ ] New requests go through `HttpClient` (and so through `AddressGuard`).
- [ ] New queries use `bind()` / `whereIn()`.
- [ ] New XML or HTML parsing uses `LIBXML_NONET` and no entity flags.
- [ ] The `external` result layout, if changed: everything external is escaped.
- [ ] Dependencies: `composer audit` reports nothing for the shipped code (the package
      ships no third-party code; dev tools only).
- [ ] Walk through the manual's Security section once more for anything new:
      <https://manual.joomla.org/docs/next/security/>.
