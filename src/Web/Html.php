<?php

declare(strict_types=1);

namespace Jot\Web;

final class Html
{
    public static function page(string $title, string $content): string
    {
        $safeTitle = self::escape($title);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>{$safeTitle} · Jot</title>
  <link rel="stylesheet" href="/styles.css">
</head>
<body>
  <main>
    <a class="brand" href="/">jot.cat</a>
    {$content}
  </main>
</body>
</html>
HTML;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
