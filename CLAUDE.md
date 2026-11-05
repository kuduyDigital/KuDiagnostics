# KUDUY MCP — project instructions

KUDUY MCP is an original, native-PHP WordPress plugin (GPL-2.0-or-later) that lets an authorized Claude client manage a WordPress site through the Model Context Protocol, always as a real WordPress user and within that user's capabilities. Repo: `github.com/kuduyDigital/KuDiagnostics`. The repo root is the plugin root.

The product brief lives outside the repo (KUDUY MCP Master Development Prompt). These project files are its working form; keep them true:

| File | Keep current with |
|---|---|
| `RESEARCH.md` | Reference findings, licenses, verified API facts, open UNVERIFIED items |
| `ARCHITECTURE.md` | Decisions (D1–D6), components, schema, flows, permission model |
| `ROADMAP.md` | Phase status and exit gates |
| `FEATURES.md` | One row per feature: `Not started` / `In progress` / `Implemented` / `Tested` / `Blocked` |
| `COMPATIBILITY.md` | Versions actually tested, with date and evidence |
| `SECURITY.md` | Threat model, controls, audit findings, known limits |
| `TESTING.md` | Test strategy, feature test matrix, latest results |
| `CHANGELOG.md` | Releases and the dated development history |

Update the affected files in the same commit as the change. Never mark anything `Tested` without a passing test or recorded manual evidence.

## Rules (from the brief, non-negotiable)

1. Research before coding; verify every hook, API and protocol detail against source or official docs. Mark anything not verified as **UNVERIFIED**.
2. Every tool runs as the authenticated WordPress user: OAuth scope + capability (per object) + safety policy. No shared keys mapped to an admin.
3. Never return or log secrets: `wp-config.php` values, passwords and hashes, tokens, Authorization headers, application passwords, payment keys. Redactor runs on every result and audit row.
4. Use official APIs (Elementor `Document::save()`, WooCommerce CRUD, SEO plugin APIs) — never guessed meta keys or raw serialized writes.
5. Snapshot before destructive changes; report partial failures honestly.
6. No unrestricted eval, shell or SQL writes by default. Code execution only behind the `KUDUY_MCP_ALLOW_CODE_EXEC` constant + `kuduy:code` scope (D4).
7. Hidden mode changes presentation only; the MCP runtime never reads it. The plugin row stays visible in Plugins.
8. No placeholders or TODOs in released code. No reference code copied (WSP is GPL-2.0, Novamira AGPL-3.0: ideas only).
9. Don't claim client support (claude.ai, Desktop, Claude Code) until it has been verified against the real client.

## Commands

PHP is not on the Bash PATH by default; prefix with
`export PATH="/c/Users/marya/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe:$PATH"`.

| Purpose | Command |
|---|---|
| Code style | `php vendor/bin/phpcs -q` (auto-fix: `php vendor/bin/phpcbf -q <files>`) |
| Static analysis | `php vendor/bin/phpstan analyse --memory-limit=1G` (level 6) |
| Integration tests | `KUDUY_WP=6.9 KUDUY_PHP=8.1 node scripts/test-integration.mjs` and `KUDUY_WP=7.1 KUDUY_PHP=8.3 …` |
| Integration with Elementor Pro (local only, ZIP git-ignored in repo root) | `KUDUY_LOCAL_PLUGINS=elementor-pro-4.3.2.zip KUDUY_WP=7.1 KUDUY_PHP=8.3 node scripts/test-integration.mjs` (Pro needs Elementor 4.x: add `KUDUY_ELEMENTOR=4.3.6` on the 6.9 pair) |
| Real-HTTP smoke incl. full OAuth | `node scripts/smoke-mcp.mjs` (port 9401) |
| Release ZIP | `php scripts/build-release.php --expect-version=X.Y.Z` then `npm run check:release` |
| Real GitHub update | `node scripts/check-update.mjs <from> <to>` |
| Public test site | `npm run tunnel` (port 9410; admin password in gitignored `.tunnel-admin.txt` — never print it) |

CI (`.github/workflows/ci.yml`) runs lint 8.1–8.4, PHPCS + PHPStan, integration on both pairs, smoke and the release-ZIP install. Actions are pinned to commit SHAs; keep them pinned.

## Conventions

- PHP 8.1+, WordPress 6.9+, namespace `Kuduy\Mcp`, prefix `kuduy_mcp`, one class per file under `src/`.
- TDD: failing test first, then code. Integration tests run inside WordPress Playground (`tests/integration/`).
- Never write files with PowerShell 5.1 `Set-Content -Encoding utf8` (adds a BOM that breaks PHP). Use the Edit/Write tools.
- Tools: names `kuduy-*`, ASCII `[a-z0-9-]`, ≤ 64 chars; implement `Mcp\Tool` (plus `Execution\Snapshottable` when destructive).
- Version lives in two places: the `Version:` header in `kuduy-mcp.php` and `Plugin::VERSION`. The build refuses a mismatch.

## Git and releases

- Commit to `main` and push after each verified change.
- Ask the user before tagging a release (`vX.Y.Z` publishes to every installed site through the updater), force-pushing, or rewriting history.
- Release = push tag → `release.yml` runs CI, builds and install-checks the ZIP, publishes `kuduy-mcp.zip` + `.sha256`.
