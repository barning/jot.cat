<?php

declare(strict_types=1);

namespace Jot\Fetch;

final class FetchResult
{
    public function __construct(
        public string $url,
        public string $body,
        public string $contentType,
    ) {
    }
}
