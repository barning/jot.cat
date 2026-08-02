<?php

declare(strict_types=1);

namespace Jot\Web;

final class PageMetadata
{
    public function __construct(
        public readonly string $canonical,
        public readonly string $title = Copy::META_TITLE,
        public readonly string $description = Copy::HOME_SUPPORT,
        public readonly string $image = '',
    ) {
    }
}
