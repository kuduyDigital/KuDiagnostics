# KUDUY MCP — Research

First research pass: 2026-11-04 (Phase 1). Re-inspected: 2026-11-05 (reference repos re-cloned, licenses re-read, gaps from the brief filled). Every statement below was checked against a primary source — a cloned repository at the named commit, an official spec or documentation page, or upstream source. Anything not yet checked is marked **UNVERIFIED** with the phase that must check it.

## 1. Development environment

| Item | Found (2026-11-05) | Notes |
|---|---|---|
| OS | Windows 10 Pro 19045 | Scripts work in Git Bash and PowerShell |
| Project | `D:\NewIdea\KuDiagnostics`, git, public repo `kuduyDigital/KuDiagnostics` | Repo root = plugin root |
| PHP | 8.3.36 (winget, user scope) | Not on the Bash PATH by default — see `CLAUDE.md` |
| Composer | 2.11.1 (official installer, signature checked) | `composer.bat` strips `^` under cmd; use `php composer.phar` or quotes |
| Node / npm | 24.18.0 / 11.7.0 | Runs WordPress Playground CLI 3.1.62 (WASM PHP + WordPress, no Docker) |
| WP-CLI, Docker | not installed | WP-CLI first needed for Phase 8 recovery tests |
| GitHub CLI | not installed | Public GitHub REST via `curl` is enough |
| cloudflared | 2026.10.7 | Quick tunnel for a public HTTPS test site (`npm run tunnel`) |

A real Claude connection needs a public HTTPS URL: claude.ai connects from Anthropic's servers (egress range `160.79.104.0/21`, per Claude connector docs), not from this machine. The user's own staging site is on Hostinger (`*.hostingersite.com`, LiteSpeed behind Hostinger's CDN `Server: hcdn`).

## 2. Claude Code skills and tooling

| Skill / tool | Source | Review result | State |
|---|---|---|---|
| caveman, ultracave, megacave | `JuliusBrussee/caveman` | Markdown only, no scripts or network calls | Installed (`~/.claude/skills`) |
| ponytail, ponytail-audit, -review, -debt, -gain, -help | `DietrichGebert/ponytail` @ HEAD | Markdown only; no network calls. The brief's command `npx skills add DietrichGebert/ponytail --skill caveman` names a skill that repo does not contain | Installed |
| Superpowers 7.0.2 | `obra/superpowers-marketplace` | TDD, systematic debugging, planning, code review, verification workflows; read before install | Installed and enabled (user scope) |
| wp-plugin-development, wp-project-triage | `WordPress/agent-skills` @ `eff3785` (the official source WSP's `skills-lock.json` points to) | Markdown plus read-only filesystem scanners, no network | Installed |
| Context7 | — | Not available in this environment | Fallback: official docs and upstream source, read directly |
| PHPCS + WPCS 3.4.1, PHPStan 2.3.5 + phpstan-wordpress, PHPUnit 10.5.66 | Composer dev dependencies | — | Installed, used in CI |

Rejected: copying the `wp-plugin-development` skill from the WSP repo (the official source was used instead).

## 3. Reference A — WSP WordPress MCP

Repository `bilalnaseer/wsp-wordpress-mcp`, commit `20a9582` (2026-10-29), plugin "WSP MCP - Free MCP Plugin for WordPress" v2.9.7, requires WordPress 6.9 / PHP 7.4. **License: GPL-2.0** (`LICENSE`, re-read 2026-11-05). About 19.5k lines of procedural PHP in `wsp-mcp-ai-agents-connector/includes/`.

| Area | Where | Finding |
|---|---|---|
| Docs | `README.md`, `AGENTS.md`, `CHANGELOG.md`, `HISTORY.md` | `AGENTS.md` is the agents' source of truth and must be updated with every change; `HISTORY.md` records why a native MCP server was built. Only `docs/TESTING-woo-plugin-tools.md` exists for testing — **no automated test suite**. |
| Bootstrap and layout | `includes/{abilities,admin,audit,seo,server,tools}/`, `registry.php`, `dependency.php` | One file per tool family; `tools/native-tools.php` is 2,361 lines. |
| MCP transport | `server/class-mcp-server.php`, `class-session-store.php` | Hand-written JSON-RPC on one REST route, `permission_callback` `__return_true`, auth inside the handler. Protocol 2024-11-05 → 2025-11-25, session-based. **No 2026-07-28** (`server/discover`). |
| Output safety | `response-guard.php` | Early output buffer on its own routes so stray notices can't corrupt JSON — good idea; KUDUY has its own `Http\OutputGuard`. |
| Auth | `server/class-auth.php`, `class-oauth-server.php`, `class-oauth-store.php` | Own OAuth AS (RFC 9728/8414/7591, PKCE S256, single-use codes) plus a **static API key that maps to the lowest-ID administrator** (`assume_admin()`), which breaks per-user authorization. Serves metadata at non-`.well-known` aliases for hosts that 404 `/.well-known/*`. |
| Permission guard | `abilities/guard.php` | `wsp_mcp_guard_edit_post()` per-object checks for posts. |
| Validation | across abilities | `sanitize_*` calls in 34 files; JSON-Schema validation (`rest_validate_value_from_schema`) is not used. |
| Audit | `audit/class-audit-log.php` | Own table: tool, status, message, duration, category, client IP, rejection counters, analytics page. |
| Core tools | `abilities/{posts,pages,cpt,taxonomy,comments,post-meta,revisions,users,menus,widgets,site,site-editor,blocks,health,diagnostics,search}.php` | Broad core coverage. |
| Plugins / themes | `abilities/{plugins,themes,theme-files,theme-upload}.php` | `Plugin_Upgrader`, `Theme_Upgrader`, `activate_plugin`, `switch_theme`. Theme file writes via `WP_Filesystem` / chunked `file_put_contents`. |
| Elementor | `abilities/elementor.php` (1,032 lines), `uae.php` | Writes `_elementor_data` with `update_post_meta` and clears **all** CSS — bypasses `Document::save()` (no revision, hooks, kses or per-post CSS). No v4 atomic support. |
| SEO | `abilities/{rankmath,yoast,redirects}.php`, `seo/class-redirects.php` | Rank Math: direct `update_post_meta` on 3 keys (`rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`). Yoast: `WPSEO_Meta::set_value()` with a raw `_yoast_wpseo_*` fallback; no guard when both SEO plugins are active. Own redirects store. |
| WooCommerce | `abilities/woocommerce*.php` | Uses CRUD objects (`wc_get_product`, `wc_get_order`, `WC_Product_Simple/Variable/Variation`, `WC_Coupon`, `WC_Shipping_Zone`). |
| ACF / forms | `abilities/{acf,cf7,wpforms,gravityforms}.php` | Present. |
| Media | `abilities/media.php` | Alt text via `_wp_attachment_image_alt`; sideload with `download_url()` (which uses the safe HTTP API) + `media_handle_sideload()`. |

**Lessons taken (as ideas, no code):** output guard, `.well-known` fallbacks, per-object guard. **Avoided:** shared admin key, raw Elementor meta writes, guessed SEO keys without a conflict check, no test suite.

## 4. Reference B — Novamira

Repository `use-novamira/novamira`, commit `9277ff3` (2026-11-04; first inspected at `aade6f5`), plugin v1.12.14, requires WordPress 6.9 / PHP 8.0. **License: AGPL-3.0** (`LICENSE`, re-read 2026-11-05). Website novamira.ai.

| Area | Where | Finding |
|---|---|---|
| Docs | `README.md`, `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md` | `CLAUDE.md` states the goal plainly: "unrestricted control over a WordPress installation" through arbitrary PHP and full filesystem access. Quality gate: Mago format/lint/analyze. |
| Dependencies | `composer.json` | `wordpress/mcp-adapter` 0.6.1 (scoped by `bin/scope-mcp-adapter.php` on install), `league/oauth2-server` ^8.5, `nyholm/psr7`, Jetpack autoloader. |
| Transport | core Abilities API + bundled MCP adapter | Abilities registered with `permission_callback => novamira_permission_callback` = plugin enabled **and** user can manage (`includes/helpers.php`). |
| PHP execution | `includes/abilities/execute-php.php` | `eval()` with `set_time_limit` and output buffering; refuses in "read mode". |
| WP-CLI | `includes/abilities/run-wp-cli.php` | `proc_open` with an argv array (no shell string), `--allow-root` when root, background jobs via `nohup sh -c … &` with log and status files. |
| Filesystem | `includes/abilities/{read,write,edit,delete,list-directory,enable,disable}-file.php` | `realpath` checks; delete refuses ABSPATH root, `wp-admin`, `wp-includes`, `mu-plugins`. `read-file.php` has **no** guard for `wp-config.php` or other secrets. |
| Database | — | No dedicated DB tool; done through `execute-php`. |
| Elementor | — | No dedicated tools; done through arbitrary PHP. |
| Recovery | sandbox loader | AI-written PHP lives in `wp-content/novamira-sandbox/`; a fatal writes a crash marker and the next request boots in safe mode. Sound pattern; KUDUY will design its own (D4). |
| Read mode | `includes/read-mode/` (`PermissionGuard`, `RequestRouter`, `policy`, `trust`) | Wraps ability callbacks to refuse writes while read mode is on. |
| Ghost mode | `includes/ghost-mode/` | Hides admin UI, WP-CLI controls, bypass constant. Filters the plugin list — **KUDUY must keep its plugin row visible.** |
| Tests | `tests/{integration,oauth,fixtures,design-smoke}` | 76 `*Test.php` files. |

**Lessons taken (as ideas):** crash marker + safe mode, read-mode policy wrapper, scoping a bundled adapter. **Rejected:** unrestricted eval/shell/filesystem as default features, unguarded secret reads, hiding the plugin row. Novamira's code is AGPL: none of it may be copied or adapted into KUDUY (GPL-2.0).

## 5. MCP protocol (modelcontextprotocol.io, schema files `schema/2026-07-28/schema.ts`, `schema/2025-11-25/schema.ts`)

- Latest revision **2026-07-28**: stateless — no `initialize`, no `Mcp-Session-Id`, no GET stream. Every request carries `_meta` `io.modelcontextprotocol/protocolVersion` (+ `clientInfo`, `clientCapabilities`). Servers MUST implement `server/discover`.
- Streamable HTTP: POST to one endpoint; JSON or request-scoped SSE reply; GET/DELETE → 405 for modern-only; notifications → 202 with no body.
- Headers `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name` (for `tools/call`, `resources/read`, `prompts/get`; `=?base64?…?=` for non-ASCII). Mismatch → 400 `-32020`; unsupported version → 400 `-32022` with `supported`; unknown method → 404 `-32601`. Origin MUST be validated (403).
- Dual-era servers MAY also serve 2025-11-25 clients, which start with `initialize`. Legacy clients read 404 as "session expired", so legacy method errors stay HTTP 200.
- Authorization: RFC 9728 protected-resource metadata (MUST), RFC 8414 or OIDC discovery (MUST), **CIMD preferred, DCR deprecated**, RFC 8707 `resource` + audience check, RFC 9207 `iss`, 401 for missing/invalid token, 403 `insufficient_scope` with a `scope` hint, tokens never in query strings.

## 6. Claude clients (claude.com connector authentication docs, support article "Get started with custom connectors using remote MCP")

- One auth stack for claude.ai, Desktop, mobile, Claude Code, Cowork. Supported modes: OAuth with DCR, OAuth with CIMD, none, static headers (org-wide credential — unsuitable as the primary path because it is not per user).
- CIMD is used only when AS metadata has `client_id_metadata_document_supported: true` **and** `none` in `token_endpoint_auth_methods_supported`.
- Sign-in starts only on **401** with `WWW-Authenticate: Bearer resource_metadata="…"`; `resource` must equal the MCP URL exactly; only the first `authorization_servers` entry is used.
- Redirect URIs: `https://claude.ai/api/mcp/auth_callback`; Claude Code uses port-agnostic loopback `http://localhost/callback` / `http://127.0.0.1/callback` and its CIMD at `https://claude.ai/oauth/claude-code-client-metadata`.
- Token endpoint form-encoded; `invalid_grant` for dead refresh tokens; rotate refresh tokens for public clients; time limits 10 s (discovery, registration, token) and 30 s (refresh). `offline_access` is requested when listed in AS `scopes_supported`.
- **Observed 2026-11-05 on the Hostinger site (v0.1.1):** the "Add custom connector" dialog pre-selected **"No sign-in · Detected"** because `initialize` answered 200 without a token; with that mode kept, Claude reported "Couldn't reach". Fixed in 0.1.2 (unauthenticated `initialize` → 401 + challenge). Re-check on the real client is pending (FEATURES O11).
- A plugin cannot add itself to a Claude account: the user adds the connector and completes sign-in in Claude.

## 7. WordPress platform (developer.wordpress.org, core source 7.1.4)

- Current WordPress 7.1.4; Abilities API in core since 6.9.
- `determine_current_user` maps a bearer token to a user, so every capability check runs as that user.
- REST routing: `WP::parse_request()` takes `rest_route` from `$_POST`, then `$_GET`, before the rewrite rule — a query or body `rest_route` overrides the pretty-permalink path (this caused the 0.1.1 token-route bug; see `SECURITY.md`).
- Updates: `wp-includes/update.php:555` `apply_filters( "update_plugins_{$hostname}", false, $plugin_data, $plugin_file, $locales )`; `wp_update_plugins()` returns at line 488 before the Update URI loop when the wordpress.org check fails. The Updates screen's "Check again" (`update-core.php?force-check=1`) runs `wp_update_plugins()` on `load-update-core.php` with a 1-minute timeout.
- `wordpress/mcp-adapter` 0.7.x deprecates bundling ("will be removed in a future version") → decision D1 changed (own layer).

## 8. Elementor (source `elementor/elementor`, release 4.3.0 at research time; 4.3.6 runs in the test site)

- Write path: `Plugin::$instance->documents->get( $id )->save( [ 'elements' => …, 'settings' => … ] )` — checks `is_editable_by_current_user()`, applies `kses_post_deep` without `unfiltered_html`, fires `elementor/document/before_save` / after-save, handles revisions. Per-post CSS: `Elementor\Core\Files\CSS\Post`.
- v3 widgets expose controls via `get_controls()`; v4 atomic elements (`modules/atomic-widgets/elements/`: `div-block`, `flexbox`, `grid`, `atomic-heading`, `atomic-paragraph`, `atomic-button`, `atomic-image`, …) expose `get_props_schema()` with typed props.
- **UNVERIFIED (Phase 5):** Kit storage of global classes and variables, the `$$type` prop envelope, v3→v4 migration behaviour, Theme Builder conditions API, add-on widget schemas (Essential Addons, UAE, Premium Addons, JetElements).

## 9. SEO, WooCommerce, ACF, forms, multilingual

- **UNVERIFIED (Phase 6):** Rank Math public API vs meta (`rank_math_*` keys seen in WSP are not an API contract), Rank Math redirections and schema storage, Yoast `WPSEO_Meta` field list and social/schema settings, AIOSEO and SEOPress models, rendered `<head>` output.
- **UNVERIFIED (Phase 7):** WooCommerce CRUD + HPOS declaration (`FeaturesUtil::declare_compatibility`), gateway settings masking, ACF `get_field` / `update_field` and field-group APIs, CF7 / WPForms / Gravity / Fluent entry APIs, WPML / Polylang language APIs, Site Editor and Global Styles REST.
- Rule: each phase starts by reading the integration's own source and docs; nothing is built from guessed meta keys.

## 10. Licensing and dependencies

| Component | License (verified) | Use in KUDUY |
|---|---|---|
| WSP WordPress MCP | GPL-2.0 | Reference only — no code copied |
| Novamira | AGPL-3.0 | Reference only — no code copied or adapted |
| `wordpress/mcp-adapter` | GPL-2.0-or-later | **Not used** (D1 changed) |
| `league/oauth2-server` | MIT | **Not used** (D2 changed) |
| WordPress | GPL-2.0-or-later | Platform |
| Runtime Composer dependencies | — | **None** (`composer.json` requires only `php >= 8.1`) |
| Dev only: PHPUnit (BSD-3), PHPStan (MIT), phpstan-wordpress (MIT), WPCS (MIT), `@wp-playground/cli` (GPL-2.0) | as listed | Not shipped in the ZIP (checked by `check:release`) |

KUDUY MCP is GPL-2.0-or-later, written from public specifications and documented APIs.

## 11. Open research items

| Item | Phase |
|---|---|
| Which MCP revision each Claude surface sends today; real sign-in from claude.ai, Desktop and Claude Code | 3 (O11) |
| Elementor Kit/atomic storage, migration, add-on schemas | 5 |
| SEO plugin APIs and rendered output | 6 |
| WooCommerce, ACF, forms, multilingual APIs | 7 |
| WP-CLI command registration for hidden-mode recovery | 8 |
| MCP Tasks extension for background jobs | later |
