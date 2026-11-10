# KUDUY MCP — Compatibility

Only versions listed as **Tested** have passing evidence (see `TESTING.md`). Everything else is a target, not a claim. Updated 2026-11-05 for v0.4.0.

## Requirements

- WordPress **6.9** or newer (header `Requires at least: 6.9`)
- PHP **8.1** or newer (header `Requires PHP: 8.1`)
- Pretty permalinks (any setting except "Plain"); an admin notice says so otherwise. Plain permalinks work for the MCP endpoint (`?rest_route=`) but Claude's sign-in needs the REST paths.
- HTTPS for OAuth and access tokens (enforced since 0.2.0). Local sites (loopback, `*.localhost`, `*.test`, `WP_ENVIRONMENT_TYPE=local`) may use HTTP. Behind a TLS-terminating proxy, `X-Forwarded-Proto: https` is accepted when the site URL is https. claude.ai and Claude Desktop also need a publicly reachable address; Claude Code can use a local site.

## WordPress and PHP

| WordPress | PHP | Status | Evidence |
|---|---|---|---|
| 6.9.11 | 8.1.34 | **Tested** | 145/145 integration tests (Elementor 4.3.6 and 3.35.9), 2026-11-05; release ZIP installs (CI) |
| 7.1.4 | 8.3.36 | **Tested** | 145/145 integration tests (Elementor 4.3.6 and 3.35.9), 2026-11-05; smoke 26/26 over real HTTP; real update v0.1.0 → v0.1.1 |
| 7.1.4 | 8.1 | **Tested** (install only) | Release ZIP install check |
| 7.0.x | any | Not tested | |
| any | 8.2, 8.4 | Syntax only | `php -l` in CI on 8.1–8.4; no integration run |
| Multisite | — | Not tested | Per-site activation only; network behaviour not designed yet |

## MCP protocol

| Revision | Status | Notes |
|---|---|---|
| 2026-07-28 (stateless, `server/discover`) | **Tested** | F3, F5 |
| 2025-11-25 (legacy `initialize`) | **Tested** | F4 |
| 2025-06-18, 2025-03-26 | Tested (negotiation only) | `initialize` echoes them; same tool behaviour |
| Batches, SSE streams, sessions, resources, prompts | Not supported | Not needed by current tools; requests rejected with a JSON-RPC error |

## Claude clients

| Client | Status | Notes |
|---|---|---|
| claude.ai (web) | **Tested** 2026-11-05 | Custom connector, "Sign in now", OAuth (DCR) against Hostinger staging; tools listed and called on 0.1.2 and 0.2.0. v0.1.1 was mis-detected as "No sign-in" (fixed in 0.1.2, O14). |
| Claude Desktop | Not verified directly | Uses the claude.ai connector; not yet observed in the Desktop chat. |
| Claude Code | Partly | Uses the synced claude.ai connector: **tested** (tools called from a Claude Code desktop session). Its own sign-in (`claude mcp add`, CIMD + loopback) is tested only with simulated clients (O3, O6). On 2026-11-08 a running session kept the 42-tool list from before 0.5.0 after reconnect, revoke and an off/on toggle, so new tools may not reach Claude Code sessions straight away; claude.ai web showed all 74. |
| Other MCP clients with OAuth 2.1 + PKCE, or with Application Passwords | Expected to work | Not tested |

## Hosting

| Environment | Status | Notes |
|---|---|---|
| WordPress Playground CLI (PHP built-in server, SQLite) | **Tested** | All automated tests run here |
| Cloudflare quick tunnel in front of Playground | **Tested** | 401 + metadata + CIMD checked from outside (2026-11-04) |
| Hostinger (LiteSpeed, Hostinger CDN `hcdn`) — user's staging site | **Tested** (0.1.2, 0.2.0) | Real claude.ai connection works; update 0.1.2 → 0.2.0 kept the plugin active; HTTPS check passes behind the CDN. Earlier (0.1.1): From outside: tool calls 401 with challenge, PRM and AS metadata (path-inserted and REST fallback), DCR endpoint reachable. Root `/.well-known/oauth-authorization-server` was 404 → served since 0.1.2. |
| Apache, Nginx (incl. hosts that block `/.well-known/`) | Not tested | Issuer is a REST path, so `{issuer}/.well-known/openid-configuration` works where `/.well-known/` is blocked |
| MySQL / MariaDB | Not tested directly | Schema uses `dbDelta`; tests run on SQLite |
| Persistent object cache | Not tested | Rate limiter uses transients (not atomic under heavy concurrency) |
| Hosts blocking wordpress.org | **Tested** (simulated) | Update still offered (G1) |

## Plugins KUDUY works alongside

| Plugin | Version | Status | Notes |
|---|---|---|---|
| Elementor | 4.3.6 | **Tested** (EL1–EL7, EL11; EL10 with claude.ai on Hostinger staging, WP 7.1.4, 2026-11-05) | CI: WP 7.1 / PHP 8.3 |
| Elementor | 3.35.9 (latest 3.x) | **Tested** (EL1–EL7; v4 tools report unavailable) | CI: WP 6.9 / PHP 8.1 |
| Essential Addons for Elementor (lite) | 6.8.8 | **Tested** (EL9) | CI: WP 7.1 job |
| Ultimate Addons for Elementor (header-footer-elementor) | 2.9.6 | **Tested** (EL9) | CI: WP 7.1 job |
| Premium Addons for Elementor | 4.11.117 | **Tested** (EL9) | CI: WP 7.1 job |
| JetElements | — | Not tested | Paid; not available |
| Elementor Pro | 4.3.2 | **Tested** 2026-11-05 (EL8, Theme Builder) with Elementor 4.3.6 on WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34. Needs Elementor core 4.0+ | Local tests only (licensed ZIP, never in CI or the repo) |
| Elementor Pro | 4.0.4 | **Tested** on Hostinger staging (EL10, claude.ai connector): 2026-11-05 Pro widgets listed with `plugin`, Theme Builder templates listed, draft header created; 2026-11-09 (KUDUY 0.6.0, `kuduy:admin`) display conditions set, conflict with the live header refused, conditions cleared | Real host, with Elementor 4.3.6 |
| WooCommerce | 11.2.1 | Coexists (smoke, tunnel site) | Before the Woo tools |
| WooCommerce | 11.2.2 | **Tested** in Playground with HPOS on and off (W1–W14, WP 7.1.4); **partly tested** 2026-11-09 on Hostinger staging (KUDUY 0.6.0, HPOS on, sync off, claude.ai web): status, product create/update/stock/get/trash, guard on `kuduy-post-update`, coupon create/get/trash, shipping, tax, gateways, revenue report. Re-run the same day with a test order, customer and Stripe 11.1.1 (not connected): order and customer masking and the confirm-first status report pass on the real host; a stored gateway secret could not be set (Stripe takes keys only through its own sign-in), so that case stays covered in Playground only. Earlier: Woo widgets in `kuduy-elementor-widgets` via Elementor Pro (EL10) | CI: WP 7.1 / PHP 8.3, HPOS on and off |
| WooCommerce | 11.0.1 | **Tested** in Playground with HPOS on and off (W1–W14, WP 6.9.11) | CI: WP 6.9 / PHP 8.1; last release for WordPress 6.9 |
| Rank Math SEO | 1.0.281 | **Tested** 2026-11-08 (S1–S4, S9, S11) on WP 7.1.4/PHP 8.3.36 and WP 6.9.11/PHP 8.1.34 | Must be set up (account connected or setup wizard skipped) or it outputs nothing. Its bundled Action Scheduler runs KUDUY jobs. CI |
| Yoast SEO | 28.8 / 28.7 | **Tested** 2026-11-08: 28.8 on WP 7.1.4, 28.7 on WP 6.9.11 (28.8 needs WordPress 7.0) | Related keyphrases (Premium) not supported. CI |
| All in One SEO | 5.0.5 | **Tested** 2026-11-08 on WP 7.1.4 and 6.9.11 | Through its own abilities (WordPress 6.9+). CI |
| SEOPress | 10.4 | **Tested** 2026-11-08 on WP 7.1.4 and 6.9.11 | Through its own abilities (WordPress 6.9+); no forced index, noarchive or cornerstone. CI |
| Two SEO plugins at once | Rank Math + Yoast | **Tested**: SEO changes refused | CI |
| SEO plugins on a real host (live `<head>` via `kuduy-seo-verify`) | Yoast SEO 28.8 | **Tested** 2026-11-08 on Hostinger staging (KUDUY 0.5.0, WP 7.1.4/PHP 8.3.36, LiteSpeed + `hcdn`), claude.ai web | Site fetches itself (200), live head matches the written title, description, og:title; no stale cache seen. WP-Cron ran a KUDUY job within a minute. Robots mismatch open (`TESTING.md` S4). Rank Math, AIOSEO, SEOPress: not run on a real host |
| Advanced Custom Fields (free) | 6.8.11 | **Tested** in Playground (A1–A6, WP 6.9.11 + 7.1.4) and 2026-11-09 on Hostinger staging (KUDUY 0.7.0, WP 7.1.4, claude.ai web): 7 / 7 steps | CI: WP 6.9 + 7.1. ACF PRO not tested |
| Contact Form 7 + Flamingo | 6.2.4.1 (WP 7.1) / 6.1.7 (WP 6.9); Flamingo 2.6.4 | **Tested** in Playground 2026-11-10 (WP 6.9.11 + 7.1.4): F1–F7; Hostinger staging with claude.ai 2026-11-10 | CI: forms job. CF7 6.2 needs WP 7.1 + PHP 8.3 |
| Fluent Forms (free) | 6.2.17 | **Tested** in Playground 2026-11-10 (WP 6.9.11 + 7.1.4): F1–F5, F7; Hostinger staging with claude.ai 2026-11-10 | Fluent Pro not tested |
| WPForms Lite | 2.0.4 | **Tested** in Playground 2026-11-10 (WP 6.9.11 + 7.1.4): forms only (Lite stores no entries) | WPForms Pro entries not supported |
| Gravity Forms | — | **Not supported** (paid; needs a licensed copy to test) | |
| LiteSpeed Cache | 7.9.3 | **Tested** in Playground 2026-11-10 (WP 6.9.11 + 7.1.4): C1–C3; Hostinger staging (LiteSpeed server) 2026-11-10 | W3 Total Cache 2.11.0, WP Super Cache 3.1.5, WP Fastest Cache 1.5.5: API read, not run; WP Rocket: docs only |
| Polylang (free) | 3.9.2 | **Tested** in Playground 2026-11-10 (WP 6.9.11 + 7.1.4): L1–L4; Hostinger staging 2026-11-10 (undo-to-no-language bug found and fixed) | WPML not supported |
| Block themes (Site Editor) | Twenty Twenty-Five | **Tested** in Playground 2026-11-10 (WP 6.9.11 + 7.1.4): S1–S5 | Hybrid/classic themes with widget areas: widgets tested with a registered test area |
| CF7, WPForms, Gravity, Fluent, WPML, Polylang | — | Not tested | Phase 7 |
| Plugins that print output during REST requests | — | **Tested** | Output guard (F2) |

## Known limitations

- 63 tools in 0.4.x; `main` adds the 14 Phase 6 SEO, media and job tools (77, not released yet) (since 0.4.0 also the 14 Elementor tools: `kuduy-elementor-documents`, `-get`, `-widgets`, `-widget-schema`, `-kit`, `-edit`, `-create`, `-kit-update`, `-verify`, `-v4-globals`, `-v4-classes`, `-v4-variables`, `-theme-templates`, `-theme-conditions`; Theme Builder tools need Elementor Pro): `kuduy-site-info`, `kuduy-snapshot-list`, `kuduy-snapshot-restore`, `kuduy-check-update`, and the read-only `kuduy-post-types`, `kuduy-post-list`, `kuduy-post-get`, `kuduy-term-list` (since 0.2.0), and, since 0.3.0: `kuduy-post-create`, `kuduy-post-update`, `kuduy-post-trash`, `kuduy-term-create`/`-update`/`-delete`, `kuduy-comment-list`/`-moderate`/`-reply`, `kuduy-revision-list`/`-restore`, `kuduy-post-meta-get`/`-set`, `kuduy-user-list`/`-get`/`-create`/`-update`, `kuduy-role-list`, `kuduy-menu-list`/`-get`/`-create`/`-item-add`/`-item-update`/`-item-delete`/`-assign`, `kuduy-settings-get`/`-update`, `kuduy-plugin-list`/`-install`/`-activate`/`-deactivate`/`-update`, `kuduy-theme-list`/`-install`/`-switch`/`-update`, `kuduy-site-health`, `kuduy-cron-list`, `kuduy-rewrite-check`, `kuduy-rewrite-flush`, `kuduy-hooks-inspect`.
- Of the real Claude clients, only claude.ai (and Claude Code through the synced claude.ai connector) is confirmed; Desktop and Claude Code's own sign-in are not yet observed (O11).
- Plugin and theme installs/updates need direct file access; sites that ask for FTP credentials get a clear refusal (do it in wp-admin).
- `Plugin_Upgrader::upgrade()` deactivates active plugins outside cron (wp-admin reactivates them in a second request). KUDUY's update tool runs like WordPress's background updates, so plugins stay active; the earlier `check:update` script used the plain upgrader, which is why it saw the plugin inactive.
- Update checksum proves integrity, not authorship (no signature yet).
