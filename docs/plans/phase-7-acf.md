# Phase 7b — Advanced Custom Fields (plan)

Research: `RESEARCH.md` §10a (ACF 6.8.11 source, read 2026-11-09; the version on the staging site). Status: **plan approved 2026-11-09; D14 decided (user): option A** — values through ACF's public API on fields shown on the object, definitions read only.

Exit gate (`ROADMAP.md`, ACF part): values written through KUDUY round-trip through ACF's own API on posts, users and terms; only fields that ACF shows on that object can be written; `password` fields never returned; tests pass on WordPress 6.9 and 7.1.

## Decision D14 — how KUDUY reads and writes ACF data

ACF 6.8 has its own abilities, but they are off by default (`enable_acf_ai`), and they manage *definitions* (field groups, ACF post types and taxonomies), not field values.

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Own tools on ACF's public API, definitions read only (recommended)** | Values: resolve the field with `acf_get_field( $key )`, check it belongs to a group shown on that object (`acf_get_field_groups()` with `post_id` / `user_id` / `taxonomy`), validate with `acf_validate_value()`, write with `update_field( $key, … )`. Definitions: read with `acf_get_field_groups()` / `acf_get_fields()`; creating or changing field groups is not offered yet | ACF's documented API; same rules as ACF's edit screen; no guessed meta keys and no "dummy field" writes | Changing field groups needs a later decision |
| B. A + definition writes through ACF's abilities when the site enabled them | As A, plus `acf/register-field-group`, `acf/register-custom-post-type`, `acf/register-custom-taxonomy` behind `kuduy:admin` when `enable_acf_ai` is on | Uses ACF's own validation for definitions | Two paths; unavailable on most sites (off by default) |
| C. Raw post meta | `update_post_meta( $id, $name, … )` | Simple | Skips ACF's reference keys, formatting and validation: against rule 4 of the brief |

## Tools (module `src/Modules/Acf/`, available only when ACF is active)

| # | Tool | Scope | Capability | Snapshot / undo |
|---|---|---|---|---|
| 1 | `kuduy-acf-status` — ACF version, free or PRO, abilities on/off, number of field groups (database and local JSON/PHP), field types available | read | `edit_posts` | — |
| 2 | `kuduy-acf-field-groups` — groups with title, key, active, location rules and fields (key, name, label, type, required, choices, default, instructions); filter by `post_id`, `post_type`, `user_id`, `taxonomy` | read | `edit_posts` | — |
| 3 | `kuduy-acf-get` — every ACF field shown on one post, user or term: raw value, formatted value, field type; `password` fields return `value: null, has_value` | read | `read_post` / `edit_user` / `edit_term` on that object | — |
| 4 | `kuduy-acf-update` — patch values by field key or name on one post, user or term; only fields of groups shown on that object; each value validated with `acf_validate_value()` before anything is written (all-or-nothing) | write | `edit_post` / `edit_user` / `edit_term` | previous raw values, restored with `update_field()` (or `delete_field()` when there was none) |

Jobs (D9): `kuduy-job-start` gains `kuduy-acf-update` (bulk field values, each item checked like a single call).

Guard in an existing tool: `kuduy-post-meta-set` refuses a key that is an ACF field name on that post and names `kuduy-acf-update` (a raw write would skip ACF's reference key and formatting).

Not offered (D14 A): creating or changing field groups, ACF post types or taxonomies; ACF PRO options pages and repeater / flexible content rows (UNVERIFIED, later with PRO).

## Redaction and personal data

- `password` field values are never returned (`value: null`, `has_value`), in `kuduy-acf-get` and in snapshots' result; audit rows of `kuduy-acf-update` mask values of `password` fields (`MasksAuditArgs`).
- ACF setting `google_api_key` is never returned by `kuduy-acf-status`.

## Tests (Playground; `KUDUY_PLUGINS=advanced-custom-fields@6.8.11`)

Field groups are registered in the test with ACF's PHP API (`acf_add_local_field_group()`), so no admin UI is needed.

- A1 status and field groups (filters by post type and by post).
- A2 get on a post, user and term; formatted and raw values; password masked.
- A3 update + undo on a post (text, number, select, true/false, relationship) and on a user and term.
- A4 refusals: a field not shown on that object, an unknown name (no dummy field written), a value ACF rejects (required empty, select option not in choices), other users' posts (author vs editor).
- A5 `kuduy-post-meta-set` refuses an ACF field name; non-ACF meta still works.
- A6 bulk update as a job.
- CI: one ACF job on each WordPress pair.

Then a staging run with claude.ai (staging has ACF 6.8.11 active).

## Progress

- 2026-11-09: steps 1–3 done — `kuduy-acf-status`, `-field-groups`, `-get`, `-update`, guard in `kuduy-post-meta-set`, jobs, CI (A1–A6). Found in tests: ACF 6.8.11 already denies `edit_post_meta` on its field keys (`acf_map_post_meta_caps`), so the guard checks `edit_post` for those keys and refuses in the tool with a clear message; ACF's select field has no validator, so KUDUY checks choices itself. Left: release and staging check (staging has ACF 6.8.11).

## Order

1. Status, field groups (A1).
2. Get (A2), update + undo (A3, A4).
3. Guard (A5), jobs (A6).
4. Release, staging check.
