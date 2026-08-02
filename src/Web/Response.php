<?php

declare(strict_types=1);

namespace Jot\Web;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }
}
