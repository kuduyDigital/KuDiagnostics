# KUDUY MCP — Changelog

Format: user-facing releases first (newest on top), then the dated development history. Versions follow semantic versioning; tags `vX.Y.Z` publish a GitHub Release that installed sites receive through the built-in updater.

## 0.3.0 — 2026-11-05

**Phase 4: WordPress core administration (45 new tools).**

- Added (Phase 4): `kuduy-post-create`, `kuduy-post-update` and `kuduy-post-trash` for posts, pages and custom post types — status (publish, schedule, unpublish, private), slug, date, parent, order, comments, terms and featured image. Updates and trash take a snapshot first and can be undone with `kuduy-snapshot-restore`. Permanent deletion is not offered. Content of Elementor-built pages is not overwritten.
- Added (Phase 4): terms (`kuduy-term-create`, `-update`, `-delete`), comments (`kuduy-comment-list`, `-moderate`, `-reply`), revisions (`kuduy-revision-list`, `-restore`) and public custom fields (`kuduy-post-meta-get`, `-set`), each with the same permission rules as WordPress's own REST API and undo for every overwrite.
- Added (Phase 4): users and roles (`kuduy-user-list`, `-get`, `-create`, `-update`, `kuduy-role-list`). Passwords are never set to a known value or returned; nobody can change their own role; the last administrator cannot be demoted; deleting users is not offered.
- Added (Phase 4): classic menus (`kuduy-menu-list`, `-get`, `-create`, `-item-add`, `-item-update`, `-item-delete`, `-assign`) and allow-listed site settings (`kuduy-settings-get`, `-update`), with undo. Site URLs, admin email, permalinks, membership and default role stay read-only.
- Added (Phase 4): plugins (`kuduy-plugin-list`, `-install`, `-activate`, `-deactivate`, `-update`) and themes (`kuduy-theme-list`, `-install`, `-switch`, `-update`). Installs from wordpress.org by slug only; deactivating, updating and switching themes first report the impact and need `confirm: true`; KUDUY never deactivates or updates itself; deleting is not offered.
- Added (Phase 4): diagnostics — `kuduy-site-health` (in-process Site Health checks and an environment summary), `kuduy-cron-list`, `kuduy-rewrite-check`, `kuduy-rewrite-flush`, `kuduy-hooks-inspect`.
- Fixed: `kuduy-user-get` was hidden from users who cannot list others, although everyone may read their own account.

## 0.2.0 — 2026-11-05

**Recommended for all sites: HTTPS enforcement and a crash fix.**

- Security: OAuth sign-in, token and registration requests, and access tokens are refused over plain HTTP, except on local development sites.
- Fixed: a fatal error when another plugin checks the current user very early (`plugins_loaded`) during a Claude request.
- Added: every tool declares an output schema (`outputSchema`).
- Added (Phase 4): read-only tools `kuduy-post-types`, `kuduy-post-list` (search, status, paging), `kuduy-post-get` (raw content, terms, featured image) and `kuduy-term-list`, limited to what the connected user may read.
- Added: audit rows record which object a call touched.
- Added: GPL-2.0 `LICENSE` file (shipped in the release ZIP).
- "Check again" on Dashboard → Updates now asks GitHub immediately instead of using the 12-hour release cache (only for users who can update plugins).
- Project files reorganised: `CLAUDE.md`, `RESEARCH.md`, `ARCHITECTURE.md`, `ROADMAP.md`, `FEATURES.md`, `COMPATIBILITY.md`, `SECURITY.md`, `TESTING.md`, `CHANGELOG.md` replace `docs/01–04` and `docs/DEVLOG.md`.

## 0.1.2 — 2026-11-05

**Security release. Update from 0.1.0 or 0.1.1.**

- Fixed (critical): an access token worked on every WordPress REST route, so a "read" connection could act with the user's full rights. Tokens now work only on the KUDUY MCP endpoint.
- Fixed: approving the consent page with every box unticked granted every permission; it now grants nothing.
- Fixed: Claude detected "No sign-in" because `initialize` answered without a token; it now answers 401 with the sign-in details.
- Added: authorization-server metadata at the site-root `/.well-known/` addresses for clients that ignore the issuer path.
- Changed: logged-out visitors are sent to login before the site downloads any client metadata document.
- Changed: undoing a change needs the same permission level and capability as the tool that made it; undo is claimed atomically.
- Changed: client registration is limited by the number of unused clients instead of per IP address (works behind CDNs).
- Changed: GitHub Actions pinned to commit SHAs.

## 0.1.1 — 2026-11-05

- Release used to test the real self-update path (v0.1.0 → v0.1.1).

## 0.1.0 — 2026-11-05

- First release: dual-era MCP endpoint (2026-07-28 + 2025-11-25), OAuth 2.1 authorization server (PKCE, CIMD, DCR, rotating refresh tokens, revocation), audit log with redaction, snapshots and undo, rate limit, daily cleanup, Tools → KUDUY MCP page (connections, recent calls), GitHub self-update with checksum. Tools: `kuduy-site-info`, `kuduy-snapshot-list`, `kuduy-snapshot-restore`, `kuduy-check-update`.

## Development history

### 2026-11-04 — Phase 1 research, Phase 2 proposal

- Repo initialised and pushed to `github.com/kuduyDigital/KuDiagnostics` (public), branch `main`.
- Studied WSP (GPL-2.0, v2.9.7) and Novamira (AGPL-3.0) from clones; no code copied.
- Verified current MCP spec (2026-07-28, stateless, CIMD preferred over DCR), Claude connector auth rules, WordPress 7.1.4 + core Abilities API, `wordpress/mcp-adapter` 0.7.1 (dual-era), Elementor 4.3.0 atomic element schemas.
- Environment gaps: no PHP, Composer, WP-CLI or Docker. Node present.
- New requirement added: self-update from GitHub releases (architecture §11).
- Decisions D1–D6 in `02-architecture.md` approved by the user.
- Next: Phase 0 tooling (PHP 8.3, Composer, Playground CLI, staging site), then Phase 3 foundation.

### 2026-11-04 — Phase 0 tooling

- PHP 8.3.36 (official Windows build via winget, user scope). `php.ini` from `php.ini-development` with curl, fileinfo, gd, intl, mbstring, openssl, pdo_sqlite, sodium, zip, exif, mysqli enabled; `memory_limit = 512M`.
- Composer 2.11.1 (official installer, SHA-384 signature checked) in `%LOCALAPPDATA%\Programs\composer`, added to user PATH.
- Claude skills installed to `~/.claude/skills`: `wp-plugin-development` + `wp-project-triage` from `WordPress/agent-skills` @ `eff3785` (reviewed: Markdown plus read-only fs scanners, no network). Also caveman and ponytail.
- Playground: `@wp-playground/cli` 3.1.62 pinned in `package.json`; blueprint `tests/playground/blueprint.json`. `npm run playground` → http://127.0.0.1:9400. Verified in wp-admin: **WordPress 7.1.4, Elementor 4.3.6 active, WooCommerce 11.2.1 active**. Note: the CLI banner says "WordPress latest" even though the blueprint asks for 7.1; check the admin footer, not the banner.
- `.github/workflows/ci.yml` skeleton: PHP 8.1–8.4 matrix, `php -l`, composer validate/install once `composer.json` exists.
- Superpowers 7.0.2 (`obra/superpowers`) installed, user scope, from `obra/superpowers-marketplace` (not yet in the local copy of the official marketplace).
- Staging: cloudflared 2026.10.7 (winget). `npm run tunnel` (`scripts/tunnel.mjs`) opens a Cloudflare quick tunnel and boots Playground with `--site-url` set to it, auto-login off, a random admin password written to gitignored `.tunnel-admin.txt`, and a mu-plugin trusting `X-Forwarded-Proto` so WP sees HTTPS. Tested: home 200 via tunnel with WP 7.1.4 / Woo 11.2.1 / Elementor 4.3.6 generator tags and zero `127.0.0.1` links; `/wp-admin/` → 302 to login; REST `url` is the tunnel; generated password logs in with a `secure` cookie; default `password` rejected.
- Playground scripts now pass `--wp=7.1 --php=8.3`: the CLI defaults `--wp` to `latest`, which ignores the blueprint's preference.
- Known limit: the quick-tunnel URL changes every run. Move to a named tunnel on our domain before Phase 3's OAuth client tests. On Windows, killing the npm parent can orphan `cloudflared`/`node`; stop with Ctrl+C.
- **Phase 0 complete.**

### 2026-11-04 — Phases 0–2 close-out audit

- Re-verified in a fresh PATH: PHP 8.3.36, Composer 2.11.1, cloudflared 2026.10.7, Node 24.18.0, 11 skills, Superpowers enabled.
- `01-research-report.md` §1–2: added dated corrections (machine facts and skill install method differed from the original report). Architecture title no longer says "proposal"; §14 notes that only the environment, not the plugin, is verified.
- Deferred on purpose, each tied to its phase: UNVERIFIED items (MCP client versions → Phase 3 E2E; Elementor Kit/atomic storage → Phase 5; SEO/Woo/ACF APIs → Phases 6–7; `update_plugins_{host}` signature → Phase 3; Tasks extension → later), emailed recovery link (§10, Phase 8 decision).

### 2026-11-04 — Phase 3 step 1: plugin skeleton, lifecycle, migrations

- Plan: `docs/plans/phase-3-01-skeleton.md`. Repo root is the plugin root (`kuduy-mcp.php`, `Update URI` header, `Requires PHP 8.1`, `Requires at least 6.9`).
- `Lifecycle\Migrations` (DB v1: the 5 tables from architecture §5 via `dbDelta`), run on activation and on `plugins_loaded` when the stored version is older (auto-update path). `Lifecycle\Uninstaller` drops tables + 3 options only with `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL`.
- Dev tools (Composer, platform PHP 8.1): PHPUnit 10.5.66, PHPStan 2.3.5 (level 6) + phpstan-wordpress 2.0.4, WPCS 3.4.1. `composer test:static` clean.
- Integration tests run PHPUnit inside Playground WordPress (`npm run test:integration`, env `KUDUY_WP`/`KUDUY_PHP`); pass/fail comes from the JUnit report, versions actually used are printed. Red first (classes missing), then 4/4 green on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36, no PHP warnings.
- Gotchas found: (1) a blueprint's `preferredVersions` — or its defaults, latest PHP/WP — **override** `--wp`/`--php`; the wrapper writes the requested versions into a generated blueprint. (2) `composer.bat` under cmd/PowerShell eats `^` in constraints (`^10.5` became `10.5`); use quotes in Git Bash or `php composer.phar`. (3) PHPUnit's launcher has a shebang line, so the runner calls `PHPUnit\TextUI\Application` directly.
- CI: syntax lint on PHP 8.1–8.4, static job, integration matrix (WP 6.9/PHP 8.1, WP 7.1/PHP 8.3).
- Deferred: deactivation hook (no cron yet), unit test suite (no pure logic yet).
- CI run 41868976135: all jobs green on GitHub.

### 2026-11-04 — Phase 3 step 2: own MCP layer (D1 changed)

- **D1 changed (user decision):** `wordpress/mcp-adapter` v0.7.0 deprecates bundling ("will be removed in a future version"); a bundled copy calls `_deprecated_function`, defines global `WP_MCP_VERSION` and shares hook names even when prefixed; the canonical plugin is not on wordpress.org. We now run our own thin dual-era layer. Core Abilities registration deferred (it would bypass OAuth scopes). Architecture §2 updated.
- Wire rules taken from `schema/2026-07-28/schema.ts`, `schema/2025-11-25/schema.ts`, and the spec pages `streamable-http` + `versioning` (fetched today). Plan: `docs/plans/phase-3-02-mcp-layer.md`.
- New: `Mcp\{Tool, ToolError, ToolRegistry, Executor, Server, RestController}`, `Http\OutputGuard`, `Modules\Core\SiteInfo` (`kuduy-site-info`). Validation reuses core `rest_validate_value_from_schema()`.
- Tests: 15 new integration tests, red first (route missing), then 19/19 green on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36. `npm run smoke:mcp` (real HTTP, Elementor + Woo active, noisy mu-plugin): 9/9 — found and fixed core's `Allow` header listing GET/DELETE on our 405.
- Design notes: legacy method errors stay HTTP 200 (legacy clients read 404 as "session expired"); tool exceptions return a generic `isError` message and log class/file/line only.
- Auth for now: any WordPress login (cookie or Application Password). OAuth (step 3) replaces it and adds `WWW-Authenticate`.

### 2026-11-05 — Phase 3 step 3: own OAuth 2.1 authorization server (D2 changed)

- **D2 changed (user decision):** `league/oauth2-server` 9.4 requires PHP 8.2 (D3 = 8.1) and has no RFC 8707 `resource`, CIMD, revocation endpoint or family revoke; we wrote a minimal AS. Plan: `docs/plans/phase-3-03-oauth.md`. Rules from the MCP 2026-07-28 authorization pages (fetched today).
- New `src/OAuth/`: `Urls`, `Scopes`, `Clients` (DCR + CIMD), `Tokens`, `TokenAuth`, `Authorize` (consent at `wp-login.php?action=kuduy-mcp-authorize`), `Endpoints` (PRM, AS metadata, token, register, revoke, root `/.well-known/`). Server/RestController emit 401/403 challenges and filter tools by scope.
- Design: issuer is a REST path, so the third discovery URL clients try (`{issuer}/.well-known/openid-configuration`) works on hosts that block `/.well-known/`. Opaque `kmcp_at_`/`kmcp_rt_` tokens, SHA-256 only; family id = code hash prefix (no schema change).
- Tests: 20 new integration tests, red first, then 39/39 green on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36. `npm run smoke:mcp` now runs Claude's whole sign-in over real HTTP (401 → PRM → AS metadata on both paths → DCR → logged-out redirect → real `wp-login.php` login → consent → code → token → bearer `tools/call` → rotation → reuse revokes all): 24/24.
- Found while testing: (1) the smoke test's noisy mu-plugin broke core's own `wp-login.php` (headers already sent) — limited to non-login pages; (2) WordPress stubs claim `WP_REST_Request::get_json_params()` always returns an array, but it returns null for non-JSON bodies — corrected with a PHPStan stub instead of trusting it. PHPCS: excluded `Squiz…IncorrectTypeHint` (rejects `list<string>` docs; PHPStan checks types).
- Needs pretty permalinks (admin notice otherwise). Deferred: admin screen to list/revoke connections, expired-row cleanup cron (step 4).
- Not yet verified: real Claude clients (O11) — needs the tunnel and you signing in.

### 2026-11-05 — Phase 3 step 4: safety layer

- Plan: `docs/plans/phase-3-04-05-safety-updater.md`.
- New: `Audit\{Redactor, AuditLog}`, `Execution\{Snapshottable, Snapshots}`, `Policy\RateLimiter`, `Lifecycle\{Cleanup, Deactivator}`, `Admin\{Connections, ConnectionsPage}`, tools `kuduy-snapshot-list` / `kuduy-snapshot-restore`.
- Every `tools/call` is audited (ok / denied / error + code, client = OAuth client id, `application-password` or `wordpress-login`); arguments **and tool results** are redacted. Destructive `Snapshottable` tools are snapshotted first; a snapshot restores once, by its owner or a site manager. 120 calls/min per user + client, then 429 + `Retry-After`. Daily cleanup cron (audit 90 d, snapshots 30 d, expired codes/tokens, unused clients); deactivation clears it. Tools → KUDUY MCP shows the URL to paste into Claude, connections with Revoke, recent calls.
- Tests: 11 new, red first, then 50/50 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; smoke 24/24.
- CI smoke failure on `cbd3299` explained: Playground answers the first request after boot with `302` to the same URL, and the followed redirect turned the POST into a GET (405). Smoke now uses `redirect: manual` + a warm-up and reports failures as public annotations (job logs need a GitHub login). CI green since `2e29af0`.

### 2026-11-05 — Phase 3 step 5: GitHub self-update

- Re-verified in WordPress 7.1.4 source (`wp-includes/update.php:555`): `apply_filters( "update_plugins_{$hostname}", false, $plugin_data, $plugin_file, $locales )`; `upgrader_pre_download` and `plugins_api` unchanged. **Found:** `wp_update_plugins()` returns (line 488) before the Update URI loop when the wordpress.org check fails, so on hosts that block wordpress.org no Update URI plugin is ever offered. Added a fallback that offers our cached newer release via `site_transient_update_plugins` (no HTTP on that path).
- New `Update\GitHubUpdater` (core hook only for our plugin file; releases API cached 12 h with ETag/304, 1 h retry; `beta` channel via `KUDUY_MCP_UPDATE_CHANNEL` or filter; release needs both `kuduy-mcp.zip` and `.sha256`; download + SHA-256 compare before install; details modal with escaped notes), tool `kuduy-check-update` (cap `update_plugins`).
- `scripts/build-release.php` (version header = `Plugin::VERSION` = tag; `composer install --no-dev --classmap-authoritative`; ZIP top folder `kuduy-mcp/`, 46 files, ~61 KB) and `scripts/check-release.mjs` (installs the ZIP like an upload in a clean Playground, activates, checks tables/routes/no dev files). CI job "Release ZIP installs" on every push; `.github/workflows/release.yml` on `v*` tags: CI → build → check → `gh release create` (pre-release for `-` tags).
- Tests: 10 new, red first, then 60/60 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; release ZIP check pass on WP 6.9/PHP 8.1; smoke 24/24.
- Gotcha: Windows PowerShell 5.1 `Set-Content -Encoding utf8` writes a BOM, which breaks PHP (`namespace` must be first). Caught by PHPCS/PHPStan; removed. `docs/04-test-matrix.md` had one from step 4 too (fixed).
- Not done here: real update `v0.1.0` → `v0.1.1` — pushing release tags needs the user's OK (step 6).

### 2026-11-05 — Phase 3 step 6 (part 1): releases + real update

- Published `v0.1.0` (tag on `d42bad5`) and `v0.1.1` (`0a7ac0e`) through `release.yml` (CI → build → install check → `gh release create`); both carry `kuduy-mcp.zip` (60 804 bytes) + `.sha256`. User approved both public releases.
- `npm run check:update` (real GitHub, clean Playground WP 7.1/PHP 8.3): installed v0.1.0 from its release asset → core `wp_update_plugins()` offered v0.1.1 → our `upgrader_pre_download` verified the checksum → core `Plugin_Upgrader` installed → fresh request runs 0.1.1, option and table rows kept, DB version intact. **Observed:** core left the plugin inactive after this programmatic upgrade; to watch on the Plugins screen / auto-update paths.
- Public test site: `node scripts/tunnel.mjs` now mounts and activates KUDUY with pretty permalinks on port 9410 (9400 was in use by another Playground server, left alone). Checked from outside over HTTPS: 401 + `resource_metadata`, PRM, AS metadata at root `.well-known`, CIMD supported.
- Waiting on the user: connect claude.ai, Claude Desktop and Claude Code (test matrix O11).

### 2026-11-05 — Security audit and fixes (0.1.2)

- Full audit (lint, PHPCS, PHPStan L6, `composer audit`, `npm audit`, 60/60 integration on both pairs, smoke 24/24, reading every file). Two holes confirmed over real HTTP on a throwaway local site, both in v0.1.0 and v0.1.1:
  1. **Token valid on every REST route.** The bearer check only matched the end of the path, so `/wp-json/kuduy-mcp/v1/mcp?rest_route=/wp/v2/...` (or `rest_route` in the body, or a PATH_INFO tail like `admin-ajax.php/wp-json/kuduy-mcp/v1/mcp`) ran core routes as the user: a `kuduy:read` token read `/wp/v2/users/me` and `/wp/v2/settings` and created a post. Fix: exact path match (or site root + `rest_route` for plain permalinks), any other `rest_route` disqualifies, and `rest_pre_dispatch` refuses a token on any route but `/kuduy-mcp/v1/mcp` (401 `kuduy_mcp_token_route`).
  2. **Approving with every box unticked granted every scope** (no `scopes` field fell back to "all offered"). Now it grants nothing (`access_denied`).
- Also fixed: `initialize` answers 401 + challenge when not signed in (claude.ai showed "No sign-in · Detected", then "Couldn't reach" after the user kept that mode); root `/.well-known/oauth-authorization-server` and `/openid-configuration` served for clients that ignore the issuer path; logged-out visitors go to login before any CIMD URL is fetched (no anonymous outbound requests); snapshot restore needs the original tool's scope and its capability for the saved input, and claims the snapshot atomically; DCR limited by unused clients (cap 1000, oldest unused > 1 h evicted) instead of 30/IP/h, which behind a CDN was one shared bucket; GitHub Actions pinned to commit SHAs.
- Tests: 6 new (route binding, rest_route in query/body, PATH_INFO, unticked consent, logged-out CIMD, write token vs admin snapshot, registration cap): 66/66 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36. Smoke adds unauthenticated `initialize` → 401 and root AS metadata: 26/26. Re-ran the exploit probe: every case now 401 / denied.
- The route guard checks only the request's first REST dispatch (the HTTP request); internal `rest_do_request()` calls a tool makes later stay allowed (found when a test reused bearer state).
- Note: the user's real test site is on Hostinger (`*.hostingersite.com`), which is why the tunnel's database stayed empty. Its endpoints check out from outside; it needs 0.1.2 for the fixes.

### 2026-11-05 — Release v0.1.2; "Check again" skips the release cache

- Published `v0.1.2` (tag on `3591d92`) after CI passed: `kuduy-mcp.zip` (62 427 bytes) + `.sha256`; GitHub lists it as latest.
- "Check again" on Dashboard → Updates (`update-core.php?force-check=1`, core runs `wp_update_plugins()` on `load-update-core.php`) now re-reads GitHub instead of our 12 h cache, for users who can `update_plugins`. The ETag makes an unchanged answer a 304, which does not count against GitHub's rate limit. Simply opening the screen still uses the cache. Test: 67/67 on both pairs. Ships in the next release (sites on 0.1.2 or older wait up to 12 h, or upload the ZIP).

### 2026-11-05 — Project files per the master prompt

- Created the nine project files the KUDUY MCP Master Development Prompt (§16) requires at the repo root: `CLAUDE.md` (permanent instructions), `RESEARCH.md`, `ARCHITECTURE.md`, `ROADMAP.md`, `FEATURES.md`, `COMPATIBILITY.md`, `SECURITY.md`, `TESTING.md`, `CHANGELOG.md`. They replace `docs/01-research-report.md`, `docs/02-architecture.md`, `docs/03-roadmap.md`, `docs/04-test-matrix.md` and `docs/DEVLOG.md` (content carried over; history in git). `docs/plans/` stays.
- Research re-inspected both references: WSP still `20a9582` (GPL-2.0); Novamira now `9277ff3` (AGPL-3.0, v1.12.14). Filled the brief's gaps: WSP has no automated tests, writes Rank Math meta directly, uses Woo CRUD; Novamira's WP-CLI runs through `proc_open` with argv, its `read-file` has no `wp-config.php` guard, every ability is gated by "plugin enabled + user can manage".
- `ARCHITECTURE.md` now describes the as-built foundation (own MCP layer and OAuth server, schema v1, token route binding) and marks later modules as planned. `FEATURES.md` tracks 104 rows with the brief's five statuses; only foundation rows are `Tested`.
- Found while writing: GitHub private vulnerability reporting is not enabled on the repo (noted in `SECURITY.md`); HTTPS is not enforced by the plugin (listed as `Not started`).

### 2026-11-05 — Phase 3 code complete; Phase 4 started

- `LICENSE` (GPL-2.0 text from gnu.org) added; the release build already copies it into the ZIP.
- HTTPS enforcement (`Urls::insecure()`): authorize page, token and registration endpoints and bearer tokens refuse plain HTTP unless the site is local; revocation stays open. Filter `kuduy_mcp_require_https`.
- **Bug found while planning it:** `rest_url()` calls `$wp_rewrite->using_index_permalinks()`, and `$wp_rewrite` does not exist during `plugins_loaded`. A plugin that checks the current user that early would turn every MCP request with a token into a fatal error. Reproduced on WP 7.1.4 (`Call to a member function using_index_permalinks() on null`); `Urls` now builds the URL itself in that window. Test O18.
- Tool contract: `output_schema()` required (listed as `outputSchema`, F13); `capability()` may return `[cap, object_id]`; `Executor::allowed()` is the single capability check. `AuditLog::target()` fills `object_type` / `object_id`.
- Phase 4 read tools with `Modules\Core\Content`: `kuduy-post-types`, `kuduy-post-list`, `kuduy-post-get`, `kuduy-term-list`. Rules: content types and taxonomies only (`show_ui`); unpublished statuses need edit rights and are limited to your own without `edit_others_posts`; `read_post` per post; protected content only for editors of that post; post password never returned.
- `LifecycleTest` now resets KUDUY itself (new test files sort before it alphabetically).
- Tests: 81/81 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; smoke 26/26.
- Phase 3 exit gate still open: real Claude clients (O11) need the user.

### 2026-11-05 — Release v0.2.0; real claude.ai connection verified

- Published `v0.2.0` (tag on `4f6eab4`) after CI passed: `kuduy-mcp.zip` (79 907 bytes, now with `LICENSE`) + `.sha256`.
- **O11, claude.ai: pass.** The user's claude.ai connector for the Hostinger staging site appeared in this Claude Code desktop session; `kuduy-site-info` returned the site (WordPress 7.1.4, user `admin`, administrator) on 0.1.2, and again on 0.2.0.
- **Real-host update:** the Hostinger site updated 0.1.2 → 0.2.0 through WordPress's own updater minutes after the release; the plugin stayed active and the connection kept working without a new sign-in. `kuduy-check-update` reports 0.2.0 = latest.
- From outside on Hostinger (0.2.0): unauthenticated `initialize` → 401 + challenge; root AS metadata 200; token with a `rest_route` override → 401; plain HTTP → 301 to HTTPS.
- Phases 0–4 recheck: Phases 0–2 complete. Phase 3 code complete; still to observe with the user: Claude Desktop, Claude Code's own sign-in, refresh after 1 h, revoke. Phase 4 in progress (see `FEATURES.md`).

### 2026-11-05 — Phase 4: post writes with undo

- Recheck of Phases 0–4: Phases 0–2 complete; Phase 3 code complete with claude.ai verified (remaining: Desktop, Claude Code's own sign-in, refresh, revoke — need the user); Phase 4 continued.
- `Modules\Core\PostWriter` (fields, status caps, date/schedule checks, term and featured-image checks before writing, Elementor content guard, snapshot state + restore) and tools `kuduy-post-create`, `kuduy-post-update` (Snapshottable), `kuduy-post-trash` (Snapshottable; trash only, refuses when `EMPTY_TRASH_DAYS` is 0).
- Found while testing: `wp_trash_post()` returns the post as it was **before** trashing, so the tool reported the old status; it now reads the post back.
- Tests: `CoreWriteTest` (8 tests); 89/89 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; smoke 26/26.

### 2026-11-05 — Phase 4: terms, comments, revisions, custom fields

- `TermWrite` (create/update/delete; capabilities as core's REST terms controller; parent checks; delete keeps posts; undo recreates the term and reassigns posts; default category protected).
- `Comments` (list/moderate/reply; non-approved and emails/IPs only for moderators, approved comments only on readable posts; `edit_comment` per comment; replies via `wp_new_comment()`, held unless the user moderates; undo of status).
- `Revisions` (list with optional content, restore via `wp_restore_post_revision()` so plugins restore their revision data; undo).
- `PostMeta` (get/set public keys only; `edit_post_meta` per key; null deletes; undo).
- Test helper trait `tests/integration/CallsTools.php` (loaded with `require_once`: PHPUnit only loads `*Test.php`, and the folder is lower-case).
- Learned while testing: WordPress stores a revision of the **new** content on each update and none on creation, so the oldest restorable state is the first update.
- Tests: `CoreEditorialTest` (7 tests); 96/96 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; smoke 26/26.

### 2026-11-05 — Phase 4: users and roles

- `Users` (list/get/create/update/roles) mirrors core's REST users controller (`list_users`, `create_users`, `edit_user`, `promote_user`, `get_editable_roles()`, no own-role change). Extra guard: the last administrator cannot be demoted. Create sets a random password that is never returned and sends WordPress's set-password email (optional). Update is snapshotted (profile + roles). Delete is not offered. Changes need scope `kuduy:admin`.
- PHPStan required an explicit field shape for `wp_insert_user()`.
- Tests: `CoreUsersTest` (7 tests); 103/103 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; smoke 26/26.

### 2026-11-05 — Phase 4: menus and site settings

- `Menus` (7 modes, `edit_theme_options`): menu items keep every field not given — `wp_update_nav_menu_item()` resets anything not passed, verified in WP 7.1.4 source. Undo for item update/delete and location assignment. Dynamic menu-item properties are read via `get_object_vars()` so PHPStan can check the rest.
- `Settings` (`manage_options`): allow-list of 17 writable settings; read-only by design: `siteurl`, `home`, `admin_email`, `permalink_structure`, `users_can_register`, `default_role`, `WPLANG`. Undo restores previous values.
- Tests: `CoreSiteTest` (7 tests); 110/110 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; smoke 26/26.

### 2026-11-05 — Phase 4: plugins and themes

- `Packages` (shared): wordpress.org slugs only, refuse when WordPress would need FTP credentials, silent `Automatic_Upgrader_Skin`, confirm-first policy.
- `Plugins` (5 modes) and `Themes` (4 modes). Impact reports: dependent plugins, page builder / shop / SEO / custom-fields plugins, classic ↔ block theme, assigned menu locations, widgets.
- **Found in WP 7.1.4 source:** `Plugin_Upgrader::upgrade()` deactivates active plugins unless `wp_doing_cron()` (wp-admin reactivates in a second request to avoid redeclaring classes). The tool runs the upgrade as background updates do, so plugins stay active. This also explains the inactive plugin seen by `check:update` earlier.
- **Bug caught by the tests:** core initializes `WP_Plugin_Dependencies` only on wp-admin screens, so during an MCP request the dependency check always said "no dependents". The tool now initializes it first.
- Tests run offline: local ZIPs (ZipArchive) served through `plugins_api` / `themes_api` / `upgrader_pre_download`, through core's real upgraders. `CorePackagesTest` (8 tests); 118/118 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; smoke 26/26.

### 2026-11-05 — Phase 4 complete: diagnostics, P3 and P4

- `Diagnostics` (5 modes, `manage_options`): Site Health limited to core's in-process checks (`rest_availability` skipped: it makes a loopback request); environment summary; cron with argument counts only; rewrite check and soft flush; hook inspector with priority, callable, relative file/line and source (core, plugin, theme, mu-plugin).
- P3 test: a subscriber's `tools/list`. It found that `kuduy-user-get` was hidden from subscribers (listing used `list_users`); listing now uses `read` and each call still checks the id.
- P4 sweep: 11 read tools and 50 audit rows contain no salts/keys, DB password, password hash or application password (plain or hashed); a token-like value in post meta is masked.
- Phase 4 exit gate met: 125/125 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36; smoke 26/26; P1–P4 pass. Items moved to their brief phases: block navigation and widgets (7), theme files (7, developer mode), media (6).
