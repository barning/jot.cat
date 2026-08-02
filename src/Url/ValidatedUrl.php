<?php

declare(strict_types=1);

namespace Jot\Url;

final class ValidatedUrl
{
    public function __construct(
        public string $value,
        public string $scheme,
        public string $host,
        public int $port,
    ) {
    }
}
