<?php

declare(strict_types=1);

namespace Jot\Article;

final class LengthClassifier
{
    public function prefix(int $wordCount): string
    {
        return match (true) {
            $wordCount < 1000 => 'S',
            $wordCount < 2500 => 'M',
            default => 'L',
        };
    }
}
