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
    public const SAVING_STATUS = 'Saving article…';
    public const IOS_SHORTCUT_URL = 'https://www.icloud.com/shortcuts/59f16d7f5b0241bc8d7365fa18dec43b';
    public const SAVED = 'Saved';
    public const ALREADY_SAVED = 'Already saved';
    public const RESULT_INSTRUCTION = 'Write this code in your notebook.';
    public const GLYPH_LABEL = 'Optional notebook mark';
    public const GLYPH_INSTRUCTION = 'Add this mark beside the code if you like.';
    public const INVALID_URL = 'Enter a complete article URL beginning with http:// or https://.';
    public const EXTRACTION_FAILED = 'Jot couldn’t find readable article text on that page. Try another article URL.';
    public const TIMEOUT = 'The article took too long to respond. Try again in a moment.';
    public const UNKNOWN_CODE = 'This code doesn’t exist. Check the four characters and try again.';
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
