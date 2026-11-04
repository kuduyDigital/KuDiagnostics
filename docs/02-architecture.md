# KUDUY MCP — Phase 2 Architecture

Status: **approved 2026-11-04** (decisions D1–D6 accepted).

## 1. Goals and non-goals

- Native PHP WordPress plugin, GPL-2.0-or-later, no KUDUY cloud backend, no Node on the server.
- Speaks MCP **2026-07-28 and 2025-11-25** (dual-era) over Streamable HTTP at `https://<site>/wp-json/kuduy-mcp/v1/mcp`.
- OAuth 2.1 authorization server inside WordPress; every tool runs **as the WordPress user who consented**, under that user's capabilities.
- Self-updates from GitHub releases of `kuduyDigital/KuDiagnostics`.
- Non-goal for v1: multisite network-wide admin (single-site first; multisite = per-site activation only), arbitrary shell execution.

## 2. Key decisions

| ID | Decision | Recommendation | Why |
|---|---|---|---|
| D1 | MCP wire layer | **Bundle official `wordpress/mcp-adapter` 0.7.x, scoped** into `Kuduy\Vendor\…` (Strauss or PHP-Scoper) | Maintained by WordPress, already dual-era incl. `server/discover`, built on core Abilities API. Hand-rolling (WSP approach) means re-implementing a spec that just changed shape. Scoping avoids clashes with other plugins bundling the adapter. Fallback if a blocker appears: our own thin JSON-RPC layer behind the same `ToolRegistry` interface. |
| D2 | OAuth core | **`league/oauth2-server` 9.x (MIT)** + our own WordPress repositories, CIMD fetcher, consent screen | Battle-tested grant/PKCE/refresh rotation logic; we write only the WP glue. Requires PHP ≥ 8.1. Alternative: own minimal AS (~1k lines of security-critical code we would have to test ourselves). |
| D3 | Minimum versions | **PHP 8.1, WordPress 6.9** (Abilities API), tested up to 7.1.x | Follows D2; WP 7.x hosting is almost always PHP 8.1+. |
| D4 | Code execution | **Off by default.** Enabled only by `define( 'KUDUY_MCP_ALLOW_CODE_EXEC', true );` in `wp-config.php` + separate OAuth scope `kuduy:code` + `manage_options` **and** `unfiltered_html`/`edit_plugins` caps | A switch that MCP itself cannot flip. Covers PHP snippets, WP-CLI passthrough, writing PHP files. |
| D5 | Auto-update | Core **`Update URI`** header + `update_plugins_github.com` filter, reading GitHub Releases; ZIP asset + SHA-256 checksum asset verified before install | Uses the supported core hook (WP 5.8+) instead of hijacking the wp.org transient; repo is public so no token stored. |
| D6 | Test runtime | Local PHP 8.3 + Composer for unit/static checks; **WordPress Playground CLI** (Node, no Docker) for integration; a **public HTTPS staging site** for real Claude connection tests | Machine has Node but no PHP/Docker; claude.ai needs a reachable HTTPS URL. |

## 3. Component diagram

```mermaid
flowchart LR
  subgraph Clients
    A[claude.ai / Desktop / mobile] 
    B[Claude Code]
  end
  subgraph WP[WordPress + KUDUY MCP]
    G[Output guard\n(early buffer on own routes)]
    O[OAuth AS\nmetadata · authorize · token · register · revoke]
    T[Token authenticator\n determine_current_user]
    M[MCP transport\n scoped wordpress/mcp-adapter]
    R[Tool registry\n Abilities API]
    P[Policy\n caps · scopes · object checks · rate limit]
    X[Executor\n validate → snapshot → run → verify → audit]
    subgraph Modules
      C[Core WP]
      E[Elementor v3/v4]
      S[SEO adapters]
      W[WooCommerce]
      I[ACF · forms · menus · blocks]
      D[Developer mode]
    end
    J[Jobs\n Action Scheduler / WP-Cron]
    L[(Audit log)]
    K[(Snapshots)]
    U[GitHub updater]
    H[Admin UI / Hidden mode]
  end
  A & B -->|HTTPS| G --> O
  G --> T --> M --> R --> P --> X --> Modules
  X --> L
  X --> K
  Modules --> J
  U -->|releases API| GH[(github.com/kuduyDigital/KuDiagnostics)]
```

## 4. Folder structure

```
kuduy-mcp/
├─ kuduy-mcp.php              # header (incl. Update URI), constants, autoload, boot
├─ uninstall.php
├─ composer.json              # runtime deps scoped into vendor-prefixed/
├─ src/
│  ├─ Plugin.php              # wiring only
│  ├─ Lifecycle/              # Activator, Deactivator, Migrations
│  ├─ Http/OutputGuard.php
│  ├─ OAuth/                  # Server, Metadata, Cimd, Consent, Repositories/, TokenAuthenticator
│  ├─ Mcp/                    # AdapterBootstrap, ToolRegistry, ToolDefinition, Result, Errors
│  ├─ Policy/                 # Scopes, Capabilities, ObjectGuard, RateLimiter
│  ├─ Execution/              # Executor, Snapshot, Verifier
│  ├─ Audit/                  # AuditLog, Redactor
│  ├─ Jobs/                   # JobQueue (Action Scheduler if present, WP-Cron fallback)
│  ├─ Admin/                  # SetupPage, HiddenMode, Cli (wp kuduy …)
│  ├─ Update/GitHubUpdater.php
│  └─ Modules/
│     ├─ Core/                # Posts, Pages, Terms, Comments, Users, Media, Menus, Plugins, Themes, Options, Health
│     ├─ Elementor/           # Documents, Elements, Schema/{V3,Atomic}, Kit, ThemeBuilder, Css, Verify, Addons/
│     ├─ Seo/                 # SeoAdapter interface, RankMath, Yoast, Aioseo, SeoPress, ImageSeo
│     ├─ Woo/
│     ├─ Acf/  Forms/  Blocks/
│     └─ Dev/                 # Files, Db (read-only), Hooks, Logs, WpCli, CodeExec (D4)
├─ assets/admin/              # small vanilla JS + CSS for setup screen
├─ languages/
└─ tests/ {unit, integration, e2e}
```

Rule: one class per file, modules depend on `Mcp\ToolDefinition` + `Policy` + `Execution`, never on each other.

## 5. Database schema

Custom tables only where options/meta do not fit (high-write, queryable, must be revocable).

| Table | Columns (abridged) | Why a table |
|---|---|---|
| `{p}kuduy_oauth_clients` | `client_id` PK, `type` (dcr/cimd/static), `name`, `redirect_uris` JSON, `metadata_url`, `metadata_cache` JSON, `created_at`, `last_used_at` | DCR creates many rows; CIMD cache |
| `{p}kuduy_oauth_codes` | `code_hash` PK, `client_id`, `user_id`, `redirect_uri`, `scope`, `resource`, `challenge`, `expires_at`, `used` | single-use, short TTL |
| `{p}kuduy_oauth_tokens` | `id`, `token_hash` UNIQUE, `type` (access/refresh), `family_id`, `client_id`, `user_id`, `scope`, `resource`, `expires_at`, `revoked_at`, `replaced_by` | rotation + reuse detection by family; revocation |
| `{p}kuduy_audit_log` | `id`, `ts`, `user_id`, `client_id`, `tool`, `args_redacted` JSON, `result` (ok/error/denied), `error_code`, `duration_ms`, `object_type`, `object_id`, `snapshot_id` | high write, filterable, retention cron |
| `{p}kuduy_snapshots` | `id`, `ts`, `user_id`, `tool`, `object_type`, `object_id`, `payload` LONGTEXT (or file path), `restored_at` | rollback of destructive changes |

Only SHA-256 hashes of codes/tokens are stored. Options: `kuduy_mcp_settings`, `kuduy_mcp_ui_hidden`, `kuduy_mcp_db_version`. Large snapshots (files, theme dirs) go to `wp-content/uploads/kuduy-mcp/snapshots/` with `index.php` + deny rules.

## 6. Tool registry design

Each tool is a small class implementing:

```php
interface Tool {
    public function name(): string;              // e.g. 'elementor-update-element'
    public function module(): string;            // 'elementor'
    public function description(): string;
    public function input_schema(): array;       // JSON Schema
    public function output_schema(): ?array;
    public function annotations(): array;        // readOnlyHint, destructiveHint, idempotentHint
    public function scope(): string;             // kuduy:read | kuduy:write | kuduy:admin | kuduy:code
    public function capability( array $input ): string|array; // WP cap(s), may depend on object
    public function is_available(): true|string;               // false reason, e.g. 'Elementor not active'
    public function execute( array $input, Context $ctx ): Result;
}
```

- Registered as **Abilities** (`wp_register_ability`) under category `kuduy-mcp/*`; the adapter exposes them as MCP tools. `permission_callback` = Policy check (scope + caps + object guard).
- `is_available()` false ⇒ tool still listed only if `KUDUY_MCP_LIST_UNAVAILABLE`, otherwise hidden; calls return a clear "integration not active" error, never a generic one.
- `Executor` wraps every call: validate input → policy → snapshot (if destructive) → execute inside output buffer + fatal guard + time limit → post-verify hook → redact → audit.
- Names stay ≤ 64 chars, ASCII, `[a-z0-9-]`, so `Mcp-Name` never needs base64.

## 7. Authentication flow

```mermaid
sequenceDiagram
  participant C as Claude
  participant W as WordPress (KUDUY)
  participant U as User browser
  C->>W: POST /wp-json/kuduy-mcp/v1/mcp (no token)
  W-->>C: 401 WWW-Authenticate: Bearer resource_metadata=".../kuduy-mcp-oauth/protected-resource", scope="kuduy:read kuduy:write"
  C->>W: GET protected-resource metadata (also served at /.well-known/oauth-protected-resource[/path])
  C->>W: GET AS metadata (RFC 8414; CIMD supported + "none" auth method)
  alt Claude Code / CIMD
    Note over W: fetch client_id URL (HTTPS, size/time capped, cached), validate redirect_uris
  else claude.ai / DCR
    C->>W: POST /register
  end
  C->>U: open /authorize?…code_challenge(S256)&resource=…
  U->>W: wp-login if needed, consent page (shows redirect host, scopes, user)
  W-->>U: 302 redirect_uri?code&state&iss
  C->>W: POST /token (form) code + verifier + resource
  W-->>C: access (1 h) + refresh (rotating, 30 d, family-tracked)
  C->>W: MCP requests with Bearer → determine_current_user → that WP user
```

Rules: S256 only; exact redirect match except **port-agnostic loopback** for `localhost`/`127.0.0.1`; token `aud` = canonical MCP URL, rejected otherwise; refresh reuse ⇒ revoke whole family; `invalid_grant` on dead refresh; `/revoke` (RFC 7009); consent page sends `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'`; non-`.well-known` metadata aliases for hosts that 404 `/.well-known/*`. Application Passwords remain a fallback for clients without OAuth (still per-user, never a shared key mapped to an admin).

## 8. Permission model

Three layers, all must pass:

1. **OAuth scope** granted at consent: `kuduy:read`, `kuduy:write`, `kuduy:admin` (plugins/themes/users/options), `kuduy:code` (D4). User can untick scopes on the consent screen.
2. **WordPress capability** of the consenting user, checked per tool and per object (`edit_post`, `delete_post`, `edit_user`, `manage_woocommerce`, `install_plugins`, …) via `current_user_can( $cap, $object_id )`.
3. **Safety policy**: refuses to deactivate KUDUY itself, refuses to switch theme / deactivate plugins flagged essential (WooCommerce with orders, the active page builder) without `confirm: true` + a pre-check report; refuses to return secrets (Redactor strips `DB_PASSWORD`, `AUTH_KEY`…, `*_secret`, `*_token`, payment gateway keys, user hashes, application passwords) from every response and audit row.

Rate limit per user+client (token bucket in transients/object cache). Admin can list and revoke connections per user.

## 9. Integration adapter design

- **SEO**: `SeoAdapter` interface (`get_meta`, `set_meta`, `get_robots`, `set_social`, `schema`, `redirects?`, `sitemap?`). `SeoResolver` picks exactly one active adapter; if two SEO plugins are active it returns an error naming both instead of writing keys for the wrong one. Writes go through each plugin's public API where it exists.
- **Elementor**: `SchemaProvider` per element family — `V3WidgetSchema` (from `get_controls()`, incl. responsive `_tablet`/`_mobile` keys) and `AtomicSchema` (from `get_props_schema()`). `AddonAdapter` interface for Essential Addons, UAE, Premium Addons, JetElements: declares widget prefixes and any non-standard settings. Edits are **patch-based** (set/unset specific keys, per breakpoint) so unrelated responsive values are never overwritten. Save via `Document::save()`, regenerate that post's CSS, optional cache purge, then fetch the permalink and confirm changed element IDs/text appear.
- **WooCommerce**: CRUD objects only (`WC_Product`, `WC_Order`, `WC_Coupon`), HPOS-compatible; gateway settings returned with secret fields masked; no refunds/charges.
- **Others** (ACF, CPT UI, forms, menus, blocks): one module each, gated by `is_available()`.

## 10. Hidden UI mode

- `kuduy_mcp_ui_hidden = true` removes: admin menu/pages, dashboard widget, notices, admin-bar node, plugin-row action links. Direct hits on hidden page slugs redirect to the dashboard.
- The plugin row in **Plugins stays visible** (requirement).
- Nothing in the runtime path reads this option except `Admin/*`: transport, OAuth, registry, modules, jobs, audit, updater are wired before and independent of it. A test asserts the tool list is identical in both modes.
- Recovery: `wp kuduy ui show`, or `define( 'KUDUY_MCP_SHOW_UI', true );`, or `?kuduy-mcp-recover=<nonce>` link emailed to the site admin email on request (**optional, decide later**).

## 11. GitHub auto-update (new requirement)

- Plugin header: `Update URI: https://github.com/kuduyDigital/KuDiagnostics`.
- Hook `update_plugins_github.com` (core filter for custom update hosts, WP 5.8+ — **re-verify signature in Phase 3**) and `plugins_api` (details modal).
- Source: `GET https://api.github.com/repos/kuduyDigital/KuDiagnostics/releases/latest`, cached 12 h in a site transient, ETag-conditional to stay well under the 60 req/h unauthenticated limit. Pre-releases ignored unless `KUDUY_MCP_UPDATE_CHANNEL=beta`.
- Release contract: tag `vX.Y.Z`, assets `kuduy-mcp.zip` (top folder `kuduy-mcp/`) and `kuduy-mcp.zip.sha256`. Before install, `upgrader_pre_download` downloads the ZIP, verifies SHA-256 against the checksum asset; mismatch aborts the update. Version compared with `version_compare` against the header.
- Works with core auto-updates toggle (`auto_update_plugin`), with hidden mode, and exposes an MCP tool `kuduy-check-update` (read) — installing an update stays the normal WP upgrader path under `update_plugins` cap.
- GitHub Actions workflow builds the ZIP (Composer `--no-dev`, scoped vendor), runs tests, attaches both assets on tag push.
- Limitation to state plainly: checksum from the same release only proves integrity, not authorship. Stronger option for later: sign the ZIP (e.g. Ed25519 via `sodium_crypto_sign_verify_detached`, public key embedded in the plugin).

## 12. Background jobs

Long operations (bulk SEO, bulk image alt text, CSS regeneration of many pages, thumbnail regeneration) return a `job_id` immediately; workers run through **Action Scheduler** when WooCommerce or another plugin provides it, else WP-Cron with a loopback kick. Tool `kuduy-job-status`. On clients that support the 2026-07-28 Tasks extension we may map jobs to it later (**UNVERIFIED** adapter support).

## 13. Developer mode (D4)

- Read-only by default: file read/search inside `ABSPATH` minus `wp-config.php` and anything matching secret patterns; DB schema + `SELECT` only via `$wpdb->prepare`, row and time capped, secret columns masked; hooks inspector; approved logs (`debug.log`, WooCommerce logs).
- Write: only under `wp-content/themes`, `wp-content/plugins`, `wp-content/mu-plugins/kuduy-sandbox/`; snapshot before every write; PHP lint (`php -l` if available, else `token_get_all` parse) before saving `.php`; crash marker + safe-mode boot for sandbox files.
- Code exec / WP-CLI: only with D4 constant + `kuduy:code` scope; time-limited; full audit of code text.

## 14. Compatibility matrix (target — nothing verified yet)

Phase 0 only proved the **test environment** boots (Playground: PHP 8.3 + WP 7.1.4 + Elementor 4.3.6 + WooCommerce 11.2.1, all active). The plugin itself is untested on everything below until Phase 3+.

| Component | Versions to test | Status |
|---|---|---|
| WordPress | 6.9.x, 7.0.x, 7.1.x | not tested |
| PHP | 8.1, 8.2, 8.3, 8.4 | not tested |
| Elementor | 3.x latest LTS, 4.3.x (atomic on/off) | not tested |
| Elementor Pro | latest | not tested |
| WooCommerce | latest, latest-1 (HPOS on/off) | not tested |
| Rank Math / Yoast / AIOSEO / SEOPress | latest | not tested |
| ACF / ACF Pro | latest | not tested |
| CF7 / WPForms / Gravity / Fluent | latest | not tested |
| Claude clients | claude.ai web, Desktop, Claude Code | not tested |
| Hosts | Apache, Nginx, LiteSpeed; one shared host that 404s `/.well-known` | not tested |
