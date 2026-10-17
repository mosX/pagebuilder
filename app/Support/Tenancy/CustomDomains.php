<?php

namespace App\Support\Tenancy;

use App\Models\Domain;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Companies' own domains: normalizing what users type, ownership checks via DNS,
 * and fast lookups of verified hosts for routing.
 *
 * Ownership is proven with a TXT record at _pagebuilder.{host} containing the domain's
 * token. Traffic reaches the platform through a CNAME to the company subdomain (or an
 * A record to the server); that part is reported, not required, so a domain can be
 * verified before it is switched over.
 */
class CustomDomains
{
    public const MAX_PER_TENANT = 5;

    public const TXT_PREFIX = '_pagebuilder';

    private const HOSTS_CACHE = 'custom-domains:hosts';

    /**
     * A host from what users type ("https://Acme.com/about" → "acme.com"), or null when invalid.
     */
    public function normalize(string $input): ?string
    {
        $host = Str::lower(trim($input));
        $host = (string) preg_replace('#^[a-z]+://#', '', $host);
        $host = rtrim((string) Str::before(Str::before($host, '/'), ':'), '.');

        $valid = strlen($host) <= 253
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host)
            && ! $this->isPlatformHost($host);

        return $valid ? $host : null;
    }

    /**
     * The platform's own hosts (admin and company subdomains) can never be custom domains.
     */
    public function isPlatformHost(string $host): bool
    {
        $domain = Subdomains::domain();
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host === $domain
            || str_ends_with($host, '.'.$domain)
            || (is_string($appHost) && $host === Str::lower($appHost));
    }

    /**
     * Route requirement of {host} for own domains: a name with a letter top-level domain
     * (so neither localhost nor IP addresses), except the platform's own hosts.
     */
    public function routePattern(): string
    {
        $excluded = ['(?:[a-z0-9-]+\.)*'.preg_quote(Subdomains::domain())];
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($appHost) && $appHost !== '') {
            $excluded[] = preg_quote(Str::lower($appHost));
        }

        return '(?!(?:'.implode('|', $excluded).')$)(?:[a-z0-9-]+\.)+[a-z][a-z0-9-]*';
    }

    public function txtName(Domain $domain): string
    {
        return self::TXT_PREFIX.'.'.$domain->host;
    }

    /**
     * Checks the TXT record and marks the domain verified. The first verified domain becomes primary.
     *
     * @return array{verified: bool, points_here: bool}
     */
    public function verify(Domain $domain, DnsLookup $dns): array
    {
        $verified = in_array($domain->verification_token, $dns->txt($this->txtName($domain)), true);
        $pointsHere = $this->pointsHere($domain, $dns);

        $domain->forceFill([
            'checked_at' => now(),
            'verified_at' => $verified ? ($domain->verified_at ?? now()) : null,
        ]);

        if ($verified && ! Domain::query()->where('tenant_id', $domain->tenant_id)->where('is_primary', true)->whereKeyNot($domain->id)->exists()) {
            $domain->is_primary = true;
        }

        if (! $verified) {
            $domain->is_primary = false;
        }

        $domain->save();
        $this->forget();

        return ['verified' => $verified, 'points_here' => $pointsHere];
    }

    /**
     * Whether the domain resolves to the same addresses as the company subdomain.
     */
    public function pointsHere(Domain $domain, DnsLookup $dns): bool
    {
        $tenant = Tenant::find($domain->tenant_id);
        $target = $tenant ? $tenant->slug.'.'.Subdomains::domain() : null;
        $ours = $target ? $dns->addresses($target) : [];

        return $ours !== [] && array_intersect($dns->addresses($domain->host), $ours) !== [];
    }

    /**
     * The verified domain for a request host.
     */
    public function find(string $host): ?Domain
    {
        $host = Str::lower($host);

        if (! in_array($host, $this->verifiedHosts(), true)) {
            return null;
        }

        return Domain::withoutGlobalScope('tenant')->where('host', $host)->whereNotNull('verified_at')->first();
    }

    /**
     * The canonical domain of a company's site, if it has one.
     */
    public function primaryOf(Tenant $tenant): ?string
    {
        $primary = $this->primaryHosts()[$tenant->id] ?? null;

        return is_string($primary) ? $primary : null;
    }

    /**
     * @return list<string>
     */
    public function verifiedHosts(): array
    {
        return $this->cached()['hosts'];
    }

    public function forget(): void
    {
        Cache::forget(self::HOSTS_CACHE);
    }

    /**
     * @return array<int, string> tenant id => host
     */
    private function primaryHosts(): array
    {
        return $this->cached()['primary'];
    }

    /**
     * Verified hosts are read on every request (routing, trusted hosts): cached until a domain changes.
     *
     * @return array{hosts: list<string>, primary: array<int, string>}
     */
    private function cached(): array
    {
        /** @var array{hosts: list<string>, primary: array<int, string>} $data */
        $data = Cache::rememberForever(self::HOSTS_CACHE, function () {
            $domains = Domain::withoutGlobalScope('tenant')->whereNotNull('verified_at')->get(['tenant_id', 'host', 'is_primary']);

            return [
                'hosts' => $domains->pluck('host')->map(fn (mixed $host) => (string) $host)->values()->all(),
                'primary' => $domains->where('is_primary', true)->mapWithKeys(fn (Domain $d) => [$d->tenant_id => $d->host])->all(),
            ];
        });

        return $data;
    }
}
