# KUDUY MCP — Phase 1 Research Report

Date: 2026-11-04. Status: research complete; architecture approved. No plugin code written.

Everything below was checked against a primary source in this session (cloned repo, official spec page, or upstream source on GitHub). Items marked **UNVERIFIED** still need checking before the phase that uses them.

## 1. Development environment

| Item | Found | Impact |
|---|---|---|
| OS | Windows 10 Pro 19045 | Paths and tooling must work on Windows |
| Project dir `I:\1\KuDiagnostics` | Empty, not a git repo | Greenfield; recommend `git init` before Phase 3 |
| PHP | **missing** | Cannot lint, run PHPUnit, PHPStan or PHPCS yet |
| Composer | **missing** | Cannot install `wordpress/mcp-adapter` or OAuth library yet |
| WP-CLI | missing | Needed for recovery-command tests |
| Docker | missing | Rules out `wp-env` |
| Node 26.7 / npm 11.19 | present | Enables WordPress Playground CLI (WASM PHP + WordPress, no Docker) |
| git 2.55, gh 2.101 | present | Repo inspection works |

> **Update 2026-11-04, after Phase 0** (re-checked on the dev machine): project dir is `D:\NewIdea\KuDiagnostics` (git repo, pushed). PHP 8.3.36 and Composer 2.11.1 installed. Node is **24.18.0** (not 26.7) with npm 11.7.0; `gh` is **not** installed (GitHub REST via `curl` works for public reads). Playground CLI 3.1.62 pinned in `package.json`. Public HTTPS via Cloudflare quick tunnel (`npm run tunnel`). WP-CLI and Docker still missing; WP-CLI is first needed for Phase 8 recovery tests.

**Conclusion:** before Phase 3 we need (a) PHP 8.3 + Composer installed locally (for example `winget install PHP.PHP.8.3` and Composer-Setup), and (b) a WordPress runtime: WordPress Playground CLI locally, plus a **publicly reachable HTTPS staging site** (or a tunnel) because claude.ai connects from Anthropic's servers (egress `160.79.104.0/21`), not from this machine.

## 2. Claude Code skills and tooling

| Skill / tool | State | Notes |
|---|---|---|
| caveman | Installed (plugin `caveman@caveman`), active this session | The given command `npx skills add DietrichGebert/ponytail --skill caveman` points at a repo that has **no** `caveman` skill (it ships `ponytail*` only). The installed caveman plugin already covers it. |
| ponytail, ponytail-audit, ponytail-review, ponytail-debt, ponytail-gain, ponytail-help | Already installed in `~/.claude/skills` | Repo cloned and inspected. `ponytail-audit` and `ponytail-review` match upstream byte-for-byte; `ponytail` differs from upstream HEAD (local copy is older/edited). No network calls in the skill text. Useful parts: `ponytail` (smallest change), `ponytail-review`, `ponytail-audit`. Not useful here: per-IDE rule files, `ponytail-gain`. |
| Superpowers (`obra/superpowers` v6.4.2) | **Not installed.** Listed in the official plugin directory, partner-reviewed at commit `8ca22db` | Covers TDD, systematic debugging, planning, code review, verification. Install through the app's plugin UI (offered separately). |
| `wp-plugin-development` skill | Present inside the WSP repo; its `skills-lock.json` says it comes from **`wordpress/agent-skills`** (official WordPress org) | Recommend installing from the official `wordpress/agent-skills` source rather than copying from WSP. Its `scripts/detect_plugins.mjs` makes no network or process calls. |
| Context7 | **Not available** in this session (no Context7 tools found) | Fallback: official docs + reading upstream source via `gh api` (used throughout this report). |
| PHP static tools (PHPCS + WPCS, PHPStan + WP stubs, PHPUnit) | Not installable until PHP/Composer exist | Planned as Composer dev dependencies. |

> **Update 2026-11-04, after Phase 0:** `claude plugin list` showed no caveman plugin, so caveman was installed as skills (`caveman`, `ultracave`, `megacave` from `JuliusBrussee/caveman`), as were the six ponytail skills from upstream HEAD, all in `~/.claude/skills`, with no hooks. Superpowers **7.0.2** installed from `obra/superpowers-marketplace` (user scope, enabled). `wp-plugin-development` + `wp-project-triage` installed from `WordPress/agent-skills` @ `eff3785` (reviewed).

## 3. Reference A — WSP WordPress MCP (`bilalnaseer/wsp-wordpress-mcp`)

Snapshot: commit `20a9582` (2026-10-29), plugin v2.9.7, requires WP 6.9 / PHP 7.4. License **GPL-2.0-or-later**. ~19.5k lines of PHP, procedural, ~200 tools.

**Architecture**
- Hand-rolled MCP server: one REST route `wsp-mcp/v1/mcp`, `permission_callback => __return_true`, auth enforced inside the handler. JSON-RPC dispatch for `initialize`, `tools/list`, `tools/call`, `ping`, empty resources/prompts. Supports protocol versions 2024-11-05 → 2025-11-25. **No 2026-07-28 support** (no `server/discover`, still session-based).
- Session table, Origin validation, rate limit 120 req/60 s, `no-store` headers.
- `response-guard.php`: opens an output buffer early on its own endpoints so stray notices from other plugins cannot corrupt JSON. **Good idea worth an original equivalent.**
- Fatal-error guard via `register_shutdown_function` that turns a PHP fatal inside a tool into a JSON-RPC error.
- Own OAuth AS: RFC 9728 + 8414 + 7591 DCR + PKCE S256, single-use codes and refresh tokens, consent page with frame-protection headers. Serves metadata at several aliases, including a non-`.well-known` path because some hosts (SiteGround Nginx) 404 `/.well-known/*` before WordPress. **Real-world hosting lesson worth keeping.**

**Weaknesses found (do not repeat)**
1. **Static API key maps to the lowest-ID administrator** (`assume_admin()` in `class-auth.php`). Anyone holding the key acts as that admin, regardless of who created the key. Breaks per-user authorization.
2. **Elementor writes bypass Elementor's API**: `update_post_meta( $id, '_elementor_data', … )` then a global `files_manager->clear_cache()`. This skips `Document::save()` — no revision, no `elementor/document/*` hooks, no `kses` for users without `unfiltered_html`, no `_elementor_version` update, and it flushes every site's CSS instead of regenerating one post.
3. **No Elementor v4 atomic support** (no references to atomic elements, classes or variables).
4. Yoast fallback writes `_yoast_wpseo_*` meta directly when `WPSEO_Meta` is absent; no guard against writing Yoast keys while Rank Math is the active SEO plugin.
5. 2,361-line `native-tools.php`; abilities are mostly large procedural files.

## 4. Reference B — Novamira (`use-novamira/novamira`)

Snapshot: commit `aade6f5` (2026-11-04). License **AGPL-3.0-or-later**. PHP ≥ 8.0, ~86k lines incl. tests/stubs.

**Architecture**
- Builds on **WordPress core Abilities API** + bundled **`wordpress/mcp-adapter` 0.6.1**, scoped into its own namespace (`Novamira\Vendor\WP\MCP\…`) to avoid clashes with other plugins bundling the adapter. **Scoping is a must if we bundle the adapter too.**
- OAuth via `league/oauth2-server` ^8.5 + `nyholm/psr7`, with pinned transitive versions for PHP 8.0.
- Capability surface is deliberately tiny and unconstrained: `execute-php` (`eval()` with `set_time_limit` + output buffering), `run-wp-cli`, read/write/edit/delete/enable/disable file, list directory. Elementor and everything else are done through arbitrary PHP; there are **no dedicated Elementor tools**.
- Sandbox loader: AI-written PHP lives in a sandbox dir loaded at runtime; a shutdown handler writes a `.crashed` marker on fatal and the next request boots in safe mode with an admin notice. **Sound recovery pattern; we will design our own.**
- "Ghost mode": hides menus and guards requests to hidden admin pages, WP-CLI `status/disable/owners add`, bypass constant `NOVAMIRA_GHOST_MODE_BYPASS`. It also appears to filter the plugin list (`filter_plugin_list`). **Our requirement differs: the plugin row must stay visible.**
- Read mode / trust policy layer, troubleshoot checks, solid integration test suite (PHPUnit fixtures driving the adapter).

**Licensing consequence:** AGPL code must not be copied or adapted into KUDUY unless KUDUY is also AGPL. We only take ideas (patterns are not copyrightable); all code will be written from specs and upstream APIs.

## 5. MCP protocol — current spec (verified at modelcontextprotocol.io)

Latest revision: **2026-07-28**. This is a breaking change from what both reference plugins were built on.

- **Stateless.** No `initialize` handshake, no `Mcp-Session-Id`, no GET stream, no resumable SSE. Every request carries `_meta` with `io.modelcontextprotocol/protocolVersion`, `clientInfo`, `clientCapabilities`.
- Servers **MUST** implement `server/discover`.
- Streamable HTTP: POST only to one endpoint; reply is JSON or a request-scoped SSE stream; GET/DELETE → 405 for modern-only servers; notifications → 202.
- Required headers `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name` (for `tools/call`, `resources/read`, `prompts/get`); header/body mismatch → 400 with JSON-RPC `-32020 HeaderMismatch`; unsupported version → 400 with `-32022` listing `supported`; unknown method → 404 with `-32601`.
- Origin header MUST be validated (403 on invalid).
- Server-to-client requests replaced by **MRTR** (`InputRequiredResult`), long-lived notifications by `subscriptions/listen`.
- **Dual-era** servers MAY keep serving legacy (`2025-11-25` and earlier) clients: an `initialize` request selects legacy session semantics.

**Implication:** KUDUY must be dual-era (2026-07-28 + 2025-11-25, optionally 2025-06-18) because not every target client is guaranteed to speak 2026-07-28 yet. **UNVERIFIED:** exactly which revision each Claude surface sends today — to be measured against the real clients in Phase 3.

**Authorization (2026-07-28):**
- MCP server MUST serve RFC 9728 protected resource metadata; AS MUST serve RFC 8414 or OIDC discovery.
- **Client ID Metadata Documents (CIMD) SHOULD be supported; DCR is now deprecated** (kept for back-compat).
- Clients send RFC 8707 `resource`; servers MUST validate token audience and only accept their own tokens; tokens never in query strings.
- AS SHOULD return `iss` in authorization responses (RFC 9207) and advertise `authorization_response_iss_parameter_supported`.
- 401 for missing/invalid token, 403 + `insufficient_scope` for step-up, `scope` hint in `WWW-Authenticate`.

## 6. Claude client requirements (verified at claude.com/docs/connectors/building/authentication)

- Same auth stack backs claude.ai, Desktop, mobile, Claude Code, Cowork.
- Supported: `oauth_dcr`, `oauth_cimd`, `none`, static headers (beta, org-wide credential — **not per user**, so unsuitable as our primary path).
- Claude uses CIMD only if AS metadata has `client_id_metadata_document_supported: true` **and** `none` in `token_endpoint_auth_methods_supported`; otherwise DCR.
- Sign-in starts only on **401** with `WWW-Authenticate: Bearer resource_metadata="…"`; `resource` must equal the MCP URL exactly; only the first `authorization_servers` entry is used.
- Redirect URIs: `https://claude.ai/api/mcp/auth_callback` (hosted apps) and **port-agnostic loopback** `http://localhost/callback` + `http://127.0.0.1/callback` (Claude Code; its CIMD is at `https://claude.ai/oauth/claude-code-client-metadata`).
- Token endpoint: form-urlencoded; `invalid_grant` for dead refresh tokens; **rotate refresh tokens** for public clients; 10 s limit for discovery/registration/token, 30 s for refresh.
- `offline_access` appended when listed in AS `scopes_supported`.

## 7. WordPress platform (verified)

- Current WordPress: **7.1.4**.
- **Abilities API is in core** (`src/wp-includes/abilities-api.php`, since 6.9).
- **`wordpress/mcp-adapter` v0.7.1** (2026-10-16, GPL-2.0-or-later, PHP ^7.4 || ^8.0) already implements **both 2026-07-28 and 2025-11-25** wire formats, including `server/discover`. It does not ship an OAuth AS; it relies on WordPress authentication.
- `determine_current_user` core filter is the supported way to map a Bearer token to a WP user so every capability check runs as that user.

## 8. Elementor (verified in `elementor/elementor` source)

- Current release **4.3.0** (2026-11-04).
- Supported write path: `Plugin::$instance->documents->get( $id )->save( [ 'elements' => …, 'settings' => … ] )`. `Document::save()` checks `is_editable_by_current_user()`, applies `kses_post_deep` when the user lacks `unfiltered_html`, runs `elementor/document/before_save` / after-save hooks and handles revisions.
- Per-post CSS: `Elementor\Core\Files\CSS\Post`.
- **v4 atomic elements** live in `modules/atomic-widgets/elements/`: `div-block`, `flexbox`, `grid`, `atomic-heading`, `atomic-paragraph`, `atomic-button`, `atomic-image`, `atomic-svg`, `atomic-divider`, `atomic-tabs`, `atomic-accordion`, `atomic-form`, `atomic-youtube`, … Each exposes a typed schema through `get_props_schema()` (e.g. heading: `classes` → `Classes_Prop_Type`, `tag` → `String_Prop_Type`, `title` → `Escaped_Html_Prop_Type`). This gives us **machine-readable schema discovery for v4**; v3 widgets expose `get_controls()`.
- **UNVERIFIED (Phase 5):** storage format of global classes and variables in the Kit, prop-type JSON envelope (`$$type`), and v3→v4 migration behaviour. Must be read from source before writing any v4 tool.

## 9. SEO, WooCommerce, ACF, forms

Not yet verified at API level; deliberately deferred to their phases (rule: verify per phase, never assume). Known starting points: Yoast `WPSEO_Meta::get_value/set_value`, Rank Math public KB + `rank_math_*` meta (**UNVERIFIED**), WooCommerce CRUD objects (`wc_get_product`, `WC_Order`) and HPOS compatibility declaration, ACF `get_field/update_field`.

## 10. Licensing summary

| Component | License | Use in KUDUY |
|---|---|---|
| WSP | GPL-2.0-or-later | Reference only, no code copied |
| Novamira | AGPL-3.0-or-later | Reference only, no code copied |
| `wordpress/mcp-adapter` | GPL-2.0-or-later | Proposed bundled dependency (scoped) |
| `league/oauth2-server` | MIT | Candidate for OAuth core (see decision D2) |
| WordPress | GPL-2.0-or-later | Platform |

KUDUY must be **GPL-2.0-or-later** (WordPress.org compatible, compatible with the adapter).
