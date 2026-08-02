# Jot

Jot turns a long article URL into a short, handwritten ID:

```text
https://jot.cat/https://example.com/article  →  M7KD
https://jot.cat/M7KD                        →  302 redirect
```

It requires PHP 8 with PDO SQLite, cURL, DOM, and mbstring. There are no Composer or JavaScript dependencies.

## Local setup

```sh
php bin/init-db.php
JOT_BASE_URL=http://127.0.0.1:8080 php -S 127.0.0.1:8080 -t public tests/router.php
```

Run the dependency-free tests with:

```sh
php tests/run.php
```

The production Apache document root must point to `public/`. Set `JOT_DB_PATH` to a writable SQLite path outside that directory and `JOT_BASE_URL` to the public origin. Initialize the database once with `php bin/init-db.php "$JOT_DB_PATH"`.

The fetcher only connects to public HTTP/HTTPS addresses. Localhost and private-network URLs are intentionally rejected, including after redirects.
