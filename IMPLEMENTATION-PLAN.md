# KuDiagnostics — upgrade plan (2026-11-10, from v1.1.0)

Goal: close the gaps in `FEATURE-GAP.md` without rebuilding anything that works. Every phase ends with tests green, records updated, and one push; a release tag needs the owner's go-ahead.

## How the work is done

- **Extend, do not replace.** New integrations are modules under `src/Modules/<Name>` implementing the existing `Tool` (and `Snapshottable`) contract, registered in `ToolRegistry`. Shared patterns are reused: adapters behind shared tools (forms, SEO), position ids and a validating save (WPBakery), in-process REST for core and plugin controllers.
- **Research first.** For each plugin: read its source from the wordpress.org ZIP, record the API facts in `RESEARCH.md`, then write a failing test, then code.
- **Real plugins in tests.** Free plugins are installed in the Playground runner and get a CI job. A stand-in is used only for paid plugins and is always labelled as such.
- **Paid products wait for a copy.** The WPBakery round showed what a stand-in can and cannot prove. No more adapters are written blind; the paid list is in phase L.
- **Honest status.** `kuduy-integrations` and `INTEGRATION-MATRIX.md` change only when a test passes.
- **Small tool surface.** Prefer one tool with adapters over a tool set per plugin; tools stay hidden when their plugin is inactive.

## Phases

| Phase | Scope | Exit gate | Size |
|---|---|---|---|
| **A. Registry** (this round) | Audit documents; `kuduy-integrations`: every target integration with installed/active/version, support class, its tools and whether they are available, test status | Tool tested; matrix and tool agree | Small — **done 2026-11-10** |
| **B. Forms** | Finish Gravity Forms and WPForms Pro entries (stand-ins); Ninja Forms and Formidable adapters (real plugins); form and field changes where the plugin supports saving | CI job per plugin; entries masked like the others | Medium — **adapters done 2026-11-14** (Ninja and Formidable tested on the real plugins; Gravity and WPForms Pro on stand-ins, to verify in L). Open: form and field changes (research first) |
| **C. Blocks** | Block-level add/update/move/duplicate/delete with validation; patterns; duplicate a post; delete media; GenerateBlocks, Kadence Blocks and Spectra in CI | Unchanged posts round-trip byte for byte; three block plugins green | Medium |
| **D. Themes** | Theme facts tool; settings read/update for Astra, Kadence, Blocksy, GeneratePress, OceanWP through each theme's registered settings; Site Editor tools run on Ollie and Spectra One | One CI job per theme | Large (five themes, each its own storage) |
| **E. Fields** | Meta Box, Pods, ACPT Lite adapters behind shared field tools; ACF field-group create/update; ASE detection | CI job per plugin; values validated by the plugin's own API | Large |
| **F. Elementor completion** | Eight more free add-ons in CI; duplicate document; dynamic tags; popups and document settings (Pro, local runs) | EL9 extended; new tests green on 3.x and 4.x | Medium |
| **G. Store, SEO, code** | WooCommerce settings and attributes; SEO audits (headings, alt, links, duplicates, broken links as a job) and Yoast schema/sitemap; Code Snippets (writes behind the code-execution constant); shortcode list; job retry | Tests per item | Medium |
| **H. Free builders** | Beaver Builder Lite adapter; Kirki after research | Real plugin in CI; layouts survive a round trip | Large |
| **I. AI layer** | Memory (D31), design scan and tokens, Markdown skills as MCP prompts (D32) | Memory never stores secrets (test); prompts listed by a real client | Large |
| **J. Modes** | Site-wide read-only switch; Ghost mode staging (D33); snapshot comparison; abilities hub (D34) | Every write tool blocked in read-only (test over all tools); staged change applies exactly what was previewed | Large |
| **K. Tokens and translation** | Measure `tools/list` and result sizes, calls per task; trim descriptions and add a compact listing if the numbers justify it; Weglot detection | Numbers recorded in `TESTING.md` before and after | Small–medium |
| **L. Paid products** (each when a copy is provided) | Verify WPBakery, Gravity Forms, WPForms Pro; then Bricks, Oxygen 6, Divi 5, Breakdance, Etch, Mosaic, Avada, Voxel, MyListing, JetEngine, JetElements, Dynamic Content for Elementor, Dynamic Shortcodes, Bricksforge, WPML, ACF Pro, ASE Pro | Tested against the licensed copy | Per product |

**2026-11-13:** the owner moved Avada ahead of everything (built, D31, `docs/plans/avada.md`). Order after Avada: B (already half written) → C → F → G → E → D → H → I → J → K. L runs alongside whenever a copy arrives.

**2026-11-14:** B's adapters are done. The owner's order from here: **all builders and themes first** — F (Elementor completion), H (free builders), D (themes), and the paid builders and themes of L as copies arrive — then C → G → E → I → J → K.

## Decisions to make along the way (recommended option first, marked for review like D18–D29)

| # | Question | Recommendation |
|---|---|---|
| D30 | Where the integration catalogue lives | Static catalogue in code with live detection; support class changes only with tests |
| D31 | Memory storage | Own table `kuduy_memory` (kind, title, body, tags, updated), redactor on write, export/import as JSON, admin can clear it |
| D32 | How skills reach Claude | MCP prompts for clients that support them, plus `kuduy-skills` / `kuduy-skill-get` for those that do not; site-specific skills stored like memory |
| D33 | Ghost mode shape | Dry-run flag on write tools that returns a stored plan; a separate approve call applies it; plans expire |
| D34 | Abilities hub | Only abilities whose plugin marks them public for MCP, each run through its own permission callback |
| D35 | Raw database updates | Not offered (rule 6) |

## Risks

- **Scale.** 50 integrations are not Full today; 33 can be verified here. Each large phase is several working sessions. The plan is ordered so every phase is useful on its own.
- **Theme and builder internals change.** Adapters record the versions they were tested with and refuse politely outside them.
- **Tool count.** 181 tools today; shared-adapter tools keep growth low, and phase K measures the cost.
- **Unverifiable claims.** Anything built without the real product stays "Partial" in the matrix and says so in its docs.
