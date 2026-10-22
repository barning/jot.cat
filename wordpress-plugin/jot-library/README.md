# Jot Library for WordPress

Jot Library turns curated URLs into short references that are easy to write beside notes in a physical notebook. It adds a quiet public library to an existing WordPress site while keeping WordPress posts, statuses, roles, taxonomies, and the block editor as the source of truth.

The plugin requires WordPress 7.0 or newer and PHP 8.0 or newer. It has no Composer, npm, remote-font, analytics, or runtime service dependency.

## Install

Build the installable archive from the repository root:

```sh
bin/build-wordpress-plugin.sh
```

Upload `dist/jot-library-0.1.0.zip` through **Plugins → Add New → Upload Plugin**, or copy `wordpress-plugin/jot-library` to `wp-content/plugins/jot-library`. Activate **Jot Library**, then save the site's permalink settings once if the host has unusual rewrite configuration. Activation registers and flushes the `/j/{CODE}` rewrite; it does not flush on ordinary requests.

Activation grants the custom entry capabilities to the Administrator and Editor roles. Deactivation preserves every entry, topic, option, and capability. Uninstall also keeps the library by default. To opt into permanent cleanup, enable **Jot Library → Settings → Permanently delete…** before uninstalling.

## Create the public library

1. Create or edit a normal WordPress page, ideally with the `/library` slug.
2. Insert the **Jot Library** block.
3. Choose a page size and whether to show the search and topic controls.
4. Publish the page.

The block is dynamic: WordPress queries only published `jot_entry` posts for the requested page. Search, topics, and pagination use ordinary GET parameters and work without JavaScript. Its stylesheet loads with the block instead of on unrelated pages. Individual entries use the active theme and receive a compact reference panel before their notes.

A card title and **Read notes** open the WordPress entry. **Visit original** and the visible code open the stored external resource through a 302 short link. The labels make the two destinations explicit.

## Add and curate entries

Use **Jot Library → Save URL**. The form accepts a public HTTP or HTTPS URL, title, short description, topics, personal notes, reading status, and WordPress visibility. Saving is an authenticated, nonce-protected POST; a bookmarklet or Shortcut may only prefill the form with GET.

Metadata fetching is optional. When selected, it uses `wp_safe_remote_get()` with a five-second timeout, three redirects, a 2 MiB response cap, and HTML-only parsing. A fetch failure never prevents saving: the source domain becomes the fallback title and the curator can edit it later. Scripts, embeds, and remote HTML are never rendered.

The conservative duplicate key lowercases the scheme and host, removes a default `:80` or `:443`, and treats an empty path as `/`. It preserves the path, query string, fragment, nondefault port, and the originally submitted URL. Thus tracking parameters and fragments remain significant. The original value remains the redirect target.

New references contain five uppercase characters from `0123456789ABCDEFGHJKMNPQRSTVWXYZ`. Generation uses `random_int()`, retries collisions across published, draft, private, pending, future, and trashed entries, and never encodes article length. The assigned code and original URL are read-only on the edit screen and survive edits, taxonomy changes, and publication changes.

Imported four-character `S`/`M`/`L` codes remain unchanged. Both formats are case-insensitive. Only published entries resolve through `/j/{CODE}`; an invalid, unknown, draft, or private code produces the site's 404 response. Successful redirects use HTTP 302 and no-cache headers. The external target comes only from the validated stored URL.

## Code and glyph

The visible code is the sole lookup key. It stays selectable, copyable, legible in a monospace stack, and appears beside the full short URL.

The adjacent SVG is decorative. PHP derives its fixed coordinates, rotation, and line path from SHA-256 of the complete uppercase code. It is generated locally, stores no image or blob, calls no service, and has `aria-hidden="true"`. The WordPress glyph deliberately uses a new geometric grammar; preserving legacy codes and redirects is mandatory, preserving the old three-cell drawing is not.

## Visibility and privacy

WordPress statuses control visibility. Published entries may appear in the library block, individual pages, WordPress search and feeds, public REST post responses, and `/j/{CODE}`. Draft and private entries, including their notes, URLs, and codes, remain subject to WordPress post permissions and are never used by the public block or redirect lookup. Entry meta is not registered for public REST output.

Publishing a URL makes the title, description, notes, source domain, topics, code, and external destination public. Optional metadata fetching sends a server-side request to the submitted host. Document those facts in the site's real privacy policy after legal review; this plugin makes no legal claims. It adds no cookies, trackers, analytics, external assets, or anonymous submission endpoint.

## Security model

- Administrators and Editors receive dedicated, mapped custom-post-type capabilities.
- Save and edit actions require both a capability check and WordPress nonce.
- User input is read by explicit key, unslashed, validated or sanitized, and escaped for its output context.
- URL storage rejects credentials, non-HTTP schemes, unresolved names, localhost names, and private or reserved IP results.
- Metadata requests use WordPress's safe HTTP client with bounded time, redirects, and body size.
- Public redirects revalidate the syntax of the stored target and never use raw request input.
- Rewrite rules flush on activation and deactivation, never per request.
- Uninstall deletion is opt-in and guarded by WordPress's uninstall context.

The plugin uses posts and post meta rather than a custom table. A short-lived atomic option serializes entry creation; code and normalized-URL queries cover all statuses. This keeps the implementation WordPress-native while reducing concurrent duplicate races.

## Legacy migration

Read [MIGRATION.md](MIGRATION.md) before working with real data. The safe sequence is backup, standalone JSON export, WP-CLI dry run, report review, nonpublic import, curation, publication, redirect verification, and only then traffic cutover.

The exporter needs PDO SQLite only where the old database is available:

```sh
php bin/export-legacy-jot.php --database=/secure/path/jot.sqlite --output=/secure/path/jot-export.json
```

The WordPress server does not need SQLite. Copy the JSON export to a protected location and run:

```sh
wp jot-library import /secure/path/jot-export.json --dry-run
wp jot-library import /secure/path/jot-export.json --status=draft
```

The importer preserves codes and timestamps, defaults to draft, reports collisions, never overwrites an entry, and skips an identical code/URL pair on repeat runs.

## Development and verification

Run the dependency-free checks from the repository root:

```sh
php tests/run.php
php wordpress-plugin/jot-library/tests/run.php
find wordpress-plugin/jot-library bin/export-legacy-jot.php -name '*.php' -print0 | xargs -0 -n1 php -l
```

Run the WordPress integration suite in a disposable WordPress 7.0+ installation after activating the plugin:

```sh
wp eval-file wp-content/plugins/jot-library/tests/integration.php
```

The integration suite covers registration, capabilities, generation and deduplication, immutability, public versus nonpublic resolution, anonymous REST restrictions, dynamic rendering, migration dry runs and idempotency, and glyph stability. The distributable ZIP excludes `tests/` and migration fixtures.

## Scope

Jot Library is a link library and notebook-reference tool. It does not archive article bodies, generate tags with AI, recognize handwriting or glyphs, replace the WordPress theme, provide social accounts, accept anonymous submissions, or add a separate SPA or backend.
