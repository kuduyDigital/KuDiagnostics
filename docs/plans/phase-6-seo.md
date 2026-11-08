# Phase 6 — SEO + media (plan)

Research: `RESEARCH.md` §9 (Rank Math 1.0.281, Yoast SEO 28.8, AIOSEO 5.0.5, SEOPress 10.4, WordPress 7.1.4 Abilities API; read 2026-11-05). Status: **plan approved 2026-11-05; D8 decided: option C (hybrid); D9 decided: option A (own jobs table + runner).**

Exit gate (`ROADMAP.md`): metadata written through KUDUY round-trips and is visible in the rendered `<head>`; with two SEO plugins active, writes are refused.

## Decision D8 — how KUDUY writes SEO data

All four plugins now register WordPress Abilities, but not evenly: AIOSEO and SEOPress have post write abilities that are always on (WordPress 6.9+); Rank Math has read abilities only; Yoast's read/write abilities exist only on production-mode sites (not on staging or local) and cannot set the focus keyphrase.

| Option | What it means | For | Against |
|---|---|---|---|
| A. Own adapters on each plugin's own save path | Yoast `WPSEO_Meta::set_value()`; Rank Math's own editor endpoint `rankmath/v1/updateMeta` called internally; AIOSEO `Models\Post::savePost()`; SEOPress's REST controllers | One approach for all; works on every environment | Calls into PHP classes for AIOSEO and SEOPress where a public ability already exists |
| B. Plugins' abilities only | `wp_get_ability()->execute()` for everything | Core public contract; plugins own validation and permissions | No Rank Math writes, no Yoast on staging/local, no Yoast/SEOPress keyword: fails the brief |
| **C. Hybrid (recommended)** | Abilities where they are complete and always on (**AIOSEO**, **SEOPress**; Rank Math reads); the plugin's own save path where they are missing or conditional (**Rank Math** writes through `updateMeta`, **Yoast** through `WPSEO_Meta` + Surfaces API for rendered values; SEOPress keyword through its REST controller) | Uses the plugin-maintained API wherever one exists; still covers every plugin on every environment | Two kinds of adapter to test |

Whichever option: one normalized field set for Claude (below); a field a plugin cannot store is refused with the plugin's name, never written to a guessed key.

## Decision D9 — background jobs

Long or bulk work (bulk SEO updates, thumbnail regeneration, alt text for many images) returns a `job_id` instead of running inside one MCP call.

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Own jobs table + Action Scheduler when present, WP-Cron otherwise (recommended)** | Table `kuduy_jobs` (DB version 2): type, owner user + OAuth client, scope, status, progress, items, per-item results, errors. Batches of items per run with a time budget. Runner = Action Scheduler (bundled by WooCommerce, Rank Math, AIOSEO) or a single WP-Cron event with a loopback kick | Works on every site; progress and partial failures are reportable; audit per item | Own table and runner to maintain |
| B. MCP Tasks extension | The protocol's own long-running task support | Native for clients | Not verified in any Claude client yet (rule 9); still needs a server-side runner |

Rules for A: a job runs as the user who started it; each item re-checks that user's capability and the snapshot/undo rules of the tool it wraps; the job stops when the connection is revoked or the user loses the capability; it is cancellable; results never include secrets (Redactor).

## Normalized SEO fields

`title`, `description`, `focus_keyword`, `additional_keywords`, `canonical`, `robots` (`noindex` true/false/null = default, `nofollow`, `noarchive`, `nosnippet`, `noimageindex`), `og` (`title`, `description`, `image`), `twitter` (`title`, `description`, `image`), `schema_type` (Yoast page/article type; Rank Math later), `cornerstone`. Each adapter declares which it supports; `kuduy-seo-status` reports them.

## Tools (module `src/Modules/Seo/`, `src/Modules/Media/`, `src/Jobs/`)

SEO (`edit_post` per post; plus the plugin's own SEO capability where it has one, e.g. `rank_math_onpage_advanced` for robots/canonical, `wpseo_edit_advanced_metadata` for Yoast advanced fields):
1. `kuduy-seo-status` (read) — active SEO plugin(s) and versions, conflict, supported fields, sitemap on/off and URL.
2. `kuduy-seo-get` (read) — one post: stored values (normalized) and the values the plugin renders (with defaults/templates applied).
3. `kuduy-seo-update` (write) — patch the normalized fields; refuses when no SEO plugin or **two or more** are active (names them); snapshot of the stored values first, undo with `kuduy-snapshot-restore`.
4. `kuduy-seo-verify` (read) — the post's rendered `<head>`: title, meta description, canonical, robots, OG/X tags; compares with expected values; live fetch for published posts, in-process otherwise.
5. `kuduy-seo-audit` (read) — posts of a type missing title/description/keyword, paged.
6. `kuduy-seo-bulk-update` (write) — many posts at once, as a job.

Media (`upload_files` to list; `edit_post` per attachment to change):
7. `kuduy-media-list` — filter by type, missing alt text, search, unattached; paged.
8. `kuduy-media-get` — metadata, sizes, alt/title/caption/description; with `include_image: true` a downscaled copy as MCP image content so Claude writes alt text from what the image shows.
9. `kuduy-media-update` — alt, title, caption, description; `decorative: true` sets an empty alt; snapshot + undo. Alt text is never invented without looking at the image (tool description says so).
10. `kuduy-media-usage` — where an image is used: post content (`wp-image-N`, URLs), featured image, Elementor data, WooCommerce gallery.
11. `kuduy-media-upload` — sideload from an HTTPS URL through the safe HTTP API; image MIME allow-list; size limit.
12. `kuduy-media-regenerate` — thumbnails for one image, or many as a job.

Jobs:
13. `kuduy-job-status` — progress and per-item results (owner or site manager).
14. `kuduy-job-cancel`.

Later in Phase 6 (after the above is tested): Rank Math schema and redirects (research the `updateSchemas` / `updateRedirection` routes in tests first), term SEO, replace-image (keeps the original, updates references), social images.

## Progress

- 2026-11-06: steps 1–3 done (resolver, `kuduy-seo-status` / `-get` / `-update`, Rank Math and Yoast adapters; S1–S4 in-process) and media tools 7–9 (S5, S6). Found in tests: Rank Math does not boot until its setup wizard is done or skipped; Yoast 28.8 needs WordPress 7.0 (6.9 is tested with 28.7). `kuduy-seo-verify`, audit and bulk tools come after the AIOSEO and SEOPress adapters.

- 2026-11-08: AIOSEO and SEOPress adapters, `kuduy-seo-verify`, `kuduy-media-usage` / `-upload` / `-regenerate`, jobs (D9: `kuduy_jobs`, `kuduy-job-start` / `-status` / `-cancel`). Change from the plan: instead of separate bulk tools (6 and the bulk part of 12), one `kuduy-job-start` runs `kuduy-seo-update`, `kuduy-media-update` or `kuduy-media-regenerate` over up to 500 items. `kuduy-seo-audit` (5) is not built yet; `kuduy-seo-get` per post and `kuduy-media-list` with `missing_alt` cover part of it.

## Order

1. Resolver + `kuduy-seo-status` (conflict detection; S1).
2. Rank Math adapter + `-get` / `-update` / `-verify` (S2, S4).
3. Yoast adapter (S3, S4).
4. AIOSEO, SEOPress adapters.
5. Media tools 7–11 (S5–S7).
6. Jobs (D9) + bulk tools 6, 12 (S8–S9).

## Tests

- Integration on Playground, WP 6.9 + 7.1, one run per SEO plugin (`KUDUY_PLUGINS=seo-by-rank-math@1.0.281`, `wordpress-seo@28.8`, `all-in-one-seo-pack@5.0.5`, `wp-seopress@10.4`) plus one run with Rank Math **and** Yoast active (conflict). Tests skip when their plugin is not active. CI gets these runs.
- Round trip per plugin: write through KUDUY → read back through the plugin's API → present in `wp_head` output. Permission tests: author vs editor vs plugin capabilities; other users' posts refused.
- Media and jobs run without SEO plugins.
- End-to-end with Claude on staging once the tools are released (image content display is UNVERIFIED until then).
