# Avada Theme and Avada Builder

KuDiagnostics lets Claude read, build and edit pages made with **Avada Builder** (the `fusion-builder` plugin that comes with the Avada theme), next to the Elementor and WPBakery tools. The 20 tools appear only while Avada Builder is active (`kuduy-avada-status` also works with the theme alone).

> **Verification status.** The page format and Avada's functions were read from an installed **Avada 7.16.4 / Avada Builder 3.16.4 / Avada Core 5.16.4** (read only). The parser, the editing tools, permissions, dry runs and undo are tested in real WordPress against a stand-in for Avada Builder's element registry, and the parser against markup with the structure of a real Avada page. **Not yet run on a site with Avada itself.** Try changes on a staging copy first, or use `dry_run: true`.

## Requirements

- Avada Builder active (the Avada theme is optional for the page tools; Global Options need the theme).
- KuDiagnostics connected to Claude ([CONNECT-CLAUDE.md](CONNECT-CLAUDE.md)); `kuduy:read` to read, `kuduy:write` to edit.
- The WordPress user must be allowed to edit the page; Global Options need permission to change theme options (`edit_theme_options`).

## Detection

`kuduy-avada-status` tells which of these a site is:

| combination | Meaning |
|---|---|
| `theme_and_builder` | Avada theme (or a child of it) and Avada Builder active |
| `theme_without_builder` | Avada theme without the builder: no page tools |
| `builder_with_other_theme` | Avada Builder with another theme |
| `not_installed` | Neither |

It also reports the versions, the child theme, the post types the builder is on for, how many elements are registered and enabled, whether Layouts, the Library, Avada Forms and WooCommerce are there, and other page builders on the site. Which builder a page uses (`avada`, `elementor`, `wpbakery`, `none`) is in every read result.

## How an Avada page is stored

```
[fusion_builder_container …][fusion_builder_row][fusion_builder_column type="1_2" …][fusion_title size="2" …]Heading[/fusion_title][/fusion_builder_column]…[/fusion_builder_row][/fusion_builder_container]
```

Container → row → columns → elements; nested columns are `fusion_builder_row_inner` → `fusion_builder_column_inner` inside a column; a global element is `[fusion_global id="123"]`, pointing at a Library item. Settings are shortcode attributes; responsive values are the same name with `_medium` or `_small`.

KuDiagnostics parses the page into a tree, changes only what you ask for, checks the result and saves it through WordPress, which also lets Avada refresh its cached CSS and JS. An unchanged page comes back byte for byte the same; an edit rewrites only the edited tag, in place, keeping the order of its other settings (including settings Avada writes twice). Elements KuDiagnostics does not know — third-party, unknown, shortcodes of plugins that are switched off — are kept exactly as they are.

Elements are addressed by **position**: `0` is the first container, `0.0` its row, `0.0.1` the second column, `0.0.1.2` the third element in it. **Ids change after every edit**; every edit returns the new tree.

## Tools

| Tool | What it does |
|---|---|
| `kuduy-avada-status` | Detection and capabilities (above) |
| `kuduy-avada-elements` | Elements registered on the site; with `tag`, every setting with type, default, options (value → label), group, dependencies, responsive |
| `kuduy-avada-tree` | Compact tree with ids and a `hash`. Start here |
| `kuduy-avada-structure` | Full structure: settings, responsive values, content, column widths, global element ids, settings not registered for the element; `element_id` for one part |
| `kuduy-avada-content` | Raw shortcodes, builder status, page custom CSS |
| `kuduy-avada-library` | Library items (global elements, templates) and Layouts with their sections |
| `kuduy-avada-global-options` | Design-related Global Options (colours, typography, breakpoints, widths) — read only; secrets never returned |
| `kuduy-avada-validate` | Checks a page or shortcode text without saving |
| `kuduy-avada-create` | New page from `containers` or `content`; draft by default |
| `kuduy-avada-add-container` | Container with row and columns (`layout` such as `"1_3 + 2_3"`, or `columns`) |
| `kuduy-avada-add-column` | Column into a container or row; with a column as parent, nested columns |
| `kuduy-avada-add-element` | Element into a column or parent element (tabs, toggles…), at a position |
| `kuduy-avada-update-element` | Settings (`attrs`), `responsive`, `content` of one element — headings, text, images, buttons, links, spacing, typography, colours are all settings |
| `kuduy-avada-update-container` | Container settings: background, padding, margins, borders, width, admin label |
| `kuduy-avada-update-column` | Width (`1_2` or `1/2`), settings, widths per screen |
| `kuduy-avada-move-element` | Move or reorder an element, column or container |
| `kuduy-avada-duplicate-element` | Copy with everything inside, right after the original |
| `kuduy-avada-delete-element` | Remove with everything inside |
| `kuduy-avada-replace` | Replace the whole layout after validating it |
| `kuduy-avada-page-css` | The page's own custom CSS |

From the original wish list, `get_element_defaults` is part of `kuduy-avada-elements`; `get_page_css` of `kuduy-avada-content`; `get_layouts`, `get_library_items` and `get_global_elements` of `kuduy-avada-library`; `update_text`, `update_heading`, `update_image`, `update_button`, `update_link`, `update_background`, `update_spacing`, `update_typography` and `update_responsive_settings` are `kuduy-avada-update-element` / `-update-container` / `-update-column` with the right settings; `preview_changes` is `dry_run: true` on every edit; `rollback_changes` is `kuduy-snapshot-restore`.

### Settings

```json
{ "tag": "fusion_button", "attrs": { "link": "https://example.com/contact/", "target": "_blank", "color": "custom" }, "content": "Contact us" }
```

- `attrs` are the element's settings by name, as listed by `kuduy-avada-elements` with `tag`. `null` removes a setting, so Avada uses its default.
- `responsive`: `{"medium": {"margin_bottom": "20px"}, "small": {"type": "1_1"}}` — stored as `margin_bottom_medium`, `type_small`.
- `content` is the element's text or HTML (the setting Avada calls `element_content`). For an image frame it is the image URL; set `image_id` too (`"123|full"`).
- Quotes and square brackets in a value are written as HTML entities, so they cannot break the shortcode.
- Settings Avada stores as base64 JSON (`logics`, `dynamic_params`, …) are shown decoded and can be given as objects.

## Safety

- **Understand first**: every edit addresses one node by id; pass `tag` to refuse if the id now points at something else, and `if_hash` from `kuduy-avada-tree` to refuse if the page changed.
- **Dry run**: `dry_run: true` returns the before/after change and the resulting tree without saving.
- **Validation**: errors (closing tags, a column outside a row, an element directly in a row, a container inside something, nested columns deeper than one level) are never saved. Warnings: unknown elements, missing global elements, option values that are not among the element's options, content outside a column.
- **Undo**: every change is snapshotted (content, builder status, page CSS); `kuduy-snapshot-restore` puts it back. WordPress also keeps a revision.
- **Verification**: after saving, the page is read back; `verified` says whether it is stored exactly as intended (WordPress filters content for accounts without `unfiltered_html`, which shows up here).
- **Scope**: page tools change one page. Library items and Layout sections appear on many pages, so changing one needs `confirm_shared: true`; the refusal says how many pages use a global element. Avada Layouts' conditions, Global Options and element on/off settings are not changed. Theme files are never touched.
- **Other builders**: pages built with Elementor or WPBakery are refused; `kuduy-post-update` refuses to overwrite an Avada layout (use `kuduy-avada-replace`).
- Every tool runs as the signed-in WordPress user and checks permission to edit that page.

## Example prompts (Claude, or any MCP client)

- "On the About page, replace the hero heading with 'Building Better Digital Experiences', change the hero background image to the one called team-photo, and make the CTA button link to /contact in a new tab. Show me the change first."
- "Create a draft Avada landing page: a full-width hero with a dark background and a two-column feature section with a heading, text and button in each column."
- "The services container on the home page has three columns. Make it two columns and keep all the content."
- "Check every Avada page in the Library for layout problems and list them. Don't change anything."
- "Which Avada elements does this site have for pricing tables, and what settings do they take?"

A good assistant reads the tree, looks up the element's settings, makes the change with `dry_run` if asked, and reports the new tree.

## Known limitations

- **Not yet run against a real Avada site** (see the note at the top). The entity escaping of quotes and brackets in attribute values is KuDiagnostics' own; Avada's editor may write them differently when it next saves the page.
- New elements are written with only the settings given; Avada fills its defaults when the page is next opened and saved in its editor.
- Ids are positions and change after every edit.
- Layouts larger than 1 MB are not edited.
- Not managed: Global Options and Layout conditions (read only / not at all), Avada Forms entries, the Avada Studio library, theme options per page (`_fusion` page options).
