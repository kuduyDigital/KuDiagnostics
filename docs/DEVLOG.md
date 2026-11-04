# KUDUY MCP — Development Log

## 2026-11-04 — Phase 1 research, Phase 2 proposal

- Repo initialised and pushed to `github.com/kuduyDigital/KuDiagnostics` (public), branch `main`.
- Studied WSP (GPL-2.0, v2.9.7) and Novamira (AGPL-3.0) from clones; no code copied.
- Verified current MCP spec (2026-07-28, stateless, CIMD preferred over DCR), Claude connector auth rules, WordPress 7.1.4 + core Abilities API, `wordpress/mcp-adapter` 0.7.1 (dual-era), Elementor 4.3.0 atomic element schemas.
- Environment gaps: no PHP, Composer, WP-CLI or Docker. Node present.
- New requirement added: self-update from GitHub releases (architecture §11).
- Decisions D1–D6 in `02-architecture.md` approved by the user.
- Next: Phase 0 tooling (PHP 8.3, Composer, Playground CLI, staging site), then Phase 3 foundation.
