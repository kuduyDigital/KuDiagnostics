# KUDUY MCP — Changelog

Format: user-facing releases first (newest on top), then the dated development history. Versions follow semantic versioning; tags `vX.Y.Z` publish a GitHub Release that installed sites receive through the built-in updater.

## Unreleased

- Added: **Avada Theme and Avada Builder** — 20 `kuduy-avada-*` tools, shown while Avada Builder is active. Detect what Avada a site has; list the builder's elements and their settings; read a page as a tree or in full; create pages; add containers, columns, nested columns and elements; change headings, text, images, buttons, links, backgrounds, spacing and responsive settings in place; move, duplicate and remove; replace a whole layout; set a page's custom CSS; read the Library, Layouts and Global Options (design settings only). Every change can be previewed with `dry_run`, is checked before saving, read back after, and can be undone. Library items shown on many pages need an explicit `confirm_shared`. Built from an installed Avada 7.16.4 / Builder 3.16.4, tested against a stand-in, **not yet run on an Avada site**. Guide: `docs/AVADA.md`.
- Changed: `kuduy-post-update` no longer overwrites the content of a page built with Avada Builder (while it is active).
- Fixed: `kuduy-dev-search` failed when `path` named a single file; it now searches that file.
- Added: `kuduy-integrations` — one place to ask what KuDiagnostics can do with the page builders, themes and plugins on a site: installed, active, how far each is supported (full, partial, detection only, none), which tools manage it and whether they are available now. Support levels reflect what has been tested, not what is installed.
- Added: upgrade plan documents — `AUDIT.md`, `FEATURE-GAP.md`, `INTEGRATION-MATRIX.md`, `IMPLEMENTATION-PLAN.md`.

## 1.1.0 — 2026-11-10

**WPBakery Page Builder (16 new tools, 181 in all), shown only while WPBakery is active.** WPBakery is a paid plugin and no licensed copy was available: the tools are tested against real WordPress with a stand-in for WPBakery's element list, and are **not yet verified against WPBakery itself**. Try them on a staging site first. Guide: `docs/WPBAKERY.md`.

- Added: reading — `kuduy-wpbakery-tree` (the layout as a tree with ids), `kuduy-wpbakery-structure` (every row, column and element with settings and content), `kuduy-wpbakery-content` (the raw shortcodes), `kuduy-wpbakery-elements` (the elements registered on the site, including the theme's and add-ons', with their settings) and `kuduy-wpbakery-validate` (checks a page or shortcode text without saving).
- Added: building — `kuduy-wpbakery-create` (a new page from rows or shortcodes, draft by default), `kuduy-wpbakery-add-row` (layouts such as 1/2 + 1/2 or 1/3 + 2/3, inner rows inside columns), `kuduy-wpbakery-add-column`, `kuduy-wpbakery-add-element`.
- Added: editing — `kuduy-wpbakery-update-element`, `-update-row`, `-update-column`, `-move-element`, `-duplicate-element`, `-delete-element` and `kuduy-wpbakery-replace`. Only the addressed element is rewritten; everything else on the page, including shortcodes of other plugins and unknown ones, keeps its exact text. Backgrounds, padding, margins and borders are set through `design`; column widths per screen size through `responsive`.
- Every change is checked first (a layout that would break is not saved) and can be undone with `kuduy-snapshot-restore`. Adding a Raw HTML or Raw JS element needs WordPress's unfiltered-HTML permission. A page someone else has open in the editor is not overwritten unless asked.
- Changed: `kuduy-post-update` no longer overwrites the content of a page built with WPBakery (while WPBakery is active); it points to the WPBakery tools. Pages built with Elementor are refused by the WPBakery tools, and Elementor's tools are unchanged.

## 1.0.0 — 2026-11-10

**First stable release (21 new tools, 165 in all).** The plugin is now called **KuDiagnostics**; the folder, tool names, connector URL and settings are unchanged, so existing Claude connections keep working.

- Added: hidden admin mode — an administrator can hide the Tools → KuDiagnostics page and its notices. Claude keeps working the same way, and the plugin stays listed in Plugins. Bring the page back with `wp kuduy ui show`, the option `kuduy_mcp_hidden_mode`, or the constant `KUDUY_MCP_SHOW_UI`.
- Added: block navigation menus — `kuduy-nav-menus`, `-nav-menu-get`, `-nav-menu-save`, `-nav-menu-trash` (block themes), with undo.
- Added: WooCommerce shipping and tax changes — `kuduy-woo-shipping-zone-save`, `kuduy-woo-shipping-method-save`, `kuduy-woo-tax-rate-save`, `kuduy-woo-tax-rate-delete` (admins), each with undo.
- Added: Rank Math redirects and schema — `kuduy-seo-redirects`, `-redirect-save`, `-redirect-trash`, `kuduy-seo-schema-get`, `-schema-save`, `-schema-delete`, with undo — and `kuduy-seo-sitemap` (Rank Math, Yoast or WordPress's own sitemap).
- Added: `kuduy-media-replace` (new file, same media item and address) and `kuduy-media-rename` (refused while the file is linked in content), with backups for undo.
- Added: guides — install, connecting Claude (with the Claude apps verified so far), developer guide, release checklist and a full tool reference.
- Fixed: `kuduy-dev-php` left its 30-second time limit on the rest of the request.
- Security: read-only database queries can no longer show passwords, keys or other secret settings by renaming or combining columns. Custom CSS in Global Styles now needs permission to edit CSS. Review, fuzz and load tests: no high findings open (`SECURITY.md` §4a).

## 0.8.1 — 2026-11-10

- Fixed: undoing `kuduy-lang-set` on a post or term that had no language now removes the language again (it used to leave the new language in place). Found on staging with claude.ai.
- Changed: `kuduy-block-types` also returns the number of block types (`count`).

## 0.8.0 — 2026-11-10

**Phase 7: forms, Site Editor and widgets, cache, Polylang, custom content types and developer tools (36 new tools).** Each appears only when the plugin or theme feature it needs is there.

- Added: forms — `kuduy-forms-status`, `-list`, `-get`, `-entries`, `-entry-get`, `-entry-update`, `-entry-note`, `-cf7-save`, `-cf7-mail` for Contact Form 7 (entries from Flamingo), Fluent Forms and WPForms Lite (forms only; Lite keeps no entries). Visitors' emails, phones, names, addresses, files and IP addresses are masked unless asked for and allowed; password fields are never shown. Entries can be marked read, starred, spam or trashed, each with undo; nothing is deleted permanently. Changing where Contact Form 7 emails submissions needs admin rights and a confirmation; marking a Flamingo message as spam asks first when it would be reported to Akismet.
- Added: Site Editor — `kuduy-block-types`, `kuduy-post-blocks`, `kuduy-site-templates`, `-template-get`, `-template-update`, `-template-revert`, `kuduy-global-styles-get`, `-update`, and widgets `kuduy-widgets`, `kuduy-widget-save`, `kuduy-widget-remove`, all through WordPress's own Site Editor API with undo. Custom CSS needs permission to edit CSS.
- Added: cache — `kuduy-cache-status`, `kuduy-cache-purge` (whole site, one post or one URL) for LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache and WP Rocket, plus object-cache flush.
- Added: Polylang — `kuduy-lang-status`, `-get`, `-set`, `-link`, `-translate` (translation drafts with text Claude writes). WPML is not supported yet.
- Added: custom post types and taxonomies — `kuduy-content-types`, `-content-type-save`, `-content-type-remove` (admins; removing a type keeps its content).
- Added: developer tools for site admins — `kuduy-dev-files`, `-file-read`, `-search`, `-log`, `-db-schema`, `-db-query` (read only; secret files refused, passwords, keys and tokens masked, queries capped). Code tools `kuduy-dev-file-write` and `kuduy-dev-php` exist only when wp-config.php defines `KUDUY_MCP_ALLOW_CODE_EXEC`, and need the separate code scope and plugin-editing rights.

## 0.7.0 — 2026-11-09

**Phase 7: Advanced Custom Fields (4 new tools) and WooCommerce fixes found on a real store.**

- Added (Phase 7): Advanced Custom Fields — `kuduy-acf-status`, `kuduy-acf-field-groups` (read), `kuduy-acf-get`, `kuduy-acf-update` for field values on posts, users and terms through ACF's own API. Only fields ACF shows on that object can be changed; values are checked the way ACF's edit screen checks them; changes can be undone and run in bulk with `kuduy-job-start`. Password fields are never shown.
- Changed: `kuduy-post-meta-set` refuses ACF field names and points to `kuduy-acf-update`.
- Fixed: `kuduy-woo-report` listed only the first 10 intervals (e.g. 10 of 30 days); it now returns every interval in the range, oldest first, and says so when a very long daily range is cut.
- Fixed: `kuduy-woo-payment-gateways` hid checkbox and select settings whose names look like credentials (e.g. Stripe's `sepa_tokens_for_ideal`); only free-text fields are hidden by name now (password fields always).
- Fixed: `kuduy-woo-order-get` showed an order note author's display name unmasked when it is an email address, and with `include_pii` left the order's top-level `email` masked.

## 0.6.0 — 2026-11-09

**Phase 7: WooCommerce (29 new tools, shown only while WooCommerce is active).** Products, variations, stock, orders, coupons, customers, reviews, shipping, tax, payment methods and reports, all through WooCommerce's own APIs and permission rules, with WooCommerce's new order storage (HPOS) on or off. Customer details are masked unless asked for and allowed, payment secrets are never shown, nothing is refunded or deleted permanently, and anything that would email a customer asks for confirmation first.

- Added (Phase 7): WooCommerce products — `kuduy-woo-status` (version, order storage, currency, tax/shipping/coupon/review settings, coming-soon mode), `kuduy-woo-product-list`, `-get`, `-create`, `-update`, `-stock`, `-trash`, `kuduy-woo-variation-list`, `-create`, `-update`. Products are created as drafts; publishing needs permission to publish products; changes, stock and trash can be undone.
- Added (Phase 7): WooCommerce orders — `kuduy-woo-order-list`, `kuduy-woo-order-get`, `kuduy-woo-order-status`, `kuduy-woo-order-note`. A status change or customer note that makes WooCommerce email the customer first reports the email and needs `confirm: true`. Undoing a status change sends no email. Refunds are not offered.
- Added (Phase 7): WooCommerce coupons (`kuduy-woo-coupon-list`, `-get`, `-create`, `-update`, `-trash`), customers (`kuduy-woo-customer-list`, `-get`, `-update` for billing and shipping addresses) and product reviews (`kuduy-woo-review-list`, `-moderate`, `-reply`). Coupon codes must be unique; percentage coupons cannot exceed 100. The addresses you send are not written to the audit log. Moderating a review updates the product's rating.
- Added (Phase 7): read-only store settings — `kuduy-woo-shipping`, `kuduy-woo-tax`, `kuduy-woo-payment-gateways` (admins only; API keys, passwords and other secrets are never shown) — and `kuduy-woo-report` (WooCommerce Analytics; customer emails masked).
- Changed: `kuduy-job-start` can also run `kuduy-woo-product-update`, `kuduy-woo-variation-update`, `kuduy-woo-product-stock` and `kuduy-woo-coupon-update` over many items, each with its own undo. Order changes and trash are not allowed as jobs.
- Changed: while WooCommerce is active, `kuduy-post-create`, `-update`, `-trash` and `kuduy-post-meta-set` refuse products, variations, orders and coupons and name the WooCommerce tools to use instead. Reading them still works.
- Security: results and audit rows also mask WooCommerce secrets (`api_signature`, `consumer_key`, `order_key`, `cart_hash`, `payment_token`) and the value of any settings field of type `password`.

## 0.5.1 — 2026-11-08

- Changed: `kuduy-job-status` results now name each item (`index` in the job, `object_id` of the post or image it acted on), next to its undo snapshot.
- Added: `kuduy-seo-status` and `kuduy-seo-verify` warn while Settings → Reading discourages search engines, because WordPress then adds noindex, nofollow to every page whatever the SEO plugin says.
- Changed: `kuduy-seo-audit` explains that `total` counts all published posts of the type (for paging), not the posts with missing data.

## 0.5.0 — 2026-11-08

**Phase 6: SEO and media (14 new tools).** Works with Rank Math, Yoast SEO, All in One SEO and SEOPress; SEO changes are refused while two SEO plugins are active.

- Added (Phase 6): All in One SEO and SEOPress support for the SEO tools (through each plugin's own abilities), `kuduy-seo-verify` (reads the live page's SEO tags and compares them with what you expect) and `kuduy-seo-audit` (posts without their own SEO title, description or keyword).
- Added (Phase 6): `kuduy-media-usage` (where an image is used), `kuduy-media-upload` (add an image from an HTTPS URL), `kuduy-media-regenerate` (rebuild thumbnail sizes).
- Added (Phase 6): background jobs — `kuduy-job-start` runs `kuduy-seo-update`, `kuduy-media-update` or `kuduy-media-regenerate` over up to 500 items, `kuduy-job-status`, `kuduy-job-cancel`. Each item is checked like a single call and keeps its own undo. New database table `kuduy_jobs` (created automatically on update).
- Added (Phase 6): `kuduy-seo-status`, `kuduy-seo-get`, `kuduy-seo-update` — a post's SEO title, description, focus and extra keywords, canonical, robots, Open Graph and X, through Rank Math's or Yoast SEO's own APIs, with each plugin's own permission rules and undo. Refused while two SEO plugins are active.
- Added (Phase 6): `kuduy-media-list`, `kuduy-media-get` (can show Claude the image itself, so alt text describes what it shows), `kuduy-media-update` (alt text, decorative images, title, caption, description; undo).

## 0.4.1 — 2026-11-06

- Added: the sign-in approval screen offers site administrators an extra, unticked "Manage plugins, themes, users and settings" box when the app did not ask for it. claude.ai asks only for read and write, so until now it could never use admin tools (plugins, themes, users, settings, Elementor Kit and Theme Builder conditions). Tick it only if Claude should manage those; existing connections keep their access until you reconnect.

## 0.4.0 — 2026-11-05

- Added (Phase 5): Elementor read tools — `kuduy-elementor-documents`, `kuduy-elementor-get` (element tree, outline, subtree, unregistered types), `kuduy-elementor-widgets`, `kuduy-elementor-widget-schema` (classic controls per breakpoint, v4 JSON Schema), `kuduy-elementor-kit`.
- Added (Phase 5): Elementor write tools — `kuduy-elementor-edit` (1–50 patch operations: update, add, move, duplicate, remove; only named settings change, other breakpoints keep their values; all-or-nothing; undo), `kuduy-elementor-create` (new Elementor page from a tree), `kuduy-elementor-kit-update` (global colors and fonts, undo), `kuduy-elementor-verify` (checks the rendered and live page for element ids and texts).
- Added (Phase 5): `kuduy-elementor-v4-globals`, `kuduy-elementor-v4-classes`, `kuduy-elementor-v4-variables` — Elementor 4.3+'s own abilities for v4 global classes and variables, behind KUDUY's scopes, permission checks, snapshots and audit.
- Tested on Elementor 3.35.9 as well as 4.3.6.
- Tested with the free Elementor add-ons Essential Addons, Ultimate Addons and Premium Addons; the widget list names the plugin each widget comes from.
- Added (Phase 5, Elementor Pro): `kuduy-elementor-theme-templates` (Theme Builder templates with location, display conditions, live status and conflicts) and `kuduy-elementor-theme-conditions` (set where a template appears; conflicts refused unless allowed; undo). `kuduy-elementor-create` gains `template_type` to create headers, footers, single, archive and 404 templates. Needs `edit_theme_options`.

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

### 2026-11-05 — Phase 5 research (Elementor 4.3.6)

- Read Elementor 4.3.6 source (wordpress.org ZIP). Findings in `RESEARCH.md` §8: `Document::save()` path (kses, hooks, version, CSS file **deleted** and rebuilt on next view); **unregistered element types are silently dropped on save**; v3 responsive controls are registered once with `is_responsive` when custom breakpoints are on; v4 atomic prop types export JSON Schema with a `$$type` envelope; Kit, global classes and variables storage.
- New: Elementor ships its own MCP abilities (27, off by default via `elementor_mcp_enabled`), v4-first with only a v3 allowlist.
- Plan `docs/plans/phase-5-elementor.md`; decision **D7** (own tools vs. wrapping Elementor's abilities vs. hybrid) waits for the user.

### 2026-11-05 — Phase 5: D7 decided, Elementor read pass

- D7 decided by the user: **hybrid** (own tools on Elementor's public document API; Elementor's own abilities for v4 extras on 4.3+).
- Integration tests now install and activate Elementor (default 4.3.6, `KUDUY_ELEMENTOR` to change, `none` to skip) before WordPress boots; the runner prints the Elementor version used.
- `Modules\Elementor\Elementor` (availability, documents, tree walk, unregistered types, type sources) and `ElementorRead` (5 tools). PHPStan gets a minimal Elementor stub (`tests/phpstan/elementor.stub`, via `scanFiles`).
- **Found in testing:** Elementor keeps controls with CSS selectors in a separate `style_controls` list that `get_controls()` omits outside the editor, so the heading's `align` was missing; the schema tool reads both lists.
- Tests: `ElementorReadTest` (7 tests); 132/132 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36 with Elementor 4.3.6; smoke 26/26 (one rerun after a slow plugin download).

### 2026-11-05 — Phase 5: Elementor write pass; dev-machine disk fix

- `Tree` (pure tree operations: find, locate, insert with nesting rules, remove, fresh unique ids), `Elementor::check_settings()` (classic control names from both control lists + per-device keys + `__globals__`/`__dynamic__`; v4 props through each prop type's `validate()`), `ElementorWrite` (edit, create, kit-update; snapshots + undo), `ElementorVerify`.
- **Found in testing:** Elementor needs element settings as an array (`sanitize_settings()` throws for an empty object); `documents->get()` caches per request, so reads after a save use `get( $id, false )`.
- **Disk full on the dev machine:** Playground CLI leaves a ~300 MB WordPress copy in the temp folder after every run (killed runs included); 25 leftovers filled C: (111 MB free). Removed them (8.0 GB free) and added `scripts/playground-temp.mjs`, called by the integration, smoke, release-check and update-check scripts after each run.
- Tests: `ElementorWriteTest` (8 tests); 141/141 on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36 with Elementor 4.3.6.

### 2026-11-05 — Phase 5: Elementor 3.x run; v4 globals through Elementor's abilities

- Full suite on Elementor **3.35.9** (latest 3.x): all pass on WP 7.1/PHP 8.3 and WP 6.9/PHP 8.1; v4-only tests skip. CI now pins Elementor per job: 3.35.9 with WP 6.9/PHP 8.1, 4.3.6 with WP 7.1/PHP 8.3.
- `ElementorAbilities` (D7 hybrid): runs `elementor/global-classes-resource`, `elementor/global-variables-resource`, `elementor/manage-classes`, `elementor/manage-global-variable` in-process, after Elementor's own `check_permission()` (its `execute_guarded()` does not check), with Elementor's input schema read from the protected definition. Variables are undoable (Kit meta); classes get a copy-only snapshot that says so. Unavailable with a clear reason on Elementor before 4.3 or if the internals change.
- Found: Elementor grants its global-class capabilities through a database update on `admin_init`; until someone opens wp-admin after installing/updating Elementor, class changes are refused. KUDUY's error now says so; the test runs Elementor's updater like a wp-admin visit would.
- Tests: `ElementorAbilitiesTest` (4 tests); 145/145 on WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6; 145 with 5 skips on WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9; smoke 26/26.

### 2026-11-05 — Phase 5: add-ons; Elementor Pro research

- Test runner: `KUDUY_PLUGINS=slug@version,…` installs more wordpress.org plugins. CI's WP 7.1 / Elementor 4.3.6 job adds Essential Addons 6.8.8, header-footer-elementor 2.9.6 and Premium Addons 4.11.117.
- `ElementorAddonsTest` (EL9): each add-on's widgets go through discovery, schema, create, edit and render with the generic tools — all pass with no add-on specific code. `kuduy-elementor-widgets` now also returns the plugin's name (`plugin`). JetElements: blocked (paid).
- Elementor Pro 4.3.2 (licensed ZIP from the user, kept out of git via `.gitignore`, read from a scratch copy): Theme Builder template types, conditions storage and API, conflict check, and Pro's own site-parts abilities recorded in `RESEARCH.md` §8; Theme Builder tool plan in `docs/plans/phase-5-elementor.md`.
- Tests: 148/148 on WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + 3 add-ons; 148 with 8 skips on WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9; smoke 26/26.

### 2026-11-05 — Phase 5: Theme Builder (Elementor Pro)

- New `ThemeBuilder` tools on Pro's public conditions manager (not Pro's own `manage-site-parts` ability, which only needs `edit_posts`): conditions need scope `kuduy:admin`, `edit_theme_options` and `edit_post` on the template. Each condition is checked against Pro's registered conditions; conflicts come from Pro's own check; the previous conditions are snapshotted.
- `kuduy-elementor-create` with `template_type` goes through `documents->create()`, then the usual checked save.
- Test runner: `KUDUY_LOCAL_PLUGINS=file.zip,…` installs licensed ZIPs from the repo folder (git-ignored; local only). PHPStan stubs for the Pro classes used.
- Tests: `ElementorThemeBuilderTest` (EL8, 3 tests). 151 pass / 3 skipped (add-ons not installed) on WP 7.1.4/PHP 8.3.36 and on WP 6.9.11/PHP 8.1.34, both with Elementor 4.3.6 + Pro 4.3.2; 148 with 8 skips on WP 6.9.11 + Elementor 3.35.9 (Pro needs core 4.0+); smoke 26/26.

### 2026-11-05 — Phase 5: EL10 with claude.ai on staging

- Claude desktop app sessions could not run EL10: their Kuduy connector stayed on a stale entry (33 tools, every call 404; the site's "Recent tool calls" stayed empty) even after the connector URL was changed and re-added in claude.ai. The endpoint itself was fine (POST 200; browser GET 405 because `/mcp` is POST only).
- Run instead from the claude.ai web chat against Hostinger staging (0.4.0, WP 7.1.4, Elementor 4.3.6, Elementor Pro 4.0.4, WooCommerce 11.2.2): 15 of 16 steps pass — draft landing page from a prompt, desktop-only edit keeps tablet/mobile values, snapshot undo, verify 11/11 ids and 7/7 texts, draft header template (id 404), page trashed. `kuduy-elementor-theme-conditions` was not offered because claude.ai's grant is `kuduy:read kuduy:write`; the tool needs `kuduy:admin`.
- Findings: v4 heading `align` uses start/center/end/justify; `_title` on containers is refused by validation.

### 2026-11-05 — Phase 6 research and plan (SEO + media)

- Read the current wordpress.org source of Rank Math 1.0.281, Yoast SEO 28.8, AIOSEO 5.0.5 and SEOPress 10.4, and core's `WP_Ability::execute()` (7.1.4) → `RESEARCH.md` §9. New finding: all four register WordPress Abilities, unevenly (AIOSEO and SEOPress write post SEO; Rank Math reads only; Yoast's exist only on production-mode sites and cannot set the keyphrase). AIOSEO keeps its data in its own table, so raw meta writes would be wrong there.
- Plan `docs/plans/phase-6-seo.md` approved by the user: 14 tools (SEO status/get/update/verify/audit/bulk; media list/get/update/usage/upload/regenerate; job status/cancel).
- D8 decided (user): hybrid SEO adapters. D9 decided (user): own `kuduy_jobs` table with Action Scheduler or WP-Cron.

### 2026-11-06 — Phase 6: SEO resolver, Rank Math, Yoast, media tools

- `Modules/Seo`: resolver (version constants; one plugin only; `ready()` refuses Rank Math until its setup wizard is done or skipped — found in tests: it does not boot otherwise), normalized flat fields, `SeoTools`. Rank Math writes through its own `rankmath/v1/updateMeta` (internal `rest_do_request()`; the `is_protected_meta` filters it removes are put back); Yoast through `WPSEO_Meta` with its indexable rebuilt at once.
- `Modules/Media/MediaTools`; the executor passes image content blocks a tool returns under `_content`.
- Tests: `SeoRankMathTest`, `SeoYoastTest`, `SeoConflictTest`, `MediaTest`, `RendersHead` (in-process `<head>` from a fresh hook state; resets Rank Math's and Yoast's per-request caches, test-only). The runner skips Rank Math's wizard as an admin would. CI gets Rank Math (6.9, 7.1), Yoast (28.7 on 6.9 because 28.8 needs WordPress 7.0; 28.8 on 7.1) and a conflict job.
- Fixed the integration runner's pass/fail counting (`TESTING.md` §3). Smoke 26/26.

### 2026-11-08 — Phase 6: AIOSEO, SEOPress, verify, audit, media library, jobs

- `AioseoAdapter` (abilities only: its data is in its own table) and `SeopressAdapter` (abilities + target-keywords route); `Seo::ability()` runs a plugin ability as the current user. The resolver's "not supported yet" branch is gone.
- `SeoVerify` parses the live head with `WP_HTML_Tag_Processor`; `SeoAudit` reads through the adapter.
- `MediaLibrary`: usage, upload (`download_url` + `media_handle_sideload`), regenerate.
- `Jobs` + `JobTools` (D9); DB version 2 adds `kuduy_jobs`; cleanup drops finished jobs after 30 days; deactivate/uninstall unschedule the hook.
- Found in tests: AIOSEO keeps the first title and SEOPress registers its head output once per PHP request, so in-process head checks run once per test class; Rank Math's bundled Action Scheduler receives KUDUY's jobs.
- Plan change: one `kuduy-job-start` instead of separate bulk tools.
- Results: `TESTING.md` §3.

### 2026-11-08 — Phase 6 end-to-end on staging (claude.ai)

- Ran on Hostinger staging (KUDUY 0.5.0, WP 7.1.4/PHP 8.3.36, Yoast 28.8) from claude.ai web: SEO update/get/verify on a test post, `kuduy-media-get` with the image shown to Claude, alt text for 2 images through `kuduy-job-start` (WP-Cron ran it unforced in under a minute), `kuduy-seo-audit`, then full cleanup through snapshots and trash. All steps pass (`TESTING.md` §3, S4, S6, S9; `COMPATIBILITY.md`).
- Open: live robots `noindex, nofollow` vs Yoast's rendered `noindex, follow` with no noindex stored, likely the site's "Discourage search engines" setting. Re-check after the user switched it off: `blog_public` is 1 and live pages serve `index, follow`; the post-level check was not repeated. `kuduy-seo-get`'s rendered robots does not include core's `wp_robots` output. `kuduy-job-status` items lack the item id; `kuduy-seo-audit`'s `total` is easy to misread.
- Claude Code desktop sessions stayed on the 42-tool connector list after reconnect, revoke and an off/on toggle, so the run was done in claude.ai web.

### 2026-11-08 — Phase 6 closed

- Exit gate passed in Playground and on staging, so `ROADMAP.md` marks Phase 6 done and `FEATURES.md` marks alt text from the image and the live `<head>` check `Tested`.
- Fixed the three issues found on staging (Unreleased above). Tests first (`JobsTest`, `SeoVerifyTest`), then code. Results: `TESTING.md` §3.

### 2026-11-08 — Phase 7 started: WooCommerce research and plan

- Read WooCommerce 11.2.2 (staging's version) from source: CRUD for products, variations, orders, coupons, customers, reviews, shipping, tax; `wc-analytics` reports; HPOS (`OrderUtil`, option switch, capability mapping, compatibility declaration); capabilities and WooCommerce's REST permission functions; secrets and personal data (`RESEARCH.md` §10). 11.2.x needs WordPress 7.0, so the 6.9 test pair will use 11.0.1.
- Found: WooCommerce registers 7 abilities of its own (products query/create/update/delete, orders query, status, note), plus REST-bridge abilities only on its own experimental MCP endpoint. Its `wc/v3/payment_gateways` returns gateway secrets in clear text, and KUDUY's `Redactor` misses `api_signature` / `consumer_key` and type-based masking. Core post tools can currently write products and coupons with `wp_update_post`, skipping WooCommerce's lookup tables; the plan adds a guard.
- Plan `docs/plans/phase-7-woocommerce.md`: 29 `kuduy-woo-*` tools, scopes, per-object capability checks, snapshots, redaction, HPOS on/off tests, Playground setup (`KUDUY_WOO`, `KUDUY_HPOS`). D10–D13 proposed in `ARCHITECTURE.md` (CRUD path, no money-moving actions, PII masked by default, existing scopes). No code until the user approves.

### 2026-11-08 — Phase 7 WooCommerce plan approved

- The user approved `docs/plans/phase-7-woocommerce.md` and decided D10–D13: option A for each (WooCommerce CRUD only, with WooCommerce's own permission functions; no refunds, order creation, payment changes or permanent deletes, and customer emails confirm-first; customer data masked by default; existing scopes).

### 2026-11-09 — Phase 7 step 1: guards, redaction, WooCommerce test runs

- `Content::assert_writable()` in the core post tools (in `snapshot()` too, so a refused call leaves no snapshot); `Redactor` key additions and type-based masking. Tests: `WooGuardsTest` (W1), `SafetyTest::test_redactor_masks_woocommerce_secrets` (W2). The code edits landed before the first Playground run executed the tests, so the failing-first step was not observed.
- Runner: `KUDUY_HPOS=on|off` with WooCommerce (sets `woocommerce_custom_orders_table_enabled`, `woocommerce_coming_soon: no`, and `kuduy_test_hpos` for the tests to check); `AUTOMATIC_UPDATER_DISABLED` on every test site (a background update of WooCommerce 11.0.1 on WordPress 6.9 left `.maintenance` and PHPUnit never ran). CI: WooCommerce 11.0.1 on 6.9 and 11.2.2 on 7.1, each with HPOS on and off.
- `CoreUsersTest`: with WooCommerce active the last-admin demotion is refused by WooCommerce's own capability rule before KUDUY's; the test accepts that refusal.
- Local disk: C: was full (Playground leaves ~300 MB per run in the system temp folder); local runs used `TMP`/`TEMP` under `build/tmp` on D:. Results: `TESTING.md` §3.

### 2026-11-09 — Phase 7 step 2: WooCommerce status and product tools

- `Modules/Woo`: `Woo` (one field map for read, write and undo; attributes; variation attribute checks; WooCommerce permission wrapper), `WooStatus`, `ProductRead`, `ProductWrite`, `VariationWrite`; 10 tools, only listed while WooCommerce is active. WPCS learns WooCommerce's capabilities; PHPStan stub extended.
- TDD: `WooProductsTest` (10 tests) failed with the tools unregistered, then passed after two fixes found by the first green run (the trash tool read the product id after `delete()` had reset it to 0; the parent price range is formatted with the store decimals, so the test compares amounts).
- Plan change: creates keep no snapshot (like `kuduy-post-create`); a created product is undone with `kuduy-woo-product-trash`.
- Results: `TESTING.md` §3 (WooCommerce 11.2.2 on 7.1 and 11.0.1 on 6.9, HPOS on and off; runs without WooCommerce unchanged).

### 2026-11-09 — Phase 7 step 3: WooCommerce orders

- `Pii` (masked email, address reduced to names, company and country), `OrderRead` (`kuduy-woo-order-list`, `-get`), `OrderWrite` (`kuduy-woo-order-status`, `-note`). Customer emails are detected from the enabled customer `WC_Email` objects on the status change's notification hooks; undo unhooks WooCommerce's email dispatch for that change and disables customer emails while it runs.
- TDD: `WooOrdersTest` (8 tests) failed with the tools unregistered, then passed at once with HPOS on and off on both WordPress pairs.
- Plan changes: no undo for notes; `refunded` refused (WooCommerce creates a refund record for it).
- An intermittent `LifecycleTest` uninstall failure came back once in a run without WooCommerce and passed on re-run (`TESTING.md` §3).

### 2026-11-09 — Phase 7 step 4: coupons, customers, reviews

- `CouponTools`, `CustomerTools`, `ReviewTools` (11 tools); `AuditMasksAuditArgs` lets a tool mask its arguments before the executor writes the audit row and the snapshot input (customer addresses).
- TDD: `WooCouponsTest`, `WooCustomersTest`, `WooReviewsTest` (13 tests) failed with the tools unregistered; first green run fixed one wrong test assertion (view vs edit context). All pass with HPOS on and off on both WordPress pairs; runs without WooCommerce unchanged.

### 2026-11-09 — Phase 7 step 5: shipping, tax, gateways, reports

- `StoreSettings` (`kuduy-woo-shipping`, `kuduy-woo-tax`, `kuduy-woo-payment-gateways`) and `Report` (`kuduy-woo-report`); `Redactor::is_secret_key()` and `Pii::scrub()` helpers. Test doubles: `KuduyFakeGateway` (a gateway with secrets under a free name and a key-like name).
- TDD: `WooStoreSettingsTest`, `WooReportsTest` (8 tests) failed with the tools unregistered; one fix after the first green run (`extended_info` for report rows). All 29 planned WooCommerce tools now exist and pass with HPOS on and off on both WordPress pairs.

### 2026-11-09 — Phase 7 step 6: WooCommerce jobs

- `Jobs::TOOLS` gains the four WooCommerce update tools. TDD: `WooJobsTest` (bulk sale prices with a failing item and per-item undo, bulk stock) failed before the change; the refusal test for order tools passed before and after.

### 2026-11-09 — Release 0.6.0

- Version 0.6.0 (Phase 7 WooCommerce tools). Tagged after CI passed on the release commit, at the user's request.

### 2026-11-09 — Phase 7 WooCommerce on staging (claude.ai)

- Staging updated to 0.6.0 by the user (update offered after `kuduy-check-update` with `refresh: true`); check run from claude.ai web (Claude Code sessions still get the stale 42-tool connector list). 11 of 14 steps pass on WooCommerce 11.2.2 with HPOS on, sync off; order/customer masking and gateway secret masking could not run on this store (no orders, no customers, no gateway with secrets). Test product and coupon left in the trash. Results: `TESTING.md` §3, `COMPATIBILITY.md`.
- Found: the report tool read only the first page of intervals (wc-analytics pages them, 10 by default). Test first (`test_daily_intervals_cover_the_whole_range` failed with 10 of 30), then fixed (100 per page, all pages up to 1,000 intervals).

### 2026-11-09 — Phase 7 staging re-run (orders, customers, gateways)

- The user signed in; the session set up a test customer (example.test address), product and pending order and installed Stripe 11.1.1 in WP admin through the built-in browser, then ran the check in claude.ai web. 7 / 7 pass; secret masking of a *stored* gateway key could not be shown on the host (Stripe only takes keys through its own sign-in). Everything removed afterwards (order and product trashed, customer deleted, Stripe deleted).
- Three findings from that run fixed test first (`WooStoreSettingsTest`, `WooOrdersTest` failed, then passed): name-based masking skips choice fields; note authors that are email addresses are masked; `email` matches billing with `include_pii`.

### 2026-11-09 — Phase 7: ACF research and plan

- Read ACF 6.8.11 (staging's version) from source: value API, field groups and location rules, validation, the abilities it registers (off by default, definitions only), free field types, secrets (`password` field values, Google Maps key). Found: `update_field()` with an unknown name writes a "dummy" raw meta key, so KUDUY must only accept fields shown on the object. `RESEARCH.md` §10a.
- Plan `docs/plans/phase-7-acf.md` (4 tools, guard in `kuduy-post-meta-set`, jobs) and D14 proposed. No code until the user approves.

### 2026-11-09 — ACF plan approved

- The user approved `docs/plans/phase-7-acf.md` and decided D14: option A (own tools on ACF's public API, only fields shown on the object, definitions read only).

### 2026-11-09 — EL10 step 6 on staging

- Re-ran the Theme Builder conditions step of EL10 on Hostinger staging (claude.ai "Kuduy" connector with `kuduy:admin` granted, called from a Claude Code session in the Claude desktop app; KUDUY MCP 0.6.0, WP 7.1.4, Elementor 4.3.6, Elementor Pro 4.0.4). A draft header took a page condition with no conflicts, `include/general` was refused because the live header 386 uses it, and the conditions were cleared; the test page was trashed. All 16 EL10 steps have now passed on staging; Phase 5 still lacks a real-client run on Elementor 3.x.

### 2026-11-09 — Phase 7: ACF tools

- `Modules/Acf` (`Acf`, `AcfTools`): 4 tools, guard in `kuduy-post-meta-set`, `kuduy-acf-update` in the job allow-list, CI jobs for ACF on both pairs, PHPStan stub for ACF.
- TDD: `AcfTest` (9 tests) failed with the tools unregistered and passed at once after; the red run also showed ACF 6.8.11 denying raw meta writes to its fields (a test-key collision with `CoreEditorialTest`, fixed by renaming the test fields).

### 2026-11-09 — Release 0.7.0

- Version 0.7.0 (ACF tools, WooCommerce fixes from the staging runs). Tagged after CI passed on the release commit, at the user's request.

### 2026-11-09 — ACF on staging (claude.ai)

- Staging on 0.7.0. The session created a test field group and draft post in WP admin (built-in browser), ran the check in claude.ai web: 7 / 7 pass (values, password never shown, four refusals with nothing changed, post-meta guard, undo). Test post and field group trashed afterwards. `TESTING.md` §3, `COMPATIBILITY.md`.

### 2026-11-09 — Forms research and plan (D15–D17 proposed)

- Read Contact Form 7 6.2.4.1 (6.1.7 for WordPress 6.9: 6.2 needs WordPress 7.1 and PHP 8.3), Flamingo 2.6.4, WPForms Lite 2.0.4 and Fluent Forms 6.2.17 from their wordpress.org ZIPs; Gravity Forms from its docs (`RESEARCH.md` §10b). Findings: WPForms Lite stores no entries; Fluent Forms' own abilities are off by default; Flamingo's spam action sends the message to Akismet; CF7 mail settings decide where submissions are emailed. Plan `docs/plans/phase-7-forms.md` (8 `kuduy-forms-*` tools) and decisions D15–D17 waiting for approval; no code.

### 2026-11-09 — Forms plan approved

- The user approved `docs/plans/phase-7-forms.md` and decided D15–D17: option A (own tools on each plugin's API; inbox actions with undo, CF7 edits, mail routing confirm-first, no permanent delete; personal data masked by field type).

### 2026-11-10 — Phase 7: forms, Site Editor and cache tools built (tests at the end of Phase 7)

- At the user's request (2026-11-09, before going offline): build all remaining Phase 7 work first, commit locally, no push; run the full test suite and publish only when Phase 7 is complete.
- Forms (`Modules/Forms`, D15–D17 A): 9 tools — `kuduy-forms-status`, `-list`, `-get`, `-entries`, `-entry-get`, `-entry-update`, `-entry-note`, `-cf7-save`, `-cf7-mail` — over Contact Form 7 + Flamingo, Fluent Forms and WPForms Lite. Tests `FormsCf7Test`, `FormsFluentTest`, `FormsWpformsTest`; CI forms job on both pairs. Interim Playground runs (2026-11-10): all forms tests pass on WP 7.1.4 and 6.9.11. Build changes to the plan: CF7 editing split into `-cf7-save` (write) and `-cf7-mail` (admin), the note tool is marked destructive so the executor keeps its snapshot (found by the first run).
- Site Editor (`Modules/Blocks`, D18 A, decided under the standing instruction): 11 tools — `kuduy-block-types`, `kuduy-post-blocks`, `kuduy-site-templates`, `-template-get`, `-template-update`, `-template-revert`, `kuduy-global-styles-get`, `-update`, `kuduy-widgets`, `kuduy-widget-save`, `kuduy-widget-remove` — through core's REST controllers in-process. Found in the interim run: core's global-styles route does not check `edit_css`, so KUDUY does; a fresh Playground install's leftover widget areas made core move new widgets to inactive (test setup fixed).
- Cache (`Modules/Cache`, D19 A, decided under the standing instruction): `kuduy-cache-status`, `kuduy-cache-purge` over LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache and WP Rocket (docs only), plus object-cache flush. Test `CacheTest`; CI cache job (LiteSpeed Cache 7.9.3).
- Runner: `KUDUY_FILTER` runs only matching tests (local debugging).

### 2026-11-10 — Phase 7: multilingual (Polylang) built

- `Modules/Multilingual` (D20 A, decided under the standing instruction): `kuduy-lang-status`, `-get`, `-set`, `-link`, `-translate` over Polylang 3.9.2's public API; WPML not supported until a licensed copy is tested. Test `MultilingualTest`; CI Polylang job on both pairs. Not run yet (full suite at the end of Phase 7).

### 2026-11-10 — Phase 7: developer mode and content types built

- `Modules/Dev` (D21 A and D4, decided under the standing instruction): read-only `kuduy-dev-files`, `-file-read`, `-search`, `-log`, `-db-schema`, `-db-query` (admin scope, `manage_options`; secret files refused, credentials masked, read-only capped queries); behind D4 `kuduy-dev-file-write` and `kuduy-dev-php` (`KUDUY_MCP_ALLOW_CODE_EXEC` + `kuduy:code` + `edit_plugins` + `manage_options`). Test `DeveloperTest` (code tools run with `KUDUY_CODE_EXEC=1`, own CI job). WP-CLI not offered.
- `Modules/Types` (D22 A): `kuduy-content-types`, `-content-type-save`, `-content-type-remove`; definitions in option `kuduy_mcp_content_types` (removed on uninstall), registered on `init`. Test `ContentTypesTest`.
- Not run yet: the full suite runs once all of Phase 7 is built.

### 2026-11-10 — Phase 7 full test round

- PHPCS, PHPStan level 6: clean. Smoke 26/26. Integration on WP 7.1.4 and 6.9.11: base, forms, LiteSpeed Cache, Polylang, ACF, WooCommerce HPOS on/off, plus the D4 code-tools run. Three test-side issues found and fixed (hidden tools answer "Unknown tool"; the widget test needs a fresh-request view of core's `$sidebars_widgets`; OAuth metadata lists `kuduy:code` with the constant on); the affected tests then pass on both pairs and in the code run. Results in `TESTING.md` §3a, `COMPATIBILITY.md`. Not yet checked on staging with claude.ai.

### 2026-11-10 — Phase 7 on staging (claude.ai)

- Released 0.8.0 (tagged after CI passed, at the user's request); staging updated itself. Test plugins (CF7 + Flamingo, Fluent Forms, Polylang, LiteSpeed Cache) installed with `kuduy-plugin-install`, one submission per form; the user ran the 12-step check in claude.ai: 11/12 pass. Fixed: undoing `kuduy-lang-set` on an object without a language now removes the language again (Polylang's `delete_translation()` / `delete_language()`); `kuduy-block-types` returns `count`. Afterwards the test page was trashed, the Polylang languages deleted and the five test plugins removed.
- Found while checking the fix: `MultilingualTest` had never run in Playground (Polylang's API loads only in a context; the class skipped silently). The runner now adds English and German before the tests for Polylang runs, and the test fails when Polylang is active without its API. All four Polylang tests pass on WP 7.1.4 and 6.9.11.

### 2026-11-10 — Road to 1.0: Phase 8 hidden UI built

- Working order agreed with the user: build all remaining work (Phase 8, Phase 10 docs, block navigation menus, WooCommerce shipping/tax writes, Rank Math extras, media replace/rename), then the full test suite, then the Phase 9 security work, then one push. New decisions take the recommended option and are marked for review.
- `Admin/HiddenMode` + `Admin/Cli` (D23 A): "Hide KUDUY MCP from WP admin" on the Tools page (administrators, nonce), option `kuduy_mcp_hidden_mode`, page slug redirected to the dashboard, permalink notice hidden; recovery `wp kuduy ui show`, the option, or `KUDUY_MCP_SHOW_UI`. Uninstall removes the option (was the planned name `kuduy_mcp_ui_hidden`). Test `HiddenModeTest` written.
- Block navigation menus (`Modules/Blocks/NavigationTools`, D18): `kuduy-nav-menus`, `-nav-menu-get`, `-nav-menu-save`, `-nav-menu-trash` through core's `wp/v2/navigation`; links given as a simple list become navigation-link / navigation-submenu blocks. Test added to `SiteEditorTest`.
- WooCommerce shipping and tax writes (`Modules/Woo/StoreSettingsWrite`, D24 A): `kuduy-woo-shipping-zone-save`, `kuduy-woo-shipping-method-save`, `kuduy-woo-tax-rate-save`, `kuduy-woo-tax-rate-delete` through WooCommerce's wc/v3 controllers (admin scope, `manage_woocommerce`), each with undo. Test `WooSettingsWriteTest`.
- Rank Math extras (`Modules/Seo/RankMathExtras`, D25 A): redirects (list, save, trash) through Rank Math's Redirection/DB classes; schema (get, save, delete) through its updateSchemas/updateMeta routes, undo by meta id incl. the rich-snippet flag; `kuduy-seo-sitemap` for Rank Math (ability), Yoast (option) and WordPress core. Test `RankMathExtrasTest`.
- Media replace and safe rename (`Modules/Media/MediaFiles`, D26 A): `kuduy-media-replace` (same id and file name, same image type, https download, sizes regenerated) and `kuduy-media-rename` (original + sizes, refused while linked by URL); old files backed up to `uploads/kuduy-backups/` for undo and pruned by the cleanup job. Tests added to `MediaLibraryTest`.
- Phase 10 docs: `docs/INSTALL.md`, `docs/CONNECT-CLAUDE.md` (states which Claude apps are verified), `docs/DEVELOPER.md`, `docs/RELEASE-CHECKLIST.md`, and `docs/TOOLS.md` generated from the registered tools by `npm run docs:tools` (`scripts/tool-reference.mjs`; 165 tools). README links them.

### 2026-11-10 — O11 revoke checked with claude.ai

- On staging (0.8.0) the user revoked the claude.ai connection on Tools → KUDUY MCP: the next `kuduy-site-info` call from the same chat failed with "needs you to sign in again" and claude.ai offered Reconnect; after reconnecting the call worked. Desktop, Claude Code's own sign-in and refresh after one hour remain open.
- Renamed (display name, user's decision D27): the plugin is now called **KuDiagnostics** in Plugins, Tools → KuDiagnostics, notices, the MCP server title, the OAuth resource name, the updater details and the docs. The internal slug `kuduy-mcp`, tool names, connector URL, options and constants are unchanged, so installed sites keep updating and Claude connections keep working.

### 2026-11-10 — Road to 1.0: full test round, fixes, security review

- Test round: 26 combinations (WP 6.9 + 7.1; base, Elementor 3.x, add-ons, the four SEO plugins and their conflict, WooCommerce HPOS on/off, ACF, forms, cache, Polylang, code tools). Fixed from it: `kuduy-dev-php` left its 30 s time limit on the request (now restored); Rank Math redirect sources read back as empty (DB class returns arrays); tests: Rank Math tables created as its Modules screen does, the sitemap value renamed to `core` (an auto-fixer had changed the expected text), hidden-mode test asked for a non-existent route. Two crashes came from running three Playground sites at once and did not repeat one at a time.
- Security (Phase 9): read-only DB query hardening (R1), `edit_css` for Global Styles CSS (R2), fuzz and load tests; review in `SECURITY.md` §4a.

### 2026-11-10 — WPBakery Page Builder support (D29)

- User request: full WPBakery support next to Elementor, published as soon as possible. No licensed copy available (the user confirmed paid plugins are tested later), so it was built from WPBakery's public knowledge base and WordPress core's shortcode rules and marked UNVERIFIED against the plugin (RESEARCH.md §10f).
- `Modules/WpBakery`: `Shortcodes` (tokenizer + range parser that pairs tags like WordPress; nodes keep their original text, so unchanged pages round-trip byte for byte and an edit rewrites one tag), `Layout` (ids by position, structure and tree, building rows/columns/elements from JSON, Design Options CSS, responsive offsets, stored formats for links, lists and repeating groups, validation), `WpBakery` (registry access through `WPBMap`, builder detection, post lock and hash guard, save with page meta), `WpBakeryRead` (5 tools), `WpBakeryWrite` (11 tools, snapshot + undo).
- `PostWriter::guard_builder` also refuses content writes on WPBakery pages while WPBakery is active.
- Tests: `WpBakeryParserTest` (every job) and `WpBakeryTest` with a stand-in registry (`tests/integration/fakes/wpbakery.php`, runner option `KUDUY_FAKES`, two new CI jobs with Elementor active). First run: 28 of 29 passed; the one failure was a wrong expectation in the test.
- Paused, not in this release: Gravity Forms and WPForms Pro entry adapters (started the same day from their documented APIs).

### 2026-11-10 — Upgrade round, phase A: audit, plan, integration registry (D30)

- User brief: bring the existing plugin up to the integration list advertised by Novamira Pro (58 integrations in 11 groups, plus memory, design intelligence, skills, read/ghost modes, token work), as an upgrade, plan first. Novamira is AGPL: the list is used as a checklist only.
- Audit against the code and test evidence: 8 of the 58 targets are fully supported today, 17 partly, 12 detection only, 21 not at all; of the 50 that are not full, 33 have a free copy that can be tested here and 17 are paid. wordpress.org versions checked the same day.
- Plan in phases B–L (`IMPLEMENTATION-PLAN.md`); paid products are built only when a licensed copy is provided. Three brief items are not planned as written: raw database updates (rule 6), permanent deletes, PHP 8.0.
- Built: `Modules/Core/IntegrationCatalog` (67 rows) and `kuduy-integrations`; `IntegrationsTest` (IR1–IR3).
- Still paused in the working tree: Gravity Forms and WPForms Pro entry adapters (phase B).

### 2026-11-13 — Avada Theme and Avada Builder (D31)

- Owner's priority: Avada before the rest of the upgrade plan. Plan and verified facts: `docs/plans/avada.md`. No Avada copy on the development machine; Avada's code and a real page were read (read only) on a live Avada 7.16.4 site connected to the session.
- `Modules/Avada`: `Avada` (detection, registry via `awb_maybe_init_element_maps()`, builder detection, shared-item guard, save + verify, change report), `AvadaLayout` (roles, values, responsive split, widths, building containers/columns/nested columns/elements, outline, structure, validation), `AvadaRead` (8 tools), `AvadaWrite` (12 tools). Reuses the WPBakery parser and tree operations (their two id error messages made builder-neutral).
- Tests: `AvadaParserTest` (every job), `AvadaTest` with a stand-in (`tests/integration/fakes/avada.php`) and two CI jobs. First run 7 of 10; the three failures were test expectations (an existing setting changes in place; WordPress strips whole script blocks; the Global Options tool is hidden without options).
- Phase B forms work (Gravity Forms, WPForms Pro, Ninja Forms, Formidable) stays paused and uncommitted; its first test run never started (a run name with capitals).
