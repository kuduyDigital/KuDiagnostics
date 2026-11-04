# Phase 3 · Step 3 — OAuth 2.1 authorization server (own, minimal)

**D2 changed 2026-11-04 (user decision):** own minimal server instead of `league/oauth2-server` (9.4 needs PHP 8.2; no RFC 8707 `resource`, CIMD, RFC 7009 revoke or token-family revoke; JWT access tokens need key files; ~8 packages to prefix).

Sources (fetched 2026-11-05): MCP 2026-07-28 `basic/authorization`, `…/authorization-server-discovery`, `…/client-registration`, `…/security-considerations`; Claude connector rules in research report §6.

## Profile (only what MCP + Claude need)

- Public clients only (`token_endpoint_auth_method: none`), authorization code + **PKCE S256 only**, refresh tokens **rotated**; reuse of a rotated/revoked refresh token **revokes the whole family** and returns `invalid_grant`.
- Opaque random tokens (`kmcp_at_…` 1 h, `kmcp_rt_…` 30 d, codes 5 min, single use, atomic claim); only SHA-256 hashes stored. Code reuse revokes tokens issued from it (family id = first 32 hex of the code hash).
- Every token bound to `resource` = canonical MCP URL; bearer accepted **only** on our MCP route.
- Scopes `kuduy:read` ⊂ `kuduy:write` ⊂ `kuduy:admin` (hierarchy honoured); `kuduy:code` only when D4's constant is set. Consent grants only scopes the user's caps justify (`edit_posts` → write, `manage_options` → admin) and lets the user untick.

## URLs

| What | URL |
|---|---|
| MCP resource | `rest_url('kuduy-mcp/v1/mcp')` |
| Issuer | `rest_url('kuduy-mcp/v1/oauth')` (has a path, so clients try three discovery URLs; the third, `{issuer}/.well-known/openid-configuration`, is a REST route → works on hosts that block `/.well-known/`) |
| PRM | `{issuer}/protected-resource` (sent in `WWW-Authenticate`), also `/.well-known/oauth-protected-resource{mcp-path}` |
| AS metadata | `/.well-known/oauth-authorization-server{issuer-path}`, `/.well-known/openid-configuration{issuer-path}`, `{issuer}/.well-known/openid-configuration` |
| Authorize | `wp-login.php?action=kuduy-mcp-authorize` (needs the browser login cookie, which REST ignores without a nonce) |
| Token / register / revoke | `{issuer}/token`, `{issuer}/register` (DCR, deprecated but Claude.ai may use it; 10/h per IP), `{issuer}/revoke` |

Requires pretty permalinks (an issuer with `?rest_route=` is not a valid issuer); admin notice otherwise.

## Rules tested

401 + `WWW-Authenticate: Bearer resource_metadata="…", scope="kuduy:read kuduy:write"`; `error="invalid_token"` for bad/expired/revoked/wrong-resource tokens; 403 `insufficient_scope` with the tool's scope; metadata fields (`code_challenge_methods_supported`, `client_id_metadata_document_supported`, `authorization_response_iss_parameter_supported`, `token_endpoint_auth_methods_supported: ["none"]`); DCR validation (https or loopback redirect URIs, no fragments); CIMD (https + path, `wp_safe_remote_get`, no redirects, 64 KB, 5 s, `client_id` equals URL, cached 5 min–24 h by `Cache-Control`); exact redirect match except port-agnostic loopback; invalid client/redirect → error page, never a redirect; other errors redirect with `state` + `iss`; consent shows client name + redirect host + loopback warning, `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'`, nonce; token endpoint: PKCE, redirect_uri, client_id, resource, single-use code, `Cache-Control: no-store`; refresh rotation + family revoke; revoke always 200.

## Tests

Integration (PHPUnit in Playground) for every rule above, with `pre_http_request` mocking CIMD fetches. HTTP E2E (`npm run smoke:mcp`): full flow over real HTTP — 401 → PRM → AS metadata (well-known **and** REST fallback) → DCR → authorize (login redirect, real `wp-login.php` login, consent HTML, approve) → code → token → `tools/call` with Bearer → refresh → reuse detected → revoke.

Deferred: admin screen listing/revoking connections (with the setup page), expired-row cleanup cron (step 4 with audit retention).
