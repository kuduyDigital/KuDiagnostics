# KUDUY MCP — Roadmap

A phase is done only when its exit gate passes with evidence recorded in `TESTING.md` and its rows in `FEATURES.md` are `Tested`. Process: TDD per tool, small commits, docs updated in the same commit, no TODOs in released code.

Status on 2026-11-05: **Phase 3 code complete; only the real-client check (O11) is left. Phase 4 started. Latest release v0.1.2; `main` has unreleased fixes and the first Phase 4 tools.**

| Phase | Scope | Exit gate | Status |
|---|---|---|---|
| 0. Tooling | PHP 8.3 + Composer, skills (reviewed), Superpowers, Playground CLI, public HTTPS test site, CI skeleton | `php -v`, `composer -V`; Playground boots WP 7.1 with Elementor + WooCommerce | **Done** 2026-11-04 |
| 1. Research | References, licenses, MCP/Claude/WordPress/Elementor docs → `RESEARCH.md` | Approved by user | **Done** 2026-11-04; re-inspected 2026-11-05 |
| 2. Architecture | `ARCHITECTURE.md`: components, folders, schema, registry, OAuth flow, permissions, adapters, compatibility, test strategy | D1–D6 approved | **Done** 2026-11-04 (D1, D2 changed in Phase 3 by user decision) |
| 3. Foundation | Bootstrap, lifecycle + migrations, output guard, dual-era MCP layer, tool registry + executor, OAuth AS (metadata, CIMD, DCR, PKCE, rotation, revoke), audit log, redaction, snapshots, rate limit, cleanup, connections page, GitHub updater + release workflow | claude.ai, Desktop and Claude Code connect, list tools and call `kuduy-site-info`; refresh + revoke work with real clients; an update from a GitHub release installs | **Code complete; waiting on O11** — everything built and tested, including HTTPS enforcement, output schemas and the early-auth fix. The real-client check needs the user. Releases v0.1.0–v0.1.2 published; real update v0.1.0 → v0.1.1 passed. |
| 4. WordPress core | Posts, pages, CPT, taxonomies, comments, meta, revisions, featured images, users/roles, media, menus, options (allow-list), plugins/themes (install/update/activate with safety checks), site health, cron, rewrite/permalink diagnostics | Integration tests green on WP 6.9 + 7.1; permission-boundary tests (P1–P4) | **In progress** — groundwork done (object capabilities, audit target, output schemas, paging); read tools `kuduy-post-types`, `kuduy-post-list`, `kuduy-post-get`, `kuduy-term-list` tested. Next: content writes with snapshots, users, menus, plugins/themes, diagnostics |
| 5. Elementor | Read (documents, tree, schema discovery v3 + atomic) → write (patch edits per breakpoint, add/move/duplicate/remove, containers) → Kit globals, breakpoints, Theme Builder → frontend verifier → add-on adapters | A full landing page built from a prompt on Elementor 3.x and 4.3; frontend checks pass; untouched responsive values preserved | Not started |
| 6. SEO + media | Resolver, Rank Math, Yoast, then AIOSEO, SEOPress; image SEO; background jobs | Metadata round-trips visible in `<head>`; dual-plugin conflict refuses writes | Not started |
| 7. WooCommerce + integrations | Products, variations, orders, coupons, customers, reviews, shipping, tax (read + safe writes), reports; ACF, CPT, forms, blocks, Site Editor, widgets, cron, cache, WPML/Polylang; developer mode (D4) | Tests with HPOS on/off; secrets never returned; dev-mode limits enforced | Not started |
| 8. Hidden UI | Hide UI, recovery via option / WP-CLI / constant | Same tools and OAuth with UI hidden; plugin row still listed; recovery works | Not started |
| 9. Security + performance | Threat-model review, security review, fuzzing tool inputs, load test `tools/list` and common calls, recovery tests | No high findings open | Not started (a foundation audit already ran 2026-11-05 — see `SECURITY.md`) |
| 10. Release | Production ZIP, installation guide, Claude connection guide, developer docs, MCP tool reference (generated from schemas), compatibility and security docs, changelog, release checklist | `v1.0.0` installs and self-updates on a clean site | Not started (release pipeline already exists) |

## Phase 3 — what is left

1. Connect claude.ai, Claude Desktop and Claude Code to the staging site running **v0.1.2** (choose "Sign in now" if the dialog does not detect it), sign in, call `kuduy-site-info`.
2. Confirm on the server: audit row per client, connection listed under Tools > KUDUY MCP, revoke ends it, refresh works after one hour.
3. Record O11 in `TESTING.md` and `FEATURES.md`, then close Phase 3.

Optional, not blocking: observe whether updating from the Plugins screen and by auto-update keeps the plugin active (the programmatic upgrade in `check:update` left it inactive).
