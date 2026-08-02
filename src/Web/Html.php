<?php

declare(strict_types=1);

namespace Jot\Web;

final class Html
{
    public static function page(
        string $title,
        string $content,
        string $description = '',
        string $language = 'en',
    ): string
    {
        $safeTitle = self::escape($title);
        $safeDescription = self::escape($description !== '' ? $description : Copy::HOME_SUPPORT);
        $safeLanguage = self::escape($language);
        $brand = Copy::BRAND;
        $tagline = Copy::TAGLINE;

        return <<<HTML
<!doctype html>
<html lang="{$safeLanguage}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="description" content="{$safeDescription}">
  <title>{$safeTitle} · jot.cat</title>
  <link rel="stylesheet" href="/styles.css">
  <script src="/app.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
  <div class="site-shell">
  <header class="site-header"><a class="brand" href="/" aria-label="jot.cat home">{$brand}</a></header>
  <main id="main">
    {$content}
  </main>
  <footer class="site-footer"><p>{$tagline}</p><nav aria-label="Footer"><a href="/privacy">Privacy</a><a href="https://github.com/barning/jot.cat">Source</a></nav></footer>
  </div>
</body>
</html>
HTML;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
