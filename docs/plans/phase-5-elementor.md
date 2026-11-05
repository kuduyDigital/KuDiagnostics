# Phase 5 — Elementor (plan)

Research: `RESEARCH.md` §8 (Elementor 4.3.6 source, 2026-11-05). Status: **D7 decided 2026-11-05 — option C (hybrid)**. Read pass **done** (tools 1–5, tested on Elementor 4.3.6). Next: write pass (tools 6–9).

## Decision D7 — who edits Elementor documents

| Option | What it means | For | Against |
|---|---|---|---|
| **A. Own tools on Elementor's public document API** | KUDUY reads/writes through `documents->get()->save()`, discovers schemas with `get_controls()` (v3) and the atomic prop types (v4), and does patch edits itself | Works for v3 **and** v4 and Elementor 3.x; full control over snapshots, unknown-element guard, responsive patching; only uses long-standing public API | More code for v4 styling/classes/variables than reusing Elementor's |
| **B. Wrap Elementor's own MCP abilities** | KUDUY calls `elementor/*` abilities internally (without turning on Elementor's public MCP) and adds our OAuth scopes, snapshots and audit | Elementor maintains the v4 logic (compositions, classes, variables, interactions) | v4-first: most v3 widgets are refused; internal classes may change between releases; Elementor ≥ 4.3 only |
| **C. Hybrid (recommended)** | A for documents, tree reading, v3 editing, Kit globals, CSS and verification; B only where it adds value on 4.3+ (v4 compositions, global classes/variables), behind `is_available()` with a clear message on older versions | Covers existing v3 sites now and reuses Elementor's own v4 logic; each part tested on its own | Two code paths to test (Elementor 3.x LTS and 4.3) |

## Tools (option C)

Read (scope `kuduy:read`, `edit_post` per document):
1. `kuduy-elementor-documents` — Elementor-built posts, pages, templates (type, status, version, edit mode).
2. `kuduy-elementor-get` — document tree (ids, elType, widgetType, settings; v3 and v4), page settings, and a list of element types that are **not registered** right now.
3. `kuduy-elementor-widgets` — registered widget types (v3 and atomic), grouped by source plugin.
4. `kuduy-elementor-widget-schema` — one widget's settings: v3 controls expanded per active breakpoint, or v4 JSON Schema from the prop types.
5. `kuduy-elementor-kit` — global colors, fonts, breakpoints, layout; v4 global classes and variables where present.

Write (scope `kuduy:write`, `edit_post` per document; snapshot of `_elementor_data` + page settings before every save; undo):
6. `kuduy-elementor-edit` — patch operations on one document: `update` (set/unset settings keys, per breakpoint), `add` (new element under a parent at an index), `move`, `duplicate` (fresh unique ids), `remove`. Rules: refuse if the tree has unregistered element types (Elementor would drop them); validate widget types and parent/child (container/section/column/widget); keep every key not mentioned; one `save()` per call.
7. `kuduy-elementor-create` — new page/post/template built with Elementor from a tree.
8. `kuduy-elementor-kit-update` — global colors/fonts (Kit save through the document API), snapshot + undo.
9. `kuduy-elementor-verify` — fetch the front-end page as the site sees it and confirm the edited element ids and texts are present; report the CSS file state (regenerated on first view).
10. v4 on Elementor ≥ 4.3 (option B part): global classes and variables management through Elementor's abilities.

Later in Phase 5: Theme Builder (needs Elementor Pro; research first), add-on adapters (Essential Addons, UAE, Premium Addons, JetElements).

## Safety rules

- Never write `_elementor_data` directly; always `Document::save()` (kses, hooks, revisions, version, CSS).
- Refuse saves that would drop unregistered elements; name the missing types.
- Patch, never replace: unrelated settings and other breakpoints stay untouched (test EL4).
- Snapshot before every save; undo restores the exact previous data.
- Report partial failures per operation; nothing half-saved.

## Tests

- Integration on Playground with Elementor 4.3.6 (current) **and** an Elementor 3.x LTS release (download from wordpress.org by version), WP 6.9 + 7.1.
- EL1–EL7 from `TESTING.md`; EL10 (full landing page from a prompt) end-to-end with Claude on the staging site.
