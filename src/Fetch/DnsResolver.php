<?php

declare(strict_types=1);

namespace Jot\Fetch;

interface DnsResolver
{
    /** @return list<string> */
    public function resolve(string $hostname): array;
}
