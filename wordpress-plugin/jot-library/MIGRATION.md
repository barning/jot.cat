# Legacy migration and cutover

Keep the standalone Jot host running until every required legacy reference has been imported, reviewed, published, and verified. A WordPress plugin installed on `niklasbarning.de` cannot make `jot.cat/{CODE}` work by itself; DNS and HTTP routing belong to the old host or edge infrastructure.

## 1. Back up both systems

Create a filesystem-level copy of the SQLite database while writes are stopped or use the host's SQLite-safe backup procedure. Export the WordPress database before importing. Keep both backups outside public web roots and record their checksums.

Do not commit the database, export, production URLs, or personal notes. The repository fixture contains synthetic addresses only.

## 2. Export SQLite to JSON

Run the exporter on a machine with PHP PDO SQLite:

```sh
php bin/export-legacy-jot.php \
  --database=/secure/backups/jot.sqlite \
  --output=/secure/backups/jot-export.json
```

The exporter reads `articles(id, url, created_at)`, validates the legacy code and URL shape, orders deterministically, and refuses to overwrite an existing output unless `--force` is supplied deliberately. It never changes the database.

## 3. Dry-run the WordPress import

Transfer the JSON file through an approved protected channel. On the intended WordPress site, confirm the target first:

```sh
wp core version --path=/path/to/wordpress
wp option get siteurl --path=/path/to/wordpress
wp db export /secure/backups/before-jot-import.sql --path=/path/to/wordpress
wp jot-library import /secure/backups/jot-export.json --dry-run --path=/path/to/wordpress
```

Review every invalid or conflict row. The importer treats the following cases differently:

- Same code and normalized URL: skip safely.
- Existing code with another URL: conflict; no write.
- Existing URL with another code: conflict; no write.
- Invalid code or unsafe URL: invalid; no write.
- New pair: “would import” in a dry run.

Resolve conflicts through explicit curation. Never delete, reassign, or regenerate a referenced code merely to make the report green.

## 4. Import as nonpublic content

```sh
wp jot-library import /secure/backups/jot-export.json --status=draft --path=/path/to/wordpress
```

`--status=private` is also supported. Both choices prevent anonymous REST access, library display, search/feed inclusion, and short-link resolution. Repeat the command to confirm that identical rows are skipped rather than duplicated.

Review titles, descriptions, notes, topics, and URLs in WordPress. Publish only entries that should become public. A legacy code begins resolving at `niklasbarning.de/j/{CODE}` only after its entry is published.

## 5. Update capture tools

The old bookmarklet and iOS Shortcut must stop writing through a GET request. Point them at the authenticated WordPress form:

```text
https://niklasbarning.de/wp-admin/edit.php?post_type=jot_entry&page=jot-library-save&url={URL-ENCODED-PAGE-URL}
```

That URL only prefills the form. The curator must be logged in and select **Save URL**, which submits an authorized nonce-protected POST. There is no public write endpoint.

## 6. Verify before routing traffic

For every legacy code that must survive:

1. Confirm its entry is published intentionally.
2. Request lowercase and uppercase forms at `/j/{CODE}`.
3. Confirm HTTP 302, no-cache headers, and the exact original destination.
4. Confirm unknown, malformed, draft, and private codes return 404.
5. Confirm the `/library` page, topic filters, search, pagination, and individual notes pages in the active theme.
6. Keep a sample of old `jot.cat/{CODE}` links in the acceptance list.

## 7. Route `jot.cat` deliberately

Choose one infrastructure approach outside this plugin:

- Keep the standalone service as a compatibility redirector and forward known codes to their original URLs.
- Reverse-proxy `jot.cat/{CODE}` to `niklasbarning.de/j/{CODE}` while preserving the requested path and response status.
- Run a small edge redirect service backed by a verified export of published mappings.
- Redirect the entire hostname only if every relevant path and code has an explicit tested destination.

Update DNS only after the chosen HTTP service is ready. Preserve TLS for `jot.cat`, monitor 404s without logging sensitive submitted URLs, and retain a rollback route. Do not retire SQLite or the old host until the acceptance list passes and the rollback window has closed.

Production traffic, DNS, proxy configuration, the privacy policy, and the iOS Shortcut distribution are not changed by installing this plugin or by this repository change.
