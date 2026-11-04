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
- Open: Superpowers plugin (user runs `/plugin`), public HTTPS staging site or tunnel (user decision).
