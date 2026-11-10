# KuDiagnostics — feature gap against the upgrade brief (2026-11-10, v1.1.0)

What the brief asks for that the plugin does not do yet. `AUDIT.md` says what exists; `INTEGRATION-MATRIX.md` has one row per integration; `IMPLEMENTATION-PLAN.md` puts the gaps in order. "Phase" refers to that plan.

## 1. Integrations

### A. Builders

| Gap | Needs | Phase |
|---|---|---|
| Elementor: duplicate a whole document; popups (Pro); dynamic tags (read, set, clear); document settings | `Document::save()` for a copy; Pro's popup document type and its conditions; `__dynamic__` settings and the registered tag list from Elementor's dynamic-tags manager | F |
| Elementor: eight more free add-ons run through the generic tools | Add them to the EL9 CI job | F |
| WPBakery: verification against the plugin; templates | A licensed copy | L |
| Beaver Builder: everything | Research its public API (`FLBuilderModel` layout data, module registry) in the free Lite version, then an adapter | H |
| Kirki: everything | Research what Kirki 6 is (Customizer framework turned builder) before deciding scope | H |
| Bricks, Oxygen 6, Divi 5, Breakdance, Etch, Mosaic: everything | Licensed copies. Each has its own storage (Bricks: post meta arrays; Oxygen and Breakdance: JSON trees in meta; Divi 5: block-based) — all UNVERIFIED | L |

### B. Themes

| Gap | Needs | Phase |
|---|---|---|
| Theme facts in one place: parent/child, block or classic, `theme.json`, supports, template parts, registered Customizer settings | Core APIs (`wp_get_theme()`, `wp_is_block_theme()`, `WP_Theme_JSON_Resolver`) | D |
| Classic theme settings (colours, typography, layout, header, footer) for Astra, Kadence, Blocksy, GeneratePress, OceanWP | Each stores them differently (options or theme mods). Read each theme's source for the option names and its own sanitizers; write only keys the theme registers | D |
| Block themes Ollie and Spectra One | Run the existing Site Editor tools on them and fix what breaks | D |
| Avada / Fusion Builder, Voxel, MyListing | Licensed copies | L |

### C. Blocks

| Gap | Needs | Phase |
|---|---|---|
| Block-level editing: add, update attributes, move, duplicate, delete one block by position, with nested blocks | `parse_blocks()` / `serialize_blocks()`; position ids as in the WPBakery tools; unknown attributes kept | C |
| Patterns: list and insert | `WP_Block_Patterns_Registry` | C |
| Block validation before save | Round-trip check and registered-attribute check against `WP_Block_Type_Registry` | C |
| GenerateBlocks, Kadence Blocks, Spectra tested as real plugins | Add to CI | C |

### D–K. Plugins

| Gap | Needs | Phase |
|---|---|---|
| Forms: Ninja Forms and Formidable adapters; Gravity Forms and WPForms Pro entries finished | Ninja's and Formidable's own model classes (free copies); stand-ins for the paid two | B |
| Forms: create or change forms and fields | Only where the plugin has a supported way to save a form (CF7 done; Fluent, Ninja, Formidable to research) | B |
| Fields: Meta Box, Pods, ACPT Lite | `rwmb_get_value()` / `rwmb_set_meta()` and the meta box registry; `pods()` API; ACPT's API — all to read from source | E |
| ACF: create and change field groups | `acf_update_field_group()` / `acf_update_field()`, behind admin scope | E |
| WooCommerce: general store settings writes, attribute taxonomies | wc/v3 settings and attributes controllers, in-process | G |
| Code Snippets: list, read, create, change, activate | The plugin's own functions. Creating or activating a PHP snippet is code execution, so those stay behind `KUDUY_MCP_ALLOW_CODE_EXEC` + `kuduy:code` | G |
| Shortcodes: list registered shortcodes; Dynamic Shortcodes | Core `$shortcode_tags` for the list; the paid plugin needs a copy | G, L |
| SEO audits: heading structure, image alt, internal links, duplicate titles/descriptions, broken links (as a background job); Yoast schema and sitemap settings | Rendered HTML of each page; job runner for the crawl | G |
| WPML | Licensed copy; documented hooks (`wpml_*`) already read | L |
| Weglot | Detection and configured languages only: translations are not stored on the site | K |
| JetEngine, JetElements, Dynamic Content for Elementor, Bricksforge | Licensed copies | L |

## 2. Platform features

| Brief | Gap | Notes | Phase |
|---|---|---|---|
| §4 Registry | One catalogue of every integration with installed/active/version, support class, tools and test status | **Built in this round**: `kuduy-integrations` | A |
| §5 Memory | Save, search, update, delete, export, import notes about the site across sessions | New table; compact records with a kind and tags; redactor on every write; never secrets. Decision D31 | I |
| §6 Design intelligence | Scan colours, fonts, spacing, radii, breakpoints from the theme, Global Styles, Elementor Kit and rendered pages; store as tokens; consistency audit | Builds on memory for storage | I |
| §7 Skills | Markdown skills: list, read, enable/disable, site-specific ones | Served as MCP prompts (the server does not implement prompts yet) plus a tool for clients without prompt support. Decision D32 | I |
| §8 Read mode | A site-wide read-only switch in addition to the per-connection read scope | Constant or option checked in the executor | J |
| §8 Ghost mode | Stage changes, preview, approve, apply | Largest design question: per-tool dry run with a stored plan that is applied on approval. Decision D33 | J |
| §12 Rollback | Before/after comparison for a snapshot | Diff of snapshot payload against the current state | J |
| §21 Abilities hub | List and run abilities registered by other plugins | WordPress Abilities API (6.9+); each ability's own permission callback, plus an allow-list. Decision D34 | J |
| §11 Jobs | Retry failed items; site-wide scans (links, audits) | Extend the job runner's allow-list | G |
| §9 Core | Duplicate a post; delete media | Small tools | C |
| §13 Tokens | Real numbers: size of `tools/list`, of typical results, calls per task; then trim | Measure first; candidates: shorter descriptions, a compact listing mode | K |

## 3. Asked for, but not planned as written

| Brief | Why not | Instead |
|---|---|---|
| §10 "Approved parameterized updates" to the database | Project rule: no SQL writes by default | Typed tools that go through WordPress and plugin APIs; raw writes stay possible only through `kuduy-dev-php` behind the code-execution constant |
| §9 Permanent delete of posts, users, plugins | Deliberately not offered (trash and undo only) | Stays in WordPress admin |
| §18 PHP 8.0 | The code uses 8.1 features; 8.0 is out of support | PHP 8.1+ |
| "Test every integration against real installations" for paid products | No licensed copies here | Built only when a copy is provided; until then listed as not supported |
| §13 token-saving percentages | Would be invented | Measured numbers only, after phase K |
