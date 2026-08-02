<?php

declare(strict_types=1);

namespace Jot\Web;

final class Copy
{
    public const BRAND = 'jot.cat';
    public const TAGLINE = 'Write the web down.';
    public const HOME_TITLE = 'Jot down links.';
    public const HOME_SUPPORT = 'Turn an article URL into a short code you can write in your notebook.';
    public const HOME_EYEBROW = 'A short code for every article';
    public const URL_LABEL = 'Article URL';
    public const URL_PLACEHOLDER = 'https://example.com/article';
    public const SAVE_ACTION = 'Save article';
    public const SAVING_ACTION = 'Saving…';
    public const SAVED = 'Saved';
    public const ALREADY_SAVED = 'Already saved';
    public const INVALID_URL = 'This does not look like a valid article URL.';
    public const EXTRACTION_FAILED = 'The article could not be read.';
    public const TIMEOUT = 'The page took too long to respond.';
    public const UNKNOWN_CODE = 'This code does not exist.';
    public const UNEXPECTED = 'Jot could not finish that request. Try again.';
}
