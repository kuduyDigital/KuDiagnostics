# Phase 7h — Custom post types and taxonomies (plan)

Research: WordPress core `register_post_type()` / `register_taxonomy()` (developer.wordpress.org; reserved terms list) and KUDUY's existing `kuduy-post-types`. Status: **D22 decided with the recommended option (A) under the user's standing instruction of 2026-11-09 to finish Phase 7 unattended; for the user's review.**

## Decision D22 — defining content types

| Option | What it means |
|---|---|
| **A. Definitions stored by KUDUY, registered with core on every request (recommended, used)** | `kuduy-content-type-save` stores a safe set of arguments in option `kuduy_mcp_content_types`; KUDUY registers them on `init` (priority 5) with `capability_type: post`, `show_in_rest: true`. Removing a definition keeps the content. The types exist while KUDUY is active; uninstall removes the definitions (content stays) |
| B. ACF's post types and taxonomies (ACF 6.8 `acf-post-type`) | Survives without KUDUY, but needs ACF and changes D14 (definitions read only) |
| C. Write a small plugin with the definitions (developer mode) | Independent of KUDUY, but needs D4's code tools |

## Tools (module `src/Modules/Types/`)

| Tool | Scope | Capability | Undo |
|---|---|---|---|
| `kuduy-content-types`: definitions made through KUDUY, with item counts | read | `edit_posts` | — |
| `kuduy-content-type-save`: define or change a post type (key ≤ 20 characters) or taxonomy (≤ 32) | admin | `manage_options` | previous definitions |
| `kuduy-content-type-remove`: remove a definition (content kept) | admin | `manage_options` | previous definitions |

Refused: core and reserved names, names already registered by WordPress, the theme or another plugin, malformed keys. Rewrite rules are flushed after each change.

## Tests (Playground)

- T1 define a post type and a taxonomy; create a post of the new type with `kuduy-post-create`.
- T2 remove the type (post stays), undo; undo the first save.
- T3 refusals: core type, reserved term, too-long key, removing a type KUDUY did not define, editor.
