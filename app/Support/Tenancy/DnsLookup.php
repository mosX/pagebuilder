<?php

namespace App\Support\Tenancy;

/**
 * DNS queries for custom domain checks (replaced with a fake in tests).
 */
class DnsLookup
{
    /**
     * @return list<string> Values of the TXT records of a name.
     */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT) ?: [];

        return array_map(
            fn (array $record) => trim((string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? [])))),
            $records,
        );
    }

    /**
     * @return list<string> IPv4 and IPv6 addresses a host resolves to (following CNAMEs).
     */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
