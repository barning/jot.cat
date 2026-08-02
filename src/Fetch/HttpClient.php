<?php

declare(strict_types=1);

namespace Jot\Fetch;

interface HttpClient
{
    public function get(string $url): FetchResult;
}
