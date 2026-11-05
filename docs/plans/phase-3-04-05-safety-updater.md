# Phase 3 · Steps 4–5 — Safety layer, GitHub self-update

## Step 4 — safety layer

| Piece | Behaviour |
|---|---|
| `Audit\Redactor` | Replaces values of secret-looking keys (`password`, `pwd`, `secret`, `token`, `api_key`, `private_key`, `auth_key`, `salt`, `cookie`, `authorization`, …) and secret-looking strings (`kmcp_` tokens, `Bearer …`, WordPress password hashes) with `[redacted]`. Applied to audit arguments **and** to every tool result. |
| `Audit\AuditLog` | One row per `tools/call`: user, client (`OAuth client id`, `application-password` or `cookie`), tool, redacted args, `ok`/`error`/`denied`, error code, duration, snapshot id. |
| `Execution\Snapshottable` + `Snapshots` | A tool with `destructiveHint: true` that implements `Snapshottable` gets a snapshot before it runs. Tools `kuduy-snapshot-list` / `kuduy-snapshot-restore` (scope write; own snapshots, or any with `manage_options`); a snapshot restores once. |
| `Policy\RateLimiter` | Per user + client, fixed one-minute window, default 120 calls (filter `kuduy_mcp_rate_limit`). Over the limit: HTTP 429, `Retry-After`, JSON-RPC error. |
| `Lifecycle\Cleanup` | Daily cron `kuduy_mcp_cleanup`: audit rows > 90 days (filter), snapshots > 30 days, codes and tokens expired > 1 day ago, CIMD cache rows unused. Scheduled on boot, cleared on deactivation (completes F1) and uninstall. |
| `Admin\ConnectionsPage` | Tools → KUDUY MCP: MCP URL to paste into Claude, active connections (own, or all for admins) with Revoke, last 20 tool calls. |

## Step 5 — GitHub self-update (architecture §11, D5)

- `Update\GitHubUpdater` on `update_plugins_github.com` (only for our plugin file), `plugins_api` (details modal), `upgrader_pre_download` (our package only: download ZIP + `.sha256`, compare, abort on mismatch). Releases API cached 12 h; pre-releases ignored unless `KUDUY_MCP_UPDATE_CHANNEL` = `beta`.
- Tool `kuduy-check-update` (read, cap `update_plugins`). Installing stays the normal WordPress upgrader.
- `scripts/build-release.php`: copies plugin files, `composer install --no-dev`, writes `build/kuduy-mcp.zip` (top folder `kuduy-mcp/`) + `kuduy-mcp.zip.sha256`; checks `Version:` header = `Plugin::VERSION` (= tag in CI).
- `.github/workflows/release.yml`: on `v*` tag → CI must pass → build → GitHub Release with both assets.
- `npm run check:release`: boots Playground with only the built ZIP's contents and activates it.

Tests: integration with mocked HTTP — `wp_update_plugins()` really offers the update (proves the core filter signature on WP 6.9 and 7.1), no offer when up to date, other github.com plugins untouched, checksum mismatch aborts, details modal, header/constant versions match. Real `v0.1.1` install = step 6 (tags need the user's OK).
