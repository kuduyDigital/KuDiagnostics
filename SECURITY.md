# KUDUY MCP — Security

Covers v0.2.0 (Phase 3 foundation, HTTPS enforcement, early-auth fix, first Phase 4 read tools). Planned controls for later phases are marked *planned*.

## Reporting a vulnerability

Please do not open a public issue. Report privately to the maintainer (KUDUY Digital). GitHub's private vulnerability reporting is **not enabled yet** on `kuduyDigital/KuDiagnostics` (checked 2026-11-05); once the maintainer turns it on under Settings → Security, use **Security → Report a vulnerability**. Fixes ship as a GitHub release, which installed sites pick up through the built-in updater.

## 1. What we protect

| Asset | Why it matters |
|---|---|
| WordPress accounts and capabilities | A connection acts as a real user; abuse = that user's full power |
| OAuth codes, access and refresh tokens | Bearer credentials |
| Site secrets (`wp-config.php`, salts, DB password, application passwords, payment keys) | Must never leave the server |
| Site content and settings | Destructive changes must be recoverable |
| The update channel | A bad release installs on every site |

## 2. Threat model

| Actor / path | Threat | Controls |
|---|---|---|
| Anonymous internet client | Call tools, read data | Every tool and `initialize` need a signed-in user; 401 + `WWW-Authenticate` challenge |
| Anonymous client | Abuse OAuth endpoints (spam registrations, outbound fetch amplification) | DCR bounded by unused clients (cap 1000, oldest unused evicted); login required before any CIMD fetch; CIMD fetch uses `wp_safe_remote_get` (no private/loopback IPs), no redirects, 64 KB, 5 s, `client_id` must equal URL |
| Malicious website (CSRF, clickjacking, DNS rebinding) | Ride a logged-in admin's browser | Origin allowlist on the MCP route (403); REST cookie auth needs a nonce; consent page nonce + `X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` |
| Stolen or leaked token | Use it beyond its grant | Token valid only on the MCP route; scopes enforced per tool; access 1 h; refresh rotation with family revoke on reuse; revocation endpoint and admin revoke; tokens stored only as SHA-256 |
| Malicious OAuth client | Steal a code via redirect | Exact redirect URI match (loopback any port only); PKCE S256 required; code single-use and bound to client, redirect and verifier; `iss` returned (RFC 9207); `resource` must be our MCP URL |
| Content read tools | Leak drafts, private or protected content | Unpublished statuses need the type's `edit_posts` (`read_private_posts` for private); without `edit_others_posts` only your own; `read_post` per post; password-protected content only for users who can edit that post; post passwords never returned; only post types and taxonomies with an admin UI |
| Content write tools | Unauthorized or destructive edits | `create_posts`/`publish_posts` per type and status; `edit_post` / `delete_post` per object; `assign_terms` per taxonomy; terms and images checked before anything is written; core kses for users without `unfiltered_html`; snapshot + undo for update and trash; trash only, never permanent delete (refused when the trash is disabled); Elementor-built content not overwritten |
| Terms, comments, revisions, custom fields | Overreach or data leaks | Same capabilities as core's REST API: `edit_terms` / `edit_term` / `delete_term`; non-approved comments and comment emails/IPs only with `moderate_comments`; `edit_comment` per comment; replies through `wp_new_comment()` (kses, flood, duplicates; held unless moderator); revisions need `edit_post`; custom fields use `edit_post_meta` per key and never touch protected (`_`) keys; undo for every overwrite; default category cannot be deleted |
| User tools | Privilege escalation, account takeover, lockout | Same rules as core's REST users controller: `list_users` to see others, `create_users`, `edit_user` per user, `promote_user` + editable roles for role changes; changes need scope `kuduy:admin`; nobody changes their own role; the last administrator cannot be demoted; passwords are never set to a known value or returned (new users get WordPress's set-password email); deleting users is not offered; undo for updates |
| Menus and settings | Site-wide changes, takeover through settings | Menus need `edit_theme_options`, settings `manage_options`; changes need scope `kuduy:admin`; settings are an allow-list — site/home URL, admin email, permalink structure, `users_can_register` and `default_role` are read-only (open registration + an admin default role would be a takeover); values validated (timezone, published front page, existing category) and passed through `sanitize_option()`; undo for every change |
| Signed-in user with low role | Escalate through tools | Offered scopes cut to the user's caps; `current_user_can()` per tool and input; undo needs the original tool's scope and capability |
| Claude (model) mistakes | Destroy data | Snapshots before destructive tools; audit log; rate limit 120/min; *planned:* confirm policy for disruptive actions |
| Other plugins | Break JSON responses, leak notices | Output guard on our routes |
| Compromised GitHub Action / release | Push a malicious update | Actions pinned to commit SHAs; release requires CI + install check; SHA-256 verified before install. Limit: checksum from the same release proves integrity, not authorship |
| Logs and audit rows | Leak secrets | Redactor on results and audit arguments (secret-looking keys, bearer values, `kmcp_` tokens, password hashes); exceptions logged as class/file/line only |

## 3. Controls by layer (code paths)

- **Transport** — `src/Mcp/RestController.php`: POST only, Origin allowlist, JSON parse errors as JSON-RPC. `src/Http/OutputGuard.php`: discards stray output.
- **Protocol** — `src/Mcp/Server.php`: strict header/body matching, version checks, auth before `initialize`/`tools/*`, scope check, rate limit.
- **Authentication** — `src/OAuth/TokenAuth.php`: bearer from the `Authorization` header only (never query), exact MCP path, `rest_route` overrides refused, `rest_pre_dispatch` guard; a bad token yields no user (no fallback to cookies).
- **Authorization server** — `src/OAuth/{Authorize,Tokens,Clients,Endpoints,Scopes,Urls}.php` as described in `ARCHITECTURE.md` §7.
- **Execution** — `src/Mcp/Executor.php`: schema validation, capability, snapshot, redaction, audit. `src/Execution/Snapshots.php`: owner/manager only, original scope + capability, atomic restore claim.
- **Data** — `src/Lifecycle/Migrations.php`: hashed tokens; `Cleanup` retention; uninstall keeps data unless `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL`.
- **Updates** — `src/Update/GitHubUpdater.php`: only our plugin file, checksum before install, releases fetched with the safe HTTP API; `.github/workflows/*.yml` pinned actions, least-privilege `permissions`.

## 4. Audit 2026-11-05 (foundation)

Ran PHPCS (WPCS), PHPStan level 6, `composer audit` (clean), `npm audit` (3 moderate in the dev-only Playground CLI; not shipped), all tests, and a line-by-line read of `src/`. Findings, all fixed in **v0.1.2** with tests:

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | Critical | A token was accepted on any REST route: `/wp-json/kuduy-mcp/v1/mcp?rest_route=/wp/v2/…` (or `rest_route` in the body, or a PATH_INFO tail) ran core routes as the user. A `kuduy:read` token read `/wp/v2/users/me` and `/wp/v2/settings` and created a post (confirmed over HTTP). Affects v0.1.0–v0.1.1. | Exact path match, other `rest_route` refused, `rest_pre_dispatch` guard (O12) |
| 2 | High | Approving consent with every box unticked granted every scope | Nothing ticked → `access_denied` (O13) |
| 3 | Medium | `initialize` answered without sign-in, so claude.ai chose "No sign-in" and failed | 401 + challenge (O14) |
| 4 | Medium | Anonymous visitors could make the site fetch any HTTPS URL (CIMD lookup before login) | Login first |
| 5 | Medium (latent) | Snapshot undo checked only `kuduy:write` and capability with empty input | Original tool's scope + capability with saved input; atomic claim |
| 6 | Medium | Release job used tag-pinned third-party actions with write access | Pinned to commit SHAs |
| 7 | Medium | DCR limit was per IP; behind a CDN all visitors share one IP, so one client could block claude.ai | Cap on unused clients instead |

Users of v0.1.0 or v0.1.1 should update to v0.1.2.

Found afterwards, fixed in **v0.2.0**: **HTTPS was not enforced** (now refused over plain HTTP outside local sites, O17), and a **fatal error** when another plugin checks the current user on `plugins_loaded` during an MCP request with a token: `rest_url()` needs `$wp_rewrite`, which does not exist yet (reproduced on WordPress 7.1.4; fixed and tested, O18).

## 5. Known limitations and accepted risks

- **Update authorship**: SHA-256 from the same release; no signature yet (later: Ed25519 with an embedded public key).
- **Refresh races**: two simultaneous refreshes with the same token revoke the family (OAuth reuse rule); the client must sign in again.
- **Rate limiter**: transient counter, not atomic under heavy concurrency (marked `shortcut:` in code).
- **Redaction** is pattern-based; application passwords and gateway keys get dedicated tests when user and WooCommerce tools land (P4).
- **Application Password / cookie users** are limited by capabilities only (no OAuth scope) — by design, same as WordPress.
- **HTTPS** is enforced for authorize, token, registration and bearer tokens (not for revocation, which is always safe) unless the site is local. Behind a TLS-terminating proxy `X-Forwarded-Proto: https` is trusted when the site URL is https; a client that fakes it over plain HTTP only exposes its own token.
- **CIMD fetch** relies on `wp_safe_remote_get`; DNS rebinding between check and connect is a known limit of the WordPress HTTP API.
- `kuduy-check-update` with `refresh: true` can be called up to the rate limit and use GitHub's anonymous quota (60/h); failure only delays update checks.

## 6. Planned controls (later phases)

Safety policy for disruptive operations (theme switch, deactivating essential plugins, Woo refunds), object-level guards per tool, file-path allow-lists and secret-file deny-list for developer mode, D4 code execution with time limit + crash marker + safe-mode boot, hidden-mode recovery that needs server or admin access, fuzzing of tool inputs (Phase 9).
