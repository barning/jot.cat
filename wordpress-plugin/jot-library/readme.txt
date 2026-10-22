=== Jot Library ===
Contributors: barning
Tags: bookmarks, library, links, block
Requires at least: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, code-first public library for curated links and handwritten notebook references.

== Description ==

Jot Library adds a WordPress-native library entry type, authenticated URL capture, five-character references, published-only short redirects, a dynamic library block, procedural SVG glyphs, and safe legacy import tooling.

It keeps entries in posts, post meta, and a topic taxonomy. Draft and private entries stay out of the public block and short-link resolver. The plugin introduces no analytics, cookies, trackers, remote assets, anonymous write endpoint, or JavaScript application.

See README.md for installation, security, privacy, testing, migration, and cutover details.

== Installation ==

1. Upload and activate the plugin.
2. Create a page and insert the Jot Library block.
3. Use Jot Library → Save URL to curate entries.
4. Publish individual entries when they are ready for public display and `/j/{CODE}` redirects.

== Frequently Asked Questions ==

= Does deactivation delete the library? =

No. Deactivation preserves all data. Uninstall also preserves data unless an administrator explicitly enables permanent cleanup in Jot Library settings first.

= Does this move jot.cat traffic? =

No. Hostname, DNS, proxy, and production routing changes are outside the plugin. Follow MIGRATION.md before cutover.

== Changelog ==

= 0.1.0 =

* Initial Jot Library plugin.
