# KuDiagnostics

WordPress plugin that lets Claude manage a WordPress site through the Model Context Protocol, always as a real WordPress user and within that user's permissions. GPL-2.0-or-later.

- **Install:** download `kuduy-mcp.zip` from the [latest release](https://github.com/kuduyDigital/KuDiagnostics/releases/latest), then Plugins → Add New Plugin → Upload Plugin. Requires WordPress 6.9+ and PHP 8.1+. Details: [docs/INSTALL.md](docs/INSTALL.md).
- **Connect:** in Claude, add a custom connector with `https://<your-site>/wp-json/kuduy-mcp/v1/mcp`, sign in to WordPress and approve. Details and which Claude apps are verified: [docs/CONNECT-CLAUDE.md](docs/CONNECT-CLAUDE.md).
- **What Claude can do:** content, media, menus, users, plugins and themes, Elementor, WPBakery Page Builder ([docs/WPBAKERY.md](docs/WPBAKERY.md); not yet verified against the paid plugin), Avada Builder ([docs/AVADA.md](docs/AVADA.md); not yet run on an Avada site), SEO (Rank Math, Yoast, AIOSEO, SEOPress), WooCommerce, ACF, forms, the Site Editor, cache, Polylang and read-only developer tools — every tool in [docs/TOOLS.md](docs/TOOLS.md). What is supported how far, per builder, theme and plugin: [INTEGRATION-MATRIX.md](INTEGRATION-MATRIX.md) (live on a site: the `kuduy-integrations` tool). Most changes can be undone; secrets are never returned; personal data is masked unless allowed.
- **Extend or contribute:** [docs/DEVELOPER.md](docs/DEVELOPER.md) · releases: [docs/RELEASE-CHECKLIST.md](docs/RELEASE-CHECKLIST.md).

Project files: [CLAUDE.md](CLAUDE.md) · [RESEARCH.md](RESEARCH.md) · [ARCHITECTURE.md](ARCHITECTURE.md) · [ROADMAP.md](ROADMAP.md) · [FEATURES.md](FEATURES.md) · [COMPATIBILITY.md](COMPATIBILITY.md) · [SECURITY.md](SECURITY.md) · [TESTING.md](TESTING.md) · [CHANGELOG.md](CHANGELOG.md)
