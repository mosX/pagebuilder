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
        // IPv4 wrapped in IPv6 (::ffff:127.0.0.1) is checked as IPv4.
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $mapped)) {
            $ip = $mapped[1];
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
