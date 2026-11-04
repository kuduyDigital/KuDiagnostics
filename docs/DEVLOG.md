# KUDUY MCP — Development Log

## 2026-11-04 — Phase 1 research, Phase 2 proposal

- Repo initialised and pushed to `github.com/kuduyDigital/KuDiagnostics` (public), branch `main`.
- Studied WSP (GPL-2.0, v2.9.7) and Novamira (AGPL-3.0) from clones; no code copied.
- Verified current MCP spec (2026-07-28, stateless, CIMD preferred over DCR), Claude connector auth rules, WordPress 7.1.4 + core Abilities API, `wordpress/mcp-adapter` 0.7.1 (dual-era), Elementor 4.3.0 atomic element schemas.
- Environment gaps: no PHP, Composer, WP-CLI or Docker. Node present.
- New requirement added: self-update from GitHub releases (architecture §11).
- Decisions D1–D6 in `02-architecture.md` approved by the user.
- Next: Phase 0 tooling (PHP 8.3, Composer, Playground CLI, staging site), then Phase 3 foundation.

## 2026-11-04 — Phase 0 tooling

- PHP 8.3.36 (official Windows build via winget, user scope). `php.ini` from `php.ini-development` with curl, fileinfo, gd, intl, mbstring, openssl, pdo_sqlite, sodium, zip, exif, mysqli enabled; `memory_limit = 512M`.
- Composer 2.11.1 (official installer, SHA-384 signature checked) in `%LOCALAPPDATA%\Programs\composer`, added to user PATH.
- Claude skills installed to `~/.claude/skills`: `wp-plugin-development` + `wp-project-triage` from `WordPress/agent-skills` @ `eff3785` (reviewed: Markdown plus read-only fs scanners, no network). Also caveman and ponytail.
- Playground: `@wp-playground/cli` 3.1.62 pinned in `package.json`; blueprint `tests/playground/blueprint.json`. `npm run playground` → http://127.0.0.1:9400. Verified in wp-admin: **WordPress 7.1.4, Elementor 4.3.6 active, WooCommerce 11.2.1 active**. Note: the CLI banner says "WordPress latest" even though the blueprint asks for 7.1; check the admin footer, not the banner.
- `.github/workflows/ci.yml` skeleton: PHP 8.1–8.4 matrix, `php -l`, composer validate/install once `composer.json` exists.
- Superpowers 7.0.2 (`obra/superpowers`) installed, user scope, from `obra/superpowers-marketplace` (not yet in the local copy of the official marketplace).
- Staging: cloudflared 2026.10.7 (winget). `npm run tunnel` (`scripts/tunnel.mjs`) opens a Cloudflare quick tunnel and boots Playground with `--site-url` set to it, auto-login off, a random admin password written to gitignored `.tunnel-admin.txt`, and a mu-plugin trusting `X-Forwarded-Proto` so WP sees HTTPS. Tested: home 200 via tunnel with WP 7.1.4 / Woo 11.2.1 / Elementor 4.3.6 generator tags and zero `127.0.0.1` links; `/wp-admin/` → 302 to login; REST `url` is the tunnel; generated password logs in with a `secure` cookie; default `password` rejected.
- Playground scripts now pass `--wp=7.1 --php=8.3`: the CLI defaults `--wp` to `latest`, which ignores the blueprint's preference.
- Known limit: the quick-tunnel URL changes every run. Move to a named tunnel on our domain before Phase 3's OAuth client tests. On Windows, killing the npm parent can orphan `cloudflared`/`node`; stop with Ctrl+C.
- **Phase 0 complete.**

## 2026-11-04 — Phases 0–2 close-out audit

- Re-verified in a fresh PATH: PHP 8.3.36, Composer 2.11.1, cloudflared 2026.10.7, Node 24.18.0, 11 skills, Superpowers enabled.
- `01-research-report.md` §1–2: added dated corrections (machine facts and skill install method differed from the original report). Architecture title no longer says "proposal"; §14 notes that only the environment, not the plugin, is verified.
- Deferred on purpose, each tied to its phase: UNVERIFIED items (MCP client versions → Phase 3 E2E; Elementor Kit/atomic storage → Phase 5; SEO/Woo/ACF APIs → Phases 6–7; `update_plugins_{host}` signature → Phase 3; Tasks extension → later), emailed recovery link (§10, Phase 8 decision).

## 2026-11-04 — Phase 3 step 1: plugin skeleton, lifecycle, migrations

- Plan: `docs/plans/phase-3-01-skeleton.md`. Repo root is the plugin root (`kuduy-mcp.php`, `Update URI` header, `Requires PHP 8.1`, `Requires at least 6.9`).
- `Lifecycle\Migrations` (DB v1: the 5 tables from architecture §5 via `dbDelta`), run on activation and on `plugins_loaded` when the stored version is older (auto-update path). `Lifecycle\Uninstaller` drops tables + 3 options only with `KUDUY_MCP_DELETE_DATA_ON_UNINSTALL`.
- Dev tools (Composer, platform PHP 8.1): PHPUnit 10.5.66, PHPStan 2.3.5 (level 6) + phpstan-wordpress 2.0.4, WPCS 3.4.1. `composer test:static` clean.
- Integration tests run PHPUnit inside Playground WordPress (`npm run test:integration`, env `KUDUY_WP`/`KUDUY_PHP`); pass/fail comes from the JUnit report, versions actually used are printed. Red first (classes missing), then 4/4 green on WP 6.9.11/PHP 8.1.34 and WP 7.1.4/PHP 8.3.36, no PHP warnings.
- Gotchas found: (1) a blueprint's `preferredVersions` — or its defaults, latest PHP/WP — **override** `--wp`/`--php`; the wrapper writes the requested versions into a generated blueprint. (2) `composer.bat` under cmd/PowerShell eats `^` in constraints (`^10.5` became `10.5`); use quotes in Git Bash or `php composer.phar`. (3) PHPUnit's launcher has a shebang line, so the runner calls `PHPUnit\TextUI\Application` directly.
- CI: syntax lint on PHP 8.1–8.4, static job, integration matrix (WP 6.9/PHP 8.1, WP 7.1/PHP 8.3).
- Deferred: deactivation hook (no cron yet), unit test suite (no pure logic yet).
