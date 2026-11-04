# Phase 3 · Step 1 — Plugin skeleton, lifecycle, migrations

Goal: an installable `kuduy-mcp` plugin that creates/upgrades its tables, cleans up on uninstall only when asked, and has unit + integration + static checks running locally and in CI. No MCP or OAuth code yet.

## Decisions

- **Repo root = plugin root** (`kuduy-mcp.php` at top). Release ZIP (step 5) copies only plugin files into `kuduy-mcp/`.
- **Integration tests run PHPUnit inside Playground's real WordPress** (SQLite): `scripts/test-integration.mjs` mounts the repo at `wp-content/plugins/kuduy-mcp`, runs a blueprint `runPHP` step that loads WP and PHPUnit, writes `build/it/junit.xml` through a mounted folder; the wrapper fails on any failure/error or missing report.
- `composer.json` `config.platform.php = 8.1.0` so the lock installs on PHP 8.1 CI.
- Deactivation hook: **skipped** until the first cron hook exists (audit retention, step 4). Test matrix F1 "cron cleared on deactivate" moves there.

## Tasks (test first)

1. Composer: PHPUnit 10.5, PHPStan 2 + WordPress extension, WPCS 3. `composer test:static`.
2. Failing integration tests `tests/integration/LifecycleTest.php`:
   - activation creates the 5 tables from architecture §5 and sets `kuduy_mcp_db_version`;
   - an older stored `db_version` is upgraded on `plugins_loaded` without re-activation (auto-update path);
   - `Uninstaller::run(false)` keeps tables/options; `run(true)` drops all 5 tables and the 3 options.
3. Implement `kuduy-mcp.php`, `src/Plugin.php`, `src/Lifecycle/{Migrations,Uninstaller}.php`, `uninstall.php`.
4. CI: static job (PHPCS, PHPStan) + integration job (Node + Playground).
5. Verify: tests green locally, plugin activates in Playground with no notices, DEVLOG updated.
