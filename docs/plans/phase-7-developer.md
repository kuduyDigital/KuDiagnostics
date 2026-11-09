# Phase 7g — Developer mode (plan)

Decision D4 (built as a scope earlier) plus D21 below. Status: **D21 decided with the recommended option (A) under the user's standing instruction of 2026-11-09 to finish Phase 7 unattended; for the user's review.** Developer tools are the most sensitive part of KUDUY: please review before enabling `KUDUY_MCP_ALLOW_CODE_EXEC` on any site.

Exit gate (`ROADMAP.md`, developer part): read-only developer tools never return secret files, secret columns or credentials; code tools exist only with D4's constant, the `kuduy:code` scope, `manage_options` and `edit_plugins` (so `DISALLOW_FILE_EDIT` turns them off); file writes are parse-checked, limited to approved folders and undoable; every call is audited.

## Decision D21 — what developer mode offers

| Option | Read-only (scope `kuduy:admin`, `manage_options`) | Code (D4: constant + `kuduy:code` + `manage_options` + `edit_plugins`) |
|---|---|---|
| **A. Read tools always; code tools only behind D4 (recommended, used)** | List, read and search files inside the WordPress folder and `wp-content` (secret files refused: `wp-config*.php`, `.env*`, keys and certificates, `.htpasswd`, `auth.json`, `.git`/`.ssh`, database dumps, KUDUY's own local secrets); read `debug.log`; database schema; capped read-only queries (`SELECT`, `SHOW`, `DESCRIBE`, `EXPLAIN`; one statement; at most 200 rows; no file or sleep functions), with password, token, key and session columns and secret option/meta values masked | Create or replace files in a plugin or theme folder or the KUDUY sandbox (`wp-content/kuduy-sandbox`), never KUDUY itself or must-use plugins; PHP files must parse; confirm-first for active plugins and themes; snapshot and undo. Run a PHP snippet (time limit, output and errors captured, fatal errors recorded in the audit log) |
| B. Read tools only | as A | none |
| C. Everything behind D4 | none without the constant | as A |

WP-CLI is not offered: it is not available inside a web request on most hosts (UNVERIFIED per host); the same work is possible through the PHP tool behind D4.

## Tools (module `src/Modules/Dev/`)

| Tool | Scope | Capability | Undo |
|---|---|---|---|
| `kuduy-dev-files`: list a folder (relative to the WordPress folder or `wp-content`) | admin | `manage_options` | — |
| `kuduy-dev-file-read`: read a text file (lines, at most 512 KB); credential-looking values masked | admin | `manage_options` | — |
| `kuduy-dev-search`: find a text in plugin, theme or other `wp-content` files (literal, case-insensitive, at most 200 matches) | admin | `manage_options` | — |
| `kuduy-dev-log`: last lines of `debug.log` (when `WP_DEBUG_LOG` is on), masked | admin | `manage_options` | — |
| `kuduy-dev-db-schema`: tables of this site with row counts and columns | admin | `manage_options` | — |
| `kuduy-dev-db-query`: one read-only query, capped, secrets masked | admin | `manage_options` | — |
| `kuduy-dev-file-write`: create or replace a file in an approved folder | code | `edit_plugins` + `manage_options` | previous content restored, or the new file removed |
| `kuduy-dev-php`: run PHP (`return` a value; output captured) | code | `edit_plugins` + `manage_options` | — (audited; changes it makes are not undone) |

## Tests (Playground)

- D1 files: list and read inside the allowed roots; `wp-config.php`, `.env`, path traversal (`../`), absolute paths outside, binary files refused; masked `define( '…_KEY', … )`.
- D2 search finds a string in a test plugin file and skips denied files.
- D3 database: schema lists `{prefix}posts`; a `SELECT` on users masks `user_pass` and `user_activation_key`; options with secret names masked; `UPDATE`, two statements, `INTO OUTFILE`, `SLEEP()` refused; rows capped.
- D4 code tools are not listed without the constant (the test suite runs without it) and are refused for an editor; with the constant defined in a dedicated run they write, parse-check, refuse KUDUY's own folder and undo (`KUDUY_CODE_EXEC=1`, local and CI).
