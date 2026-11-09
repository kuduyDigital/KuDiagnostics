# KUDUY MCP — Testing

A PHP function existing is not proof that a feature works. A row is `pass` only with a passing automated test or recorded manual evidence, with the date and versions. Rows without a result have **not been run**.

## 1. Strategy

| Layer | Tool | What it proves | Where |
|---|---|---|---|
| Syntax | `php -l` on PHP 8.1, 8.2, 8.3, 8.4 | Code parses on every supported PHP | CI |
| Coding standards | PHPCS with WordPress Coding Standards 3.4.1 | Escaping, sanitizing, nonces, prepared SQL, i18n | Local + CI |
| Static analysis | PHPStan 2.3.5 level 6 + phpstan-wordpress | Types and WordPress API misuse | Local + CI |
| Integration | PHPUnit 10.5 **inside WordPress Playground** (`scripts/test-integration.mjs`; Elementor installed and active first, version from `KUDUY_ELEMENTOR`, default 4.3.6, `none` to skip; more wordpress.org plugins with `KUDUY_PLUGINS=slug@version,…`; licensed ZIPs kept git-ignored in the repo folder with `KUDUY_LOCAL_PLUGINS=file.zip,…`, local runs only) | Real WordPress: REST dispatch, OAuth, DB tables, cron, updater hooks. Pass/fail read from the JUnit report; the WP/PHP versions actually used are printed | Local + CI: WP 6.9 / PHP 8.1 and WP 7.1 / PHP 8.3 |
| Real HTTP smoke | `scripts/smoke-mcp.mjs` | The endpoint over real HTTP with Elementor + WooCommerce active and a noisy mu-plugin; the whole OAuth sign-in with a real `wp-login.php` login | Local + CI |
| Release | `scripts/build-release.php` + `scripts/check-release.mjs` | The ZIP installs as an upload, activates, creates tables and routes, ships no dev files | CI on every push and before every release |
| Real update | `scripts/check-update.mjs <from> <to>` | Install an older GitHub release, let core find and install the newer one, data kept | Manual |
| Security probe | Throwaway local site + scripted HTTP requests (kept out of the repo) | Confirms or rules out exploit paths found in reviews | Manual, per audit |
| End to end | Real Claude clients against a public HTTPS staging site | claude.ai, Desktop, Claude Code sign in and call tools | Manual (O11) |

Process: test first (red), then code (green); every new security fix gets a regression test; manual checks are written down here with the date.

## 2. Commands

See `CLAUDE.md` → Commands. Integration: `KUDUY_WP=<6.9|7.1> KUDUY_PHP=<8.1|8.3> node scripts/test-integration.mjs`. Smoke: `node scripts/smoke-mcp.mjs`.

## 3. Latest results (2026-11-05, v0.4.0)

| Check | Result |
|---|---|
| PHPCS (WPCS) | 0 errors, 0 warnings |
| PHPStan level 6 | No errors |
| `composer audit` | No advisories |
| `npm audit` | 3 moderate in `@wp-playground/cli` dependencies (dev only, not shipped) |
| Integration, WordPress 6.9.11 / PHP 8.1.34 + Elementor 4.3.6 | **145 / 145 pass** |
| Integration, WordPress 6.9.11 / PHP 8.1.34 + Elementor 3.35.9 (CI pairing) | **148 pass, 8 skipped** (v4-only and add-on tests) |
| Integration, WordPress 7.1.4 / PHP 8.3.36 + Elementor 3.35.9 | **141 pass, 1 skipped** (before the v4 tools; v4-only tests skip) |
| Integration, WordPress 7.1.4 / PHP 8.3.36 + Elementor 4.3.6 + 3 free add-ons (CI pairing) | **148 / 148 pass** (Theme Builder tests skip: no Pro in CI) |
| Integration, WordPress 7.1.4 / PHP 8.3.36 + Elementor 4.3.6 + Elementor Pro 4.3.2 (local, 2026-11-05) | **151 pass, 3 skipped** (add-ons not installed in this run) |
| Integration, WordPress 6.9.11 / PHP 8.1.34 + Elementor 4.3.6 + Elementor Pro 4.3.2 (local, 2026-11-05) | **151 pass, 3 skipped** (add-ons not installed in this run) |
| Integration with SEO plugins (local, 2026-11-06): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Rank Math 1.0.281 | **157 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Rank Math 1.0.281 | **157 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Yoast 28.8 | **155 pass, 5 skipped** (counted from the JUnit test cases, before the runner fix below) |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Yoast 28.7 | **156 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Rank Math + Yoast (conflict) | **153 pass, 4 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no SEO plugin (CI pairing) | **153 pass, 9 skipped** |
| Integration (local, 2026-11-08, Phase 6 complete code): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Rank Math 1.0.281 | **163 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Rank Math 1.0.281 | **163 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Yoast 28.8 | **162 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Yoast 28.7 | **162 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + AIOSEO 5.0.5 | **161 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + AIOSEO 5.0.5 | **161 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + SEOPress 10.4 | **161 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + SEOPress 10.4 | **161 pass, 5 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 + Rank Math + Yoast (conflict) | **158 pass, 4 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no SEO plugin | **158 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-08) | **26 / 26 pass** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 2) | **26 / 26 pass** |
| Integration (local, 2026-11-09, Phase 7 step 3: orders): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on / off | **180 pass, 4 skipped** each (184 tests). With the order tools unregistered all 8 `WooOrdersTest` tests failed and nothing else did |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on / off | **180 pass, 4 skipped** each |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6, no WooCommerce | First run: **1 failure** — `LifecycleTest::test_uninstall_with_opt_in_drops_tables_and_options` (`kuduy_oauth_clients still exists`), the same intermittent failure seen once on 2026-11-08; re-run: **156 pass, 4 skipped**. Not caused by this change (no lifecycle code touched); open item: intermittent `DROP TABLE` in Playground's SQLite layer, UNVERIFIED cause |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no WooCommerce | **151 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 3) | **26 / 26 pass** |
| Integration (local, 2026-11-09, Phase 7 step 4: coupons, customers, reviews): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on / off | **193 pass, 4 skipped** each (197 tests). With the tools unregistered all 13 new tests failed and nothing else did; the first green run had one test error (it read the minimum spend in view context, where WooCommerce shows "0" for empty) |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on / off | **193 pass, 4 skipped** each |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6, no WooCommerce; WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no WooCommerce | **156 pass, 4 skipped**; **151 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 4) | **26 / 26 pass** |
| Integration (local, 2026-11-09, Phase 7 step 5: shipping, tax, gateways, reports): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on / off | **201 pass, 4 skipped** each (205 tests). With the tools unregistered all 8 new tests failed and nothing else did; the first green run showed report rows carry product names only with `extended_info` (now always requested) |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on / off | **201 pass, 4 skipped** each |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6, no WooCommerce; WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no WooCommerce | **156 pass, 4 skipped**; **151 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 5) | **26 / 26 pass** |
| Integration (local, 2026-11-09, Phase 7 step 6: WooCommerce jobs): WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on / off | **204 pass, 4 skipped** each (208 tests; `WooJobsTest`: bulk sale prices with one failing item and per-item undo, bulk stock, order status/notes/trash refused as jobs). Before the change the two job tests failed |
| Integration without WooCommerce (WP 7.1.4 + Elementor 4.3.6; WP 6.9.11 + Elementor 3.35.9) | **156 pass, 4 skipped**; **151 pass, 9 skipped** |
| Smoke over real HTTP (2026-11-09, after Phase 7 step 6) | **26 / 26 pass** |
| Phase 7 with a real client (claude.ai web → Hostinger staging, KUDUY 0.6.0, WP 7.1.4 / PHP 8.3.36, WooCommerce 11.2.2 with HPOS on and sync off, BDT store in coming-soon mode, 2026-11-09 08:36–08:38 UTC) | **11 / 14 steps pass, 3 not testable on this store**: status; product 411 created as draft, sale price + stock management (snapshot 8), stock set 5 then −2 → 3 (snapshots 9, 10), get; `kuduy-post-update` on the product refused naming the kuduy-woo tools (W1); coupon 412 created, found by code in upper case, trashed (snapshot 11); shipping (2 zones, free shipping in BD), tax (off, 3 classes); gateways bacs/cheque/cod listed, none has a secret field; revenue report 0 orders; cleanup: product and coupon in trash. Not testable: order and customer masking (store has no orders or customers), gateway secret masking (no gateway with secrets) — covered in Playground (W6, W10, W13). Found: the 30-day revenue report listed only 10 daily intervals (wc-analytics pages intervals, 10 by default) — fixed after the run (`WooReportsTest::test_daily_intervals_cover_the_whole_range`) |
| Integration (local, 2026-11-09, report intervals fix): WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on / off | **205 pass, 4 skipped** each (209 tests). Before the fix the new test failed with 10 of 30 intervals. Smoke **26 / 26** |
| Phase 7 re-run on staging (claude.ai web, driven from the KUDUY session in the built-in browser; KUDUY 0.6.0, HPOS on, 2026-11-09) | **7 / 7 pass**. Setup in WP admin: test customer #3 (`kuduy.e2e.customer@example.test`, no welcome mail), product 413, pending order #414 with test billing data, Stripe 11.1.1 installed (test mode, not connected: its settings offer only Stripe sign-in, no manual key fields). Order list/get masked (`k***@example.test`, no street, phone, IP or order key); with `include_pii` full street, email, phone; status to processing without `confirm` → `done: false`, "Processing order" email listed, order still pending, no note added; customer list/get masked, full with `include_pii`; Stripe: `test_secret_key`, `secret_key`, `test_webhook_secret`, `webhook_secret`, `api_credentials` have `value: null` (empty — no keys stored, so a stored secret was not shown on the real host; covered by W13 in Playground). Cleanup: order #414 and product 413 trashed, customer #3 deleted, Stripe deactivated and deleted. Found (fixed after the run, test first): Stripe's `sepa_tokens_for_*` checkboxes were hidden by the name rule; order notes showed the author's display name, an email address, unmasked; with `include_pii` the top-level order `email` stayed masked |
| Integration (local, 2026-11-09, fixes from the staging re-run): WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on / off | **205 pass, 4 skipped** each (209 tests). Before the fixes the three new assertions failed (`WooOrdersTest` ×2, `WooStoreSettingsTest`) and nothing else did. PHPCS, PHPStan clean; smoke **26 / 26** |
| Integration (local, 2026-11-09, Phase 7 ACF): WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + ACF 6.8.11 | **165 pass, 4 skipped** each (169 tests). With the tools unregistered the 9 `AcfTest` tests failed, plus `CoreEditorialTest::test_meta_set_get_undo_and_protected_keys`: its meta key `subtitle` matched a test ACF field, and ACF denied the raw write (test fields renamed `kacf_*`) |
| Integration: WP 7.1.4 + WooCommerce 11.2.2 (HPOS on); WP 7.1.4 and WP 6.9.11 + Elementor 3.35.9 without plugins | **205 pass, 4 skipped**; **156 pass, 4 skipped**; **151 pass, 9 skipped**. PHPCS, PHPStan clean; smoke **26 / 26** |
| ACF with a real client (claude.ai web → Hostinger staging, KUDUY 0.7.0, WP 7.1.4, ACF 6.8.11 free, 2026-11-09) | **7 / 7 pass**. Setup in WP admin: field group "KUDUY test" (post 417; `kuduy_subtitle` text required, `kuduy_price` number min 0, `kuduy_color` select red/blue, `kuduy_secret` password; posts) and draft post 422 (`kuduy-post-create`). Status (6.8.11, free, abilities off, 1 group); field groups on post 422; update of all four fields (snapshot 17); get: values back, `kuduy_secret` `value: null`, `has_value: true`, the secret text nowhere in any result; refused with nothing changed: color "green" ("use one of red, blue"), price −5 ("equal to or higher than 0"), empty subtitle ("value is required"), unknown `page_only`; `kuduy-post-meta-set` on `kuduy_subtitle` refused naming `kuduy-acf-update`; undo → all four `has_value: false`. Note: number values come back as strings ("12.5"), as ACF stores them. Cleanup: post 422 and the field group trashed |
| Integration (local, 2026-11-08, after the Phase 6 close-out fixes: job results name their item, search-visibility note, audit `total` described): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + Yoast 28.8 | **158 pass, 5 skipped** (163 tests). Before the fix the two new assertions failed as expected; that run also had one `LifecycleTest` uninstall failure (`kuduy_oauth_clients still exists`) that did not repeat in any later run |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + Yoast 28.7 | **158 pass, 5 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6, no SEO plugin | **155 pass, 4 skipped** |
| Integration (local, 2026-11-09, Phase 7 step 1): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on | **162 pass, 4 skipped** (166 tests; `WooGuardsTest` 6/6). First run: one `CoreUsersTest` failure (WooCommerce refuses non-admins editing admins before KUDUY's last-admin rule; test adjusted) |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS off | **162 pass, 4 skipped** |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on | **162 pass, 4 skipped** (after `AUTOMATIC_UPDATER_DISABLED`; before it, a background update left `.maintenance` and PHPUnit never ran) |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS off | **162 pass, 4 skipped** |
| Integration: WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6, no WooCommerce | **156 pass, 4 skipped** each (`WooGuardsTest` skips; run before the `AUTOMATIC_UPDATER_DISABLED` step, which the WooCommerce runs cover) |
| Integration (local, 2026-11-09, Phase 7 step 2: status + product tools): WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6 + WooCommerce 11.2.2, HPOS on / off | **172 pass, 4 skipped** each (176 tests). Before the tools were registered all 10 `WooProductsTest` tests failed and nothing else did; the first green run found the trash id bug (`WC_Data::delete()` resets the id) and a formatted parent price |
| Integration: WP 6.9.11/PHP 8.1.34 + Elementor 4.3.6 + WooCommerce 11.0.1, HPOS on / off | **172 pass, 4 skipped** each |
| Integration: WP 7.1.4/PHP 8.3.36 + Elementor 4.3.6, no WooCommerce; WP 6.9.11/PHP 8.1.34 + Elementor 3.35.9, no WooCommerce | **156 pass, 4 skipped**; **151 pass, 9 skipped** (Woo tests skip) |
| Runner fix (2026-11-06) | `test-integration.mjs` now counts the `<testcase>`, `<failure>`, `<error>`, `<skipped>` elements. It used to read the first `<testsuite>` attributes, which PHPUnit 10 leaves off the root when a class skips in `setUpBeforeClass()`, so a failure in another class could have passed unnoticed |
| Smoke over real HTTP (2026-11-06, after the executor change) | **26 / 26 pass** |
| Smoke over real HTTP (WP 7.1.4 / PHP 8.3, Elementor 4.3.6 + WooCommerce 11.2.1 active) | **26 / 26 pass** |
| Security probe (read-only token on core routes, `rest_route` override, PATH_INFO, unticked consent, unauthenticated `initialize`, root AS metadata) | All refused / as expected on 0.1.2 |
| GitHub CI on `3591d92` | All 9 jobs green; release workflow for `v0.1.2` green |
| Real update v0.1.0 → v0.1.1 | Pass (2026-11-05) |
| Real update v0.5.0 → v0.5.1 on Hostinger staging (2026-11-08) | **Pass**: offered after `kuduy-check-update` with `refresh: true` (the 12 h release cache still held 0.5.0), installed from the Plugins screen by the user; `kuduy-site-info` then reported 0.5.1 over the same claude.ai connection |
| Real Claude clients (O11) | **claude.ai: pass** (Hostinger, 0.1.2 and 0.2.0). Desktop, Claude Code's own sign-in, refresh, revoke: not yet run |
| EL10 with a real client (claude.ai web → Hostinger staging, 0.4.0, Elementor 4.3.6 + Pro 4.0.4, 2026-11-05) | **15 / 16 steps pass**; `kuduy-elementor-theme-conditions` not available (grant has no `kuduy:admin`). **Step 6 re-run 2026-11-09 on 0.6.0 with `kuduy:admin`: pass** (conditions set, conflict with live header 386 refused, cleared) — all 16 steps now passed on staging. Claude desktop app sessions could not run it: their Kuduy connector stayed on a stale entry (33 tools, every call 404, no calls reached the site) |
| Phase 6 with a real client (claude.ai web → Hostinger staging, 0.5.0, WP 7.1.4 / PHP 8.3.36, Yoast 28.8, 2026-11-08 15:24–15:26 UTC) | **8 / 8 steps pass**: 74 tools listed; `kuduy-seo-status` yoast, no conflict; `kuduy-seo-update` + `-get` (stored = rendered); `kuduy-seo-verify` live 200, no mismatches (S4 E); image shown to Claude (S6 M); job run by WP-Cron (S9 E); `kuduy-seo-audit` did not list the post (it had all fields); cleanup by snapshots 3–5 and trash (snapshot 6 kept). Open: robots mismatch (S4 note). `kuduy-seo-audit` returns `total` = posts checked next to an empty `posts`, which reads like a count of problems. Claude Code desktop sessions could not run it: their Kuduy connector stayed at 42 tools (the pre-0.5.0 list) after reconnecting, revoking and toggling it, while `kuduy-site-info` reported 0.5.0 |
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
| O14 | Admin opt-in when the client does not ask | admin sees `kuduy:admin` unticked; left as rendered → read + write; ticked → admin granted; `kuduy:code` never offered unasked; editor not offered admin and a forged admin box is ignored | I — **pass 2026-11-05** (`test_admins_may_opt_in_to_admin_scope_the_client_did_not_request`; failed before the change) |
| O14 | Sign-in detection | unauthenticated `initialize` → 401 + challenge, so claude.ai detects OAuth | I — **pass 2026-11-05** (0.1.2, also real HTTP); claude.ai check in O11 |
| O15 | Registration cap | unused DCR clients capped; at cap the oldest unused (> 1 h) is evicted, else 429; not per IP | I — **pass 2026-11-05** (0.1.2) |
| O17 | HTTPS required | with HTTPS required (non-local site): token, registration and authorize refused; bearer over plain HTTP gives no user and a 401 mentioning HTTPS; local sites allowed | I — **pass 2026-11-05** |
| O18 | Early user check | a bearer token authenticates even before WordPress creates `$wp_rewrite` (another plugin calling `wp_get_current_user()` on `plugins_loaded`). Before the fix, `rest_url()` there threw `Call to a member function using_index_permalinks() on null` (reproduced on WP 7.1.4) | I — **pass 2026-11-05** |
| O16 | No anonymous outbound fetch | logged-out authorize request with a CIMD `client_id` → login redirect, zero HTTP requests | I — **pass 2026-11-05** (0.1.2) |

## Permissions

| # | Tests | Type |
|---|---|---|
| P1 | Author can edit own post, not others' | I — **pass 2026-11-05** (author cannot list, read or update other people's drafts/posts; contributor cannot trash others' posts) |
| P2 | Editor cannot install plugins | I — **pass 2026-11-05** (editor refused for plugin/theme list, plugin install, theme switch) |
| P3 | Subscriber token sees only read tools it can use | I — **pass 2026-11-05** (`CoreDiagnosticsTest`: a subscriber's `tools/list` contains site info, post list/get, own user and comment reply, and none of plugin/theme/user/settings/diagnostics/content-write/menu/term tools; plus per-tool refusals in every Phase 4 test file and scope filtering O10) |
| P4 | No response or log contains wp-config secrets, password hashes, app passwords, gateway keys | U, I — **pass 2026-11-05** (`CoreDiagnosticsTest` sweep: 11 read tools as administrator + the last 50 audit rows contain none of the salts/keys, `DB_PASSWORD`, the user's password hash, an application password or its hash; a `kmcp_at_` token in post meta is masked. Gateway keys: re-test when WooCommerce tools land, Phase 7) |

## Core WordPress

| # | Feature | Type |
|---|---|---|
| C1 | Posts/pages CRUD, status, schedule, search, count | I — read part **pass 2026-11-05** (`CoreContentTest`: list/search/paging, drafts and private by role, `read_post` per post, password-protected content, unknown type); writes **pass 2026-11-05** (`CoreWriteTest`: create draft with terms + featured image, contributor cannot publish, kses without `unfiltered_html`, scheduling needs a future date, `edit_post` per object, update snapshot + undo restores fields/status/terms, invalid terms/images refused before writing, Elementor content guard, trash + undo restores status). Count not run |
| C2 | CPT + custom taxonomies, terms CRUD | I — read part **pass 2026-11-05** (`kuduy-post-types` hides types without admin UI; `kuduy-term-list` public vs non-public taxonomy); writes **pass 2026-11-05** (`CoreEditorialTest`: author cannot create categories, editor creates parent/child, a term cannot be its own parent, rename + undo, delete keeps posts and undo recreates the term and reassigns posts, default category protected) |
| C3 | Comments moderate/reply | I — **pass 2026-11-05** (subscriber reply held, admin reply approved and threaded, closed comments refused; subscriber sees only approved comments of readable posts and no emails; held/all need `moderate_comments`; contributor cannot moderate others' comments; spam + undo) |
| C4 | Post meta (protected keys blocked), revisions list/restore | I — **pass 2026-11-05** (meta set/get incl. arrays, `_` keys neither read nor written, undo, null deletes, contributor refused; revisions listed with content, contributor refused, restore + undo) |
| C5 | Featured image set/unset | I |
| C6 | Users/roles create/update (no password echo) | I — **pass 2026-11-05** (`CoreUsersTest`: subscriber sees only themselves; list/get never contain the password hash or activation key; role list shows caps and assignable roles; editor cannot create users; admin creates an author with a random, never-returned password; unknown role refused; role change + undo; editor cannot promote; nobody changes their own role; last administrator cannot be demoted) |
| C7 | Plugins/themes list/install/update/activate; essential-plugin guard | I — **pass 2026-11-05** (`CorePackagesTest`, offline through core's real `Plugin_Upgrader` / `Theme_Upgrader` with local ZIPs: list with versions and `Requires Plugins`; install by slug, refuse URLs, unknown and duplicate slugs; activate + undo; deactivate dry run names the dependent plugin and changes nothing, confirm deactivates, undo re-activates; KUDUY cannot deactivate or update itself; update dry run shows 1.0 → 2.0, confirm updates and the plugin stays active; theme switch dry run + confirm + undo; theme install and update) |
| C8 | Menus, widgets, permalinks, site health | I — menus and settings **pass 2026-11-05** (`CoreSiteTest`: editors refused; menu with page link, custom link and submenu; drafts and incomplete links refused; item update keeps other fields + undo; delete + undo; location assign + undo; unknown location refused; settings read/update + undo; URLs, admin email, membership, default role and permalinks refused; bad timezone and front page refused). Diagnostics **pass 2026-11-05** (`CoreDiagnosticsTest`: managers only; Site Health runs in-process checks only (no `rest_availability` loopback) with matching counts; cron lists events with argument counts, never values; rewrite check + flush; hooks inspector names KUDUY's callback with priority, source and relative file). Widgets: Phase 7 |

## Elementor

| # | Feature | Type |
|---|---|---|
| EL1 | List documents/templates, read tree (v3 sections/columns, containers, atomic) | I — **pass 2026-11-05** on Elementor 4.3.6 (`ElementorReadTest`: list with document type, subscriber refused, tree with counts, outline without settings, subtree by id, author refused on someone else's page, unregistered add-on widget named) |
| EL2 | Widget schema discovery v3 (`get_controls`) and atomic (`get_props_schema`) | I — **pass 2026-11-05** on 4.3.6 (heading: `title`, responsive `align` with `align`/`align_tablet`/`align_mobile`; `e-heading` props as JSON Schema with the `$$type` envelope and `anyOf` for overridable values; unknown type refused) |
| EL3 | Add / update / move / duplicate / remove element; IDs unique | I — **pass 2026-11-05** on 4.3.6 (`ElementorWriteTest`: add at index, duplicate with fresh id, move, remove, all ids unique; widget at top level, column in a container and unknown widget refused; one invalid operation saves nothing) |
| EL4 | Patch edit on desktop does not change tablet/mobile values | I — **pass 2026-11-05** (title + `align_tablet` changed, `align` and `align_mobile` kept; `unset align_mobile` removes only that key) |
| EL5 | Save via `Document::save`; revision created; non-`unfiltered_html` user gets kses | I — **pass 2026-11-05** (all saves through `Document::save()`; author's `<script>` stripped; author refused on someone else's page; pages with an inactive add-on widget refused and left untouched; undo restores the previous tree). Revision count not asserted |
| EL6 | Post CSS regenerated; frontend HTML contains edited element ID and text | I — **pass 2026-11-05** (`kuduy-elementor-verify`: rendered HTML contains the element id and texts; missing text reported; drafts checked by rendering only). Live-page fetch: E on staging |
| EL7 | Kit global colors/typography/breakpoints read + update | I — **pass 2026-11-05** (read: Kit id, breakpoints, colors; update: custom color added and read back, undo removes it, unknown id refused, editors refused) |
| EL8 | Theme Builder templates list/conditions (Pro) | I — **pass 2026-11-05** (`ElementorThemeBuilderTest`, Elementor Pro 4.3.2 from the user's licensed ZIP via `KUDUY_LOCAL_PLUGINS`, on WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34, both with Elementor 4.3.6: header created as `elementor_library` with type meta; listed with location, conditions, live flag; `include/general` + an exclude saved through Pro and read back; a second header with the same condition refused naming the first, then allowed with `allow_conflicts` and the conflict reported; undo restores empty conditions; `[]` clears; 5 invalid conditions, a normal page and an unknown type refused; editor refused for conditions and template creation). Local only: CI has no Pro. Pro 4.3.2 needs Elementor core 4.0+, so it does not load next to 3.35.9 |
| EL9 | Addon adapters (EA, UAE, Premium, Jet) widget discovery | I — **pass 2026-11-05** (`ElementorAddonsTest`, WP 7.1 + Elementor 4.3.6 + Essential Addons 6.8.8, header-footer-elementor 2.9.6, Premium Addons 4.11.117: widgets listed with folder and plugin name; schema; page created with an add-on widget and kept on save; text setting edited; rendered with its element id). JetElements: blocked (paid) |
| EL11 | v4 global classes and variables through Elementor's own abilities | I — **pass 2026-11-05** on 4.3.6 (`ElementorAbilitiesTest`: Elementor's input schema used; variable create, read back, undo; editors refused; class create; class snapshot is a copy and says so; invalid input refused). On 3.35.9 the tools report themselves unavailable (tests skip) |
| EL10 | Build a full page from a prompt on 3.x and 4.3 | E — **pass on 4.3 2026-11-05**, claude.ai web chat → Hostinger staging (KUDUY MCP 0.4.0, WP 7.1.4, Elementor 4.3.6, Elementor Pro 4.0.4, WooCommerce 11.2.2; admin user, grant `kuduy:read kuduy:write`). Draft page 400 created from a prompt (hero heading/text/button, 3 features, CTA; 11 elements); heading `align` center / `align_tablet` start / `align_mobile` end; desktop `align` edited to end → tablet/mobile unchanged; snapshot restore → back to center, tablet/mobile unchanged; `kuduy-elementor-verify` 11/11 ids, 7/7 texts (draft: render only); draft header template 404 created; page trashed (undo snapshot 2). Step 6 was not covered then (`kuduy-elementor-theme-conditions` needs `kuduy:admin`, which that grant lacked). **Step 6 re-run 2026-11-09 — pass** (claude.ai "Kuduy" connector with `kuduy:admin` granted, called from a Claude Code session in the Claude desktop app; KUDUY MCP 0.6.0, WP 7.1.4, Elementor 4.3.6, Elementor Pro 4.0.4): draft page 415 created; draft header 404 given `include/singular/page/415` → saved, no conflicts (snapshot 13); `include/general` → refused, naming live header 386 (`include/general`), nothing changed; conditions cleared with `[]` (snapshot 15); header 386 unchanged; page 415 trashed (snapshot 16). Evidence: this tool-call log, recorded by the Claude Code session that ran it. Elementor 3.x: integration tests only, no real-client run. Findings: v4 heading `align` takes start/center/end/justify (no left/right); `_title` on a container is refused by validation (first create saved nothing, retry without it passed). Evidence: claude.ai run report pasted by the user |

## SEO + Image SEO

| # | Feature | Type |
|---|---|---|
| S1 | Resolver picks the single active plugin; two active → refuses writes | I — **pass 2026-11-06** (`SeoConflictTest` with Rank Math 1.0.281 + Yoast 28.8 on WP 7.1.4: status shows the conflict, update refused naming both, post meta unchanged; without an SEO plugin only `kuduy-seo-status` is available) |
| S2 | Rank Math: title, description, focus + extra keywords, canonical, robots, OG/Twitter, schema, redirects | I — **pass 2026-11-06** for title, description, keywords, canonical, robots, OG/X, cornerstone (`SeoRankMathTest`, Rank Math 1.0.281 on WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34: stored as its editor stores them, read back through its own ability, null clears, undo, authors refused for robots without `rank_math_onpage_advanced`, others' posts refused, bad canonical refused). Schema, redirects: not built |
| S3 | Yoast: title, metadesc, focus keyphrase, canonical, robots, social, schema | I — **pass 2026-11-06** except schema (`SeoYoastTest`, Yoast 28.8 on WP 7.1.4, 28.7 on WP 6.9.11: stored in `_yoast_wpseo_*` through `WPSEO_Meta`, rendered values current right after the write, noindex true/false/null, advanced robots, null clears, undo, `wpseo_edit_advanced_metadata` enforced for authors, related keyphrases refused) |
| S4 | Output appears in rendered `<head>` | I — **pass 2026-11-08** for all four plugins: in-process head (`RendersHead`) has title, description, robots, og:title, and `kuduy-seo-verify` reads the same head as the live page with no mismatches (`SeoVerifyTest` + each plugin test). E (live page on staging): **pass 2026-11-08** (claude.ai web → Hostinger staging, KUDUY 0.5.0, WP 7.1.4/PHP 8.3.36, Yoast 28.8, post 407): `kuduy-seo-verify` `live_checked: true`, `http_status: 200`, no mismatches; live head had the title, description and og:title just written (site fetched itself; no stale cache seen). Open: live `robots` was `noindex, nofollow` while `kuduy-seo-get` rendered `noindex, follow` and stored noindex was null. Likely cause: the site still had "Discourage search engines" on (core `wp_robots` adds `nofollow`); the user has since switched indexing on. Re-check 2026-11-08 (from outside, no KUDUY write): `kuduy-settings-get` shows `blog_public: "1"`; the live home page and `/shop/` now serve `index, follow, max-image-preview:large, …`. This supports that cause, but the post check was not repeated (this session's connector had no SEO tools). `kuduy-seo-get`'s rendered robots comes from the SEO plugin and leaves out core's `wp_robots` additions, so `kuduy-seo-status` and `kuduy-seo-verify` now add a note while search engines are discouraged (`SeoVerifyTest`, verified against core `wp_robots_noindex()` / `wp_robots_no_robots()` in WP 7.1.4) |
| S5 | Media list, dimensions, metadata; alt/title/caption/description updates | I — **pass 2026-11-06** (`MediaTest`: list filters by type and missing alt; 1600 × 900 PNG returned as an MCP image block ≤ 1024 px with matching MIME; alt/caption update keeps the title; undo; alt null removes; kses strips `<script>` for authors; others' media and contributors refused) |
| S6 | Decorative flag → empty alt; no invented alt text | I — decorative → empty alt and not "missing", decorative + alt refused: **pass 2026-11-06**. M (Claude looks at the image before writing alt text): **pass 2026-11-08** on staging via claude.ai web: `kuduy-media-get` with `include_image: true` on ids 384 (logo) and 383 (favicon) showed Claude the actual image, not just a link; the alt text was written from what it showed ("YNNP. logo: bold black letters next to a red broadcast-signal icon", "Red broadcast-signal icon: a dot with curved waves on both sides") and then restored |
| S7 | Image usage scan (content, Elementor, featured, Woo gallery) | I — **pass 2026-11-08** for featured image, block content, Elementor data; authors do not see uses in posts they cannot read (`MediaLibraryTest`). Woo gallery, site icon/logo: code path not exercised |
| S8 | Replace image keeps original, updates references; thumbnail regen job | I — regenerate restores a missing size: **pass 2026-11-08**; upload from URL (mocked download) with alt/title/parent, plain HTTP, non-image and contributors refused: **pass**. Replace image: not built |
| S9 | Bulk operations run as jobs with progress | I — **pass 2026-11-08** (`JobsTest`: every item validated before start, only listed tools, queued on WP-Cron or Action Scheduler (Rank Math/AIOSEO), batch runs each item as the owner — the item on someone else's image fails, others succeed with their own undo snapshot; owner/site manager only; cancel; revoked connection and read-only scope stop the job). E (real host): **pass 2026-11-08** on Hostinger staging via claude.ai web: `kuduy-job-start` with 2 × `kuduy-media-update` → job 1 `queued` at 15:25:17Z; WP-Cron ran it unforced at 15:25:57Z; `done` 2, `failed` 0 by 15:26:20Z; item snapshots 4 and 5, both restored. `job-status` results gave only `snapshot_id` per item; since this close-out they also give `index` and `object_id` (`JobsTest`) |
| S10 | AIOSEO and SEOPress adapters | I — **pass 2026-11-08** (`SeoAioseoTest`: saved in AIOSEO's own table, explicit index vs default, undo; `SeoSeopressTest`: stored in its meta keys through its abilities, target keywords, unsupported cornerstone refused, undo, ownership) |
| S11 | `kuduy-seo-audit` lists posts missing SEO data | I — **pass 2026-11-08** with each SEO plugin (`SeoAuditTest`) |

## WooCommerce

Ids from `docs/plans/phase-7-woocommerce.md`. Every order test runs with HPOS on and off (`KUDUY_HPOS`).

| # | Feature | Type |
|---|---|---|
| W1 | Core post tools refuse WooCommerce types for writes; reads and plain posts unchanged; the run's HPOS mode is the one asked for | I — **pass 2026-11-08** (`WooGuardsTest`, 6 tests: `kuduy-post-update` / `-create` (product, coupon) / `-trash` / `-meta-set` refused naming the `kuduy-woo-*` tools, product unchanged; `kuduy-post-get` on a product and updates of plain posts still work) on WP 7.1.4 + WooCommerce 11.2.2 with HPOS on and off; 6.9 pair: see §3 |
| W2 | Secrets never in results, errors or audit rows (gateway credentials, API keys, webhook secrets, payment tokens, order keys; fields of type `password` whatever their name) | I — Redactor part **pass 2026-11-08** (`SafetyTest::test_redactor_masks_woocommerce_secrets`); end-to-end **pass 2026-11-09**: gateway secrets (W13) and order keys (W6) never in results or audit rows. No tool reads REST API keys, webhooks or payment tokens |
| W3 | Product CRUD round trip through `wc_get_product()`, lookup table updated | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooProductsTest`: create as draft, read back through `wc_get_product()`, SKU and price in `wc_product_meta_lookup`; duplicate SKU refused with nothing created; update patches only sent fields, undo restores name, sale price, short description and status; list by SKU, get, non-product refused; trash + undo restores status and variations; editor and author refused; create and publish need `publish_products`) |
| W4 | Variations + parent sync | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooProductsTest`: custom attribute with variation flag, two variations, parent price range synced after create and update, undo, unknown option refused) |
| W5 | Stock set/increase/decrease + undo | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooProductsTest`: refused while stock is not managed; set 10, decrease 3 → 7; undo → 10) |
| W6 | Order read and PII masking, HPOS on and off | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai, HPOS on). I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooOrdersTest`: list and get mask email, address, phone and IP; order key never in any result; `include_pii` gives full billing data and IP to a shop manager and is refused to a role that can only read orders) |
| W7 | Order status confirm-first, emails captured, undo | I, E — E (confirm-first report, nothing changed): **pass 2026-11-09** on Hostinger staging. I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooOrdersTest`: processing → completed reports the customer email and changes nothing; with `confirm` one email reaches the customer (`pre_wp_mail`); undo restores processing with no customer email; processing → on-hold runs at once; `refunded` refused with no refund created; private note sends nothing, customer note confirm-first then one email) |
| W8 | Per-object order capabilities (shop manager yes, editor and author no), HPOS on and off | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooOrdersTest`: editor and author refused list, get and status; order unchanged) |
| W9 | Coupons: unique code, undo | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooCouponsTest`: create reads back through `WC_Coupon`; same code in another case refused; percent over 100 refused; update + undo incl. expiry and minimum spend; list, get by code, trash + undo; editor and author refused) |
| W10 | Customer address update; shop manager limited to customers | I, E — E (read and masking): **pass 2026-11-09** on Hostinger staging. I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooCustomersTest`: list and get masked, full with `include_pii`; billing update + undo, untouched fields kept, new address absent from audit rows; shop manager refused on an editor; author refused; invalid billing email refused) |
| W11 | Reviews moderation + rating cache | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooReviewsTest`: list with rating and masked email; approve → review count 1, average 4; undo → pending, count 0; reply by the current user under the review; plain comment refused; editor without `edit_products` refused) |
| W12 | Shipping and tax read | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooStoreSettingsTest`: zone with region country:DE and a flat-rate method with its cost, rest-of-world zone last; DE 19 % rate in the standard class, shipping flag; editor refused) |
| W13 | Gateway settings masked by field type | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooStoreSettingsTest`: a fake extension gateway with a `password` field under a free name and a text field named `api_signature` → both `value: null`, `has_value: true`; neither secret in the result or the audit rows; title and test mode visible; editor refused) |
| W14 | Report numbers match the fixture orders | I — **pass 2026-11-09** on WP 7.1.4 + WooCommerce 11.2.2 and WP 6.9.11 + 11.0.1, HPOS on and off (`WooReportsTest`: after WooCommerce's own order import, revenue for today counts both orders and at least 100 gross; products report lists the item with 4 sold; customer and order reports show `r***@example.test` and no city; author refused) |

## ACF

Ids from `docs/plans/phase-7-acf.md`. Field groups registered in the test with `acf_add_local_field_group()`.

| # | Feature | Type |
|---|---|---|
| A1 | Status and field groups by location | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: version, abilities off, no Google Maps key; groups by post type and by post) |
| A2 | Values read on posts, users, terms; password never returned | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: raw and formatted values; password field `value: null`, `has_value: true`, secret absent from the result) |
| A3 | Update + undo on posts, users, terms | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: text, number, select, true/false written with ACF's reference key; undo restores the old value and removes values that did not exist; user and term fields by name and by key) |
| A4 | Refusals write nothing | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: field of another location, unknown name (no dummy meta), required empty, select option not in choices, number below minimum, invalid email, one bad value of two; author on an editor's post and on another user) |
| A5 | Raw meta writes to ACF fields refused | I, E — E: **pass 2026-11-09** on Hostinger staging (claude.ai). I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`: `kuduy-post-meta-set` names `kuduy-acf-update`; non-ACF meta still works. ACF 6.8.11 itself also denies `edit_post_meta` on its field keys) |
| A6 | Bulk update as a job; password kept out of audit rows | I — **pass 2026-11-09** on WP 7.1.4 and 6.9.11 + ACF 6.8.11 (`AcfTest`) |

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
