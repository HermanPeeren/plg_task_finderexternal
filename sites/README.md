# Site profiles

The plugin itself knows nothing about any particular site. A *profile* is the set of task
parameters for one external site, kept here as JSON so it can be reviewed, versioned and
applied again. A profile holds:

- `title`: the task's title;
- `params`: exactly the fields of the task form (see `forms/index.xml` for their names);
- `schedule`: how often the task runs (`interval`: minutes, hours or days; `every`).

## Applying a profile

Either fill in the task form in **System > Scheduled Tasks** with the values of `params`,
or, on a MySQL site you can reach from the command line:

```bash
php tools/add-site-task.php sites/manual.joomla.org.json path/to/joomla
```

That adds the task, or updates the task with the same title. Lines in a pattern or selector
field that start with `//` are comments.

## The profiles

### manual.joomla.org

The Joomla Programmers Manual is a Docusaurus site. Each page's text is in
`.theme-doc-markdown`; heading anchors, the mobile table of contents and the copy buttons
of code blocks are removed. The category is the breadcrumb.

The profile indexes the current version of the docs and the update notes (`/updates/`):
`/docs/next/` and the numbered older versions are excluded, so a search doesn't return
the same page five times.

> **Note: the manual currently asks not to be indexed.** The pages of the current
> version (served at `/docs/`) carry `<meta name="robots" content="noindex, nofollow">`,
> and because Docusaurus leaves noindex pages out of the sitemap, the sitemap doesn't list
> them either. The upcoming version (`/docs/next/`) and the older ones are indexable and
> are in the sitemap. The plugin respects the tag, so with this profile only the update
> notes are indexed (81 pages in November 2026). This looks like a setting on the manual
> site (`noIndex` on the current version) rather than intent, and is worth raising with
> the documentation team. Once it is fixed, this profile indexes the current docs without
> changes.

### magazine.joomla.org

The Joomla Community Magazine is a Joomla site, so its articles have core's markup. Its
JSitemap lists only a handful of pages, so the profile starts from the RSS feed, the home
page and the issues page, and follows the issue pages (with their `?start=` pagination) to
the articles. Only article URLs (`/issues/<year>/<month>/<slug>`) are indexed; list pages,
feeds, print views and tag, author and search pages are excluded. The description is the
article's first paragraph, because every page has the same meta description. Date, author
and category come from the article's info block.

A full crawl is about 3000 articles. With the default 20 seconds per run and half a second
between requests, the first cycle takes a while. Later cycles only re-read what is due
(every 30 days) plus the start pages.
