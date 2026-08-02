<?php

declare(strict_types=1);

namespace Jot\Fetch;

final class NativeDnsResolver implements DnsResolver
{
    public function resolve(string $hostname): array
    {
        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return [$hostname];
        }

        $records = dns_get_record($hostname, DNS_A | DNS_AAAA);
        $addresses = [];

        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;

                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
