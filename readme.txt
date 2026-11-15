=== Jot Library ===
Contributors: barning
Tags: bookmarks, library, links, block
Requires at least: 7.0
Requires PHP: 8.0
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, code-first public library for curated links and handwritten notebook references.

== Description ==

Jot Library adds a WordPress-native library entry type, authenticated browser and iOS Shortcut capture, five-character references, published-only short redirects, dynamic library and date-range blocks, procedural SVG glyphs, CSV transfers, and background metadata and topic enrichment.

It keeps entries in posts, post meta, and a topic taxonomy. Draft and private entries stay out of the public block and short-link resolver. The plugin introduces no analytics, cookies, trackers, remote assets, anonymous write endpoint, or JavaScript application.

See README.md for installation, security, privacy, and testing details.

== Installation ==

1. Upload and activate the plugin.
2. Create a page and insert the Jot Library block.
3. Use Jot Library → Add library entry to curate entries.
4. Move libraries through Jot Library → Import / Export when needed.
5. Insert Saved Links by Date in a post when you want a date-based digest.
6. Publish individual entries when they are ready for public display and `/j/{CODE}` redirects.

== Frequently Asked Questions ==

= Does deactivation delete the library? =

No. Deactivation preserves all data. Uninstall also preserves data unless an administrator explicitly enables permanent cleanup in Jot Library settings first.

= Can an iOS Shortcut save a link directly? =

Yes. Send an authenticated JSON POST to `/wp-json/jot-library/v1/entries` using a dedicated WordPress Application Password. The endpoint requires permission to create Jot entries, always saves drafts, and queues missing metadata and topic suggestions by default. See README.md for REST API details.

== Changelog ==

= 0.5.0 =

* Add an authenticated REST endpoint for iOS Shortcuts and other trusted personal clients.
* Save Shortcut entries as drafts, return stable reference details, and queue background enrichment.

= 0.4.0 =

* Enrich CSV imports in safe background batches with missing page titles, descriptions, and topic suggestions.
* Read Open Graph metadata and preserve every explicitly curated CSV value.

= 0.3.0 =

* Add a dynamic Saved Links by Date block with inclusive From and To controls.
* Display published entries in the selected site-timezone range, newest first.

= 0.2.0 =

* Add administrator CSV checking, import, and export with round-trip entry fields and conflict protection.
* Suggest topics from page metadata and existing library topics without an external AI service.
* Make the repository plugin-first and publish tagged ZIP files through GitHub releases.

= 0.1.3 =

* Make public-list short codes copy their complete short URL with accessible tooltip feedback.

= 0.1.2 =

* Lead public library entries with their titles and provide a compact “Copy short link CODE” action in the reference footer.
* Adapt search and topic controls to wide, medium, and narrow block containers.
* Keep pagination links anchored to the library page.

= 0.1.1 =

* Consolidate entry creation into one admin flow.
* Arrange public library cards to follow the original Jot identity-first layout while inheriting the active theme.

= 0.1.0 =

* Initial Jot Library plugin.
