# KUDUY MCP — Research

First research pass: 2026-11-04 (Phase 1). Re-inspected: 2026-11-05 (reference repos re-cloned, licenses re-read, gaps from the brief filled). Every statement below was checked against a primary source — a cloned repository at the named commit, an official spec or documentation page, or upstream source. Anything not yet checked is marked **UNVERIFIED** with the phase that must check it.

## 1. Development environment

| Item | Found (2026-11-05) | Notes |
|---|---|---|
| OS | Windows 10 Pro 19045 | Scripts work in Git Bash and PowerShell |
| Project | `D:\NewIdea\KuDiagnostics`, git, public repo `kuduyDigital/KuDiagnostics` | Repo root = plugin root |
| PHP | 8.3.36 (winget, user scope) | Not on the Bash PATH by default — see `CLAUDE.md` |
| Composer | 2.11.1 (official installer, signature checked) | `composer.bat` strips `^` under cmd; use `php composer.phar` or quotes |
| Node / npm | 24.18.0 / 11.7.0 | Runs WordPress Playground CLI 3.1.62 (WASM PHP + WordPress, no Docker) |
| WP-CLI, Docker | not installed | WP-CLI first needed for Phase 8 recovery tests |
| GitHub CLI | not installed | Public GitHub REST via `curl` is enough |
| cloudflared | 2026.10.7 | Quick tunnel for a public HTTPS test site (`npm run tunnel`) |

A real Claude connection needs a public HTTPS URL: claude.ai connects from Anthropic's servers (egress range `160.79.104.0/21`, per Claude connector docs), not from this machine. The user's own staging site is on Hostinger (`*.hostingersite.com`, LiteSpeed behind Hostinger's CDN `Server: hcdn`).

## 2. Claude Code skills and tooling

| Skill / tool | Source | Review result | State |
|---|---|---|---|
| caveman, ultracave, megacave | `JuliusBrussee/caveman` | Markdown only, no scripts or network calls | Installed (`~/.claude/skills`) |
| ponytail, ponytail-audit, -review, -debt, -gain, -help | `DietrichGebert/ponytail` @ HEAD | Markdown only; no network calls. The brief's command `npx skills add DietrichGebert/ponytail --skill caveman` names a skill that repo does not contain | Installed |
| Superpowers 7.0.2 | `obra/superpowers-marketplace` | TDD, systematic debugging, planning, code review, verification workflows; read before install | Installed and enabled (user scope) |
| wp-plugin-development, wp-project-triage | `WordPress/agent-skills` @ `eff3785` (the official source WSP's `skills-lock.json` points to) | Markdown plus read-only filesystem scanners, no network | Installed |
| Context7 | — | Not available in this environment | Fallback: official docs and upstream source, read directly |
| PHPCS + WPCS 3.4.1, PHPStan 2.3.5 + phpstan-wordpress, PHPUnit 10.5.66 | Composer dev dependencies | — | Installed, used in CI |

Rejected: copying the `wp-plugin-development` skill from the WSP repo (the official source was used instead).

## 3. Reference A — WSP WordPress MCP

Repository `bilalnaseer/wsp-wordpress-mcp`, commit `20a9582` (2026-10-29), plugin "WSP MCP - Free MCP Plugin for WordPress" v2.9.7, requires WordPress 6.9 / PHP 7.4. **License: GPL-2.0** (`LICENSE`, re-read 2026-11-05). About 19.5k lines of procedural PHP in `wsp-mcp-ai-agents-connector/includes/`.

| Area | Where | Finding |
|---|---|---|
| Docs | `README.md`, `AGENTS.md`, `CHANGELOG.md`, `HISTORY.md` | `AGENTS.md` is the agents' source of truth and must be updated with every change; `HISTORY.md` records why a native MCP server was built. Only `docs/TESTING-woo-plugin-tools.md` exists for testing — **no automated test suite**. |
| Bootstrap and layout | `includes/{abilities,admin,audit,seo,server,tools}/`, `registry.php`, `dependency.php` | One file per tool family; `tools/native-tools.php` is 2,361 lines. |
| MCP transport | `server/class-mcp-server.php`, `class-session-store.php` | Hand-written JSON-RPC on one REST route, `permission_callback` `__return_true`, auth inside the handler. Protocol 2024-11-05 → 2025-11-25, session-based. **No 2026-07-28** (`server/discover`). |
| Output safety | `response-guard.php` | Early output buffer on its own routes so stray notices can't corrupt JSON — good idea; KUDUY has its own `Http\OutputGuard`. |
| Auth | `server/class-auth.php`, `class-oauth-server.php`, `class-oauth-store.php` | Own OAuth AS (RFC 9728/8414/7591, PKCE S256, single-use codes) plus a **static API key that maps to the lowest-ID administrator** (`assume_admin()`), which breaks per-user authorization. Serves metadata at non-`.well-known` aliases for hosts that 404 `/.well-known/*`. |
| Permission guard | `abilities/guard.php` | `wsp_mcp_guard_edit_post()` per-object checks for posts. |
| Validation | across abilities | `sanitize_*` calls in 34 files; JSON-Schema validation (`rest_validate_value_from_schema`) is not used. |
| Audit | `audit/class-audit-log.php` | Own table: tool, status, message, duration, category, client IP, rejection counters, analytics page. |
| Core tools | `abilities/{posts,pages,cpt,taxonomy,comments,post-meta,revisions,users,menus,widgets,site,site-editor,blocks,health,diagnostics,search}.php` | Broad core coverage. |
| Plugins / themes | `abilities/{plugins,themes,theme-files,theme-upload}.php` | `Plugin_Upgrader`, `Theme_Upgrader`, `activate_plugin`, `switch_theme`. Theme file writes via `WP_Filesystem` / chunked `file_put_contents`. |
| Elementor | `abilities/elementor.php` (1,032 lines), `uae.php` | Writes `_elementor_data` with `update_post_meta` and clears **all** CSS — bypasses `Document::save()` (no revision, hooks, kses or per-post CSS). No v4 atomic support. |
| SEO | `abilities/{rankmath,yoast,redirects}.php`, `seo/class-redirects.php` | Rank Math: direct `update_post_meta` on 3 keys (`rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`). Yoast: `WPSEO_Meta::set_value()` with a raw `_yoast_wpseo_*` fallback; no guard when both SEO plugins are active. Own redirects store. |
| WooCommerce | `abilities/woocommerce*.php` | Uses CRUD objects (`wc_get_product`, `wc_get_order`, `WC_Product_Simple/Variable/Variation`, `WC_Coupon`, `WC_Shipping_Zone`). |
| ACF / forms | `abilities/{acf,cf7,wpforms,gravityforms}.php` | Present. |
| Media | `abilities/media.php` | Alt text via `_wp_attachment_image_alt`; sideload with `download_url()` (which uses the safe HTTP API) + `media_handle_sideload()`. |

**Lessons taken (as ideas, no code):** output guard, `.well-known` fallbacks, per-object guard. **Avoided:** shared admin key, raw Elementor meta writes, guessed SEO keys without a conflict check, no test suite.

## 4. Reference B — Novamira

Repository `use-novamira/novamira`, commit `9277ff3` (2026-11-04; first inspected at `aade6f5`), plugin v1.12.14, requires WordPress 6.9 / PHP 8.0. **License: AGPL-3.0** (`LICENSE`, re-read 2026-11-05). Website novamira.ai.

| Area | Where | Finding |
|---|---|---|
| Docs | `README.md`, `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md` | `CLAUDE.md` states the goal plainly: "unrestricted control over a WordPress installation" through arbitrary PHP and full filesystem access. Quality gate: Mago format/lint/analyze. |
| Dependencies | `composer.json` | `wordpress/mcp-adapter` 0.6.1 (scoped by `bin/scope-mcp-adapter.php` on install), `league/oauth2-server` ^8.5, `nyholm/psr7`, Jetpack autoloader. |
| Transport | core Abilities API + bundled MCP adapter | Abilities registered with `permission_callback => novamira_permission_callback` = plugin enabled **and** user can manage (`includes/helpers.php`). |
| PHP execution | `includes/abilities/execute-php.php` | `eval()` with `set_time_limit` and output buffering; refuses in "read mode". |
| WP-CLI | `includes/abilities/run-wp-cli.php` | `proc_open` with an argv array (no shell string), `--allow-root` when root, background jobs via `nohup sh -c … &` with log and status files. |
| Filesystem | `includes/abilities/{read,write,edit,delete,list-directory,enable,disable}-file.php` | `realpath` checks; delete refuses ABSPATH root, `wp-admin`, `wp-includes`, `mu-plugins`. `read-file.php` has **no** guard for `wp-config.php` or other secrets. |
| Database | — | No dedicated DB tool; done through `execute-php`. |
| Elementor | — | No dedicated tools; done through arbitrary PHP. |
| Recovery | sandbox loader | AI-written PHP lives in `wp-content/novamira-sandbox/`; a fatal writes a crash marker and the next request boots in safe mode. Sound pattern; KUDUY will design its own (D4). |
| Read mode | `includes/read-mode/` (`PermissionGuard`, `RequestRouter`, `policy`, `trust`) | Wraps ability callbacks to refuse writes while read mode is on. |
| Ghost mode | `includes/ghost-mode/` | Hides admin UI, WP-CLI controls, bypass constant. Filters the plugin list — **KUDUY must keep its plugin row visible.** |
| Tests | `tests/{integration,oauth,fixtures,design-smoke}` | 76 `*Test.php` files. |

**Lessons taken (as ideas):** crash marker + safe mode, read-mode policy wrapper, scoping a bundled adapter. **Rejected:** unrestricted eval/shell/filesystem as default features, unguarded secret reads, hiding the plugin row. Novamira's code is AGPL: none of it may be copied or adapted into KUDUY (GPL-2.0).

## 5. MCP protocol (modelcontextprotocol.io, schema files `schema/2026-07-28/schema.ts`, `schema/2025-11-25/schema.ts`)

- Latest revision **2026-07-28**: stateless — no `initialize`, no `Mcp-Session-Id`, no GET stream. Every request carries `_meta` `io.modelcontextprotocol/protocolVersion` (+ `clientInfo`, `clientCapabilities`). Servers MUST implement `server/discover`.
- Streamable HTTP: POST to one endpoint; JSON or request-scoped SSE reply; GET/DELETE → 405 for modern-only; notifications → 202 with no body.
- Headers `MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name` (for `tools/call`, `resources/read`, `prompts/get`; `=?base64?…?=` for non-ASCII). Mismatch → 400 `-32020`; unsupported version → 400 `-32022` with `supported`; unknown method → 404 `-32601`. Origin MUST be validated (403).
- Dual-era servers MAY also serve 2025-11-25 clients, which start with `initialize`. Legacy clients read 404 as "session expired", so legacy method errors stay HTTP 200.
- Authorization: RFC 9728 protected-resource metadata (MUST), RFC 8414 or OIDC discovery (MUST), **CIMD preferred, DCR deprecated**, RFC 8707 `resource` + audience check, RFC 9207 `iss`, 401 for missing/invalid token, 403 `insufficient_scope` with a `scope` hint, tokens never in query strings.

## 6. Claude clients (claude.com connector authentication docs, support article "Get started with custom connectors using remote MCP")

- One auth stack for claude.ai, Desktop, mobile, Claude Code, Cowork. Supported modes: OAuth with DCR, OAuth with CIMD, none, static headers (org-wide credential — unsuitable as the primary path because it is not per user).
- CIMD is used only when AS metadata has `client_id_metadata_document_supported: true` **and** `none` in `token_endpoint_auth_methods_supported`.
- Sign-in starts only on **401** with `WWW-Authenticate: Bearer resource_metadata="…"`; `resource` must equal the MCP URL exactly; only the first `authorization_servers` entry is used.
- Redirect URIs: `https://claude.ai/api/mcp/auth_callback`; Claude Code uses port-agnostic loopback `http://localhost/callback` / `http://127.0.0.1/callback` and its CIMD at `https://claude.ai/oauth/claude-code-client-metadata`.
- Token endpoint form-encoded; `invalid_grant` for dead refresh tokens; rotate refresh tokens for public clients; time limits 10 s (discovery, registration, token) and 30 s (refresh). `offline_access` is requested when listed in AS `scopes_supported`.
- **Observed 2026-11-05 on the Hostinger site (v0.1.1):** the "Add custom connector" dialog pre-selected **"No sign-in · Detected"** because `initialize` answered 200 without a token; with that mode kept, Claude reported "Couldn't reach". Fixed in 0.1.2 (unauthenticated `initialize` → 401 + challenge). Re-check on the real client is pending (FEATURES O11).
- A plugin cannot add itself to a Claude account: the user adds the connector and completes sign-in in Claude.

## 7. WordPress platform (developer.wordpress.org, core source 7.1.4)

- Current WordPress 7.1.4; Abilities API in core since 6.9.
- `determine_current_user` maps a bearer token to a user, so every capability check runs as that user.
- REST routing: `WP::parse_request()` takes `rest_route` from `$_POST`, then `$_GET`, before the rewrite rule — a query or body `rest_route` overrides the pretty-permalink path (this caused the 0.1.1 token-route bug; see `SECURITY.md`).
- Updates: `wp-includes/update.php:555` `apply_filters( "update_plugins_{$hostname}", false, $plugin_data, $plugin_file, $locales )`; `wp_update_plugins()` returns at line 488 before the Update URI loop when the wordpress.org check fails. The Updates screen's "Check again" (`update-core.php?force-check=1`) runs `wp_update_plugins()` on `load-update-core.php` with a 1-minute timeout.
- `wordpress/mcp-adapter` 0.7.x deprecates bundling ("will be removed in a future version") → decision D1 changed (own layer).

## 8. Elementor (source of Elementor **4.3.6**, wordpress.org ZIP, read 2026-11-05 for Phase 5)

Free Elementor only; Elementor Pro (Theme Builder, custom CSS) is a paid plugin whose source was not available, so Pro behaviour stays **UNVERIFIED**.

**Saving a document** — `core/base/document.php`:
- `Plugin::$instance->documents->get( $id )->save( [ 'elements' => …, 'settings' => … ] )`: applies the `elementor/document/save/data` filter, refuses unless `is_editable_by_current_user()`, fires `elementor/document/before_save`, runs `Utils::kses_post_deep()` for users without `unfiltered_html`, saves settings, then elements, template type and `_elementor_version`, **deletes** the post's CSS file (`Post_CSS::create( $id )->delete()`; it is regenerated on the next front-end view) and the document cache, then fires `elementor/document/after_save`. Returns `false` when not allowed.
- `save_elements()` stores `_elementor_data` as slashed JSON through `update_metadata()`, saves a plain-text copy into `post_content` (`db->save_plain_text()`), fires `elementor/db/before_save` and `elementor/editor/after_save`.
- **Silent data loss risk:** `get_elements_raw_data()` builds each element through `elements_manager->create_element_instance()`; an element whose type is not registered (an add-on that is inactive, a removed widget) returns `null` and is **skipped**. Saving such a document through `save()` drops those elements. KUDUY must refuse to save when the tree contains unregistered element types.
- Element ids: `Utils::generate_random_string()` = `dechex( rand() )` (short hex, not guaranteed unique). KUDUY must check uniqueness inside the document when it creates ids.
- `_elementor_edit_mode = builder` marks a post as built with Elementor (`set_is_built_with_elementor()`).
- Element `settings` must be a PHP **array** when saving: `Controls_Stack::sanitize_settings()` is typed `array` and throws a `TypeError` for an empty object (found in tests on 4.3.6).
- Page settings are saved whole, not merged (`save_settings_to_db()`); `Kit::save()` runs each tab's hooks and then clears **all** CSS files (`files_manager->clear_cache()`).
- `documents->get( $id )` caches per request; read with `get( $id, false )` after a save in the same request.

**Classic (v3) widgets** — `includes/base/controls-stack.php`:
- Widget schema: `get_controls()` per widget type (`widgets_manager->get_widget_types( $name )`).
- Responsive settings are stored as `{control}` (desktop) and `{control}_{device}` (`tablet`, `mobile`, and with custom breakpoints `mobile_extra`, `tablet_extra`, `laptop`, `widescreen`; keys from `breakpoints->get_active_devices_list()`).
- With the `additional_custom_breakpoints` experiment active (default in current releases), the server registers **one** control with `is_responsive = true`; the per-device copies exist only in the editor's JavaScript. Schema discovery must expand responsive controls itself.
- **Style controls are kept apart** (found in tests on 4.3.6): controls with CSS `selectors` (e.g. heading `align`) live in the stack's `style_controls`, and `get_controls()` merges them only when `Performance::is_use_style_controls()` is on (in the editor, not in REST requests). Read `get_stack()['controls'] + ['style_controls']` for the full list.

**Atomic (v4) elements** — `modules/atomic-widgets/`:
- Elements: `div-block`, `flexbox`, `grid`, `atomic-heading`, `atomic-paragraph`, `atomic-button`, `atomic-image`, `atomic-svg`, `atomic-divider`, `atomic-tabs`, `atomic-accordion`, `atomic-form`, `atomic-list`, `atomic-youtube`, `atomic-self-hosted-video`, `atomic-background-video`, `atomic-collection-loop`.
- Each declares `define_props_schema()` with typed prop types (e.g. heading: `classes`, `tag` enum h1–h6, `title`, `link`, `attributes`). Values use the envelope `{ "$$type": "<key>", "value": … }`; prop types export JSON Schema (`to_json_schema()`) and have `validate()` / `sanitize()`.
- Global classes: own post type with meta `_elementor_global_class_data` (`modules/global-classes/`). Global variables: Kit meta `_elementor_global_variables` via a repository (`modules/variables/storage/`).

**Kit (site settings)** — `core/kits/`: active Kit id in option `elementor_active_kit`; `kits_manager->get_active_kit()`; global colors in Kit settings `system_colors` / `custom_colors`, global fonts in `system_typography` / `custom_typography` (repeaters); breakpoints under `viewport_*` settings.

**Elementor's own MCP (new finding)** — `modules/mcp/`:
- Elementor 4.3 bundles `wordpress/mcp-adapter` and an `elementor-mcp-composer` package and defines 27 abilities (`elementor/get-page-structure`, `elementor/manage-elements`, `elementor/build-composition`, `elementor/get-widget-schema`, `elementor/list-widget-schemas`, `elementor/manage-classes`, `elementor/manage-global-variable`, `elementor/create-page`, `elementor/publish-document`, `elementor/update-page-settings`, …).
- They are registered with the Abilities API only when the option `elementor_mcp_enabled` is true (**off by default**). The module builds its ability registry in any case (`Module::build_core_registry()`, `Ability_Registry::find_by_id()`); abilities check `edit_posts` and `edit_post` per document; settings are saved through `document->save()`.
- Coverage: built for **v4 atomic** elements. Classic v3 widgets are editable only from a closed allowlist, with settings merged **without schema validation**; other v3 widgets are rejected (`elementor_v3_not_supported`). Most existing sites are v3, so Elementor's MCP alone cannot edit them.
- These classes are Elementor internals (not a documented public API) and may change between releases.
- Calling them (verified 4.3.6): `modules_manager->get_modules( 'mcp' )->registry()->find_by_id( $id )`; `execute_guarded()` does **not** check permissions (Elementor's own proxy calls `check_permission()` first); the input schema sits on the protected `definition()`; resource abilities return JSON strings.
- Global-class capabilities (`elementor_global_classes_update_class`, …) are granted to administrators by a database update that runs on `admin_init` — a site where nobody opened wp-admin since installing/updating Elementor refuses class changes until then.

**Add-ons (tested 2026-11-05):** Essential Addons 6.8.8, Ultimate Addons (`header-footer-elementor`) 2.9.6 and Premium Addons 4.11.117 register ordinary `Widget_Base` widgets; KUDUY's generic discovery, schema (both control lists), save and render work for them unchanged.

**Elementor Pro 4.3.2 (licensed ZIP supplied by the user, read 2026-11-05):**
- Theme Builder template types (`modules/theme-builder/documents/`): `header`, `footer`, `single`, `single-post`, `single-page`, `archive`, `search-results`, `error-404`, `section`. Created like any document: `documents->create( $type, $post_data )` (core sets `_elementor_template_type` and `_elementor_edit_mode`).
- Display conditions: `Module::instance()->get_conditions_manager()`. `save_conditions( $post_id, $conditions )` takes rows `[ type, name, sub_name, sub_id ]`, stores them as strings `type/name/sub_name/sub_id` (e.g. `include/general`, `include/singular/page/42`, `exclude/archive/category`) in the template's `_elementor_conditions` meta, and regenerates the conditions cache. `get_document_conditions()` parses them back. Registered conditions and their sub-conditions: `get_conditions_config()` (extensible through `elementor/theme/register_conditions`).
- Conflicts: `get_conditions_conflicts( $post_id, $condition )` → templates of the same location already using that condition (locations that allow multiple templates never conflict).
- Pro ships its own MCP abilities too: `elementor/list-site-parts`, `elementor/manage-site-parts` (create/update conditions/delete; permission `edit_posts` + per-post checks). Other Pro modules: popup, loop-builder, custom-code, custom-css, display-conditions, woocommerce, mcp.

**Still UNVERIFIED (Phase 5):** Pro behaviour in a running site (not tested yet); popups and loop items; v3 → v4 migration.

## 9. SEO, WooCommerce, ACF, forms, multilingual

- **UNVERIFIED (Phase 6):** Rank Math public API vs meta (`rank_math_*` keys seen in WSP are not an API contract), Rank Math redirections and schema storage, Yoast `WPSEO_Meta` field list and social/schema settings, AIOSEO and SEOPress models, rendered `<head>` output.
- **UNVERIFIED (Phase 7):** WooCommerce CRUD + HPOS declaration (`FeaturesUtil::declare_compatibility`), gateway settings masking, ACF `get_field` / `update_field` and field-group APIs, CF7 / WPForms / Gravity / Fluent entry APIs, WPML / Polylang language APIs, Site Editor and Global Styles REST.
- Rule: each phase starts by reading the integration's own source and docs; nothing is built from guessed meta keys.

## 10. Licensing and dependencies

| Component | License (verified) | Use in KUDUY |
|---|---|---|
| WSP WordPress MCP | GPL-2.0 | Reference only — no code copied |
| Novamira | AGPL-3.0 | Reference only — no code copied or adapted |
| `wordpress/mcp-adapter` | GPL-2.0-or-later | **Not used** (D1 changed) |
| `league/oauth2-server` | MIT | **Not used** (D2 changed) |
| WordPress | GPL-2.0-or-later | Platform |
| Runtime Composer dependencies | — | **None** (`composer.json` requires only `php >= 8.1`) |
| Dev only: PHPUnit (BSD-3), PHPStan (MIT), phpstan-wordpress (MIT), WPCS (MIT), `@wp-playground/cli` (GPL-2.0) | as listed | Not shipped in the ZIP (checked by `check:release`) |

KUDUY MCP is GPL-2.0-or-later, written from public specifications and documented APIs.

## 11. Open research items

| Item | Phase |
|---|---|
| Which MCP revision each Claude surface sends today; real sign-in from claude.ai, Desktop and Claude Code | 3 (O11) |
| Elementor Pro Theme Builder / custom CSS, add-on schemas, v3 → v4 migration, Elementor 3.x behaviour | 5 |
| SEO plugin APIs and rendered output | 6 |
| WooCommerce, ACF, forms, multilingual APIs | 7 |
| WP-CLI command registration for hidden-mode recovery | 8 |
| MCP Tasks extension for background jobs | later |
