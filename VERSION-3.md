# vg-classifieds — Version 0.3.0

Product requirements and change documentation for scheduling on the `vg_classified` custom post type.

**Status:** Implemented — see `includes/class-vg-classifieds-schedule.php`, `class-vg-classifieds-scheduler.php`, `class-vg-classifieds-admin-schedule.php`.  
**Plugin version:** `0.3.0` (publish uses core; expire uses plugin)  
**Prior baseline:** `0.1.0` (ZIP import, CPT, taxonomy, importer meta)

---

## Summary

Version **0.3.0** time-boxes Classified visibility in two layers:

| When to go live | Mechanism |
|-----------------|-----------|
| **Publish** | **WordPress core** — document sidebar **Publish** control sets `post_date` and `future` / `publish` status; core cron publishes scheduled posts. |
| **Expire** | **vg-classifieds** — optional `_vg_expire_at` meta; plugin cron moves `publish` → `draft` when due. |

Editors schedule go-live with the normal block editor Publish row. Optional **Expires on** (date only) lives in the **Expiration** meta box. ZIP import does not set or change `_vg_expire_at`.

Legacy `_vg_publish_at` (from unreleased dev builds) is removed on upgrade via one-time DB cleanup.

---

## Goals

1. **Time-boxed visibility** — Classifieds go live and leave the site on a schedule without manual status changes at each moment.
2. **Editor-friendly** — One familiar control for publish timing (core); one field for expiration.
3. **WordPress-native** — Core scheduling for publish; post meta + plugin cron only where core has no expire API.
4. **Newspack-safe** — No collision with Newspack meta prefixes; standard CPT + block editor flows.

## Non-goals (0.3.0)

- Custom meta or cron for draft → publish (use core instead).
- Parsing expire dates from imported HTML.
- Front-end countdown UI.
- Trash/delete on expiration (only `draft`).
- Email or Slack notifications on status change.

---

## User stories

| As a… | I want to… | So that… |
|--------|------------|----------|
| Editor | Schedule go-live with the **Publish** row in the document sidebar | WordPress publishes the Classified at that time (core behavior). |
| Editor | Set **Expires on** (date) on a Classified | It drops off the public site after the end of that day. |
| Editor | Leave expire blank | The post stays published until I change it manually. |
| Editor | See validation when expire is before the post publish date | I don’t configure impossible schedules. |
| Editor | Sort the list by **Expires on** | I can review upcoming or past expirations quickly. |
| Site owner | Rely on cron for expiration on low-traffic hosts | Expirations still run within an acceptable delay (see Cron). |

Core already shows **Scheduled** in the list table for `future` posts — no duplicate plugin badge.

---

## Data model

### Publish (core)

- **`post_date`** / **`post_status`** (`future`, `publish`, `draft`) — standard WordPress scheduling.
- No `_vg_publish_at` meta.

### Post meta (plugin)

| Meta key | Type | Storage format | Description |
|----------|------|----------------|-------------|
| `_vg_expire_at` | string (optional) | MySQL datetime in **site timezone** — date-only in UI; stored as end of day `Y-m-d 23:59:59` | Automatic unpublish (→ `draft`) after the last day selected. Empty = no auto-expire. |

**Registration:** `register_post_meta()` for `vg_classified`, `show_in_rest` => true, `auth_callback` => `edit_post`.

**Validation:**

- If set, expire end-of-day must be strictly **after** the post’s `post_date` (publish moment).
- Past expire dates allowed on save; cron handles due items on next run.
- **After expiration:** cron sets `draft` only; `_vg_expire_at` **remains stored** for reference.

### Post status interaction

| Current status | Core publish due | Expire at due (plugin cron) |
|----------------|------------------|----------------------------|
| `future` | Core → `publish` | No-op until published |
| `draft` | Manual / core per editor | No-op |
| `publish` | — | → `draft` |
| `trash` / other | — | Skip |

### Import behavior

- Do **not** write, clear, or parse `_vg_expire_at`.
- Re-import leaves existing expire meta unchanged.

---

## Admin UX

### Post edit screen

- **Publish:** document sidebar **Publish** row (core).
- **Expiration** meta box: **Expires on** (`date` input — day only; stored as end of that day in site TZ), pointer to core Publish for go-live.

### List table

- **Expires on** column (date only, formatted).
- **Sortable:** click the **Expires on** header to sort ascending/descending by `_vg_expire_at` (stored datetime, end-of-day). Classifieds with no expire date sort **last** in either direction.
- Implementation: `manage_edit-vg_classified_sortable_columns`, `pre_get_posts`, and `posts_clauses` (LEFT JOIN on post meta) in `VG_Classifieds_Admin_Schedule`.
- **Scheduled** state for upcoming publishes comes from **WordPress core** (`future` posts).

---

## Scheduling & cron

### `VG_Classifieds_Scheduler`

- Recurring hook: `vg_classifieds_process_schedules` (default every **15 minutes**, filterable).
- **Expire due only:** `publish` posts with `_vg_expire_at` <= now → `draft`; meta retained.
- `do_action( 'vg_classifieds_expired_scheduled', $post_id )` after expire.
- Admin catch-up if last run > 2× interval (filterable).

Publish scheduling uses **WordPress core cron** (`publish_future_post`, etc.) — not this plugin.

---

## REST API

- `_vg_expire_at` exposed on `vg_classified` for editors (`auth_callback`).

---

## Implementation checklist

- [x] Bump `VG_CLASSIFIEDS_VERSION` and plugin header to `0.3.0`
- [x] Core publish scheduling (no `_vg_publish_at`, no plugin publish cron)
- [x] `register_post_meta` for `_vg_expire_at`
- [x] **Expiration** meta box + sortable list column
- [x] Expire-only `VG_Classifieds_Scheduler`
- [x] Legacy `_vg_publish_at` removal migration
- [x] Importer does not touch `_vg_expire_at`
- [x] `README.md`, `AGENTS.md` updated
- [ ] Automated tests; i18n `.pot` when present

---

## Test plan

1. Schedule Classified via sidebar **Publish** (future date) → status `future`, then `publish` when core cron runs.
2. Published Classified, **Expires on** today or future → after end of that day becomes `draft`; meta kept.
3. **Expires on** before post **publish date** → error, meta not saved.
4. Only core schedule, no expire → publishes on core schedule; never auto-expires.
5. Import ZIP → no `_vg_expire_at` added; existing expire meta unchanged on re-import.
6. Click **Expires on** column header → list sorts by expire date; click again toggles ASC/DESC; rows with no expire date stay at the bottom.
7. Low-traffic site: plugin cron expires on time; core cron publishes on time.

---

## Version history (plugin)

| Version | Highlights |
|---------|------------|
| 0.1.0 | ZIP import, `vg_classified` CPT, `vg_classified_category` taxonomy, importer meta |
| 0.3.0 | Core publish scheduling; **Expires on** date + expire cron; sortable list column; import leaves expire meta untouched |

---

## References

- `AGENTS.md`, `README.md`
- [Scheduling Posts](https://developer.wordpress.org/plugins/cron/), [register_post_meta](https://developer.wordpress.org/reference/functions/register_post_meta/)
