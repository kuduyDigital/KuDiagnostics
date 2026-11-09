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

**Verified by test 2026-11-05 (EL8):** creating a header through `documents->create()` gives an `elementor_library` post with `_elementor_template_type`; `save_conditions()` stores the strings and regenerates the cache, so `get_conditions_conflicts()` sees the new template at once (drafts included). `save_conditions()` returns false when the value is unchanged, so KUDUY compares the stored meta instead. Storage format: `general` takes no sub-condition; sub-conditions sit under `singular` / `archive` (e.g. `include/singular/page/42`). Pro 4.3.2 refuses to load on Elementor core below 4.0 (`ELEMENTOR_PRO_REQUIRED_CORE_VERSION`).

**Still UNVERIFIED (Phase 5):** that a live header actually renders on the frontend of a real site (checked with Claude on staging, EL10); popups and loop items; Pro custom CSS; v3 → v4 migration.

## 9. SEO, media, background jobs (Phase 6), WooCommerce, ACF, forms, multilingual

Source read 2026-11-05 from the current wordpress.org ZIPs (all tested up to WordPress 7.1.4): **Rank Math 1.0.281**, **Yoast SEO 28.8**, **AIOSEO 5.0.5**, **SEOPress 10.4**; WordPress core `abilities-api/class-wp-ability.php` at tag 7.1.4. Only free versions; Pro/Premium behaviour stays **UNVERIFIED**.

**New finding: every one of the four plugins now registers WordPress Abilities** (core Abilities API, WordPress 6.9+), including write abilities for post SEO data in three of them. Abilities are a core public contract (`wp_get_ability( $name )->execute( $input )`); core's `execute()` normalizes and validates the input against the ability's schema, runs its `permission_callback` as the current user (failure → `ability_invalid_permissions`), runs it and validates the output. On 7.1 a `wp_pre_execute_ability` filter can short-circuit any ability (not on 6.9).

**Detection** (constants defined by each plugin's main file): `RANK_MATH_VERSION`, `WPSEO_VERSION`, `AIOSEO_VERSION` (+ `aioseo()`), `SEOPRESS_VERSION`. SEOPress's own sitemap diagnostics detect the others the same way.

**Yoast SEO 28.8** — `inc/class-wpseo-meta.php`, `src/abilities/`, `src/surfaces/`. Yoast 28.8 **requires WordPress 7.0** (refuses to activate on 6.9, found in tests); 28.7 is the last release for 6.9 ("Requires at least: 6.9" in `wp-seo.php`), and the files KUDUY relies on (`class-wpseo-meta.php`, the post and post-meta watchers, the context memoizer, the meta surface, `class-capability-utils.php`) are identical in 28.7 and 28.8.
- Storage: post meta `_yoast_wpseo_{key}` (`WPSEO_Meta::$meta_prefix`). Keys: `title`, `metadesc`, `focuskw`, `canonical`, `meta-robots-noindex` (`0` post-type default, `1` noindex, `2` index), `meta-robots-nofollow` (`0`/`1`), `meta-robots-adv` (comma list of `noimageindex`, `noarchive`, `nosnippet`), `bctitle`, `redirect` (Premium UI), `is_cornerstone`, `schema_page_type`, `schema_article_type` (enums `Schema_Types::PAGE_TYPES` / `ARTICLE_TYPES`), and `opengraph-{title,description,image,image-id}` / `twitter-{…}` **only when** the `opengraph` / `twitter` social options are on.
- API: `WPSEO_Meta::get_value( $key, $post_id )` (default when unset) and `set_value( $key, $value, $post_id )` (slashes, then `update_post_meta`). Every key is registered with `register_meta()` and `sanitize_post_meta()` as sanitize callback; values equal to the default are deleted rather than stored.
- Read model: the `wp_yoast_indexable` table. `Indexable_Post_Meta_Watcher` collects changed `_yoast_wpseo_*` meta and rebuilds the indexable **at shutdown**, so a read in the same request sees the old indexable. Rendered values: Surfaces API `YoastSEO()->meta->for_post( $id )` (`->title`, `->description`, `->canonical`, `->robots`, `->get_head()`).
- Abilities `yoast-seo/get-post-seo-data`, `yoast-seo/update-post-seo-data` (patch; fields `seo_title`, `meta_description`, `canonical`, `is_cornerstone`, `noindex` true/false/null, `nofollow`, `noimageindex`, `noarchive`, `nosnippet`, OG/X title + description, schema page/article type; **no focus keyphrase**). Permission `wpseo_edit_advanced_metadata` + edit check per post; it rebuilds the indexable at once. Free Yoast adds an upsell string `ai_generate_hint` to results. **These abilities exist only when indexables are on, which by default means `wp_get_environment_type() === 'production'`** — on staging and local sites they are not registered.

**Rank Math 1.0.281** — `includes/rest/class-shared.php`, `includes/abilities/`, `includes/admin/metabox/class-screen.php`:
- Storage: post meta `rank_math_{key}` (no underscore). Editor keys (`Screen::get_object_values()`): `title`, `description`, `focus_keyword` (comma list, first = primary), `pillar_content`, `canonical_url`, `breadcrumb_title`, `robots` (array of `index`/`noindex`, `nofollow`, `noarchive`, `nosnippet`, `noimageindex`), `advanced_robots` (array), `facebook_{title,description,image,image_id}`, `twitter_use_facebook`, `twitter_card_type`, `twitter_{title,description,image,image_id}`. Schema: one meta row per schema `rank_math_schema_{Type}`.
- Write path used by its own editor: REST `POST rankmath/v1/updateMeta` (`objectType`, `objectID`, `meta`), permission `Rest_Helper::can_edit_post()` (accessible post type + `edit_post` **or** `edit_others_posts`); values go through `Sanitize::sanitize()` per key; an empty value **deletes** the key. The handler calls `remove_all_filters( 'is_protected_meta' )` for the rest of the request. Schemas: `rankmath/v1/updateSchemas`; redirects: `rankmath/v1/updateRedirection` (Redirections module).
- Role manager capabilities (granted per role by Rank Math): `rank_math_onpage_general` (title, description, keyword), `rank_math_onpage_advanced` (robots, canonical), `rank_math_onpage_social`, `rank_math_onpage_snippet` (schema), `rank_math_redirections`, `rank_math_sitemap`. Authors get `onpage_general` but not advanced by default.
- **Verified by test 2026-11-05:** Rank Math does nothing (no front-end tags, no REST routes, `init_actions()` never runs) until an account is connected or the setup wizard is skipped — `Registration->invalid` from `Helper::is_invalid_registration()` (option `rank_math_registration_skip` or constant `RANK_MATH_REGISTRATION_SKIP`). Writes through `updateMeta` round-trip into its editor keys (`rank_math_robots` stored as an array, keywords as `kuduy,mcp,seo`), show up in its read ability and in `wp_head` (description, robots, OG, X once `twitter_use_facebook` is `off`; canonical is omitted on noindex pages). A non-empty `rank_math_robots` replaces the global robots defaults (`Singular::get_effective_robots()`). Within one PHP request Rank Math caches the page object (`Paper::get()`) and each post's meta once read (`Metadata::$objects`), and every `new OpenGraph()` (its read ability creates two) adds a `rank_math/head` hook — harmless in MCP requests, but in-process `<head>` checks must start from a fresh request state.
- Abilities (always on with WordPress 6.9+): read `rank-math/get-post-seo-meta` (rendered title, description, focus keyword, effective robots, canonical, OG/X; cap `rank_math_onpage_general`), `rank-math/get-post-schema`, `-get-redirections`, `-get-sitemap-status`, site settings setters. **No ability writes post SEO data.**

**AIOSEO 5.0.5** — `app/Common/Models/Post.php`, `app/Common/Abilities/Abilities.php`, `app/Common/Services/PostSeoService.php`:
- Storage: own table `{prefix}aioseo_posts` (model `Models\Post`; JSON columns `keyphrases`, `schema`, …) plus mirror post meta `_aioseo_title`, `_aioseo_description`, `_aioseo_og_*`, `_aioseo_twitter_*` for multilingual plugins. Raw meta writes would miss the table, which is what AIOSEO reads.
- Abilities `aioseo-posts/seo-data-get` and `aioseo-posts/seo-data-update` (patch; `postId`, `title`, `description`, `canonical_url`, `focus_keyphrase`, `additional_keyphrases`, `pillar_content`, `robots{use_default, noindex, nofollow, noarchive, nosnippet, noimageindex, …}`, `social{og_title, og_description, twitter_title, twitter_description}`; schema refused). Permission `aioseo_page_general_settings` and `edit_post` per post; saves through `Models\Post::savePost()` (filter `aioseo_save_post`, action `aioseo_insert_post`). Always on with 6.9+.

**Verified by test 2026-11-08 (AIOSEO 5.0.5, WP 7.1.4):** writes through `aioseo-posts/seo-data-update` land in the `aioseo_posts` table and in `wp_head` (title, description, robots, og:title); `robots.use_default` false + `noindex` false gives an explicit index. AIOSEO keeps the first computed title for the rest of a PHP request, so a second in-process head render repeats it (test-harness limit only).

**SEOPress 10.4** — `src/Actions/Abilities/AbilitiesApi.php`, `src/Actions/Api/`:
- Storage: post meta `_seopress_titles_title`, `_seopress_titles_desc`, `_seopress_analysis_target_kw` (comma list), `_seopress_robots_{index,follow,imageindex,snippet}` (`yes` = no-…), `_seopress_robots_canonical`, `_seopress_social_{fb,twitter}_*`, `_seopress_redirections_*`.
- Abilities `seopress/get-post-title-description`, `-robots-settings`, `-social-settings` and the `update-post-…` counterparts (robots/social take a map of the known meta keys above; unknown keys ignored); permission: edit the post + the role is not blocked from the SEO metabox (Advanced > Security). Writes go through SEOPress's own REST controllers (`processPut`). Always on with 6.9+. No ability for the target keyword (REST `TargetKeywords` controller instead, `PUT seopress/v1/posts/{id}/target-keywords`, permission edit_post + role not blocked for content analysis). Per post SEOPress only stores "no…" flags (`yes`), so there is no forced index, and it has no noarchive or cornerstone flag. **Verified by test 2026-11-08 (WP 7.1.4):** round trip into its meta keys and `wp_head`; SEOPress registers its head output once per PHP request, so a second in-process render in the same process prints none of its tags (test-harness limit only).

**Rendered `<head>`:** all four print through `wp_head` for the main query. Checking it inside one request needs a front-end main query for the post; a live fetch needs the published URL (Playground has no loopback, as for `kuduy-elementor-verify`). In-process: set the main query for the post, fire `wp`, capture `wp_head` — **works for Rank Math, Yoast, AIOSEO and SEOPress** (tested 2026-11-06/08) on the first render of a process, once the per-request caches are fresh; block themes print `<title>` from `locate_block_template()` (`_block_template_render_title_tag`), so an in-process check adds that hook. `kuduy-seo-verify` reads the live page instead (needs loopback HTTP: **UNVERIFIED** on the Hostinger staging site).

**Media (core):** alt text is post meta `_wp_attachment_image_alt` (core-defined, read by `wp_get_attachment_image()`); title, caption and description are the attachment's `post_title`, `post_excerpt`, `post_content` (`wp_update_post()`); sizes from `wp_get_attachment_metadata()`; regeneration with `wp_generate_attachment_metadata()` (`wp-admin/includes/image.php`); sideload with `download_url()` (safe HTTP API) + `media_handle_sideload()`. MCP `CallToolResult.content` allows `{ "type": "image", "data": <base64>, "mimeType" }` (both schema revisions), so a tool can let Claude look at an image before writing alt text. **UNVERIFIED:** how claude.ai / Desktop / Claude Code show image content returned by a tool.

**Background jobs:** Action Scheduler is bundled by WooCommerce, Rank Math and AIOSEO (`as_enqueue_async_action()` when loaded), otherwise WP-Cron (`wp_schedule_single_event()` + `spawn_cron()`, which needs a loopback request). **UNVERIFIED:** WP-Cron loopback on the Hostinger staging site.

- **UNVERIFIED (Phase 6, later):** Rank Math schema and redirect writes, Yoast/Rank Math term SEO, Pro/Premium features (Yoast redirects, SEOPress PRO), sitemap contents per plugin.
- **UNVERIFIED (Phase 7, later in the phase):** ACF `get_field` / `update_field` and field-group APIs, CF7 / WPForms / Gravity / Fluent entry APIs, WPML / Polylang language APIs, Site Editor and Global Styles REST. WooCommerce: §10.
- Rule: each phase starts by reading the integration's own source and docs; nothing is built from guessed meta keys.

## 10. WooCommerce (Phase 7)

Source read 2026-11-08 from the wordpress.org ZIP of **WooCommerce 11.2.2** (the version on staging; "Requires at least: 7.0", tested up to 7.1.4). Plugin directory API and SVN tags: **11.0.1 is the last release for WordPress 6.9** (`Requires at least: 6.9` in 10.9.4 and 11.0.1; 11.1.2 needs 7.0), so the 6.9 test pair uses 11.0.1, as for Yoast 28.7 in Phase 6. Nothing below has run in a test yet; every API is **UNVERIFIED in behaviour** until the Phase 7 tests pass.

**Detection:** constant `WC_VERSION`, function `WC()`; `class-woocommerce.php` boots its container services (abilities, MCP adapter, HPOS controller) on `plugins_loaded`.

**Abilities (core Abilities API) — WooCommerce registers its own:**
- Always (on `wp_abilities_api_init`, since 10.9.0, `src/Internal/Abilities/AbilitiesLoader.php`): `woocommerce/products-query`, `woocommerce/product-create`, `woocommerce/product-update`, `woocommerce/product-delete` (`force: true` deletes permanently), `woocommerce/orders-query`, `woocommerce/order-update-status`, `woocommerce/order-add-note`. Category `woocommerce`; meta `mcp.public: true`. Permission callbacks are WooCommerce's own REST checks: `wc_rest_check_post_permissions( 'product' | 'shop_order', read/create/edit/delete, $id )`; product update also needs `publish_products` to publish. Order output includes `billing_email`, `payment_method`, line items.
- Only on WooCommerce's own MCP endpoint (`/wp-json/woocommerce/mcp`, feature `mcp_integration`, experimental, off by default; `AbilitiesRestBridge` checks the request URI): `woocommerce/products-list|get|create|update|delete`, `woocommerce/orders-list|get|create|update`, wrapping the `wc/v3` REST controllers. They never exist in a KUDUY request.
- Coverage is thin: no variations, coupons, customers, reviews, shipping, tax, reports, stock or refunds. Same CRUD underneath.

**CRUD (data stores hide the storage, so the same calls work with HPOS on or off):**
- Products: `wc_get_product( $id )`, `wc_get_products( $args )`; classes `WC_Product_Simple|Variable|Grouped|External`, `WC_Product_Variation`; setters + `save()`; `set_sku()` throws `WC_Data_Exception` `product_invalid_sku` on a duplicate; `validate_props()` before save. Variations: `$variable->get_children()`, `WC_Product_Variable::sync( $product )` and `sync_stock_status()` after variation changes; `wc_get_product_variation_attributes()`. Stock: `wc_update_product_stock( $product, $qty, 'set'|'increase'|'decrease' )` (the data store writes `_stock` with its own SQL instead of a full product save; increase/decrease read the current value first), `wc_update_product_stock_status()`. Trash/delete: `WC_Data::delete( $force )` (false = trash); variations follow the parent (`untrash_variations`). `product` and `product_variation` both use capability type `product`.
- Orders: `wc_get_order()`, `wc_get_orders( $args )` (both stores), `wc_create_order()`; `$order->update_status( $status, $note, $manual )` fires `woocommerce_order_status_{status}` — **customer emails are sent** from those hooks (e.g. completed: `woocommerce_order_status_completed_notification`); `add_order_note( $note, $is_customer_note )` emails the customer when `$is_customer_note`. `wc_create_refund( [ amount, reason, order_id, line_items, refund_payment => false, restock_items => false ] )`; `refund_payment: true` calls the payment gateway (**moves money**). Trash: `$order->delete( false )`, restore: `WC_Order::untrash()` (both stores).
- Coupons: `WC_Coupon` (by id or code), `wc_get_coupon_id_by_code( $code, $exclude )`; post type `shop_coupon`. The code lookup compares `LOWER(post_title)` of **published** coupons only, so a draft's code is not reserved. `get_minimum_amount()` / `get_maximum_amount()` return "0" in view context when empty ("" in edit context). Discount types: `wc_get_coupon_types()` (percent, fixed_cart, fixed_product).
- Customers: `WC_Customer( $user_id )` (billing/shipping fields, `get_order_count()`, `get_total_spent()`), users with role `customer`.
- Reviews: comments of type `review` on products; comment meta `rating` (1–5) and `verified`; `WC_Comments::clear_transients( $product_id )` refreshes the cached average and counts (`get_average_rating_for_product`, `get_rating_counts_for_product`).
- Shipping (read): `WC_Shipping_Zones::get_zones()`, `get_zone( $id )`, zone → `get_shipping_methods()` (instance settings), `wc_shipping_enabled()`.
- Tax (read): `wc_tax_enabled()`, `WC_Tax::get_tax_classes()`, `get_tax_class_slugs()`, `get_rates_for_tax_class( $class )`, `find_rates( $args )`.
- Reports: Analytics REST `wc-analytics/reports/*` (revenue/orders/products/variations/categories/coupons/taxes/stock/customers/downloads stats; `GenericController extends WC_REST_Reports_Controller`), read internally with `rest_do_request()`. Data comes from lookup tables (`wc_order_stats`, …) filled by an import that is scheduled (option `woocommerce_analytics_scheduled_import`, renamed in 10.5.0) — reports can lag orders. Analytics can be switched off (`woocommerce_analytics_enabled`). Legacy `wc/v3/reports/*` still exists.

**HPOS:**
- `Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()` reads option `woocommerce_custom_orders_table_enabled` (`yes`/`no`); `custom_orders_table_data_sync_is_enabled()` for compatibility sync; `get_order_type( $id )`, `is_order( $id )`, `get_table_for_orders()`.
- Changing the option creates the tables when missing and **throws** while orders are pending sync (`CustomOrdersTableController::process_pre_update_option`). New shops get HPOS on at install (`WC_Install::maybe_enable_hpos()`) when there are no orders and no active "WooCommerce-aware" plugin is incompatible or uncertain.
- Capabilities under HPOS: with sync off, `map_meta_cap` translates `edit_post` / `delete_post` / `read_post` for order ids (no post, or a `shop_order_placehold` post) through `HposOrderCapabilityHelper`, so `current_user_can( 'edit_post', $order_id )` keeps working. Order post types stay registered.
- Compatibility declaration: `FeaturesUtil::declare_compatibility( 'custom_order_tables', $plugin_file, true )` must run inside `before_woocommerce_init`. HPOS's default plugin compatibility is **incompatible**, but only for "WooCommerce-aware" plugins (`PluginUtil::is_woocommerce_aware_plugin()`: a non-empty `WC tested up to` header). KUDUY has no such header today, so it is ignored; adding the header needs the declaration in the same change.

**Capabilities** (`WC_Install::get_core_capabilities()`; given to `administrator` and `shop_manager`): `manage_woocommerce`, `view_woocommerce_reports`, `create_customers`, and for each of `product`, `shop_order`, `shop_coupon` the full post-type set (`edit_*`, `read_*`, `delete_*`, `edit_others_*s`, `publish_*s`, `read_private_*s`, …) plus `manage|edit|delete|assign_*_terms`. WooCommerce's REST checks, which KUDUY mirrors so the `woocommerce_rest_check_permissions` filter still applies:
- `wc_rest_check_post_permissions( $type, $context, $id )`: read → `read_private_{type}s`, create → `publish_{type}s`, edit → `edit_post` (meta cap, per object), delete → `delete_post`, batch → `edit_others_{type}s`.
- `wc_rest_check_user_permissions()`: read `list_users`, create `create_customers`, edit `edit_users`; a shop manager may only edit `customer` users (filter `woocommerce_shop_manager_editable_roles`) or themself; multisite users outside the site refused.
- `wc_rest_check_manager_permissions()`: reports → `view_woocommerce_reports`; settings, system status, shipping methods, payment gateways, webhooks → `manage_woocommerce`; attributes → `manage_product_terms`.
- `wc_rest_check_product_reviews_permissions()`: read `moderate_comments`, write `edit_products`; the comment must be of type `review`.

**Secrets and personal data:**
- Payment gateways: settings live in options `woocommerce_{gateway_id}_settings`; secret fields are form fields of type `password` (core PayPal: `identity_token`, `api_password`, `api_signature`, sandbox variants; MaxMind integration `license_key`; extensions such as Stripe use their own names, e.g. `secret_key`, `webhook_secret` — **UNVERIFIED** per extension). WooCommerce's own `wc/v3/payment_gateways` returns every setting **value in clear text** (`get_settings()`), so KUDUY cannot pass that through. The present `Redactor` key pattern misses `api_signature` and `consumer_key`, and values sit in `{ id, type, value }` lists where key-based masking does not apply — masking must use the field `type`.
- REST API keys: table `woocommerce_api_keys` (`consumer_key` hashed, `consumer_secret` plain, `truncated_key`). Webhooks: `WC_Webhook::get_secret()` (REST exposes it in `edit` context).
- Payment tokens: table `woocommerce_payment_tokens` (`token` = gateway reference). Order key (`get_order_key()`, `wc_order_…`) opens the order-received/payment page without login — treat as secret.
- Customer PII: billing/shipping name, address, email, phone (orders and `WC_Customer`), `get_customer_ip_address()`, `get_customer_user_agent()`, customer notes; `WC_Privacy` registers WordPress personal-data exporters/erasers for it.
- Note for staging checks: new installs set `woocommerce_coming_soon` = `yes`, which hides store pages from visitors.

**Still UNVERIFIED (Phase 7):** staging's HPOS state; that `setSiteOptions` in a Playground blueprint switches HPOS on a fresh shop; analytics import timing in Playground (Action Scheduler without loopback); behaviour of gateway extensions' settings; WooCommerce subscriptions/bookings extensions (out of scope).

## 11. Licensing and dependencies

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

## 12. Open research items

| Item | Phase |
|---|---|
| Which MCP revision each Claude surface sends today; real sign-in from claude.ai, Desktop and Claude Code | 3 (O11) |
| Elementor Pro Theme Builder / custom CSS, add-on schemas, v3 → v4 migration, Elementor 3.x behaviour | 5 |
| SEO plugin APIs and rendered output | 6 — APIs read (§9); in-process `<head>`, Pro/Premium, schema/redirect writes still to check |
| WooCommerce APIs | 7 — read from 11.2.2 source (§10); behaviour, HPOS switching in Playground and staging's HPOS state still to check |
| ACF, forms, multilingual, Site Editor APIs | 7 (later in the phase) |
| WP-CLI command registration for hidden-mode recovery | 8 |
| MCP Tasks extension for background jobs | later |
