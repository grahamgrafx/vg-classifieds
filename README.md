# vg-classifieds

vg-classifieds is a WordPress plugin that imports a ZIP of HTML files into the `vg_classified` custom post type.

**Requirements:** WordPress 6.0+; PHP 7.4+ with the Zip extension (`ZipArchive`) enabled.

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

## Import options

- **Publish status:** `draft` (default) or `publish`.
- **Trusted HTML:** optional admin-only mode to import raw HTML directly.
  - If disabled, content is sanitized with `wp_kses_post()`.

## Imported file state

- Imported HTML files are expected to follow a consistent structure across the whole ZIP.
- The importer currently assumes this consistency and does not perform heavy per-file normalization.
- A canonical sample template is provided at `example-file.tpl.html`.
- Use that template as the source format reference when preparing import files.

## Current status

This plugin is under active development. Expect behavior and data handling to evolve as compatibility with WordPress core and Newspack is hardened.
