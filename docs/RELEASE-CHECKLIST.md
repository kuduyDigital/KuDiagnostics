# Release checklist

Use for every release; `v1.0.0` additionally needs the "1.0 only" items. A tag publishes to every installed site through the updater, so tagging always needs the owner's go-ahead.

## Before tagging

- [ ] `main` is green in CI (`.github/workflows/ci.yml`: lint 8.1–8.4, PHPCS + PHPStan, integration on WP 6.9 and 7.1 with every plugin job, smoke, release-ZIP install).
- [ ] Locally: `php vendor/bin/phpcs -q`, `php vendor/bin/phpstan analyse --memory-limit=1G` clean.
- [ ] Version bumped in **both** places: `Version:` in `kuduy-mcp.php` and `Plugin::VERSION` (the build refuses a mismatch).
- [ ] `CHANGELOG.md`: a section for the version with user-facing notes (Added / Changed / Fixed / Security).
- [ ] `docs/TOOLS.md` regenerated (`npm run docs:tools`) when tools changed.
- [ ] `FEATURES.md`, `TESTING.md`, `COMPATIBILITY.md`, `ROADMAP.md`, `SECURITY.md` match the release (nothing marked Tested without evidence).
- [ ] `php scripts/build-release.php --expect-version=X.Y.Z` and `npm run check:release` pass (installs from ZIP, version, tables, routes, no dev files shipped).
- [ ] New decisions marked "for review" have been reviewed by the owner (or are explicitly accepted as is).

## Tag and publish

- [ ] Owner's go-ahead for this version.
- [ ] `git tag -a vX.Y.Z -m "KUDUY MCP X.Y.Z" && git push origin vX.Y.Z`.
- [ ] `release.yml` finished: the GitHub release has `kuduy-mcp.zip` and `kuduy-mcp.zip.sha256`.

## After publishing

- [ ] `kuduy-check-update` (or `node scripts/check-update.mjs <old> <new>`) on a site with the previous version sees the update; the update installs and the site stays healthy.
- [ ] Staging updated; a short claude.ai check of the changed tools.
- [ ] `TESTING.md` / `COMPATIBILITY.md` updated with the evidence.

## 1.0 only

- [ ] Phase 9 security work done: no high findings open (`SECURITY.md`).
- [ ] Real-client checks recorded (O11: Claude Desktop and Claude Code sign-in, refresh after an hour, revoke) or the README states which apps are verified.
- [ ] `docs/INSTALL.md` and `docs/CONNECT-CLAUDE.md` walked through on a clean site.
