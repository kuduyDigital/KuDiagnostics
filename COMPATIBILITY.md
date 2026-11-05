# KUDUY MCP — Compatibility

Only versions listed as **Tested** have passing evidence (see `TESTING.md`). Everything else is a target, not a claim. Updated 2026-11-05 for v0.2.0.

## Requirements

- WordPress **6.9** or newer (header `Requires at least: 6.9`)
- PHP **8.1** or newer (header `Requires PHP: 8.1`)
- Pretty permalinks (any setting except "Plain"); an admin notice says so otherwise. Plain permalinks work for the MCP endpoint (`?rest_route=`) but Claude's sign-in needs the REST paths.
- HTTPS for OAuth and access tokens (enforced since 0.2.0). Local sites (loopback, `*.localhost`, `*.test`, `WP_ENVIRONMENT_TYPE=local`) may use HTTP. Behind a TLS-terminating proxy, `X-Forwarded-Proto: https` is accepted when the site URL is https. claude.ai and Claude Desktop also need a publicly reachable address; Claude Code can use a local site.

## WordPress and PHP

| WordPress | PHP | Status | Evidence |
|---|---|---|---|
| 6.9.11 | 8.1.34 | **Tested** | 81/81 integration tests, 2026-11-05; release ZIP installs (CI) |
| 7.1.4 | 8.3.36 | **Tested** | 81/81 integration tests, 2026-11-05; smoke 26/26 over real HTTP; real update v0.1.0 → v0.1.1 |
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
| claude.ai (web) | **Not verified** | v0.1.1 was mis-detected as "No sign-in" → fixed in 0.1.2 (O14). Real sign-in pending (O11). |
| Claude Desktop | Not verified | Uses the connector configured in claude.ai. |
| Claude Code | Not verified | CIMD + loopback redirect implemented and tested with simulated clients (O3, O6). |
| Other MCP clients with OAuth 2.1 + PKCE, or with Application Passwords | Expected to work | Not tested |

## Hosting

| Environment | Status | Notes |
|---|---|---|
| WordPress Playground CLI (PHP built-in server, SQLite) | **Tested** | All automated tests run here |
| Cloudflare quick tunnel in front of Playground | **Tested** | 401 + metadata + CIMD checked from outside (2026-11-04) |
| Hostinger (LiteSpeed, Hostinger CDN `hcdn`) — user's staging site | Partly checked (v0.1.1) | From outside: tool calls 401 with challenge, PRM and AS metadata (path-inserted and REST fallback), DCR endpoint reachable. Root `/.well-known/oauth-authorization-server` was 404 → served since 0.1.2. |
| Apache, Nginx (incl. hosts that block `/.well-known/`) | Not tested | Issuer is a REST path, so `{issuer}/.well-known/openid-configuration` works where `/.well-known/` is blocked |
| MySQL / MariaDB | Not tested directly | Schema uses `dbDelta`; tests run on SQLite |
| Persistent object cache | Not tested | Rate limiter uses transients (not atomic under heavy concurrency) |
| Hosts blocking wordpress.org | **Tested** (simulated) | Update still offered (G1) |

## Plugins KUDUY works alongside

| Plugin | Version | Status | Notes |
|---|---|---|---|
| Elementor | 4.3.6 | Coexists (smoke, tunnel site) | No Elementor tools yet (Phase 5) |
| WooCommerce | 11.2.1 | Coexists (smoke, tunnel site) | No Woo tools yet (Phase 7) |
| Rank Math, Yoast, AIOSEO, SEOPress | — | Not tested | Phase 6 |
| Elementor Pro, add-ons (EA, UAE, Premium, Jet) | — | Not tested | Phase 5 |
| ACF, CF7, WPForms, Gravity, Fluent, WPML, Polylang | — | Not tested | Phase 7 |
| Plugins that print output during REST requests | — | **Tested** | Output guard (F2) |

## Known limitations

- Eight tools so far: `kuduy-site-info`, `kuduy-snapshot-list`, `kuduy-snapshot-restore`, `kuduy-check-update`, and the read-only `kuduy-post-types`, `kuduy-post-list`, `kuduy-post-get`, `kuduy-term-list` (the last four since 0.2.0).
- Real Claude clients have not been confirmed yet (O11).
- After a programmatic upgrade, WordPress left the plugin inactive once; Plugins-screen and auto-update paths not observed yet.
- Update checksum proves integrity, not authorship (no signature yet).
