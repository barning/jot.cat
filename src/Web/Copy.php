<?php

declare(strict_types=1);

namespace Jot\Web;

final class Copy
{
    public const BRAND = 'jot';
    public const TAGLINE = 'Write the web down.';
    public const META_TITLE = 'Jot — write the web down';
    public const HOME_TITLE = 'Write the web down.';
    public const HOME_SUPPORT = 'Turn an article URL into a four-character reference you can write in your notebook.';
    public const HOME_EYEBROW = 'A tiny link tool for paper notebooks';
    public const URL_LABEL = 'Article URL';
    public const URL_PLACEHOLDER = 'https://example.com/article';
    public const SAVE_ACTION = 'Create a jot';
    public const SAVING_ACTION = 'Saving…';
    public const IOS_SHORTCUT_URL = 'https://www.icloud.com/shortcuts/59f16d7f5b0241bc8d7365fa18dec43b';
    public const SAVED = 'Saved';
    public const ALREADY_SAVED = 'Already saved';
    public const INVALID_URL = 'This does not look like a valid article URL.';
    public const EXTRACTION_FAILED = 'The article could not be read.';
    public const TIMEOUT = 'The page took too long to respond.';
    public const UNKNOWN_CODE = 'This code does not exist.';
    public const UNEXPECTED = 'Jot could not finish that request. Try again.';

    public static function lengthLabel(string $prefix): string
    {
        return match ($prefix) {
            'S' => 'S — Short read',
            'M' => 'M — Medium read',
            'L' => 'L — Long read',
            default => '',
        };
    }
}
