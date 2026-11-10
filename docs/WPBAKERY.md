# WPBakery Page Builder

KuDiagnostics lets Claude read, build and edit pages made with **WPBakery Page Builder** (formerly Visual Composer), next to the existing Elementor tools. The 16 tools appear only while WPBakery is active.

> **Verification status.** WPBakery is a paid plugin and no licensed copy was available while this was built. The shortcode parser, the editing tools, permissions and undo are tested (see `TESTING.md`, WB1–WB12) against real WordPress with a stand-in for WPBakery's element registry. What has **not** been checked against a real WPBakery install: element discovery through `WPBMap`, and how WPBakery's own editor shows pages saved by these tools. Try it on a staging site first.

## Requirements

- WPBakery Page Builder installed and active (the plugin defines `WPB_VC_VERSION` and the `WPBMap` class).
- KuDiagnostics connected to Claude as usual ([CONNECT-CLAUDE.md](CONNECT-CLAUDE.md)). Reading needs the `kuduy:read` scope, editing `kuduy:write`.
- The WordPress user must be allowed to edit the page (`edit_post`); creating a page needs permission to create pages, publishing needs permission to publish.

Nothing has to be set up in WPBakery. If WPBakery is not active the tools are not offered to Claude at all.

## How a WPBakery page is stored

WPBakery keeps the layout as nested shortcodes in the page content:

```
[vc_row][vc_column width="1/2"][vc_column_text]Hello[/vc_column_text][/vc_column][vc_column width="1/2"][vc_single_image image="12"][/vc_column][/vc_row]
```

KuDiagnostics parses this into a tree, changes only the part you ask for, checks the result and writes it back. An unchanged page comes back byte for byte the same, and an edit rewrites only the tag that was edited. Shortcodes KuDiagnostics does not know (theme elements, add-ons, shortcodes of plugins that are switched off) are kept exactly as they are.

Elements have no ids in WPBakery, so the tools use **positions**: `0` is the first row, `0.1` its second column, `0.1.2` the third element in that column. **Ids change after every edit**; every editing tool returns the new tree.

## Tools

Full inputs are in [TOOLS.md](TOOLS.md).

| Tool | What it does |
|---|---|
| `kuduy-wpbakery-tree` | The layout as a compact tree with ids, plus a `hash` of the content. Start here. |
| `kuduy-wpbakery-structure` | The full structure: every row, column and element with attributes and content. `element_id` returns one part. |
| `kuduy-wpbakery-content` | The raw shortcodes exactly as stored, and the page's Design Options CSS. |
| `kuduy-wpbakery-elements` | Elements registered on this site (WPBakery's, the theme's, add-ons'). With `tag`: every setting of one element with type, default and options. |
| `kuduy-wpbakery-validate` | Checks a saved page (`post_id`) or shortcode text (`content`) without saving. |
| `kuduy-wpbakery-create` | Creates a page from `rows` or raw `content`: title, slug, status (draft by default), template, parent. |
| `kuduy-wpbakery-add-row` | Adds a row: `layout` such as `"1/3 + 2/3"` or `columns`; inside a column it becomes an inner row. |
| `kuduy-wpbakery-add-column` | Adds a column to a row: width, responsive widths, elements. |
| `kuduy-wpbakery-add-element` | Adds an element to a column or other container, at a position. |
| `kuduy-wpbakery-update-element` | Changes one element: `attrs`, `content`, `design`. |
| `kuduy-wpbakery-update-row` | Row settings, design, and `layout` to set the widths of its columns. |
| `kuduy-wpbakery-update-column` | Column width, responsive widths and hiding, settings, design. |
| `kuduy-wpbakery-move-element` | Moves an element, column or row to another parent or position. |
| `kuduy-wpbakery-duplicate-element` | Copies an item with everything inside it, right after the original. |
| `kuduy-wpbakery-delete-element` | Removes an item with everything inside it. |
| `kuduy-wpbakery-replace` | Replaces the whole layout with `rows` or `content`, after validating it. |

### Elements and settings

An element is given as JSON:

```json
{ "tag": "vc_btn", "attrs": { "title": "Get started", "color": "blue", "link": { "url": "https://example.com/start", "target": "_blank" } } }
```

- `content` holds the text or HTML of elements that have it (`vc_column_text`, `vc_raw_html`, ...); `children` holds elements of containers (tabs, accordions). Not both.
- `attrs` are the element's settings by name, as listed by `kuduy-wpbakery-elements` with `tag`. `null` removes a setting.
- Links are objects (`url`, `title`, `target`, `rel`), images are media ids, galleries are lists of media ids, repeating groups (progress bars, etc.) are lists of objects. KuDiagnostics converts them to WPBakery's stored formats and back.
- Quotes and square brackets in a value are stored the way WPBakery stores them, so they cannot break the shortcode.
- `design` sets Design Options: `margin_*`, `padding_*`, `border_*_width`, `border_color`, `border_style`, `border_radius`, `background_color`, `background_image` (media id), `background_size`, `background_repeat`, `background_position`. Numbers are pixels. The page's Design Options CSS is refreshed on every save.
- Columns take `width` (`1/1`, `1/2`, `1/3`, `2/3`, `1/4`, `3/4`, `1/5`, `1/6`, `5/12`, ...) and `responsive`, e.g. `{ "md": "1/2", "xs": "1/1", "hide": ["xs"] }`.
- Row settings such as full width (`full_width: stretch_row`), gap, equal height or extra classes go in the row's `attrs`.

Which elements exist is read from the site at the time of the call; nothing is hardcoded. An element that is not registered is still shown, moved, duplicated and kept, and validation says it is unknown.

### Validation

Errors (the layout would break; nothing is saved):

- a closing tag without an opening tag, or tags closed in the wrong order;
- a column outside a row, an element directly inside a row, a row nested in a column (it must be an inner row), an inner row outside a column;
- a row, column or section without its closing tag.

Warnings (saved, but worth a look): unknown elements, attributes that are not registered settings of the element, option values that are not among the element's options, content outside a row, a child the parent is not registered to hold.

WPBakery has no "required" flag for settings, so missing values are not reported.

### Safety

- Every tool runs as the signed-in WordPress user and checks `edit_post` for that page.
- Every change to an existing page is snapshotted first (content and WPBakery's page meta); undo with `kuduy-snapshot-restore`. WordPress also keeps its normal revision. A new page is undone with `kuduy-post-trash`.
- Saving goes through WordPress (`wp_update_post`), so accounts without `unfiltered_html` have their content filtered as usual. Adding or changing a **Raw HTML** or **Raw JS** element needs `unfiltered_html`, because their content is stored encoded and WordPress cannot filter it.
- If another user has the page open in the editor, the call is refused unless `ignore_lock` is true. Pass the `hash` from `kuduy-wpbakery-tree` as `if_hash` to refuse a save when the page changed in between.
- Pages built with Elementor are refused by the editing tools, and Elementor data is never touched. `kuduy-post-update` refuses to overwrite the content of a WPBakery page; use `kuduy-wpbakery-replace`.
- Nothing from the input is executed on the server.

## Example prompts

- "Create a landing page with WPBakery: a full-width hero with a dark background, a two-column feature section, three testimonials and a call-to-action button. Keep it as a draft and give me the edit link."
- "On the About page, change the hero heading to 'Building Better Digital Experiences' and add a button below the introduction that links to /contact."
- "The services section on the home page has three columns. Make it two columns and keep all the content."
- "Check the Pricing page for layout problems and tell me what you find. Don't change anything."
- "Which WPBakery elements does this site have for pricing tables, and what settings do they take?"

Claude will normally read the tree, look up the element's settings, make the change, and report the new tree.

## Known limitations

- **Not yet verified against a licensed WPBakery** (see the note at the top).
- Ids are positions and change after every edit; always use the tree from the latest result.
- WordPress pairs an opening tag with the first closing tag of the same name, so a shortcode cannot be nested inside itself. This is why rows inside columns are inner rows, and WPBakery supports only that one level of row nesting.
- Layouts larger than 1 MB are not edited.
- Bracketed words that are not registered shortcodes and have no closing tag (for example `[1]` in text) are treated as text.
- WPBakery templates, grid builder items, role settings and the element presets ("Save as default") are not managed.
- The frontend is not rendered to check the result; view the page or use the returned link.
