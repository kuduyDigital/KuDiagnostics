# Phase 3 · Step 2 — MCP layer (own, dual-era)

Goal: `POST /wp-json/kuduy-mcp/v1/mcp` speaks MCP 2026-07-28 (stateless) and 2025-11-25 (legacy `initialize`), lists tools the current user may use, and runs `kuduy-site-info`. Auth here is any WordPress login (cookie or Application Password); OAuth replaces it in step 3.

Sources (fetched 2026-11-04): `schema/2026-07-28/schema.ts`, `schema/2025-11-25/schema.ts`, modelcontextprotocol.io `basic/transports/streamable-http`, `basic/versioning`.

## Wire rules implemented

| Case | Response |
|---|---|
| Body has `_meta["io.modelcontextprotocol/protocolVersion"]` | modern, stateless |
| `initialize` | legacy; echo `2025-11-25`/`2025-06-18`/`2025-03-26`, else `2025-11-25`; no `Mcp-Session-Id` (optional in legacy) |
| Neither | legacy request (needs prior `initialize`; not tracked: sessions are optional) |
| Modern version ≠ `2026-07-28` | 400, `-32022`, `data.supported` + `data.requested` |
| Missing/mismatched `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name` (tools/call; `=?base64?…?=` decoded) | 400, `-32020` |
| Unknown method | 404, `-32601` |
| Notification (no `id`) | 202, empty body |
| GET / DELETE | 405 |
| Foreign `Origin` | 403 (allowed: site origin, `https://claude.ai`, `https://claude.com`, filter `kuduy_mcp_allowed_origins`) |
| Bad JSON / batch array | 400, `-32700` / `-32600` |
| Not logged in, for `tools/*` | 401, `-32001`-style JSON-RPC error (step 3 adds `WWW-Authenticate`) |

Modern results carry `resultType: "complete"`, `_meta["io.modelcontextprotocol/serverInfo"]`; `server/discover` and `tools/list` add `ttlMs: 0`, `cacheScope: "private"` (lists depend on the user).

## Components

- `Mcp\Tool` interface (architecture §6, `Context` arrives with OAuth), `Mcp\ToolError`, `Mcp\ToolRegistry` (action `kuduy_mcp_register_tools`).
- `Mcp\Executor`: available? → capability → `rest_validate_value_from_schema()` + sanitize → execute; any other `Throwable` becomes a generic tool error (no internals leaked). Audit + snapshots arrive in step 4.
- `Mcp\Server` (JSON-RPC routing, era detection, results) and `Mcp\RestController` (route, headers, status codes).
- `Http\OutputGuard`: buffers from plugin load on our route, discards stray output before serving, sends 202 with an empty body.
- `Modules\Core\SiteInfo` → `kuduy-site-info` (read-only, cap `read`).

## Tests

Integration (`rest_do_request`): every row of the table above; tools/list filtered by capability; site-info result; validation error; denied tool; throwing tool.
HTTP smoke (`npm run smoke:mcp`): real Playground server + a mu-plugin that echoes junk on `init`; checks JSON stays clean (F2), 202 empty body, 405.
