# KUDUY MCP — Roadmap

A phase is done only when its exit gate passes with evidence recorded in `TESTING.md` and its rows in `FEATURES.md` are `Tested`. Process: TDD per tool, small commits, docs updated in the same commit, no TODOs in released code.

Status on 2026-11-05: **Phase 3 code complete; claude.ai verified; Desktop, Claude Code's own sign-in, refresh and revoke still to observe (O11). Phase 4 done. Latest release v0.3.0 (all of Phase 4, 49 tools).**

| Phase | Scope | Exit gate | Status |
|---|---|---|---|
| 0. Tooling | PHP 8.3 + Composer, skills (reviewed), Superpowers, Playground CLI, public HTTPS test site, CI skeleton | `php -v`, `composer -V`; Playground boots WP 7.1 with Elementor + WooCommerce | **Done** 2026-11-04 |
| 1. Research | References, licenses, MCP/Claude/WordPress/Elementor docs → `RESEARCH.md` | Approved by user | **Done** 2026-11-04; re-inspected 2026-11-05 |
| 2. Architecture | `ARCHITECTURE.md`: components, folders, schema, registry, OAuth flow, permissions, adapters, compatibility, test strategy | D1–D6 approved | **Done** 2026-11-04 (D1, D2 changed in Phase 3 by user decision) |
| 3. Foundation | Bootstrap, lifecycle + migrations, output guard, dual-era MCP layer, tool registry + executor, OAuth AS (metadata, CIMD, DCR, PKCE, rotation, revoke), audit log, redaction, snapshots, rate limit, cleanup, connections page, GitHub updater + release workflow | claude.ai, Desktop and Claude Code connect, list tools and call `kuduy-site-info`; refresh + revoke work with real clients; an update from a GitHub release installs | **Code complete; waiting on O11** — everything built and tested, including HTTPS enforcement, output schemas and the early-auth fix. The real-client check needs the user. Releases v0.1.0–v0.1.2 published; real update v0.1.0 → v0.1.1 passed. |
| 4. WordPress core | Posts, pages, CPT, taxonomies, comments, meta, revisions, featured images, users/roles, media, menus, options (allow-list), plugins/themes (install/update/activate with safety checks), site health, cron, rewrite/permalink diagnostics | Integration tests green on WP 6.9 + 7.1; permission-boundary tests (P1–P4) | **Done** 2026-11-05 — 45 core tools: content, terms, comments, revisions, custom fields, users/roles, menus, settings allow-list, plugins/themes (confirm-first), Site Health/cron/rewrite/hooks diagnostics; undo for every overwrite where possible; P1–P4 pass; 125/125 on WP 6.9 + 7.1. Moved on purpose: block-theme navigation and widgets (Phase 7 with Site Editor), theme file inspection (Phase 7 developer mode), media (Phase 6 image SEO). Not offered by design: permanent deletes of posts, users, plugins, themes |
| 5. Elementor | Read (documents, tree, schema discovery v3 + atomic) → write (patch edits per breakpoint, add/move/duplicate/remove, containers) → Kit globals, breakpoints, Theme Builder → frontend verifier → add-on adapters | A full landing page built from a prompt on Elementor 3.x and 4.3; frontend checks pass; untouched responsive values preserved | **In progress** — research done (`RESEARCH.md` §8), plan `docs/plans/phase-5-elementor.md`; D7 decided (hybrid); read and write passes done on Elementor 4.3.6 (`kuduy-elementor-documents`, `-get`, `-widgets`, `-widget-schema`, `-kit`, `-edit`, `-create`, `-kit-update`, `-verify`; EL1–EL7 pass). Next: Elementor 3.x LTS run, v4 classes/variables, Theme Builder, add-on adapters, EL10 end-to-end |
| 6. SEO + media | Resolver, Rank Math, Yoast, then AIOSEO, SEOPress; image SEO; background jobs | Metadata round-trips visible in `<head>`; dual-plugin conflict refuses writes | Not started |
| 7. WooCommerce + integrations | Products, variations, orders, coupons, customers, reviews, shipping, tax (read + safe writes), reports; ACF, CPT, forms, blocks, Site Editor, widgets, cron, cache, WPML/Polylang; developer mode (D4) | Tests with HPOS on/off; secrets never returned; dev-mode limits enforced | Not started |
| 8. Hidden UI | Hide UI, recovery via option / WP-CLI / constant | Same tools and OAuth with UI hidden; plugin row still listed; recovery works | Not started |
| 9. Security + performance | Threat-model review, security review, fuzzing tool inputs, load test `tools/list` and common calls, recovery tests | No high findings open | Not started (a foundation audit already ran 2026-11-05 — see `SECURITY.md`) |
| 10. Release | Production ZIP, installation guide, Claude connection guide, developer docs, MCP tool reference (generated from schemas), compatibility and security docs, changelog, release checklist | `v1.0.0` installs and self-updates on a clean site | Not started (release pipeline already exists) |

## Phase 3 — what is left

1. ~~claude.ai~~ — **done 2026-11-05** (tools listed and called on 0.1.2 and 0.2.0; update 0.1.2 → 0.2.0 kept the connection).
2. Claude Desktop: open a chat, enable the KUDUY connector, ask for `kuduy-site-info`.
3. Claude Code's own sign-in: `claude mcp add --transport http kuduy-direct https://<site>/wp-json/kuduy-mcp/v1/mcp`, then `/mcp` → Authenticate (exercises CIMD + loopback).
4. Revoke: Tools > KUDUY MCP → Revoke the claude.ai connection; the next Claude call must fail and ask to sign in again. Refresh is covered by any connection that keeps working after one hour.
5. Record O11, then close Phase 3.

Observed 2026-11-05: the normal WordPress update on Hostinger (0.1.2 → 0.2.0) kept the plugin active.
