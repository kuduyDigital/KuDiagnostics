# KUDUY MCP — Testing

A PHP function existing is not proof that a feature works. A row is `pass` only with a passing automated test or recorded manual evidence, with the date and versions. Rows without a result have **not been run**.

## 1. Strategy

| Layer | Tool | What it proves | Where |
|---|---|---|---|
| Syntax | `php -l` on PHP 8.1, 8.2, 8.3, 8.4 | Code parses on every supported PHP | CI |
| Coding standards | PHPCS with WordPress Coding Standards 3.4.1 | Escaping, sanitizing, nonces, prepared SQL, i18n | Local + CI |
| Static analysis | PHPStan 2.3.5 level 6 + phpstan-wordpress | Types and WordPress API misuse | Local + CI |
| Integration | PHPUnit 10.5 **inside WordPress Playground** (`scripts/test-integration.mjs`) | Real WordPress: REST dispatch, OAuth, DB tables, cron, updater hooks. Pass/fail read from the JUnit report; the WP/PHP versions actually used are printed | Local + CI: WP 6.9 / PHP 8.1 and WP 7.1 / PHP 8.3 |
| Real HTTP smoke | `scripts/smoke-mcp.mjs` | The endpoint over real HTTP with Elementor + WooCommerce active and a noisy mu-plugin; the whole OAuth sign-in with a real `wp-login.php` login | Local + CI |
| Release | `scripts/build-release.php` + `scripts/check-release.mjs` | The ZIP installs as an upload, activates, creates tables and routes, ships no dev files | CI on every push and before every release |
| Real update | `scripts/check-update.mjs <from> <to>` | Install an older GitHub release, let core find and install the newer one, data kept | Manual |
| Security probe | Throwaway local site + scripted HTTP requests (kept out of the repo) | Confirms or rules out exploit paths found in reviews | Manual, per audit |
| End to end | Real Claude clients against a public HTTPS staging site | claude.ai, Desktop, Claude Code sign in and call tools | Manual (O11) |

Process: test first (red), then code (green); every new security fix gets a regression test; manual checks are written down here with the date.

## 2. Commands

See `CLAUDE.md` → Commands. Integration: `KUDUY_WP=<6.9|7.1> KUDUY_PHP=<8.1|8.3> node scripts/test-integration.mjs`. Smoke: `node scripts/smoke-mcp.mjs`.

## 3. Latest results (2026-11-05, v0.2.0)

| Check | Result |
|---|---|
| PHPCS (WPCS) | 0 errors, 0 warnings |
| PHPStan level 6 | No errors |
| `composer audit` | No advisories |
| `npm audit` | 3 moderate in `@wp-playground/cli` dependencies (dev only, not shipped) |
| Integration, WordPress 6.9.11 / PHP 8.1.34 | **103 / 103 pass** |
| Integration, WordPress 7.1.4 / PHP 8.3.36 | **103 / 103 pass** |
| Smoke over real HTTP (WP 7.1.4 / PHP 8.3, Elementor 4.3.6 + WooCommerce 11.2.1 active) | **26 / 26 pass** |
| Security probe (read-only token on core routes, `rest_route` override, PATH_INFO, unticked consent, unauthenticated `initialize`, root AS metadata) | All refused / as expected on 0.1.2 |
| GitHub CI on `3591d92` | All 9 jobs green; release workflow for `v0.1.2` green |
| Real update v0.1.0 → v0.1.1 | Pass (2026-11-05) |
| Real Claude clients (O11) | **claude.ai: pass** (Hostinger, 0.1.2 and 0.2.0). Desktop, Claude Code's own sign-in, refresh, revoke: not yet run |
| Real host after release (Hostinger, 0.2.0, from outside) | Unauthenticated `initialize` → 401 + challenge; root AS metadata 200; token with `rest_route` override → 401; plain HTTP → 301 to HTTPS |

## 4. Feature test matrix

Legend: **U** unit, **I** integration (Playground), **E** end-to-end with a real Claude client, **M** manual check. Feature status per row lives in `FEATURES.md`.

## Foundation

| # | Feature | Tests | Type |
|---|---|---|---|
| F1 | Activation / deactivation / uninstall | tables created/upgraded; cron cleared on deactivate; uninstall drops tables + options only when `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL` | I — **pass 2026-11-04** (`tests/integration/LifecycleTest.php`, WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36) and "cron cleared on deactivate" **pass 2026-11-05** (`SafetyTest`) |
| F2 | Output guard | stray `echo` from another plugin on `init` does not break JSON | I — **pass 2026-11-04** (`npm run smoke:mcp`, real HTTP, mu-plugin echoes + `E_USER_NOTICE` with `WP_DEBUG_DISPLAY`) |
| F3 | `server/discover` (2026-07-28) | returns supported versions + capabilities | I — **pass 2026-11-04** |
| F4 | Legacy `initialize` (2025-11-25) | handshake + `tools/list` (no `Mcp-Session-Id`: sessions are optional in 2025-11-25) | I — **pass 2026-11-04** (also over real HTTP) |
| F5 | Header validation | missing `Mcp-Method` / mismatched `Mcp-Name` → 400 `-32020`; bad version → 400 `-32022` | I — **pass 2026-11-04** (incl. base64 `Mcp-Name`) |
| F6 | Origin validation | foreign Origin → 403 | I — **pass 2026-11-04** |
| F7 | `tools/list` | names valid, schemas valid JSON Schema, unavailable integrations hidden | U, I — **pass 2026-11-04** (I only; filtered by capability) |
| F8 | `tools/call` | input validation errors and exceptions are tool errors (`isError: true`, per MCP); exception internals never returned | I — **pass 2026-11-04** |
| F9 | Audit log | every call logged; secrets redacted; retention purge | I — **pass 2026-11-05** (`SafetyTest`: ok/denied/error rows, redacted args and results, 90-day purge) |
| F10 | Snapshots | destructive tool writes snapshot; restore works | I — **pass 2026-11-05** (restores once; others cannot restore) |
| F11 | Rate limit | burst over limit → 429 with retry hint | I — **pass 2026-11-05** (`Retry-After`) |
| F13 | Output schemas | every listed tool has an object `outputSchema`; `structuredContent` of each core tool validates against it | I — **pass 2026-11-05** |
| F12 | Undo needs the original grant | write-scope token cannot restore an admin-scope tool's snapshot; admin-scope token can | I — **pass 2026-11-05** (0.1.2) |

## OAuth

| # | Feature | Tests | Type |
|---|---|---|---|
| O1 | 401 challenge | `WWW-Authenticate` has `resource_metadata` + `scope` | I — **pass 2026-11-05** (also real HTTP) |
| O2 | Metadata | RFC 9728 + 8414 at `.well-known` and alias paths; `resource` equals MCP URL exactly | I — **pass 2026-11-05** (root `.well-known` + REST `{issuer}/.well-known/openid-configuration` over real HTTP) |
| O3 | CIMD | Claude Code metadata URL fetched, cached, redirect URIs enforced; SSRF guard (HTTPS only, no private IPs, size/time caps) | I — **pass 2026-11-05** with a mocked fetch (`wp_safe_remote_get`, no redirects, 64 KB, 5 s, `client_id` must equal URL). Real Claude Code URL: in O11 |
| O4 | DCR | register returns client; invalid redirect rejected | I — **pass 2026-11-05** |
| O5 | Authorize | S256 required; plain rejected; consent shows redirect host; login redirect; `iss` returned | I, M — **pass 2026-11-05** (I + real `wp-login.php` login and consent form over HTTP; visual check still manual) |
| O6 | Loopback | `localhost` and `127.0.0.1` matched on any port | I — **pass 2026-11-05** |
| O7 | Token | code single-use; verifier mismatch → `invalid_grant`; `resource`/audience bound | I — **pass 2026-11-05** (code reuse also revokes its tokens; raw tokens never stored) |
| O8 | Refresh | rotation; reuse of old refresh revokes family | I — **pass 2026-11-05** (also real HTTP) |
| O9 | Revoke | RFC 7009; revoked token → 401 | I — **pass 2026-11-05** |
| O10 | Scopes | token without `kuduy:write` calling a write tool → 403 `insufficient_scope` | I — **pass 2026-11-05** (hierarchy write ⊃ read; consent limited by caps) |
| O11 | Real clients | connect + call from claude.ai, Desktop, Claude Code; refresh after 1 h; revoke | E — **claude.ai pass 2026-11-05**: claude.ai custom connector on the user's Hostinger staging site (OAuth done by claude.ai), synced into a Claude Code desktop session: tools listed (4 on 0.1.2) and `kuduy-site-info` + `kuduy-check-update` called as user `admin`, on 0.1.2 and again on 0.2.0. Before: v0.1.1 was detected as "No sign-in" → "Couldn't reach" (fixed in 0.1.2, O14). **Still open:** Claude Desktop, Claude Code's own sign-in (`claude mcp add` + CIMD), refresh after 1 h, revoke from Tools > KUDUY MCP |
| O12 | Token bound to the MCP route | token refused on core routes, with `rest_route` in query or body, and on PATH_INFO tails; plain-permalink `?rest_route=/kuduy-mcp/v1/mcp` still works | I, M — **pass 2026-11-05** (0.1.2; exploit probe over real HTTP: all 401) |
| O13 | Consent with nothing ticked | approve with every box unticked → `access_denied`, no code | I — **pass 2026-11-05** (0.1.2) |
| O14 | Sign-in detection | unauthenticated `initialize` → 401 + challenge, so claude.ai detects OAuth | I — **pass 2026-11-05** (0.1.2, also real HTTP); claude.ai check in O11 |
| O15 | Registration cap | unused DCR clients capped; at cap the oldest unused (> 1 h) is evicted, else 429; not per IP | I — **pass 2026-11-05** (0.1.2) |
| O17 | HTTPS required | with HTTPS required (non-local site): token, registration and authorize refused; bearer over plain HTTP gives no user and a 401 mentioning HTTPS; local sites allowed | I — **pass 2026-11-05** |
| O18 | Early user check | a bearer token authenticates even before WordPress creates `$wp_rewrite` (another plugin calling `wp_get_current_user()` on `plugins_loaded`). Before the fix, `rest_url()` there threw `Call to a member function using_index_permalinks() on null` (reproduced on WP 7.1.4) | I — **pass 2026-11-05** |
| O16 | No anonymous outbound fetch | logged-out authorize request with a CIMD `client_id` → login redirect, zero HTTP requests | I — **pass 2026-11-05** (0.1.2) |

## Permissions

| # | Tests | Type |
|---|---|---|
| P1 | Author can edit own post, not others' | I — **pass 2026-11-05** (author cannot list, read or update other people's drafts/posts; contributor cannot trash others' posts) |
| P2 | Editor cannot install plugins | I |
| P3 | Subscriber token sees only read tools it can use | I — partly **pass** (subscriber: published posts only, no drafts/private, no non-public taxonomy, only their own user, no user list or roles; scope filtering O10) |
| P4 | No response or log contains wp-config secrets, password hashes, app passwords, gateway keys | U, I — partly **pass** (redactor F9; post password never returned; password hashes and activation keys never returned by user tools; comment emails/IPs only for moderators; protected meta never returned) |

## Core WordPress

| # | Feature | Type |
|---|---|---|
| C1 | Posts/pages CRUD, status, schedule, search, count | I — read part **pass 2026-11-05** (`CoreContentTest`: list/search/paging, drafts and private by role, `read_post` per post, password-protected content, unknown type); writes **pass 2026-11-05** (`CoreWriteTest`: create draft with terms + featured image, contributor cannot publish, kses without `unfiltered_html`, scheduling needs a future date, `edit_post` per object, update snapshot + undo restores fields/status/terms, invalid terms/images refused before writing, Elementor content guard, trash + undo restores status). Count not run |
| C2 | CPT + custom taxonomies, terms CRUD | I — read part **pass 2026-11-05** (`kuduy-post-types` hides types without admin UI; `kuduy-term-list` public vs non-public taxonomy); writes **pass 2026-11-05** (`CoreEditorialTest`: author cannot create categories, editor creates parent/child, a term cannot be its own parent, rename + undo, delete keeps posts and undo recreates the term and reassigns posts, default category protected) |
| C3 | Comments moderate/reply | I — **pass 2026-11-05** (subscriber reply held, admin reply approved and threaded, closed comments refused; subscriber sees only approved comments of readable posts and no emails; held/all need `moderate_comments`; contributor cannot moderate others' comments; spam + undo) |
| C4 | Post meta (protected keys blocked), revisions list/restore | I — **pass 2026-11-05** (meta set/get incl. arrays, `_` keys neither read nor written, undo, null deletes, contributor refused; revisions listed with content, contributor refused, restore + undo) |
| C5 | Featured image set/unset | I |
| C6 | Users/roles create/update (no password echo) | I — **pass 2026-11-05** (`CoreUsersTest`: subscriber sees only themselves; list/get never contain the password hash or activation key; role list shows caps and assignable roles; editor cannot create users; admin creates an author with a random, never-returned password; unknown role refused; role change + undo; editor cannot promote; nobody changes their own role; last administrator cannot be demoted) |
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
| G1 | Updater detects newer GitHub release, shows in Updates | I — **pass 2026-11-05** (`UpdaterTest` through core `wp_update_plugins()`, also when wordpress.org is unreachable) |
| G2 | Checksum mismatch aborts install | I — **pass 2026-11-05** |
| G3 | Update from release ZIP keeps data and settings | I, E — release ZIP install + activation **pass 2026-11-05** (`npm run check:release`, WP 6.9/PHP 8.1 and WP 7.1/PHP 8.1); real update v0.1.0 → v0.1.1 from GitHub Releases **pass 2026-11-05** (`npm run check:update`: core offered it, checksum verified, `Plugin_Upgrader` installed, settings + rows kept). Note: after this programmatic upgrade core left the plugin inactive (reactivated by the script); Plugins-screen and auto-update paths still to observe. **Real host 2026-11-05:** Hostinger staging updated 0.1.2 → 0.2.0 through WordPress's updater minutes after the release; plugin stayed active and the Claude connection kept working. |
| G4 | GitHub API failure / rate limit → no error spam, cached result | I — **pass 2026-11-05** (12 h cache, ETag/304, 1 h retry after failure) |
| G5 | "Check again" on Dashboard → Updates re-reads GitHub; opening the screen alone uses the cache | I — **pass 2026-11-05** |
