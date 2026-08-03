# Jot

Jot turns an article URL into a four-character reference for a paper notebook. Paste a URL into the homepage form, use the “Jot this article” bookmarklet, add the [Jot shortcut for iPhone and iPad](https://www.icloud.com/shortcuts/59f16d7f5b0241bc8d7365fa18dec43b), or keep using the original direct-path syntax:

```text
https://your-jot.example/https://example.com/article  →  M7KD
https://your-jot.example/M7KD                        →  302 redirect
```

It requires PHP 8 with PDO SQLite, cURL, DOM, and mbstring. There are no package-manager dependencies; the small JavaScript enhancement is optional and the form works without it.

## Local setup

```sh
php bin/init-db.php
JOT_BASE_URL=http://127.0.0.1:8080 php -S 127.0.0.1:8080 -t public tests/router.php
```

Run the dependency-free tests with:

```sh
php tests/run.php
```

The production Apache document root must point to `public/`. Set `JOT_DB_PATH` to a writable SQLite path outside that directory and expose `JOT_BASE_URL` to PHP as the public origin. `JOT_BASE_URL` is required and must be an absolute root-level HTTP or HTTPS URL such as `https://your-jot.example`; paths, credentials, query strings, and fragments are rejected. Jot uses this value for public result links, workflow examples, the bookmarklet, canonical URLs, social-preview metadata, and its fetcher identity. Browser-runtime assets, navigation, and forms remain origin-relative so local and proxy previews continue to work under the site’s same-origin security policy. Initialize the database once with `php bin/init-db.php "$JOT_DB_PATH"`.

The decorative three-cell mark is derived from the complete four-character ID with SHA-256. It is never stored and never participates in copying or lookup; the written code remains the reference.

Copy the privacy variables from `.env.example`, replace every placeholder with accurate deployment information, and expose them to PHP. The privacy page deliberately returns 503 when any required value is missing or the contact email is invalid. Check a deployment before releasing it with:

```sh
php bin/check-config.php
```

The fetcher only connects to public HTTP/HTTPS addresses. Localhost and private-network URLs are intentionally rejected, including after redirects.

## Analytics

Set `JOT_UMAMI_WEBSITE_ID` to the website UUID shown in the Umami Cloud tracking code. Jot loads the standard Umami Cloud tracker, respects Do Not Track, and sends pageviews manually so submitted article URLs and generated codes are replaced with coarse page categories. It records successful new or duplicate saves, copy outcomes, article opens, bookmarklet help, and iOS Shortcut link clicks; event properties contain only outcome and article-length categories.

The application continues to work without analytics when this variable is absent or invalid, and analytics also stays disabled while the privacy configuration is incomplete. `php bin/check-config.php` treats either case as an incomplete production configuration. Before deployment, update the privacy processor, third-country transfer, analytics legal-basis, analytics-retention, and notice-date values to match the Umami Cloud account and selected data region, and complete any required data-processing agreement.
