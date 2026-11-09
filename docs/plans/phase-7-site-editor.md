# Phase 7d — Blocks, Site Editor, Global Styles, widgets (plan)

Research: `RESEARCH.md` §10c (WordPress 7.1.4 core, read 2026-11-09). Status: **D18 decided with the recommended option (A) under the user's standing instruction of 2026-11-09 to finish Phase 7 unattended; for the user's review.** Built and tested together with the rest of Phase 7.

Exit gate (`ROADMAP.md`, Site Editor part): templates, template parts, Global Styles and widgets can be read and changed only within core's own permissions (`edit_theme_options`, `edit_css`), every change has an undo, nothing is deleted permanently, and the tools say clearly when the theme has no templates (classic theme) or no widget areas (block theme).

## Decision D18 — how KUDUY reads and writes Site Editor data

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Core REST controllers in-process (recommended, used)** | Tools call `wp/v2/templates`, `template-parts`, `global-styles`, `sidebars`, `widgets`, `block-types` with `rest_do_request()` as the current user | Core's own permission checks, kses filtering, custom-CSS validation, theme-file vs. database handling and revisions; the same path as the Site Editor | Results follow core's REST shape |
| B. Core PHP functions | `get_block_templates()`, `wp_insert_post()`, `wp_update_custom_css_post()`, `wp_set_sidebars_widgets()` | Fewer layers | KUDUY would have to re-create core's checks and the theme-file copy logic |

## Tools (module `src/Modules/Blocks/`)

| # | Tool | Scope | Capability (core) | Undo |
|---|---|---|---|---|
| 1 | `kuduy-block-types`: registered block types (name, title, category, attributes, supports); filter by name prefix | read | `edit_posts` | — |
| 2 | `kuduy-post-blocks`: block tree of one post (`parse_blocks()`): path, name, attributes, inner HTML text | read | `read_post` (edit for drafts, as `kuduy-post-get`) | — |
| 3 | `kuduy-site-templates`: templates or template parts of the active block theme: id, slug, title, source, customized, area | read | `edit_posts` (core) | — |
| 4 | `kuduy-site-template-get`: one template or part with its block markup | read | as 3 | — |
| 5 | `kuduy-site-template-update`: new block markup, title or description | write | `edit_theme_options` (core) | previous content restored; a template that was only a theme file before is reverted to the file |
| 6 | `kuduy-site-template-revert`: put a customized theme template back to the theme file (core moves the database copy to the trash) | write | `edit_theme_options` | the copy is restored from the trash |
| 7 | `kuduy-global-styles-get`: the site's Global Styles (user styles and settings) and, optionally, the theme's defaults | read | `read_post` on the styles post / `edit_theme_options` | — |
| 8 | `kuduy-global-styles-update`: patch `styles` and/or `settings` (deep merge into the current values, then core's update); `styles.css` needs `edit_css` | write | `edit_post` on the styles post (core) | previous styles and settings restored |
| 9 | `kuduy-widgets`: widget areas (sidebars) and their widgets: id, type, title, block content or settings | read | `edit_theme_options` | — |
| 10 | `kuduy-widget-save`: add a block widget or change a widget's content/settings or area (raw instances only, as core allows) | write | `edit_theme_options` | previous instance and area restored; an added widget is moved to inactive widgets |
| 11 | `kuduy-widget-remove`: move a widget to "Inactive widgets" (never deleted) | write | `edit_theme_options` | moved back to its area and position |

Not offered: permanent deletes (`force`), theme file edits, block navigation menus (`wp_navigation`; classic menus already have tools), style variations, font library.

Availability: 3–6 need a block theme; 9–11 need registered widget areas; each tool says which when it is not available.

## Tests (Playground, both pairs; default theme is a block theme, a classic theme is switched to for widgets)

- S1 block types and a post's block tree.
- S2 templates list/get; update a theme template (creates a custom copy), undo reverts to the theme file; update a custom template and undo; revert + undo.
- S3 Global Styles get; patch a color and a setting; undo; custom CSS refused for a user without `edit_css`.
- S4 widgets on a classic theme: list, add a block widget, change it, remove (moved to inactive), undo each.
- S5 refusals: editor without `edit_theme_options`; template tools on a classic theme; widget tools on a block theme without widget areas.
