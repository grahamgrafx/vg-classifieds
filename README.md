# Vineyard Gazette Classifieds

Vineyard Gazette Classifieds is a WordPress plugin that imports a ZIP of HTML files into the `vg_classified` custom post type.

**Requirements:** WordPress 6.0+; PHP 7.4+ with the Zip extension (`ZipArchive`) enabled.

**Dependency:** Requires the `vineyard-gazette` parent plugin (checks for `VG_PLUGIN_VERSION`).

**Newspack:** Intended to run alongside [Newspack Plugin](https://github.com/Automattic/newspack-plugin). Development is checked against Newspack **6.x**; the reference version used for that check is **6.32.0**. Earlier Newspack majors are not validated here—test on a staging site before relying on them.

## How it works

- Registers a public `vg_classified` post type with REST support.
- Registers a hierarchical `vg_classified_category` taxonomy for classifieds.
- Adds an admin import screen under `Classifieds > Classifieds Import`.
- Accepts a ZIP upload and processes only `.html` / `.htm` files.
- Creates or updates posts based on source filename.
- Extracts post titles from each HTML file's `Classification Title Here` section (excluding leading numeric IDs).
- Skips files when content hash is unchanged.
- Stores source filename, content hash, and raw HTML in post meta.

## Scheduling (0.3)

- **Go live:** use the block editor **Publish** control (WordPress core scheduling — `post_date`, `future` status).
- **Auto-expire:** optional **Expires on** date in the **Expiration** meta box (last published day; expires after end of that day in site timezone). Plugin cron moves published posts to `draft` when due.
- List table: sortable **Expires on** column; **Scheduled** for upcoming publishes is shown by core for `future` posts.
- ZIP import does not set or change expire meta.

## Import options

- **Publish status:** `draft` (default) or `publish`.
- **Trusted HTML:** optional admin-only mode to import raw HTML directly.
  - If disabled, content is sanitized with `wp_kses_post()`.

## Imported file state

- Imported HTML files are expected to follow a consistent structure across the whole ZIP.
- The importer currently assumes this consistency and does not perform heavy per-file normalization.
- A canonical sample template is provided at `example-file.tpl.html`.
- Use that template as the source format reference when preparing import files.

## Documentation

- [VERSION-3.md](VERSION-3.md) — PRD and technical notes for scheduling.

## Current status

Version **0.3.0** — ZIP import, core publish scheduling, plugin auto-expire. See `VERSION-3.md` for details.
