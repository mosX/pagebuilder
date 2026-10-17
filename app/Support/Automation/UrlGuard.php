<?php

namespace App\Support\Automation;

use RuntimeException;

/**
 * Protects outgoing requests (webhooks) from reaching internal networks (SSRF).
 *
 * Only http(s) URLs whose host resolves to public addresses are allowed. The check runs
 * right before sending and returns cURL options that pin the request to the checked
 * address, so a DNS change between the check and the request cannot bypass it.
 */
class UrlGuard
{
    /**
     * @return array<int, mixed> cURL options for the request (address pinning).
     */
    public function assertPublic(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Адрес должен начинаться с http:// или https://.');
        }

        if (config('webhooks.allow_private')) {
            return [];
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $ips = $literal ? [$host] : $this->resolve($host);

        if ($ips === []) {
            throw new RuntimeException("Не удалось найти адрес сервера {$host}.");
        }

        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                throw new RuntimeException('Запросы во внутренние сети запрещены.');
            }
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $literal ? [] : [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]];
    }

    public function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) is checked as the IPv4 address it carries.
            if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
                return $this->isPublic((string) inet_ntop(substr($binary, 12)));
            }

            // Prefixes that route to an embedded IPv4 address are never allowed:
            // IPv4-compatible ::/96, NAT64 64:ff9b::/96, 6to4 2002::/16, Teredo 2001::/32.
            if (str_starts_with($binary, str_repeat("\0", 12))
                || str_starts_with($binary, "\x00\x64\xff\x9b".str_repeat("\0", 8))
                || str_starts_with($binary, "\x20\x02")
                || str_starts_with($binary, "\x20\x01\x00\x00")) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            // Carrier-grade NAT (100.64.0.0/10) is not public either.
            && ! (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000));
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }
}
