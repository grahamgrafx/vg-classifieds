# vg-classifieds: Agent Instructions

This file covers what is specific to the **vg-classifieds** plugin (`wp-content/plugins/vg-classifieds`). It is written so an agent already following **Newspack Plugin** guidance can orient the same way here.

For Newspack codebase conventions (lint commands, PHP/React patterns, wizards, Composer classmap, namespace map, recipes), see `wp-content/plugins/newspack-plugin/AGENTS.md`. This plugin does **not** duplicate that stack; treat Newspack AGENTS as the authority for Newspack itself.

## Relationship to Newspack (same mental model as Newspack AGENTS)

- **Primary principle:** compatibility with **WordPress core** and **Newspack Plugin** (`wp-content/plugins/newspack-plugin`) is paramount.
- **Reference docs:** `README.md` (requirements, Newspack version line), `VERSION-3.md` (0.3.0 PRD: scheduling / expire). In code, validate risky changes against `newspack-plugin` before merging.
- **No core edits:** extend WordPress only via this plugin (hooks, CPT, taxonomy, admin screens)—same boundary as Newspack AGENTS implies for `newspack-plugin`.

## Bootstrap & file layout (vg-classifieds-specific)

| Role | Location |
|------|----------|
| Plugin header, constants, activation/deactivation hooks | `vg-classifieds.php` |
| Textdomain + importer, taxonomy, schedule, scheduler, admin schedule `init()` | `plugins_loaded` (priority 5) in `vg-classifieds.php` |
| Hierarchical taxonomy for classifieds | `includes/class-vg-classifieds-taxonomy.php` |
| Schedule meta + REST registration | `includes/class-vg-classifieds-schedule.php` |
| Cron auto-expire only | `includes/class-vg-classifieds-scheduler.php` |
| Expiration meta box, list column, save | `includes/class-vg-classifieds-admin-schedule.php` |

- **Constants:** `VG_CLASSIFIEDS_VERSION`, `VG_CLASSIFIEDS_FILE`, `VG_CLASSIFIEDS_PATH` (main file).
- **Autoloading:** no Composer; new PHP is `require_once` from `vg-classifieds.php` (or follow existing `includes/` pattern).
- **Zip imports:** require PHP **ZipArchive**; gated in code + admin notice if missing.

## Class initialization (aligned with Newspack’s “static `init()` pattern”)

- **`NCI_Classifieds_Importer`:** static `init()` registers CPT, admin menu, notices; workhorse for ZIP import.
- **`VG_Classifieds_Taxonomy`:** static `init()` + `register()` for taxonomy (also called from activation flush).
- **`VG_Classifieds_Schedule`:** `_vg_expire_at` meta, `register_post_meta`, datetime helpers; publish uses core `post_date`.
- **`VG_Classifieds_Scheduler`:** cron hook `vg_classifieds_process_schedules` (expire only); scheduled on activation / `init` fallback.
- **`VG_Classifieds_Admin_Schedule`:** Expiration meta box, expire column, `save_post` validation (admin only).
- **Guards:** both classes wrapped in `class_exists( ..., false )` to avoid fatals if the file loads twice.
- **Namespaces:** unlike Newspack’s `Newspack\` namespace, this plugin uses **prefixed class names** (`NCI_`, `VG_`) and no PSR-4 autoload—keep new types consistently prefixed.

## Data model & storage (do not rename casually)

| Mechanism | Slug / key | Notes |
|-----------|------------|--------|
| Post type | `vg_classified` | Public, REST, archive slug `classifieds` |
| Taxonomy | `vg_classified_category` | Hierarchical; attached only to `vg_classified` |
| Post meta | `_nci_source_file`, `_nci_source_hash`, `_nci_raw_html` | Importer bookkeeping / dedup |
| Post meta (0.3+) | `_vg_expire_at` | Optional expire date (site TZ, end of day); cron drafts published posts — publish via core `post_date` — see `VERSION-3.md` |

Avoid collisions with Newspack’s storage conventions: do **not** reuse `newspack_*` / `np_` prefixes for new options or meta unless explicitly integrating.

## Admin UX

- Import UI: **`Classifieds → Classifieds Import`** (submenu of `edit.php?post_type=vg_classified`), capability `manage_options`.
- Publish timing: core block editor **Publish** row. Expire: **Expiration** meta box; sortable **Expires on** list column.
- Not aligned with Newspack Wizards/React stack—admin is plain PHP markup.

## Compatibility requirements (shared spirit with Newspack AGENTS)

- Prefer WordPress APIs, nonces, capabilities, `sanitize_*` / `wp_kses_post`, and standard hooks.
- Avoid changing post meta keys, CPT/taxonomy slugs, or option names unless there is a deliberate, documented migration.
- Do not assume theme-specific markup; classifieds should behave like normal CPT content in the block editor and on the front end.

## References

- Newspack Plugin repo: [https://github.com/Automattic/newspack-plugin/](https://github.com/Automattic/newspack-plugin/)
- Local Newspack agent guide: `wp-content/plugins/newspack-plugin/AGENTS.md`
