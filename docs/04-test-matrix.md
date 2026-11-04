# KUDUY MCP — Feature Test Matrix

Legend: **U** unit (PHPUnit, no WP), **I** integration (WP test suite / Playground), **E** end-to-end with a real Claude client against staging, **M** manual check. Status for every row starts at `not run`.

## Foundation

| # | Feature | Tests | Type |
|---|---|---|---|
| F1 | Activation / deactivation / uninstall | tables created/upgraded; cron cleared on deactivate; uninstall drops tables + options only when `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL` | I — **pass 2026-11-04** (`tests/integration/LifecycleTest.php`, WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36) except "cron cleared on deactivate": not run, no cron hooks until audit retention (Phase 3 step 4) |
| F2 | Output guard | stray `echo` from another plugin on `init` does not break JSON | I |
| F3 | `server/discover` (2026-07-28) | returns supported versions + capabilities | I |
| F4 | Legacy `initialize` (2025-11-25) | handshake + session + `tools/list` | I |
| F5 | Header validation | missing `Mcp-Method` / mismatched `Mcp-Name` → 400 `-32020`; bad version → 400 `-32022` | I |
| F6 | Origin validation | foreign Origin → 403 | I |
| F7 | `tools/list` | names valid, schemas valid JSON Schema, unavailable integrations hidden | U, I |
| F8 | `tools/call` | input validation errors are tool errors, fatals become JSON-RPC errors | I |
| F9 | Audit log | every call logged; secrets redacted; retention purge | U, I |
| F10 | Snapshots | destructive tool writes snapshot; restore works | I |
| F11 | Rate limit | burst over limit → 429 with retry hint | I |

## OAuth

| # | Feature | Tests | Type |
|---|---|---|---|
| O1 | 401 challenge | `WWW-Authenticate` has `resource_metadata` + `scope` | I |
| O2 | Metadata | RFC 9728 + 8414 at `.well-known` and alias paths; `resource` equals MCP URL exactly | I |
| O3 | CIMD | Claude Code metadata URL fetched, cached, redirect URIs enforced; SSRF guard (HTTPS only, no private IPs, size/time caps) | U, I |
| O4 | DCR | register returns client; invalid redirect rejected | I |
| O5 | Authorize | S256 required; plain rejected; consent shows redirect host; login redirect; `iss` returned | I, M |
| O6 | Loopback | `localhost` and `127.0.0.1` matched on any port | U |
| O7 | Token | code single-use; verifier mismatch → `invalid_grant`; `resource`/audience bound | I |
| O8 | Refresh | rotation; reuse of old refresh revokes family | I |
| O9 | Revoke | RFC 7009; revoked token → 401 | I |
| O10 | Scopes | token without `kuduy:write` calling a write tool → 403 `insufficient_scope` | I |
| O11 | Real clients | connect + call from claude.ai, Desktop, Claude Code | E |

## Permissions

| # | Tests | Type |
|---|---|---|
| P1 | Author can edit own post, not others' | I |
| P2 | Editor cannot install plugins | I |
| P3 | Subscriber token sees only read tools it can use | I |
| P4 | No response or log contains wp-config secrets, password hashes, app passwords, gateway keys | U, I |

## Core WordPress

| # | Feature | Type |
|---|---|---|
| C1 | Posts/pages CRUD, status, schedule, search, count | I |
| C2 | CPT + custom taxonomies, terms CRUD | I |
| C3 | Comments moderate/reply | I |
| C4 | Post meta (protected keys blocked), revisions list/restore | I |
| C5 | Featured image set/unset | I |
| C6 | Users/roles create/update (no password echo) | I |
| C7 | Plugins/themes list/install/update/activate; essential-plugin guard | I |
| C8 | Menus, widgets, permalinks, site health | I |

## Elementor

| # | Feature | Type |
|---|---|---|
| EL1 | List documents/templates, read tree (v3 sections/columns, containers, atomic) | I |
| EL2 | Widget schema discovery v3 (`get_controls`) and atomic (`get_props_schema`) | I |
| EL3 | Add / update / move / duplicate / remove element; IDs unique | I |
| EL4 | Patch edit on desktop does not change tablet/mobile values | I |
| EL5 | Save via `Document::save`; revision created; non-`unfiltered_html` user gets kses | I |
| EL6 | Post CSS regenerated; frontend HTML contains edited element ID and text | I, E |
| EL7 | Kit global colors/typography/breakpoints read + update | I |
| EL8 | Theme Builder templates list/conditions (Pro) | I |
| EL9 | Addon adapters (EA, UAE, Premium, Jet) widget discovery | I |
| EL10 | Build a full page from a prompt on 3.x and 4.3 | E |

## SEO + Image SEO

| # | Feature | Type |
|---|---|---|
| S1 | Resolver picks the single active plugin; two active → refuses writes | I |
| S2 | Rank Math: title, description, focus + extra keywords, canonical, robots, OG/Twitter, schema, redirects | I |
| S3 | Yoast: title, metadesc, focus keyphrase, canonical, robots, social, schema | I |
| S4 | Output appears in rendered `<head>` | I, E |
| S5 | Media list, dimensions, metadata; alt/title/caption/description updates | I |
| S6 | Decorative flag → empty alt; no invented alt text | U, M |
| S7 | Image usage scan (content, Elementor, featured, Woo gallery) | I |
| S8 | Replace image keeps original, updates references; thumbnail regen job | I |
| S9 | Bulk operations run as jobs with progress | I |

## WooCommerce

| # | Feature | Type |
|---|---|---|
| W1 | Products simple/variable CRUD, attributes, stock, prices, images | I |
| W2 | Orders list/read/status/notes on HPOS on and off | I |
| W3 | Coupons, customers, reviews, shipping zones/methods, tax read | I |
| W4 | Gateway settings masked; no refunds or charges possible | I |
| W5 | Sales report numbers match WooCommerce analytics | I |

## Advanced + Developer mode

| # | Feature | Type |
|---|---|---|
| A1 | ACF field groups/values, CPT UI, forms (each plugin) detected or clear "not installed" | I |
| A2 | Blocks / site editor / global styles read + update | I |
| A3 | Cron list/run, cache + perf diagnostics | I |
| D1 | File read blocked for `wp-config.php` and outside allowed roots, incl. `../` and symlinks | U, I |
| D2 | DB read-only, prepared, capped; secret columns masked | I |
| D3 | Code exec unavailable without constant + scope; available and audited with both | I |
| D4 | Sandbox fatal → crash marker → safe mode next request | I |

## Hidden mode, updater, lifecycle

| # | Feature | Type |
|---|---|---|
| H1 | Hide UI removes menu, pages, widgets, notices, admin-bar node, row links | I, M |
| H2 | Plugin row still visible in Plugins list | I |
| H3 | Tool list, OAuth, jobs, audit identical with UI hidden | I |
| H4 | `wp kuduy ui show` and `KUDUY_MCP_SHOW_UI` restore UI | I |
| G1 | Updater detects newer GitHub release, shows in Updates | I |
| G2 | Checksum mismatch aborts install | I |
| G3 | Update from release ZIP keeps data and settings | I, E |
| G4 | GitHub API failure / rate limit → no error spam, cached result | U |
