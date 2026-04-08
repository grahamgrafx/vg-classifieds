# vg-classifieds

vg-classifieds is a WordPress plugin that imports a ZIP of HTML files into a `classified` custom post type.

## How it works

- Registers a public `classified` post type with REST support.
- Adds an admin import screen under `Tools > Classifieds Import`.
- Accepts a ZIP upload and processes only `.html` / `.htm` files.
- Creates or updates posts based on source filename.
- Skips files when content hash is unchanged.
- Stores source filename, content hash, and raw HTML in post meta.

## Import options

- **Publish status:** `draft` (default) or `publish`.
- **Trusted HTML:** optional admin-only mode to import raw HTML directly.
  - If disabled, content is sanitized with `wp_kses_post()`.

## Current status

This plugin is under active development. Expect behavior and data handling to evolve as compatibility with WordPress core and Newspack is hardened.
