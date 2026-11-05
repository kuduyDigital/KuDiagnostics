# KUDUY MCP — Features

Status values: `Not started` · `In progress` · `Implemented` (code exists, tests incomplete) · `Tested` (passing tests or recorded manual evidence; IDs refer to `TESTING.md`) · `Blocked`.
Section numbers follow the KUDUY MCP Master Development Prompt. Updated 2026-11-05 (v0.2.0).

Summary: Foundation (brief §4, §12, §13) is built and tested; real Claude clients are partly verified (claude.ai works; Desktop, Claude Code's own sign-in, refresh and revoke still to observe — O11). Phase 4 in progress: read tools for post types, posts and terms, post create/update/trash, terms, comments, revisions and custom fields, users and roles, classic menus, allow-listed site settings, plugins and themes (with undo where it is possible, confirm-first where it is not) are `Tested`; everything else from §5 onward is `Not started`.

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
| Background processing / jobs | Not started | ARCHITECTURE §12 |
| Real Claude clients: claude.ai | Tested | O11 — claude.ai custom connector on the user's Hostinger staging site (OAuth done by claude.ai), synced into a Claude Code desktop session: tools listed (4 on 0.1.2) and `kuduy-site-info` + `kuduy-check-update` called as user `admin`, on 0.1.2 and again on 0.2.0 (2026-11-05) |
| Real Claude clients: Claude Desktop, Claude Code's own sign-in (CIMD), refresh after 1 h, revoke | In progress | O11 — need the user's clients and a Revoke click |

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
| Sign-in detection (`initialize` → 401 + challenge) | Tested | O14; claude.ai re-check in O11 |
| Logged-out visitors cannot trigger outbound CIMD fetches | Tested | OAuth test `logged_out_visitors_cannot_make_the_site_fetch_a_cimd_url` |
| WordPress identity binding (`determine_current_user`) | Tested | O7, O12; also when another plugin checks the user on `plugins_loaded` (O18) |
| Per-tool capability checks | Tested | F7, F8 |
| Object-level authorization | Tested | `capability()` may return `[cap, object_id]`; `kuduy-post-get` checks `read_post` per post (C1, P1) |
| Rate limiting | Tested | F11 |
| Request validation, sanitization, escaping | Tested | F5, F8, PHPCS (WPCS) clean |
| HTTPS enforcement | Tested | O17: token, registration, authorize and bearer refused over plain HTTP unless the site is local (filter `kuduy_mcp_require_https`) |
| Safe uploads and paths | Not started | Comes with media and developer tools |
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
| Block-theme navigation (`wp_navigation`) | Not started |
| Block Editor content inspect/edit | Implemented (raw block markup via get/update; no block-level tools yet) |
| Elementor-built pages protected from content overwrite | Tested (update refuses `content` on `_elementor_edit_mode = builder`) |
| Users and roles: inspect, create, update; capabilities | Tested (C6: `kuduy-user-list`, `kuduy-user-get`, `kuduy-user-create`, `kuduy-user-update`, `kuduy-role-list`; own-role and last-admin guards; undo) |
| Delete users | Not started — deliberately not offered (content reassignment is a human decision in wp-admin) |
| Never return passwords, hashes, tokens | Tested (redactor F9; user tools never return hashes or activation keys and never set a known password) |
| Plugins/themes: list, versions, install, update, activate, deactivate (safety checks) | Tested (C7: `kuduy-plugin-list`/`-install`/`-activate`/`-deactivate`/`-update`, `kuduy-theme-list`/`-install`/`-switch`/`-update`; wordpress.org slugs only; deactivate, update and theme switch are confirm-first with an impact report; KUDUY never deactivates or updates itself; undo for activate/deactivate/switch) |
| Plugins/themes: delete | Not started — deliberately not offered |
| Theme file inspection, conflict diagnosis | Not started |
| Registered post types | Tested (`kuduy-post-types`) |
| Site settings (allow-list) | Tested (C8: `kuduy-settings-get`, `kuduy-settings-update`; URLs, admin email, permalinks, membership and default role read-only; undo) |
| Site Health, cron, rewrite rules, registered hooks/caps, cache diagnostics | Not started |

## Elementor (§6) — Phase 5

| Feature | Status |
|---|---|
| Discover documents and templates; read structure and settings | Not started |
| Create, duplicate, update documents via `Document::save()` | Not started |
| Theme Builder templates | Not started |
| Widget discovery v3 (`get_controls`) and v4 atomic (`get_props_schema`) | Not started |
| Add, update, move, duplicate, reorder, remove elements; nested containers; ID/parent validation | Not started |
| Styling and responsive values (patch per breakpoint) | Not started |
| Kit globals: colors, typography, breakpoints, classes, variables | Not started |
| CSS regeneration, cache clearing, frontend verification | Not started |
| Add-on adapters (Essential Addons, UAE, Premium Addons, JetElements) | Not started |
| Legacy / v3 / v4 / mixed-version matrix | Not started |

## SEO and image SEO (§7) — Phase 6

| Feature | Status |
|---|---|
| Active SEO plugin detection; conflict refusal | Not started |
| Rank Math: title, description, keywords, canonical, robots, OG/Twitter, schema, sitemap, redirects | Not started |
| Yoast: title, description, keyphrase, canonical, robots, social, schema, sitemap | Not started |
| AIOSEO and SEOPress adapters | Not started |
| Bulk metadata inspect/update | Not started |
| Media: list, inspect, alt/title/caption/description, featured image, usage scan | Not started |
| Social images, optimization via installed tools, thumbnail regeneration, safe rename | Not started |
| Alt text from actual image content; decorative images | Not started |
| SEO verification in rendered `<head>` and sitemap | Not started |

## WooCommerce (§8) — Phase 7

| Feature | Status |
|---|---|
| Products, variations, attributes, categories, tags, images, prices, stock | Not started |
| Orders, items, status, notes (HPOS on/off) | Not started |
| Customers, coupons, reviews | Not started |
| Shipping zones/methods, store settings, tax (read + safe writes) | Not started |
| Reports and diagnostics | Not started |
| Gateway secrets and customer data protected; no refunds/charges without explicit policy | Not started |

## Additional integrations (§9) — Phase 7

| Feature | Status |
|---|---|
| ACF; CPT and taxonomies | Not started |
| Fluent Forms, WPForms, Gravity Forms, Contact Form 7 | Not started |
| Navigation menus | Tested (classic menus, see §5) |
| Widgets and sidebars | Not started |
| Block Editor, Site Editor, Global Styles | Not started |
| WP-Cron; cache and performance plugins | Not started |
| WPML and Polylang | Not started |
| Clear "not installed / not supported" messages | Implemented (`is_available()` + executor message, F7); per-integration tests pending |

## Developer and advanced operations (§10) — Phase 7

| Feature | Status |
|---|---|
| Inspect approved files, search sources, inspect hooks | Not started |
| DB schema and capped read-only queries, secret columns masked | Not started |
| PHP error and conflict diagnosis; approved logs redacted | Not started |
| File create/edit in approved directories with snapshots | Not started |
| Custom plugin/theme creation | Not started |
| WP-CLI availability + allow-listed commands | Not started |
| Code execution behind D4 (constant + `kuduy:code` + caps, time limit, audit, recovery) | Not started (scope gating `Tested` in O10) |

## Hidden UI mode (§11) — Phase 8

| Feature | Status |
|---|---|
| Setup screen with connector instructions | Implemented (Tools > KUDUY MCP shows the URL, connections, recent calls; full setup guide pending) |
| Hide UI action | Not started |
| Hidden mode removes menu, pages, dashboard widgets, notices, admin-bar node, plugin-row links | Not started |
| Plugin stays in Installed Plugins | Tested (nothing hides it today; to re-test in hidden mode, H2) |
| MCP, OAuth, tools, jobs, audit keep working when hidden | Not started |
| Recovery: `wp option update kuduy_mcp_hidden_mode 0`, WP-CLI command, `KUDUY_MCP_SHOW_UI` constant | Not started |

## Release deliverables (§15 Phase 10)

| Feature | Status |
|---|---|
| Production ZIP | Tested (release workflow + `check:release`) |
| Installation guide, Claude connection guide, developer docs, tool reference | Not started |
| Compatibility, security, changelog docs | In progress (`COMPATIBILITY.md`, `SECURITY.md`, `CHANGELOG.md`) |
| Release checklist | Not started |
