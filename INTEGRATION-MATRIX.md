# KuDiagnostics — integration support matrix (2026-11-10, v1.1.0)

One row per integration in the upgrade brief (58), plus what KuDiagnostics supports beyond it. The same data is served live by the `kuduy-integrations` tool, which adds what is actually installed on the site.

**Support today**

| Class | Meaning |
|---|---|
| Full | Real operations exist and are tested against the real plugin or theme |
| Partial | Some real operations exist; important ones are missing, or the work is not verified against the real product |
| Detection | Recognised (listed, version shown) but not managed |
| None | No implementation |

**Copy to test with** — `free` means a copy on wordpress.org that the test runner can install (version found 2026-11-10); `paid` means no copy is available here, so nothing can be verified until one is provided.

## A. Builders

| Integration | Copy | Support today | What exists | Phase |
|---|---|---|---|---|
| Elementor | free 4.3.6 (Pro paid; 4.3.2 ZIP local) | **Full** | 19 tools, tested on 3.35.9 and 4.3.6, incl. duplicate a document, page settings, dynamic tags (2026-11-14); Theme Builder with Pro. Missing: popups (Pro), dynamic tags on v4 elements | F |
| WPBakery Page Builder | paid | **Partial** | 16 tools; tested against a stand-in, not the plugin. Missing: templates | L (verify) |
| Beaver Builder | free Lite 2.11.1.2 | **Full** (BB1–BB6, 2026-11-14) | 7 tools: status, modules, schema, list, read, create, edit (rows, columns, modules) with undo. Not changed: global nodes, column widths, saved templates; paid version untested | — |
| Kirki | free 6.4.2 | None | — (now sold as a page builder; needs research) | H |
| Bricks | paid (theme) | None | — | L |
| Oxygen 6 | paid | None | — | L |
| Divi 5 | paid | None | — | L |
| Breakdance | paid (free tier not on wordpress.org) | None | — | L |
| Etch | paid | None | — | L |
| Mosaic | unknown source | None | — | L |

## B. Themes

| Integration | Copy | Support today | What exists | Phase |
|---|---|---|---|---|
| Ollie | free 1.6.6 (block theme) | **Partial** | Generic Site Editor tools (templates, Global Styles, navigation); never run on this theme | D |
| Spectra One | free 1.2.6 (block theme) | **Partial** | Same generic tools; never run on this theme | D |
| Astra | free 4.14.2 | Detection | `kuduy-theme-list`, install, switch | D |
| Kadence | free 1.6.0 | Detection | same | D |
| Blocksy | free 2.1.61 | Detection | same | D |
| GeneratePress | free 3.6.1 | Detection | same | D |
| OceanWP | free 4.2.8 | Detection | same | D |
| Avada (+ Avada Builder) | paid (real 7.16.4 on staging) | **Full** (builder) / Partial (theme: Global Options read only) | 20 `kuduy-avada-*` tools (D31); tested on real Avada 7.16.4 / Builder 3.16.4 on staging, 2026-11-13 (AV11) | — |
| Voxel | paid | Detection | same | L |
| MyListing | paid | Detection | same | L |

## C. Gutenberg and blocks

| Integration | Copy | Support today | What exists | Phase |
|---|---|---|---|---|
| Native Gutenberg blocks | core | **Partial** | Block types, blocks of a post (read), templates, Global Styles. Missing: block-level add/update/move/delete, patterns | C |
| GenerateBlocks | free 2.5.1 | Detection | Its blocks appear in `kuduy-block-types` when active; untested | C |
| Kadence Blocks | free 3.9.0 | Detection | same | C |
| Spectra / Spectra Blocks | free 2.20.6 (`ultimate-addons-for-gutenberg`; one plugin under two names) | Detection | same | C |

## D. Store

| Integration | Copy | Support today | What exists | Phase |
|---|---|---|---|---|
| WooCommerce | free 11.2.2 | **Full** | 33 tools, HPOS on/off. Not offered by decision: permanent delete, refunds. Missing: general store settings writes, attribute taxonomy management | G |

## E. Forms

| Integration | Copy | Support today | What exists | Phase |
|---|---|---|---|---|
| Contact Form 7 | free 6.2.4.1 (+ Flamingo) | **Full** | Forms read and save, mail routing, entries through Flamingo | — |
| Fluent Forms | free 6.2.17 | **Partial** | Forms read, entries read/update/notes. Missing: create or change forms and fields | B |
| WPForms | free Lite 2.0.4 (Pro paid) | **Partial** | Forms read (Lite). Pro entries: read, read/starred/spam/trash with undo, notes — tested against a stand-in 2026-11-14, not against Pro | L (verify) |
| Gravity Forms | paid | **Partial** | Forms read; entries read, read/starred/spam/trash with undo, notes — built from the docs, tested against a stand-in 2026-11-14, not against the plugin | L (verify) |
| Ninja Forms | free 3.16.1 | **Partial** | Forms read; submissions read, trash and restore with undo (tested 2026-11-14). Missing: create or change forms and fields | B (form changes) |
| Formidable Forms | free 7.0 | **Partial** | Forms read; entries read only, spam listed (tested 2026-11-14). Missing: create or change forms and fields | B (form changes) |

## F. Field plugins

| Integration | Copy | Support today | What exists | Phase |
|---|---|---|---|---|
| ACF | free 6.8.11 (Pro paid) | **Partial** | Field groups read, values read/update with ACF's own validation. Missing: field group create/update, repeater and flexible content (Pro) | E |
| Meta Box | free 5.15.4 | None | — | E |
| Pods | free 3.3.9.2 | None | — | E |
| ACPT | free Lite 2.1.1 | None | — | E |
| ASE | free 9.3.2 (fields are Pro) | None | — | E (detection), L |
| JetEngine | paid | None | — | L |

## G. Builder add-ons

Elementor add-on widgets need no per-add-on code: the generic Elementor tools discover any registered widget with its plugin name and schema (proven with three add-ons, test EL9). "Partial" below means "expected to work the same way, not yet run".

| Integration | Copy | Support today | Phase |
|---|---|---|---|
| Ultimate Addons for Elementor | free Lite 2.9.6 (`header-footer-elementor`) | **Full** (EL9) | — |
| Happy Addons | free 3.51.3 | **Full** (EL9b, 2026-11-14) | — |
| HT Mega | free 3.2.8 | **Full** (EL9b, 2026-11-14) | — |
| PowerPack for Elementor | free Lite 3.1.4 | **Full** (EL9b, 2026-11-14) | — |
| Qi Addons for Elementor | free 1.11.2 | **Full** (EL9b, 2026-11-14) | — |
| Unlimited Elements for Elementor | free 2.0.28 | Partial (a fresh install registers no widgets; untested) | F |
| Xpro Elementor Addons | free 1.7.11 | **Full** (EL9b, 2026-11-14) | — |
| Element Pack | free Lite 10.0.5 | **Full** (EL9b, 2026-11-14) | — |
| Piotnet Addons for Elementor | free 2.4.37 | **Full** (EL9b, 2026-11-14) | — |
| JetElements | paid | Partial (untested) | L |
| Dynamic Content for Elementor | paid | Partial (untested) | L |
| Bricksforge | paid, needs Bricks | None | L |

## H–K. Dynamic data, code, SEO, translation

| Integration | Copy | Support today | What exists | Phase |
|---|---|---|---|---|
| Dynamic Shortcodes | paid | None | — | L |
| Code Snippets | free 4.1.0 | None | — | G |
| Rank Math | free 1.0.281 | **Full** | Meta, redirects, schema, sitemap status | G (audits) |
| Yoast SEO | free 28.8 | **Full** (meta) | Missing: schema type, sitemap settings | G |
| All in One SEO | free 5.0.5 | **Full** (meta) | — | G (audits) |
| SEOPress | free 10.4 | **Full** (meta) | — | G (audits) |
| WPML | paid | None | — | L |
| Weglot | free 6.4.3, but translations live in Weglot's cloud and need an account | None | — | K (detection and languages only) |

## Supported beyond the brief

| Integration | Support | Evidence |
|---|---|---|
| Polylang 3.9.2 | Full | L1–L4 |
| LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache | Full (status, purge) | C1–C3 |
| WP Rocket | Partial (from docs) | — |
| Essential Addons, Premium Addons for Elementor | Full | EL9 |
| Flamingo | Full | F1–F5 |

## Totals for the 58 target integrations

| Class | Count |
|---|---|
| Full | 8 (Elementor, WooCommerce, Contact Form 7, Ultimate Addons for Elementor, Rank Math, Yoast, AIOSEO, SEOPress) |
| Partial | 17 |
| Detection | 12 |
| None | 21 |

Of the 50 that are not Full: 33 have a free copy (or are WordPress core) and can be built and verified here, Weglot only as far as an account-free install goes; 17 are paid or unavailable and cannot be verified until a copy is provided.
