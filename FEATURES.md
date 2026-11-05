# KUDUY MCP — Features

Status values: `Not started` · `In progress` · `Implemented` (code exists, tests incomplete) · `Tested` (passing tests or recorded manual evidence; IDs refer to `TESTING.md`) · `Blocked`.
Section numbers follow the KUDUY MCP Master Development Prompt. Updated 2026-11-05 (v0.1.2).

Summary: Foundation (brief §4, §12, §13) is built and tested except real Claude clients. Everything from §5 onward is `Not started`.

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
| Output schema per tool (§14) | Not started | Input schemas exist; `outputSchema` not yet declared |
| Background processing / jobs | Not started | ARCHITECTURE §12 |
| Real Claude clients (claude.ai, Desktop, Claude Code) | In progress | O11 — endpoints checked from outside on the user's Hostinger site; real sign-in not yet confirmed |

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
| WordPress identity binding (`determine_current_user`) | Tested | O7, O12 |
| Per-tool capability checks | Tested | F7, F8 |
| Object-level authorization | In progress | Tool contract passes input to `capability()`; object guards come with Phase 4 tools |
| Rate limiting | Tested | F11 |
| Request validation, sanitization, escaping | Tested | F5, F8, PHPCS (WPCS) clean |
| HTTPS enforcement | Not started | Endpoints work over http on local dev; plan: refuse OAuth on non-HTTPS except loopback/local environment |
| Safe uploads and paths | Not started | Comes with media and developer tools |
| Backup and recovery for destructive changes | Tested | F10, snapshot scope test |

## Data storage and audit (§13)

| Feature | Status | Evidence / notes |
|---|---|---|
| Custom tables only where justified (5 tables) | Tested | F1 |
| Token material hashed (SHA-256), no plaintext refresh tokens | Tested | O7 |
| Audit: actor, client, tool, result, error code, duration, snapshot | Tested | F9 |
| Audit: target object type/id | Implemented | Columns exist; filled by object tools from Phase 4 |
| Secrets redacted in results and audit rows | Tested | F9, P4 partial |
| Retention and cleanup (daily cron) | Tested | Safety tests |
| Multisite behaviour designed and tested | Not started | Per-site activation only today; see ARCHITECTURE §12 |

## Updates and release

| Feature | Status | Evidence / notes |
|---|---|---|
| GitHub self-update with SHA-256 check | Tested | G1, G2, G4; real update v0.1.0 → v0.1.1 (G3) |
| "Check again" bypasses the release cache | Tested | Updater test |
| Release workflow (CI → build → install check → publish) | Tested | v0.1.0, v0.1.1, v0.1.2 published |
| Plugin stays active after Plugins-screen / auto-update | Not started | Programmatic upgrade left it inactive; to observe |

## WordPress core administration (§5) — Phase 4

| Feature | Status |
|---|---|
| Site info tool (`kuduy-site-info`) | Tested (F8, smoke) |
| Posts and pages: list, search, read, create, update, publish, schedule, delete | Not started |
| Drafts, revisions, comments, excerpts, slugs, permalinks | Not started |
| Categories, tags, custom taxonomies | Not started |
| Custom post types | Not started |
| Post meta (protected keys blocked), featured images | Not started |
| Menus and navigation | Not started |
| Block Editor content inspect/edit | Not started |
| Users and roles: inspect, create, update; capabilities | Not started |
| Never return passwords, hashes, tokens | Tested (redactor, F9); per-tool tests come with user tools |
| Plugins/themes: list, versions, install, update, activate, deactivate (safety checks) | Not started |
| Theme file inspection, conflict diagnosis | Not started |
| Site settings (allow-list), Site Health, cron, rewrite rules, registered types/hooks/caps, cache diagnostics | Not started |

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
| Navigation menus, widgets and sidebars | Not started |
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
