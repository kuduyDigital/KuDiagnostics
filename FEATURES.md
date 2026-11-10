# KUDUY MCP — Features

Status values: `Not started` · `In progress` · `Implemented` (code exists, tests incomplete) · `Tested` (passing tests or recorded manual evidence; IDs refer to `TESTING.md`) · `Blocked`.
Section numbers follow the KUDUY MCP Master Development Prompt. Updated 2026-11-05 (v0.4.0).

Summary: Foundation (brief §4, §12, §13) is built and tested; real Claude clients are partly verified (claude.ai works; Desktop, Claude Code's own sign-in, refresh and revoke still to observe — O11). **Phase 4 (WordPress core, §5) is complete:** content, terms, comments, revisions, custom fields, users and roles, menus, settings, plugins and themes, and diagnostics are `Tested`, with undo where possible and confirm-first where not. The few §5 items left (block-theme navigation, theme file inspection, media) belong to later phases. **Phase 5 (Elementor, §6)** passed its exit test with claude.ai on staging; **Phase 6 (SEO and image SEO, §7) is done** (2026-11-08, Playground and staging). Everything from §8 onward is `Not started`.

## Foundation — MCP and transport (§4)

| Feature | Status | Evidence / notes |
|---|---|---|
| Plugin bootstrap, activation, migrations, deactivation, uninstall | Tested | F1 |
| Native PHP only, no cloud service, no Node on host | Tested | `composer.json` has no runtime deps; `check:release` verifies no dev files ship |
| Streamable HTTP endpoint `/wp-json/kuduy-mcp/v1/mcp` | Tested | F3–F6, smoke |
| MCP 2026-07-28 (`server/discover`, header rules, version errors) | Tested | F3, F5 |
| MCP 2025-11-25 legacy (`initialize`, `ping`, no session) | Tested | F4 |
| Output guard against stray output from other plugins | Tested | F2 |
| Origin validation | Tested | F6 |
| Tool discovery filtered by availability, scope and capability | Tested | F7 |
| Tool execution, schema validation, structured errors | Tested | F8 |
| Output schema per tool (§14) | Tested | F13: every tool declares `outputSchema`; results validated against it |
| Background processing / jobs | Tested | `kuduy-job-start` / `-status` / `-cancel`; Action Scheduler or WP-Cron (D9); WP-Cron ran a job unforced on Hostinger staging (2026-11-08). Results name each item (`index`, `object_id`) |
| Real Claude clients: claude.ai | Tested | O11 — claude.ai custom connector on the user's Hostinger staging site (OAuth done by claude.ai), synced into a Claude Code desktop session: tools listed (4 on 0.1.2) and `kuduy-site-info` + `kuduy-check-update` called as user `admin`, on 0.1.2 and again on 0.2.0 (2026-11-05) |
| Real Claude clients: Claude Desktop, Claude Code's own sign-in (CIMD), refresh after 1 h, revoke | In progress | O11 — revoke **Tested** 2026-11-10 with claude.ai on staging (old connection refused, reconnect works); Desktop, Claude Code's own sign-in and refresh after 1 h still need the user's clients |

## Authentication and authorization (§12)

| Feature | Status | Evidence / notes |
|---|---|---|
| OAuth 2.1 code flow with PKCE S256 only | Tested | O5, O7 |
| Protected resource metadata (RFC 9728) + AS metadata (RFC 8414 / OIDC paths, root `.well-known`) | Tested | O1, O2, smoke |
| CIMD client registration (SSRF-safe fetch, cache) | Tested | O3 (mocked fetch); real Claude Code URL pending O11 |
| Dynamic Client Registration, bounded by unused clients | Tested | O4 + registration-cap test |
| Access/refresh tokens hashed, refresh rotation, family revoke on reuse | Tested | O7, O8 |
| Revocation (RFC 7009) and admin revoke under Tools > KUDUY MCP | Tested | O9, connections test |
| Token bound to the MCP route only | Tested | O12 (+ exploit probe over HTTP) |
| Scopes `read` ⊂ `write` ⊂ `admin`, `code` behind D4 constant; limited by user caps | Tested | O10 |
| Consent: unticked scopes not granted, nothing ticked → denied | Tested | O13 |
| Consent: site admins may opt in to `kuduy:admin` when the client did not ask (claude.ai asks for none); unticked by default | Tested | O14 |
| Sign-in detection (`initialize` → 401 + challenge) | Tested | O14; claude.ai re-check in O11 |
| Logged-out visitors cannot trigger outbound CIMD fetches | Tested | OAuth test `logged_out_visitors_cannot_make_the_site_fetch_a_cimd_url` |
| WordPress identity binding (`determine_current_user`) | Tested | O7, O12; also when another plugin checks the user on `plugins_loaded` (O18) |
| Per-tool capability checks | Tested | F7, F8 |
| Object-level authorization | Tested | `capability()` may return `[cap, object_id]`; `kuduy-post-get` checks `read_post` per post (C1, P1) |
| Rate limiting | Tested | F11 |
| Request validation, sanitization, escaping | Tested | F5, F8, PHPCS (WPCS) clean |
| HTTPS enforcement | Tested | O17: token, registration, authorize and bearer refused over plain HTTP unless the site is local (filter `kuduy_mcp_require_https`) |
| Safe uploads and paths | Tested (media) | `kuduy-media-upload`: HTTPS only, `wp_safe_remote_get`, content-checked raster images ≤ 10 MB; developer-mode paths: Phase 7 |
| Backup and recovery for destructive changes | Tested | F10, snapshot scope test |

## Data storage and audit (§13)

| Feature | Status | Evidence / notes |
|---|---|---|
| Custom tables only where justified (5 tables) | Tested | F1 |
| Token material hashed (SHA-256), no plaintext refresh tokens | Tested | O7 |
| Audit: actor, client, tool, result, error code, duration, snapshot | Tested | F9 |
| Audit: target object type/id | Tested | Filled from snapshots and by object tools (`kuduy-post-get` test) |
| Secrets redacted in results and audit rows | Tested | F9, P4 partial |
| Retention and cleanup (daily cron) | Tested | Safety tests |
| Multisite behaviour designed and tested | Not started | Per-site activation only today; see ARCHITECTURE §12 |

## Updates and release

| Feature | Status | Evidence / notes |
|---|---|---|
| GitHub self-update with SHA-256 check | Tested | G1, G2, G4; real update v0.1.0 → v0.1.1 (G3) |
| "Check again" bypasses the release cache | Tested | Updater test |
| Release workflow (CI → build → install check → publish) | Tested | v0.1.0, v0.1.1, v0.1.2 published |
| Plugin stays active after a normal WordPress update | Tested | G3 — Hostinger site went 0.1.2 → 0.2.0 through WordPress's own updater, stayed active, and the existing Claude connection kept working without signing in again |

## WordPress core administration (§5) — Phase 4

| Feature | Status |
|---|---|
| Site info tool (`kuduy-site-info`) | Tested (F8, smoke) |
| Posts and pages: list, search, read | Tested (C1: `kuduy-post-list`, `kuduy-post-get`) |
| Posts and pages: create, update, publish, schedule, unpublish | Tested (C1 writes: `kuduy-post-create`, `kuduy-post-update`) |
| Posts and pages: delete | Tested — trash only (`kuduy-post-trash`, undo restores the previous status); permanent deletion deliberately not offered |
| Drafts, excerpts, slugs, dates, parent, menu order, comment status | Tested (`kuduy-post-update` fields) |
| Revisions: list, restore (with undo) | Tested (C4: `kuduy-revision-list`, `kuduy-revision-restore`) |
| Comments: list, moderate, reply | Tested (C3: `kuduy-comment-list`, `kuduy-comment-moderate`, `kuduy-comment-reply`; emails/IPs only for moderators) |
| Categories, tags, custom taxonomies: list | Tested (C2: `kuduy-term-list`) |
| Categories, tags, custom taxonomies: assign to posts | Tested (`terms` on create/update; `assign_terms` checked) |
| Categories, tags, custom taxonomies: create, update, delete terms | Tested (C2: `kuduy-term-create`, `kuduy-term-update`, `kuduy-term-delete`; undo; default category protected) |
| Custom post types: discover and read (`kuduy-post-types`, list, get) | Tested (C2) |
| Custom post types: write | Tested (same create/update/trash tools; only types with an admin UI) |
| Featured images: set/remove | Tested (`featured_media`; must be an image attachment) |
| Post meta (protected keys blocked) | Tested (C4: `kuduy-post-meta-get`, `kuduy-post-meta-set`; `_` keys never read or written; undo) |
| Menus and navigation (classic menus) | Tested (C8: `kuduy-menu-list`, `-get`, `-create`, `-item-add`, `-item-update`, `-item-delete`, `-assign`; undo) |
| Block-theme navigation (`wp_navigation`) | Implemented 2026-11-10 (`kuduy-nav-menus`, `-nav-menu-get`, `-nav-menu-save`, `-nav-menu-trash`; links as a tree or block markup; undo; test in `SiteEditorTest`, run in the final round) |
| Block Editor content inspect/edit | Implemented (raw block markup via get/update; no block-level tools yet) |
| Elementor-built pages protected from content overwrite | Tested (update refuses `content` on `_elementor_edit_mode = builder`) |
| Users and roles: inspect, create, update; capabilities | Tested (C6: `kuduy-user-list`, `kuduy-user-get`, `kuduy-user-create`, `kuduy-user-update`, `kuduy-role-list`; own-role and last-admin guards; undo) |
| Delete users | Not started — deliberately not offered (content reassignment is a human decision in wp-admin) |
| Never return passwords, hashes, tokens | Tested (redactor F9; user tools never return hashes or activation keys and never set a known password) |
| Plugins/themes: list, versions, install, update, activate, deactivate (safety checks) | Tested (C7: `kuduy-plugin-list`/`-install`/`-activate`/`-deactivate`/`-update`, `kuduy-theme-list`/`-install`/`-switch`/`-update`; wordpress.org slugs only; deactivate, update and theme switch are confirm-first with an impact report; KUDUY never deactivates or updates itself; undo for activate/deactivate/switch) |
| Plugins/themes: delete | Not started — deliberately not offered |
| Conflict diagnosis (which plugin hooks what) | Tested (`kuduy-hooks-inspect`) |
| Theme file inspection | Not started — developer mode (Phase 7, D4) |
| Registered post types | Tested (`kuduy-post-types`) |
| Site settings (allow-list) | Tested (C8: `kuduy-settings-get`, `kuduy-settings-update`; URLs, admin email, permalinks, membership and default role read-only; undo) |
| Site Health, cron, rewrite rules, registered hooks/caps, cache diagnostics | Tested (`kuduy-site-health` in-process checks + environment incl. object cache and opcache, `kuduy-cron-list`, `kuduy-rewrite-check`, `kuduy-rewrite-flush`, `kuduy-hooks-inspect`; capabilities via `kuduy-user-get` / `kuduy-role-list`) |

## Elementor (§6) — Phase 5

| Feature | Status |
|---|---|
| Discover documents and templates; read structure and settings | Tested (EL1: `kuduy-elementor-documents`, `kuduy-elementor-get` incl. outline, subtree and unregistered types; Elementor 4.3.6) |
| Create and update documents via `Document::save()` | Tested (`kuduy-elementor-create`, `kuduy-elementor-edit`; snapshot + undo; unregistered-widget guard) |
| Duplicate whole documents | Not started |
| Theme Builder templates (Elementor Pro): create (`kuduy-elementor-create` + `template_type`), list (`kuduy-elementor-theme-templates`), display conditions with conflict check and undo (`kuduy-elementor-theme-conditions`) | Tested (EL8, Elementor Pro 4.3.2, local runs only) |
| Widget discovery v3 (`get_controls`) and v4 atomic (`get_props_schema`) | Tested (EL2: `kuduy-elementor-widgets`, `kuduy-elementor-widget-schema`; v3 style controls included, responsive keys per active device; v4 JSON Schema per prop) |
| Add, update, move, duplicate, reorder, remove elements; nested containers; ID/parent validation | Tested (EL3: 1–50 operations per call, all-or-nothing, fresh unique ids, nesting rules, settings checked against the widget schema) |
| Styling and responsive values (patch per breakpoint) | Tested (EL4: only named keys change; `{control}_{device}` keys; `unset`) |
| Kit globals: colors, typography, breakpoints (read) | Tested (EL7 read: `kuduy-elementor-kit`) |
| Kit globals: colors and fonts update | Tested (EL7: `kuduy-elementor-kit-update`, add/update/remove, undo) |
| v4 global classes and variables | Tested (EL11: `kuduy-elementor-v4-globals`, `-v4-classes`, `-v4-variables` run Elementor's own abilities on 4.3+; variables undoable, classes snapshotted as a copy; unavailable with a clear reason on 3.x) |
| CSS regeneration, cache clearing, frontend verification | Tested (EL6: `kuduy-elementor-verify` renders with Elementor's front-end renderer and, when published, fetches the live page; CSS is reset by `Document::save()` and rebuilt on the next view). Page-cache purging: Not started |
| Add-on widgets: Essential Addons 6.8.8, Ultimate Addons (header-footer-elementor) 2.9.6, Premium Addons 4.11.117 | Tested (EL9: discovery with plugin name, schema, create, edit, render through the generic tools; no special code needed) |
| Add-on widgets: JetElements | Blocked — paid plugin, not available for testing |
| Legacy / v3 / v4 / mixed-version matrix | In progress — Elementor 3.35.9 and 4.3.6 tested (all tools; v4-only tools skip on 3.x); legacy sections/columns covered by nesting rules; v3 → v4 migration not tested |

## SEO and image SEO (§7) — Phase 6

| Feature | Status |
|---|---|
| Active SEO plugin detection; conflict refusal | Tested (`kuduy-seo-status`; Rank Math + Yoast active → writes refused naming both, nothing changed; Rank Math not set up → refused with what to do) |
| Rank Math: title, description, keywords, canonical, robots, OG/Twitter | Tested (`kuduy-seo-get`, `kuduy-seo-update` through Rank Math's own `updateMeta`; WP 6.9 + 7.1) |
| Rank Math: schema, sitemap, redirects | Implemented 2026-11-10 (D25: `kuduy-seo-redirects`, `-redirect-save`, `-redirect-trash`, `-schema-get`, `-schema-save`, `-schema-delete`, `kuduy-seo-sitemap` for every SEO plugin; tests written, run in the final round) |
| Yoast: title, description, keyphrase, canonical, robots, social | Tested (`WPSEO_Meta` + Surfaces API; Yoast 28.8 on WP 7.1, 28.7 on 6.9) |
| Yoast: schema type, sitemap; related keyphrases (Premium) | Not started |
| AIOSEO and SEOPress adapters | Tested (AIOSEO through its abilities and own table; SEOPress through its abilities + target-keywords route; WP 6.9 + 7.1) |
| Bulk metadata inspect/update | Tested (`kuduy-seo-audit` lists posts missing title/description/keyword; `kuduy-job-start` with `kuduy-seo-update` for many posts) |
| Media: list, inspect, alt/title/caption/description | Tested (`kuduy-media-list`, `-get`, `-update`, undo) |
| Media: usage scan | Tested (`kuduy-media-usage`: featured, content, Elementor, Woo gallery, site icon/logo; Woo gallery and icon/logo paths not exercised by a test) |
| Thumbnail regeneration; upload from URL | Tested (`kuduy-media-regenerate`; `kuduy-media-upload`) |
| Social images, optimization via installed tools, replace image, safe rename | Replace image and safe rename implemented 2026-11-10 (D26: `kuduy-media-replace`, `kuduy-media-rename`; tests written, run in the final round). Social images: set through the SEO tools (`og.*`). Optimization via installed tools: not offered |
| Alt text from actual image content; decorative images | Tested — `kuduy-media-get` returns the image as MCP image content and `decorative` sets an empty alt (integration); claude.ai web shows the image to Claude, which wrote alt text from it (staging, 2026-11-08, S6 M) |
| SEO verification in rendered `<head>` and sitemap | Tested for `<head>` (`kuduy-seo-verify` parses the live page; each plugin's real head checked through it in tests; live self-fetch on Hostinger staging with Yoast 28.8, 2026-11-08, S4 E). `kuduy-seo-status` and `-verify` warn while search engines are discouraged. Sitemap: not verified |

## WooCommerce (§8) — Phase 7

| Feature | Status |
|---|---|
| Products, variations, attributes, categories, tags, images, prices, stock | Tested (W3–W5: `kuduy-woo-status`, `kuduy-woo-product-list` / `-get` / `-create` / `-update` / `-stock` / `-trash`, `kuduy-woo-variation-list` / `-create` / `-update`; HPOS on and off, WP 6.9 + 7.1; not yet on a real host) |
| Orders, items, status, notes (HPOS on/off) | Tested (W6–W8: `kuduy-woo-order-list` / `-get` / `-status` / `-note`; customer data masked by default, customer emails confirm-first, refunds refused; not yet on a real host) |
| Customers, coupons, reviews | Tested (W9–W11: 5 coupon, 3 customer, 3 review tools; customer data masked, addresses kept out of audit rows; not yet on a real host) |
| Shipping zones/methods, store settings, tax (read + safe writes) | Read tested (W12). Writes implemented 2026-11-10 (D24: `kuduy-woo-shipping-zone-save`, `-shipping-method-save`, `-tax-rate-save`, `-tax-rate-delete`; W15–W17 written, run in the final round). General store settings stay read only |
| Reports and diagnostics | Tested (W14: `kuduy-woo-report` over WooCommerce Analytics, customer data masked) |
| Gateway secrets and customer data protected; no refunds/charges without explicit policy | Tested (W2, W6, W10, W13: secrets masked by type and name, customer data masked unless allowed, no refund or charge tool; `kuduy-woo-payment-gateways` read only, admin scope) |
| Core post tools refuse WooCommerce objects (writes go through WooCommerce CRUD) | Tested (W1, `WooGuardsTest`, HPOS on and off) |

## Additional integrations (§9) — Phase 7

| Feature | Status |
|---|---|
| ACF field values (posts, users, terms) and field groups (read) | Tested (A1–A6: `kuduy-acf-status`, `-field-groups`, `-get`, `-update`; ACF 6.8.11 free, WP 6.9 + 7.1; on Hostinger staging with claude.ai 2026-11-09; PRO field types UNVERIFIED) |
| CPT and taxonomies (registering new ones) | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — (`kuduy-content-types`, `-content-type-save`, `-content-type-remove`; D22; tests T1–T3) |
| Fluent Forms, WPForms, Gravity Forms, Contact Form 7 | Tested 2026-11-10 in Playground (WP 6.9.11 + 7.1.4) for CF7 + Flamingo, Fluent Forms, WPForms Lite (forms only); Gravity Forms Blocked (needs a licensed copy) — (researched 2026-11-09, `RESEARCH.md` §10b; plan `docs/plans/phase-7-forms.md`, D15–D17 decided: A) |
| Navigation menus | Tested (classic menus, see §5) |
| Widgets and sidebars | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — (`kuduy-widgets`, `kuduy-widget-save`, `kuduy-widget-remove`; D18; S4) |
| Block Editor, Site Editor, Global Styles | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — (`kuduy-block-types`, `kuduy-post-blocks`, `kuduy-site-templates`, `-template-get`, `-template-update`, `-template-revert`, `kuduy-global-styles-get`, `-update`; D18; S1–S3, S5) |
| WP-Cron inspection | Tested (`kuduy-cron-list`, arguments never shown) |
| Cache and performance plugins | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — (`kuduy-cache-status`, `kuduy-cache-purge`: LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache; WP Rocket from docs, UNVERIFIED; D19; C1–C3) |
| WPML and Polylang | Polylang: Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 (`kuduy-lang-status`, `-get`, `-set`, `-link`, `-translate`; D20; L1–L4). WPML: Blocked (needs a licensed copy to test) |
| Clear "not installed / not supported" messages | Implemented (`is_available()` + executor message, F7); per-integration tests pending |

## Developer and advanced operations (§10) — Phase 7

| Feature | Status |
|---|---|
| Inspect approved files, search sources, inspect hooks | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — (`kuduy-dev-files`, `kuduy-dev-file-read`, `kuduy-dev-search`; hooks: `kuduy-hooks-inspect`; D21; D1–D2) |
| DB schema and capped read-only queries, secret columns masked | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — (`kuduy-dev-db-schema`, `kuduy-dev-db-query`; D3) |
| PHP error and conflict diagnosis; approved logs redacted | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — (`kuduy-dev-log`, masked; conflict diagnosis through `kuduy-site-health` and `kuduy-hooks-inspect`) |
| File create/edit in approved directories with snapshots | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — behind D4 (`kuduy-dev-file-write`; D4 tests) |
| Custom plugin/theme creation | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — behind D4 (`kuduy-dev-file-write` into a new plugin or theme folder, then `kuduy-plugin-activate` / `kuduy-theme-switch`) |
| WP-CLI availability + allow-listed commands | Not offered (D21: WP-CLI is not available inside web requests on most hosts, UNVERIFIED per host; `kuduy-dev-php` covers the same work behind D4) |
| Code execution behind D4 (constant + `kuduy:code` + caps, time limit, audit, recovery) | Tested 2026-11-10 in Playground, WP 6.9.11 + 7.1.4 — (`kuduy-dev-php`: time limit, output and errors captured, fatal errors audited; scope gating `Tested` in O10) |

## Hidden UI mode (§11) — Phase 8

| Feature | Status |
|---|---|
| Setup screen with connector instructions | Implemented (Tools > KUDUY MCP shows the URL, connections, recent calls; full setup guide pending) |
| Hide UI action | Implemented 2026-11-10 (D23; tests H1–H3 written, run in the final test round) |
| Hidden mode removes menu, pages, dashboard widgets, notices, admin-bar node, plugin-row links | Implemented 2026-11-10 (D23; tests H1–H3 written, run in the final test round) |
| Plugin stays in Installed Plugins | Tested (nothing hides it today; to re-test in hidden mode, H2) |
| MCP, OAuth, tools, jobs, audit keep working when hidden | Implemented 2026-11-10 (D23; tests H1–H3 written, run in the final test round) |
| Recovery: `wp option update kuduy_mcp_hidden_mode 0`, WP-CLI command, `KUDUY_MCP_SHOW_UI` constant | Implemented 2026-11-10 (D23; tests H1–H3 written, run in the final test round) |

## Release deliverables (§15 Phase 10)

| Feature | Status |
|---|---|
| Production ZIP | Tested (release workflow + `check:release`) |
| Installation guide, Claude connection guide, developer docs, tool reference | Implemented 2026-11-10 (`docs/INSTALL.md`, `docs/CONNECT-CLAUDE.md`, `docs/DEVELOPER.md`, `docs/TOOLS.md` generated by `npm run docs:tools`) |
| Compatibility, security, changelog docs | In progress (`COMPATIBILITY.md`, `SECURITY.md`, `CHANGELOG.md`) |
| Release checklist | Implemented 2026-11-10 (`docs/RELEASE-CHECKLIST.md`) |
