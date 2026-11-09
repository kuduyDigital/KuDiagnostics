# Phase 7f — Multilingual (plan)

Research: `RESEARCH.md` §10e (Polylang 3.9.2 source, read 2026-11-10). Status: **D20 decided with the recommended option (A) under the user's standing instruction of 2026-11-09 to finish Phase 7 unattended; for the user's review.**

Exit gate (`ROADMAP.md`, multilingual part): languages and translation groups read and changed only through Polylang's public API and the object's own permissions; every change has an undo; WPML clearly reported as not supported.

## Decision D20 — multilingual data path

| Option | What it means |
|---|---|
| **A. Polylang's public `pll_*` API; WPML later with a licensed copy (recommended, used)** | Language and translation-group tools for posts and terms; creating a translation as a draft with text Claude writes; no machine-translation service; WPML not shipped until tested |
| B. Polylang + WPML through WPML's documented hooks now | Covers more sites, but WPML would ship untested (against rule 9 of the brief) |

## Tools (module `src/Modules/Multilingual/`, available while Polylang is active)

| Tool | Scope | Capability | Undo |
|---|---|---|---|
| `kuduy-lang-status`: version, languages (slug, name, locale, default, posts), translated post types and taxonomies | read | `edit_posts` | — |
| `kuduy-lang-get`: language and translations of a post or term | read | `read_post` / `edit_term` | — |
| `kuduy-lang-set`: set the language of a post or term | write | `edit_post` / `edit_term` | previous language and group |
| `kuduy-lang-link`: link objects as translations (`{ "en": 12, "de": 34 }`); each must already have that language and be editable | write | `edit_post` / `edit_term` on every object | previous groups |
| `kuduy-lang-translate`: create a translation draft with given title, content, excerpt and link it | write | `edit_post` on the original + the type's `create_posts` | `kuduy-post-trash` on the draft |

Not offered: adding or removing languages, Polylang settings, string translations, machine translation.

## Tests (Playground, `KUDUY_PLUGINS=polylang@3.9.2`)

- L1 status (English and German added through Polylang's model).
- L2 set language + undo; unknown language refused.
- L3 link + get + undo; one post for two languages, mismatched languages and another user's post refused.
- L4 translate: linked draft created; a second translation for the same language refused.
- CI: one Polylang job per WordPress pair.
