# Plan — Avada Theme and Avada Builder (D31, 2026-11-13)

Owner's priority: Avada first, before the rest of the upgrade plan (phases B–L wait). This file is the audit, the verified facts and the build plan; `docs/AVADA.md` is the user guide.

## 1. What already exists (audit)

- **WPBakery module** (`Modules/WpBakery`): a shortcode tokenizer/serializer (`Shortcodes`) that keeps every node's original text (byte-identical round trip, an edit rewrites one tag, unknown shortcodes kept), position ids and tree operations (`Layout::path/at/put/insert`), post-lock and content-hash guards, save through `wp_update_post()`. All format-neutral; Avada reuses them.
- **Elementor module**: separate data store (`_elementor_data`), refused by shortcode builders.
- **Safety plumbing**: OAuth scope + capability per post, snapshots and `kuduy-snapshot-restore`, audit log, redactor.
- **Registry**: `kuduy-integrations` lists Avada (theme) as detection only.
- Avada support today: none beyond the theme list.

## 2. Verified facts (read 2026-11-13 from an installed Avada 7.16.4 / Avada Builder 3.16.4 / Avada Core 5.16.4, read-only)

Source: a live site connected to this session, read with KuDiagnostics' own read-only developer tools; nothing on it was changed.

| Fact | Where |
|---|---|
| Layout = shortcodes in `post_content`: `fusion_builder_container` > `fusion_builder_row` > `fusion_builder_column` > elements; nested columns `fusion_builder_row_inner` > `fusion_builder_column_inner` inside a column. Other structural tags: `fusion_builder_blank_page`, `fusion_builder_next_page`, `fusion_builder_inline`, `fusion_builder_form_step` | `inc/shortcodes.php` (`fusion_builder_filter_available_elements`) and real pages |
| Saved pages write the full attribute list on every tag, sometimes the same attribute twice (`type="1_2" type="1_2"`); column widths are `1_2`, `1_3`, …, per screen `type_medium`, `type_small`; responsive values are attributes with `_medium` / `_small` suffixes | real pages |
| Element content is the shortcode content (`[fusion_title …]Text[/fusion_title]`; image frame: URL as content plus `image_id="44581|full"`); the setting holding it is `element_content` | real pages, element maps |
| Global elements: `[fusion_global id="N"]` points at a `fusion_element` post | real pages, `fusion_get_vendor_shortcodes` |
| Element registry: `fusion_builder_map( $module )` fills `global $all_fusion_builder_elements[ shortcode ]`; module keys `name`, `shortcode`, `icon`, `params`, `multi` / `element_child` (parent/child elements), `help_url`; param keys `type`, `heading`, `description`, `param_name`, `value` (option value => label), `default`, `dependency` (list of element/value/operator), `group`, `responsive`, `remove_from_atts` | `inc/shortcodes.php`, `shortcodes/fusion-title.php` |
| Outside the builder screens the maps are not built; `awb_maybe_init_element_maps()` (since Builder 3.15.6) builds them on demand | `inc/helpers.php` |
| Enabled elements: option `fusion_builder_settings['fusion_elements']` (empty = all) | `inc/shortcodes.php` |
| Builder on for a post: meta `fusion_builder_status` = `active` | `inc/class-fusion-builder.php` |
| Page custom CSS: meta `_fusion_builder_custom_css` | `inc/helpers.php` (`fusion_builder_save_meta`) |
| On `save_post` Avada resets its dynamic CSS/JS caches itself, so saving with `wp_update_post()` refreshes the page's styles | `class-fusion-dynamic-css.php`, `class-fusion-dynamic-js.php` |
| Library: post types `fusion_element` (taxonomy `element_category`) and `fusion_template` (`template_category`); Layouts: `fusion_tb_layout`, sections `fusion_tb_section` (`fusion_tb_category`); forms `fusion_form` | `class-fusion-builder-library.php`, `class-fusion-template-builder.php` |
| Some attributes hold base64 JSON (`logics`, `dynamic_params`, `track_tags`, `alternate_post_cards`) | element sources |
| Global Options: option `fusion_options` | `class-awb-widget-framework.php` |

**UNVERIFIED** (not found in source yet): how Avada's editor escapes `"`, `[` and `]` inside an attribute value (KuDiagnostics writes HTML entities `&quot;`, `&#91;`, `&#93;`); whether Avada's editor opens hand-written minimal shortcodes without rewriting them (it fills defaults when it next saves); which `element_category` terms mark global items.

## 3. Tools (consolidated to the project's naming; the brief's list maps onto them)

| Tool | Covers from the brief |
|---|---|
| `kuduy-avada-status` | get_status, get_capabilities (theme/builder/core versions, which combination, post types, enabled elements, Layouts, Library, WooCommerce) |
| `kuduy-avada-elements` | list_elements, get_element_schema, get_element_defaults |
| `kuduy-avada-tree` / `kuduy-avada-structure` | get_page_structure (compact tree with ids, or full structure with settings, responsive values, global references) |
| `kuduy-avada-content` | get_page_content, get_page_css |
| `kuduy-avada-library` | get_layouts, get_library_items, get_global_elements |
| `kuduy-avada-global-options` | get_global_options (read only: colours, typography, breakpoints) |
| `kuduy-avada-validate` | validate_page |
| `kuduy-avada-create`, `-add-container`, `-add-column`, `-add-element` | create_page, add_* |
| `kuduy-avada-update-element` (attrs, content, responsive) | update_element, replace_element_content, update_text/heading/image/button/link/background/spacing/typography/responsive_settings |
| `kuduy-avada-update-container`, `-update-column` | update_container, update_column |
| `kuduy-avada-move-element`, `-duplicate-element`, `-delete-element` | move, duplicate, delete_* |
| `kuduy-avada-replace` | update_page |
| `kuduy-avada-page-css` | update_custom_css |
| `dry_run` on every write + `kuduy-snapshot-restore` | preview_changes, rollback_changes |

Not offered: changing Global Options or Layout conditions (global scope, no supported API found yet), disabling elements, editing theme files.

## 4. Safety

Every write: parse → change only the addressed node → validate (errors are never saved) → `dry_run` returns the before/after change without saving → snapshot (content, builder status, page CSS) → `wp_update_post()` → read back and verify → result with the new tree. Post lock and `if_hash` against concurrent edits. Elementor and WPBakery pages refused. Library items and Layout sections are shared by many pages: changing one needs `confirm_shared: true`, and the refusal says how many pages reference it.

## 5. Tests

- Parser round trip on real Avada markup (structure copied from a real page, URLs neutralised) — every CI job.
- Tools against a stand-in for Avada Builder's registry and post types (`KUDUY_FAKES=avada`), Elementor active in the same run.
- Verification against the real Avada install: read-only tools on the live site once the version with these tools is installed there (owner's decision); writes only on a staging copy.
