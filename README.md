# Jot

Jot turns an article URL into a four-character reference for a paper notebook. Paste a URL into the homepage form, use the “Jot this article” bookmarklet, or keep using the original direct-path syntax:

```text
https://jot.cat/https://example.com/article  →  M7KD
https://jot.cat/M7KD                        →  302 redirect
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

The production Apache document root must point to `public/`. Set `JOT_DB_PATH` to a writable SQLite path outside that directory and `JOT_BASE_URL` to the public origin. `JOT_BASE_URL` must be an absolute root-level HTTP or HTTPS URL such as `https://jot.uber.space`; paths, credentials, query strings, and fragments are rejected. Jot uses this value for result links, workflow examples, the bookmarklet, canonical URLs, and social-preview metadata. Initialize the database once with `php bin/init-db.php "$JOT_DB_PATH"`.

The decorative three-cell mark is derived from the complete four-character ID with SHA-256. It is never stored and never participates in copying or lookup; the written code remains the reference.

Copy the privacy variables from `.env.example`, replace every placeholder with accurate deployment information, and expose them to PHP. The privacy page deliberately returns 503 when any required value is missing or the contact email is invalid. Check a deployment before releasing it with:

```sh
php bin/check-config.php
```

The fetcher only connects to public HTTP/HTTPS addresses. Localhost and private-network URLs are intentionally rejected, including after redirects.
