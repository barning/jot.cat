# Jot Library for WordPress

Jot Library turns curated URLs into short references that are easy to write beside notes in a physical notebook. It adds a quiet public library to an existing WordPress site while keeping WordPress posts, statuses, roles, taxonomies, and the block editor as the source of truth.

The plugin requires WordPress 7.0 or newer and PHP 8.0 or newer. It has no Composer, npm, remote-font, analytics, or runtime service dependency.

## Install

Build the installable archive from the repository root:

```sh
bin/build-wordpress-plugin.sh
```

Upload `dist/jot-library-0.5.0.zip` through **Plugins → Add New → Upload Plugin**. For development, this repository can also be mounted or copied to `wp-content/plugins/jot-library`. Activate **Jot Library**, then save the site's permalink settings once if the host has unusual rewrite configuration. Activation registers and flushes the `/j/{CODE}` rewrite; it does not flush on ordinary requests.

Activation grants the custom entry capabilities to the Administrator and Editor roles. Deactivation preserves every entry, topic, option, and capability. Uninstall also keeps the library by default. To opt into permanent cleanup, enable **Jot Library → Settings → Permanently delete…** before uninstalling.

## Create the public library

1. Create or edit a normal WordPress page, ideally with the `/library` slug.
2. Insert the **Jot Library** block.
3. Choose a page size and whether to show the search and topic controls.
4. Publish the page.

The block is dynamic: WordPress queries only published `jot_entry` posts for the requested page. Search, topics, and pagination use ordinary GET parameters and work without JavaScript. Its stylesheet loads with the block instead of on unrelated pages. Individual entries use the active theme and receive a compact reference panel before their notes.

Each result leads with its title, source, and curated notes. The title opens the stored external resource directly; a compact reference footer contains a clear “Copy short link {CODE}” button that copies the complete stable short URL.

## Add a saved-links digest to a post

Insert the **Saved Links by Date** block in any post or page, then choose inclusive **From** and **To** dates in the block sidebar. Dates use the WordPress site timezone. The dynamic block displays every published entry saved in that range, newest first, with its linked title, source, saved date, and notes or description. Draft and private entries never appear.

The date range remains stored as block attributes while the results are queried when the post is viewed. Newly published entries that belong to the selected range therefore appear automatically without editing the post again.

## Add and curate entries

Use **Jot Library → Save URL**. The form accepts a public HTTP or HTTPS URL, title, short description, topics, personal notes, reading status, and WordPress visibility. Saving is an authenticated, nonce-protected POST. The bookmarklet only prefills this form; the authenticated Shortcut API below can save a shared URL directly.

Metadata fetching is optional. When selected, it uses `wp_safe_remote_get()` with a five-second timeout, three redirects, a 2 MiB response cap, and HTML-only parsing. A fetch failure never prevents saving: the source domain becomes the fallback title and the curator can edit it later. Scripts, embeds, and remote HTML are never rendered.

When Topics is empty, the same opt-in can suggest up to five topics. Suggestions combine the page’s keyword and `article:tag` metadata with exact matches from topics already used in the library. They are saved as normal WordPress terms and remain editable. The plugin does not send content to an AI or suggestion service.

## Save from iPhone or iPad

The plugin registers `POST /wp-json/jot-library/v1/entries` for iOS Shortcuts and other trusted personal clients. Authenticate over HTTPS with a revocable WordPress Application Password. The authenticated user must have the `create_jot_entries` capability; anonymous requests receive `401` and authenticated users without that capability receive `403`.

The JSON request requires `url` and may include `title`, `description`, `notes`, `topics`, `reading_status`, and `fetch_metadata`. Entries created through this endpoint are always drafts. `fetch_metadata` defaults to `true`, which adds the entry to the existing background queue for missing titles, descriptions, and topic suggestions.

```json
{
  "url": "https://example.com/article",
  "notes": "Saved from the Share Sheet.",
  "topics": ["Independent web"],
  "reading_status": "unread"
}
```

The response uses `201 Created` for a new entry and `200 OK` for a duplicate. It includes `created`, `status`, `code`, `short_url`, `edit_url`, `enrichment_queued`, and a short `message` suitable for a Shortcut notification. Because the new entry is a draft, its stable short URL starts resolving only after the entry is reviewed and published.

In Shortcuts, accept URLs from the Share Sheet and use **Get Contents of URL** to send JSON to `https://your-site.example/wp-json/jot-library/v1/entries`. Add HTTP Basic authentication with the WordPress username and its dedicated Application Password. Show the returned `message` in a notification. Do not put the Application Password in the request URL or share a configured Shortcut publicly.

The conservative duplicate key lowercases the scheme and host, removes a default `:80` or `:443`, and treats an empty path as `/`. It preserves the path, query string, fragment, nondefault port, and the originally submitted URL. Thus tracking parameters and fragments remain significant. The original value remains the redirect target.

New references contain five uppercase characters from `0123456789ABCDEFGHJKMNPQRSTVWXYZ`. Generation uses `random_int()`, retries collisions across published, draft, private, pending, future, and trashed entries, and never encodes article length. The assigned code and original URL are read-only on the edit screen and survive edits, taxonomy changes, and publication changes.

Codes are five characters and case-insensitive. Only published entries resolve through `/j/{CODE}`; an invalid, unknown, draft, or private code produces the site's 404 response. Successful redirects use HTTP 302 and no-cache headers. The external target comes only from the validated stored URL.

## Code and short links

The visible code is the sole lookup key. In the public library it appears in a keyboard-accessible “Copy short link {CODE}” button that copies the complete short URL and provides brief status feedback. On individual entry pages it remains selectable beside the full short URL.

## Visibility and privacy

WordPress statuses control visibility. Published entries may appear in the library block, individual pages, WordPress search and feeds, public REST post responses, and `/j/{CODE}`. Draft and private entries, including their notes, URLs, and codes, remain subject to WordPress post permissions and are never used by the public block or redirect lookup. Entry meta is not registered for public REST output.

Publishing a URL makes the title, description, notes, source domain, topics, code, and external destination public. Optional metadata fetching sends a server-side request to the submitted host. Document those facts in the site's real privacy policy after legal review; this plugin makes no legal claims. It adds no cookies, trackers, analytics, external assets, or anonymous submission endpoint. The Shortcut endpoint accepts only authenticated requests and always creates nonpublic drafts.

## CSV import and export

Administrators can use **Jot Library → Import / Export** to download or restore a library. Exports contain `code`, `url`, `title`, `description`, `notes`, `topics`, `status`, `reading_status`, and `saved_at`. Topic names use `|` inside the CSV field so commas remain valid topic characters.

Imports require only a `url` column. Leave `code` empty to generate a new reference; include it to preserve a Jot code. When supplied, `saved_at` uses UTC `YYYY-MM-DD HH:MM:SS`. **Check CSV** performs a dry run. **Import CSV** creates only rows that pass validation; it never overwrites an existing code or URL. Draft is the safe default, with private and preserve-status options available to administrators; pending and scheduled rows become drafts. Uploads are limited to `.csv`, 5 MiB, and 5,000 entries per operation.

Every newly imported CSV entry is added to a WordPress cron queue. Small background batches request the original page through WordPress's safe HTTP client, fill missing titles and descriptions from Open Graph or standard HTML metadata, and combine page keywords or article tags with existing library-topic matches. Values supplied in the CSV remain authoritative. On sites with WP-Cron disabled, run `wp cron event run jot_library_enrich_imported_entries` until the queue is empty.

## Security model

- Administrators and Editors receive dedicated, mapped custom-post-type capabilities.
- Save and edit actions require both a capability check and WordPress nonce.
- Shortcut API writes require WordPress authentication, the custom create capability, validated JSON arguments, and always produce drafts.
- User input is read by explicit key, unslashed, validated or sanitized, and escaped for its output context.
- URL storage rejects credentials, non-HTTP schemes, unresolved names, localhost names, and private or reserved IP results.
- Metadata requests use WordPress's safe HTTP client with bounded time, redirects, and body size.
- Public redirects revalidate the syntax of the stored target and never use raw request input.
- Rewrite rules flush on activation and deactivation, never per request.
- Uninstall deletion is opt-in and guarded by WordPress's uninstall context.

The plugin uses posts and post meta rather than a custom table. A short-lived atomic option serializes entry creation; code and normalized-URL queries cover all statuses. This keeps the implementation WordPress-native while reducing concurrent duplicate races. CSV transfer actions require administrator access and nonce-protected POST requests.

## Development and verification

Run the dependency-free checks from the repository root:

```sh
php tests/run.php
find . \( -path './.git' -o -path './dist' \) -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

Run the WordPress integration suite in a disposable WordPress 7.0+ installation after activating the plugin:

```sh
wp eval-file wp-content/plugins/jot-library/tests/integration.php
```

The integration suite covers registration, capabilities, generation and deduplication, immutability, Shortcut authentication and responses, public versus nonpublic resolution, anonymous REST restrictions, dynamic rendering, CSV transfers and enrichment, and queue concurrency. The distributable ZIP excludes `tests/`.

## Scope

Jot Library is a link library and notebook-reference tool. It does not archive article bodies, generate topics with AI, replace the WordPress theme, provide social accounts, accept anonymous submissions, or add a separate SPA or backend.
