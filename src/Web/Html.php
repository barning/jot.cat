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
        ?PageMetadata $metadata = null,
    ): string
    {
        $safeTitle = self::escape($title);
        $safeDescription = self::escape($description !== '' ? $description : Copy::HOME_SUPPORT);
        $safeLanguage = self::escape($language);
        $brand = self::escape(Copy::BRAND);
        $tagline = self::escape(Copy::TAGLINE);
        $metadataMarkup = '';

        if ($metadata !== null) {
            $canonical = self::escape($metadata->canonical);
            $metaTitle = self::escape($metadata->title);
            $metaDescription = self::escape($metadata->description);
            $image = self::escape($metadata->image);
            $imageAlt = self::escape('Jot glyph and the code M7KD — short references for paper notebooks.');
            $metadataMarkup = <<<HTML
  <link rel="canonical" href="{$canonical}">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Jot">
  <meta property="og:title" content="{$metaTitle}">
  <meta property="og:description" content="{$metaDescription}">
  <meta property="og:url" content="{$canonical}">
  <meta property="og:image" content="{$image}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="{$imageAlt}">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{$metaTitle}">
  <meta name="twitter:description" content="{$metaDescription}">
  <meta name="twitter:image" content="{$image}">
  <meta name="twitter:image:alt" content="{$imageAlt}">
HTML;
        }

        return <<<HTML
<!doctype html>
<html lang="{$safeLanguage}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="description" content="{$safeDescription}">
  <title>{$safeTitle}</title>
{$metadataMarkup}
  <link rel="stylesheet" href="/styles.css">
  <script src="/app.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
  <div class="site-shell">
  <header class="site-header"><a class="brand" href="/" aria-label="Jot home">{$brand}</a></header>
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
