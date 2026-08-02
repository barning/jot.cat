# Jot MVP -- Codex Implementation Plan

## Vision

Jot is a tiny web utility that turns long article URLs into short
handwritten IDs.

## Core workflow

### Save

`https://jot.cat/https://example.com/article`

The service: 1. Validate URL 2. Check duplicates 3. Download article 4.
Estimate reading length 5. Generate unique ID 6. Store mapping 7. Return
ID (e.g. `M7KD`)

### Open

`https://jot.cat/M7KD`

Immediately redirect to the original article.

## ID format

-   Prefix:
    -   S = Short
    -   M = Medium
    -   L = Long
-   Four random Crockford Base32 characters.

Examples:

-   S4F7
-   M7KD
-   L2XT

Alphabet:

`0123456789ABCDEFGHJKMNPQRSTVWXYZ`

Requirements: - Random IDs - Collision check - Case-insensitive lookup -
Uppercase output

## Duplicate URLs

Saving the same URL must always return the same ID.

## Article length

Download the page, extract readable text with the simplest reliable
solution and classify:

-   Short: \<1000 words
-   Medium: 1000--2499 words
-   Long: 2500+ words

If extraction fails: - show an error - do not save

No archiving or Defuddle in the MVP.

## Routing

If the request starts with `http://` or `https://`, save the URL.

Otherwise treat it as an ID.

No `/save` or `/add` routes.

## Database

``` sql
CREATE TABLE articles (
  id TEXT PRIMARY KEY,
  url TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

## Tech

-   PHP 8
-   SQLite
-   PDO
-   Apache rewrite rules
-   Plain HTML/CSS

## Security

-   HTTP/HTTPS only
-   Prepared statements
-   HTML escaping
-   URL length limit
-   Reject malformed URLs

## Tests

-   Save article
-   Duplicate URL
-   Redirect
-   Invalid ID
-   Invalid URL
-   ID generation
-   Collision handling
-   Length classification

## Suggested subagents

1.  Routing
2.  Storage
3.  Article parsing
4.  ID generator
5.  UI

## Out of scope

-   Accounts
-   Search
-   Tags
-   Archiving
-   Markdown export
-   AI summaries
-   Browser extensions

## Definition of done

-   Saving a URL returns an ID like `M7KD`
-   Saving it again returns the same ID
-   `/M7KD` redirects
-   Invalid IDs return 404
-   Runs on Uberspace 8 using PHP + SQLite only.
