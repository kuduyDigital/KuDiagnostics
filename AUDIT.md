# KuDiagnostics — audit of the existing MCP (2026-11-10, v1.1.0)

Scope: what the plugin does today, measured against the "Novamira Pro-level" upgrade brief. Source: the code in `src/`, the generated tool reference (`docs/TOOLS.md`), and the test evidence in `TESTING.md`. Nothing here is counted as supported because its name appears in code; "tested" means a passing test or recorded manual evidence.

Companion files: `FEATURE-GAP.md` (what is missing), `INTEGRATION-MATRIX.md` (one row per target integration), `IMPLEMENTATION-PLAN.md` (order of work).

## 1. Architecture as it stands

| Part | Where | State |
|---|---|---|
| Entry point | `kuduy-mcp.php` → `Plugin::boot()` | Native PHP WordPress plugin, PHP 8.1+, WordPress 6.9+, no runtime dependencies |
| MCP transport | `Mcp/RestController`, `Mcp/Server` | Streamable HTTP on `/wp-json/kuduy-mcp/v1/mcp`; MCP 2026-07-28 (stateless) and 2025-11-25 (legacy initialize). Only `tools/*` is implemented: no prompts, no resources |
| Tool contract | `Mcp/Tool`, `Mcp/ToolRegistry`, `Mcp/Executor` | One class per tool family with modes; each tool declares name, schemas, annotations, scope, capability, availability. Tools of inactive plugins are not listed to the client |
| Authentication | `OAuth/*` | Own OAuth 2.1 authorization server (metadata, CIMD, DCR, PKCE, rotation, revoke). Every call runs as the signed-in WordPress user |
| Permissions | Executor | OAuth scope (`kuduy:read` ⊂ `write` ⊂ `admin`, `kuduy:code` separate) + WordPress capability per object + per-tool safety rules (confirm-first, refusals) |
| Undo | `Execution/Snapshots`, `Snapshottable` | Snapshot before every destructive tool; `kuduy-snapshot-list` / `-restore` |
| Audit | `Audit/AuditLog`, `Redactor` | Every call logged; secrets and personal data masked in results and log rows |
| Background jobs | `Jobs/*` | `kuduy-job-start` / `-status` / `-cancel` over an allow-list of tools; one item at a time through WP-Cron |
| Storage | `Lifecycle/Migrations` | Six tables: OAuth clients, codes, tokens, audit log, snapshots, jobs |
| Hidden admin mode | `Admin/HiddenMode` | Presentation only |
| Updates | `Update/GitHubUpdater` | GitHub Releases with checksum |
| Tests | `tests/integration` (Playground), `scripts/smoke-mcp.mjs` | 28 integration jobs in CI across WordPress 6.9 / 7.1 and plugin sets, plus smoke and release-ZIP jobs; PHPCS + PHPStan level 6 |

There is **no central integration registry**: each module answers "is my plugin active?" on its own (`is_available()`), and some areas have a status tool (`kuduy-seo-status`, `kuduy-woo-status`, `kuduy-forms-status`, `kuduy-acf-status`, `kuduy-cache-status`, `kuduy-lang-status`).

## 2. Tool inventory (181 tools, 14 areas)

| Area | Tools | What they do | Evidence |
|---|---|---|---|
| Core | 49 | Site info, posts/pages/custom types (list, get, create, update, trash), terms, comments, revisions, post meta, users and roles, classic menus, settings (allow-list), plugins and themes (list, install, activate, update, switch), site health, cron, rewrite rules, hooks, snapshots, update check | Tested (C1–C8) |
| Elementor | 14 | Documents, tree, widget discovery and schema (v3 controls, v4 atomic props), edit (add/update/move/duplicate/remove, 1–50 operations, per-breakpoint), create, Kit colours and fonts, v4 global classes and variables, Theme Builder templates and conditions (Pro), frontend verify | Tested on 3.35.9 and 4.3.6; Pro 4.3.2 locally; claude.ai on staging |
| WPBakery | 16 | Tree, structure, content, element discovery, validate, create, add row/column/element, update element/row/column, move, duplicate, delete, replace | Tested against a stand-in registry; **not verified against the plugin** |
| Blocks / Site Editor | 15 | Block types, blocks of a post (read), site templates (list, get, update, revert), Global Styles (get, update), classic widgets, block navigation menus | Tested (S1–S5) |
| SEO | 12 | Status, get/update meta for Rank Math, Yoast, AIOSEO, SEOPress; verify rendered head; audit for missing fields; Rank Math redirects and schema; sitemap status | Tested, all four plugins on both WordPress pairs |
| Media | 8 | List, get (image shown to the model), update, usage scan, upload from URL, regenerate, replace, rename | Tested |
| WooCommerce | 33 | Status, products, variations, stock, orders (status, notes), coupons, customers, reviews, shipping and tax (read + writes), payment gateways (read, secrets hidden), reports | Tested, HPOS on and off |
| ACF | 4 | Status, field groups (read), values get/update on posts, users, terms | Tested with ACF 6.8.11 free |
| Forms | 9 | Status, forms list/get, entries list/get/update/note, CF7 save and mail | Tested: CF7 + Flamingo, Fluent Forms, WPForms Lite (forms only) |
| Multilingual | 5 | Polylang: status, get, set, link, translate | Tested with Polylang 3.9.2 |
| Cache | 2 | Status and purge for LiteSpeed, W3TC, WP Super Cache, WP Fastest Cache, WP Rocket | Tested (WP Rocket from docs only) |
| Custom content types | 3 | List, save, remove custom post types and taxonomies | Tested |
| Developer | 8 | Files (list, read, search), debug log, DB schema, read-only DB query; file write and PHP only behind `KUDUY_MCP_ALLOW_CODE_EXEC` + `kuduy:code` | Tested, incl. fuzz |
| Jobs | 3 | Start, status, cancel | Tested |

## 3. Cross-cutting capabilities against the brief

| Brief section | Today | Class |
|---|---|---|
| §4 Integration registry | Per-module availability and status tools; no single catalogue | Partially supported |
| §5 Cross-session memory | Nothing | Not supported |
| §6 Design intelligence | Elementor Kit, v4 variables/classes, Global Styles readable and writable; no scan, no token store, no consistency audit | Partially supported |
| §7 Markdown skills | Nothing; MCP prompts/resources not implemented | Not supported |
| §8 Read mode | A connection granted only `kuduy:read` cannot change anything (enforced server-side); no site-wide switch | Partially supported |
| §8 Ghost mode | Confirm-first on risky tools (emails, deactivation, theme switch); no staging of changes | Partially supported |
| §9 Core | Covered, except: duplicate a post, permanent delete (deliberately absent), delete media, delete users (deliberately absent) | Fully supported with stated exceptions |
| §10 Database | Schema and read-only queries with masking; no writes (rule 6) | Partially supported by design |
| §10 Filesystem | List, read, search; write only behind the code-execution constant; uploads through media | Partially supported by design |
| §11 Background jobs | Start, status (progress, per-item results), cancel; no retry, no site-wide scans | Partially supported |
| §12 Backup and rollback | Snapshots with restore; audit log; WordPress revisions; no before/after comparison | Partially supported |
| §13 Token optimization | Inactive integrations unlisted; paging and filters on lists; outline vs full tree for builders; no measurements of list size or tokens | Partially supported |
| §14 Security | OAuth 2.1, scopes, capabilities per object, redaction, rate limit, audit, review with no high findings open (`SECURITY.md`) | Fully supported |
| §15 Lightweight | No runtime dependencies; every tool object is constructed on each request (cheap, but all 181) | Fully supported |
| §21 "Abilities hub" | Uses other plugins' abilities internally (Elementor v4, AIOSEO, SEOPress); does not expose registered abilities generally | Not supported |

## 4. Things in the working tree that are not released

Gravity Forms and WPForms Pro entry adapters, started 2026-11-10 from the plugins' documented APIs, with a stand-in for Gravity Forms. Not committed, not tested end to end. They are scheduled in `IMPLEMENTATION-PLAN.md` phase B.

## 5. Constraints the upgrade has to respect

These come from the project's own rules (`CLAUDE.md`) and are not negotiable inside this work:

1. Every tool runs as the WordPress user; no shared keys.
2. Official plugin APIs only; no guessed meta keys or raw serialized writes.
3. No SQL writes, shell or eval by default; code execution only behind `KUDUY_MCP_ALLOW_CODE_EXEC` + `kuduy:code`.
4. Nothing is marked Tested without a passing test or recorded evidence; client and plugin support is not claimed before it is verified.
5. Novamira is AGPL-3.0: ideas only, no code copied. The target list is used as a feature checklist.
6. PHP 8.1+ stays the minimum (the brief says 8.0+; the code uses 8.1 language features, and PHP 8.0 is out of support).
