# KUDUY MCP — Development Roadmap

Each phase ends with a verification gate. A phase is not done until its rows in `04-test-matrix.md` pass with recorded evidence.

| Phase | Scope | Exit gate |
|---|---|---|
| 0. Tooling | Install PHP 8.3 + Composer; Superpowers plugin; `wordpress/agent-skills` WP plugin-dev skill (reviewed first); Playground CLI; staging site or tunnel; GitHub Actions skeleton | `php -v`, `composer -V`, Playground boots WP 7.1 with Elementor + WooCommerce — **gate passed 2026-11-04**; Superpowers + staging still open |
| 1. Research | Done — `01-research-report.md` | Approved |
| 2. Architecture | Done — `02-architecture.md` | D1–D6 approved 2026-11-04 |
| 3. Foundation | Bootstrap, lifecycle + migrations, output guard, scoped adapter, tool registry + executor, OAuth AS (metadata, CIMD, DCR, PKCE, rotation, revoke), audit log, snapshots, rate limit, **GitHub updater + release workflow** | claude.ai, Desktop and Claude Code connect to staging, list tools, call `kuduy-site-info`; refresh + revoke work; update from a test release `v0.1.1` installs |
| 4. Core WP | Posts, pages, CPT, taxonomies, comments, meta, revisions, featured images, users/roles, media, menus, options (allow-list), plugins/themes (install/update/activate with safety checks), site health | Integration tests green on WP 6.9 + 7.1 |
| 5. Elementor | Read pass (documents, tree, schema discovery v3 + atomic) → write pass (patch edits per breakpoint, add/move/duplicate/remove, containers) → Kit globals, breakpoints, Theme Builder → frontend verifier → addon adapters | Build a full landing page from a prompt on Elementor 3.x and 4.3; frontend checks pass; responsive values untouched where not edited |
| 6. SEO | Resolver, Rank Math, Yoast, then AIOSEO, SEOPress; image SEO (alt/title/caption, usage scan, decorative flag, bulk via jobs, thumbnail regen) | Metadata round-trips visible in page `<head>`; dual-plugin conflict test refuses writes |
| 7. Woo + advanced | Products/variations/attributes/stock/prices, orders (status, notes), coupons, customers, reviews, shipping, tax (read + safe writes), reports; ACF, CPT UI, forms, blocks, site editor, widgets, cron, cache/perf diagnostics; developer mode (D4) | Integration tests on Woo HPOS on/off; secrets never returned |
| 8. Hidden mode | Hide UI, recovery CLI/constant | Same tool list and OAuth working with UI hidden; plugin row still listed |
| 9. Security + perf | Threat-model review, `security-review`, PHPStan level 6+, WPCS, fuzz tool inputs, load test `tools/list` and common calls | No high findings open |
| 10. Release | ZIP via workflow, install/connection/dev guides, tool reference (generated from schemas), compatibility doc, security doc, CHANGELOG, release checklist | Tagged `v1.0.0` installs and self-updates on a clean site |

Process rules: TDD per tool (failing test → implementation → green), small commits, `docs/DEVLOG.md` updated each session, no TODOs in released code.
