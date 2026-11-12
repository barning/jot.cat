# Jot Library for WordPress

Jot Library is a WordPress plugin for curating useful links into a quiet public library. Every entry receives a short, stable reference that is easy to copy, share, or write beside notes in a paper notebook.

The plugin is the primary project in this repository. It uses WordPress posts, statuses, roles, taxonomies, and the block editor as its source of truth, with no Composer, npm, analytics, remote-font, or runtime-service dependency.

## What it includes

- An authenticated workflow for saving and curating URLs.
- An authenticated iOS Shortcut API that saves shared pages as drafts and queues enrichment.
- A responsive `jot/library` block with search, topic filters, pagination, and accessible copy controls.
- A `jot/saved-links` block for date-based link digests inside posts and pages.
- Stable `/j/{CODE}` redirects and locally generated decorative glyphs.
- Administrator CSV checking, import, and export without overwriting existing entries.
- Automatic background metadata and topic enrichment for imported links—without an AI service.
- Draft/private safeguards, capability checks, nonces, conservative URL validation, and opt-in uninstall deletion.

## Install a release

Download `jot-library-VERSION.zip` from the [GitHub releases](https://github.com/barning/jot.cat/releases), then use **Plugins → Add New → Upload Plugin** in WordPress.

To build the current checkout instead:

```sh
bin/build-wordpress-plugin.sh
```

The archive is written to `dist/`. The plugin requires WordPress 7.0 or newer and PHP 8.0 or newer.

## Use the plugin

1. Activate **Jot Library**.
2. Create a page and insert the **Jot Library** block.
3. Add links through **Jot Library → Add library entry**.
4. Move libraries between WordPress sites through **Jot Library → Import / Export**.
5. Publish individual entries when they are ready for the public block and short-link redirects.

Detailed installation, privacy, data-model, CSV, and development documentation lives in the [plugin README](wordpress-plugin/jot-library/README.md). The WordPress.org-compatible metadata is in [readme.txt](wordpress-plugin/jot-library/readme.txt).

## Development

```sh
php tests/run.php
php wordpress-plugin/jot-library/tests/run.php
find wordpress-plugin/jot-library -name '*.php' -print0 | xargs -0 -n1 php -l
bin/build-wordpress-plugin.sh
```

The disposable-WordPress integration suite is at `wordpress-plugin/jot-library/tests/integration.php`. Tagged versions matching the plugin header (for example `v0.5.0`) are tested, packaged, and attached to a GitHub release by the repository workflow.

## Legacy standalone service

The original standalone PHP/SQLite implementation remains in `public/`, `src/`, and the root `tests/` for compatibility with existing four-character links. It is maintained as a migration source, not as the repository’s primary distributable. Read [MIGRATION.md](wordpress-plugin/jot-library/MIGRATION.md) before changing traffic, DNS, or legacy redirects.

## License

Jot Library is licensed under GPL-2.0-or-later. See [LICENSE](LICENSE).
