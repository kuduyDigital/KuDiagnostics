# Installing KuDiagnostics

KuDiagnostics lets Claude manage a WordPress site through the Model Context Protocol, always as a real WordPress user and only within that user's permissions.

## Requirements

| | Minimum | Tested |
|---|---|---|
| WordPress | 6.9 | 6.9.11 and 7.1.4 |
| PHP | 8.1 | 8.1.34 and 8.3.36 |
| HTTPS | Required for Claude to connect (local sites may use http) | Hostinger, Cloudflare tunnel |
| Permalinks | Any setting except "Plain" (Claude's sign-in needs pretty URLs) | |

Plugins KuDiagnostics works with (each only when active): Elementor (+ Pro), WooCommerce (HPOS on or off), Rank Math, Yoast SEO, All in One SEO, SEOPress, Advanced Custom Fields, Contact Form 7 + Flamingo, Fluent Forms, WPForms Lite, Polylang, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache. Versions and test evidence: `COMPATIBILITY.md`.

## Install

1. Download `kuduy-mcp.zip` from the latest release: <https://github.com/kuduyDigital/KuDiagnostics/releases/latest>. Optionally check it against `kuduy-mcp.zip.sha256` from the same release.
2. In WP admin go to **Plugins → Add New → Upload Plugin**, choose the ZIP, **Install Now**, then **Activate**.
3. Open **Tools → KuDiagnostics**. It shows the address Claude connects to, for example `https://example.com/wp-json/kuduy-mcp/v1/mcp`.
4. If WordPress shows "KuDiagnostics needs pretty permalinks", go to **Settings → Permalinks** and choose any option except "Plain".

Then connect Claude: `docs/CONNECT-CLAUDE.md`.

## Updates

KuDiagnostics updates itself from its GitHub releases like any plugin: **Dashboard → Updates** shows new versions, and WordPress auto-updates apply them if you turn them on for this plugin. Each release ZIP is checked against its SHA-256 file before WordPress installs it. `define( 'KUDUY_MCP_UPDATE_CHANNEL', 'beta' );` also offers pre-releases.

## Settings in wp-config.php (all optional)

| Constant | Effect |
|---|---|
| `KUDUY_MCP_SHOW_UI` = `true` | Shows KuDiagnostics's admin page even when it was hidden (recovery for hidden mode) |
| `KUDUY_MCP_ALLOW_CODE_EXEC` = `true` | Turns on the code tools (`kuduy-dev-file-write`, `kuduy-dev-php`) and the `kuduy:code` scope. **Development sites only** — read `SECURITY.md` first |
| `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL` = `true` | Deleting the plugin also removes its tables (audit log, snapshots, connections, jobs) and options. Without it, uninstall keeps them |
| `KUDUY_MCP_LIST_UNAVAILABLE` = `true` | Lists tools of inactive plugins too (they answer with the reason they are unavailable) |
| `KUDUY_MCP_UPDATE_CHANNEL` = `'beta'` | Offers pre-releases as updates |

## Hidden mode

A site administrator can hide KuDiagnostics's admin page (**Tools → KuDiagnostics → Hide KuDiagnostics from WP admin**). Claude keeps working; the plugin stays listed under Plugins. To show it again, any one of:

- `wp kuduy ui show` (WP-CLI)
- `wp option update kuduy_mcp_hidden_mode 0`
- `define( 'KUDUY_MCP_SHOW_UI', true );` in `wp-config.php`

## Uninstall

Deactivate and delete the plugin from **Plugins**. Connections stop working at once. Data is kept unless `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL` is set (see above). Custom post types defined through KuDiagnostics stop being registered; their content stays in the database.
