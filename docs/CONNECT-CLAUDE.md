# Connecting Claude to KUDUY MCP

Claude connects to your site as a **custom connector** (a remote MCP server). You sign in with your own WordPress account and approve what Claude may do; Claude then acts as you, never with more rights than your WordPress role has.

## Which Claude apps are verified

| Claude app | Status |
|---|---|
| claude.ai in a browser | **Verified** on a real host (Hostinger staging, 2026-11-05 to 2026-11-10): sign-in, tool calls, admin scope |
| Claude Desktop | Not yet verified with a real sign-in (open item O11) |
| Claude Code | Not yet verified with its own sign-in (open item O11) |

KUDUY follows the MCP authorization specification (OAuth 2.1 with PKCE, protected-resource metadata, dynamic client registration and client ID metadata documents), which these apps use; the table only lists what has been checked by hand.

## Connect from claude.ai

1. In WP admin open **Tools → KUDUY MCP** and copy the connector address (ends in `/wp-json/kuduy-mcp/v1/mcp`).
2. In claude.ai open **Settings → Connectors → Add custom connector**, give it a name (for example "My site") and paste the address.
3. Claude opens your site's sign-in page. Log in with your WordPress account.
4. The approval screen lists what Claude asks for:
   - **Read** — view content, settings summaries, reports.
   - **Write** — create and change content, media, SEO, shop items.
   - **Admin** — site-wide changes: plugins, themes, users, store settings, developer read tools. Offered unticked to administrators only.
   - **Code** — only on sites with `KUDUY_MCP_ALLOW_CODE_EXEC`; never offered unasked.
   Approve. The connection appears under **Tools → KUDUY MCP → Active connections**.
5. In a chat, turn the connector on and ask, for example, "List my draft posts" or "Show the SEO title of the home page".

## Good to know

- **Undo:** most changes are snapshotted. Ask Claude to undo ("restore snapshot 42"); snapshots are kept for 30 days.
- **Confirmation:** actions that email customers, change where form submissions go, deactivate important plugins or report data to outside services first only describe what would happen; Claude asks you before confirming.
- **Personal data:** customer and form-visitor details are masked unless you ask for them and are allowed to edit them.
- **New tools:** after an update, Claude may show the new tools only once it refreshes the connector's tool list (start a new chat).
- **Revoke:** **Tools → KUDUY MCP → Revoke** ends a connection immediately; Claude must sign in again.
- **Audit log:** every tool call is logged with user, app, tool and result (no secrets) under **Tools → KUDUY MCP → Recent tool calls**.

## Troubleshooting

| Problem | Fix |
|---|---|
| "needs pretty permalinks" | **Settings → Permalinks**: choose any option except "Plain" |
| Claude cannot reach the site | The site must be reachable over HTTPS from the internet |
| A tool is missing | Its plugin is not active, or your account lacks the right; with `KUDUY_MCP_LIST_UNAVAILABLE` Claude sees the reason |
| "insufficient scope" | Reconnect and tick the scope the tool needs (for example Admin) |
| Too many requests | 120 calls per minute per user and app (filter `kuduy_mcp_rate_limit`) |
