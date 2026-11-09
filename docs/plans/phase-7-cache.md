# Phase 7e — Cache plugins (plan)

Research: `RESEARCH.md` §10d (LiteSpeed Cache 7.9.3, W3 Total Cache 2.11.0, WP Super Cache 3.1.5, WP Fastest Cache 1.5.5 source; WP Rocket docs). Status: **D19 decided with the recommended option (A) under the user's standing instruction of 2026-11-09 to finish Phase 7 unattended; for the user's review.**

Exit gate (`ROADMAP.md`, cache part): a purge runs through the cache plugin's own API, only within the permissions below; nothing but cached copies is removed; the tools say clearly when no cache plugin is active.

## Decision D19 — cache purges

| Option | What it means |
|---|---|
| **A. Each plugin's documented purge API, three targets (recommended, used)** | `kuduy-cache-purge` with target all / post / url in every detected plugin (or one named plugin); `object: true` flushes the persistent object cache. Whole site, URL and object cache need `manage_options`; one post needs `edit_post`. No snapshot (a purge removes copies, not content) |
| B. Whole-site purge only | Simpler, but every small fix empties the whole cache |

## Tools (module `src/Modules/Cache/`)

| Tool | Scope | Capability |
|---|---|---|
| `kuduy-cache-status`: cache plugins found and versions, persistent object cache in use, `WP_CACHE` | read | `edit_posts` |
| `kuduy-cache-purge`: target all, post (`post_id`) or url (an address on this site), optional plugin, optional object-cache flush | write | `manage_options`, or `edit_post` for one post |

Not offered: cache plugin settings, preload/crawler runs, CDN purges, LiteSpeed's critical-CSS/UCSS queues.

## Tests (Playground)

- C1 status and object-cache flush (every run).
- C2 permissions: an author cannot purge the whole site or flush the object cache; can purge their own post; URLs outside the site refused; a clear message with no cache plugin.
- C3 LiteSpeed Cache 7.9.3: purge all runs its own purge (`litespeed_purged_all`), post and URL purges; note about LiteSpeed servers.
- CI: one cache job per WordPress pair (LiteSpeed Cache).
