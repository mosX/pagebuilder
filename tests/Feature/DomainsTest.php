<?php

use App\Models\Domain;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Tenancy\CustomDomains;
use App\Support\Tenancy\DnsLookup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $this->owner = memberOf($this->tenant);
    app(PagesCollection::class)->ensure($this->tenant);

    // DNS as the company set it up: TXT records and where hosts point.
    $this->dns = new class extends DnsLookup
    {
        /** @var array<string, list<string>> */
        public array $txt = [];

        /** @var array<string, list<string>> */
        public array $ips = ['acme.pagebuilder.test' => ['203.0.113.10']];

        public function txt(string $name): array
        {
            return $this->txt[$name] ?? [];
        }

        public function addresses(string $host): array
        {
            return $this->ips[$host] ?? [];
        }
    };
    $this->app->instance(DnsLookup::class, $this->dns);
});

/**
 * Adds a domain in the admin and publishes its TXT record (and a CNAME) in the fake DNS.
 */
function connectDomain(string $host, bool $verify = true): Domain
{
    $id = test()->actingAs(test()->owner)->postJson(route('api.domains.store'), ['host' => $host])
        ->assertCreated()
        ->json('domain.id');
    $domain = Domain::withoutGlobalScope('tenant')->findOrFail($id);

    if ($verify) {
        test()->dns->txt["_pagebuilder.{$host}"] = [$domain->verification_token];
        test()->dns->ips[$host] = ['203.0.113.10'];
        test()->actingAs(test()->owner)->postJson(route('api.domains.verify', $domain))
            ->assertOk()
            ->assertJsonPath('domain.points_here', true);
    }

    return $domain->fresh() ?? $domain;
}

test('domains typed in any form are normalized and platform hosts are refused', function (string $input, ?string $host) {
    expect(app(CustomDomains::class)->normalize($input))->toBe($host);
})->with([
    ['https://Acme.com/about', 'acme.com'],
    ['www.acme.co.uk:443', 'www.acme.co.uk'],
    ['acme.com.', 'acme.com'],
    ['localhost', null],
    ['127.0.0.1', null],
    ['acme.pagebuilder.test', null],
    ['pagebuilder.test', null],
    ['-bad-.com', null],
    ['acme', null],
]);

test('a verified domain serves the site and becomes its address', function () {
    $this->get('http://acme.com/')->assertNotFound();

    $domain = connectDomain('acme.com');
    expect($domain->is_primary)->toBeTrue()
        ->and($this->tenant->fresh()->siteUrl())->toBe('https://acme.com');

    $this->get('http://acme.com/')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://acme.com/">', false);

    // The subdomain moves to the domain; the preview path stays.
    $this->get('http://acme.pagebuilder.test/about?x=1')->assertRedirect('https://acme.com/about?x=1')->assertStatus(301);
    $this->get('http://localhost/site/acme')->assertOk();
});

test('ownership must be proven before a domain serves anything', function () {
    $domain = connectDomain('acme.com', verify: false);

    $this->actingAs($this->owner)->postJson(route('api.domains.verify', $domain))
        ->assertUnprocessable()
        ->assertJsonPath('verified', false);

    // A wrong token does not count either.
    $this->dns->txt['_pagebuilder.acme.com'] = ['pagebuilder-verify=someone-else'];
    $this->actingAs($this->owner)->postJson(route('api.domains.verify', $domain))->assertUnprocessable();

    $this->get('http://acme.com/')->assertNotFound();
    $this->get('http://acme.pagebuilder.test/')->assertOk();
});

test('secondary domains redirect to the primary one', function () {
    connectDomain('acme.com');
    $www = connectDomain('www.acme.com');

    expect($www->is_primary)->toBeFalse();
    $this->get('http://www.acme.com/about')->assertRedirect('https://acme.com/about');

    $this->actingAs($this->owner)->postJson(route('api.domains.primary', $www))->assertOk();
    $this->get('http://acme.com/about')->assertRedirect('https://www.acme.com/about');

    // Removing the primary hands the role to the remaining verified domain.
    $this->actingAs($this->owner)->deleteJson(route('api.domains.destroy', $www))->assertOk();
    expect($this->tenant->fresh()->siteUrl())->toBe('https://acme.com');
    $this->get('http://www.acme.com/')->assertNotFound();
});

test('a host belongs to one company only', function () {
    connectDomain('acme.com');

    $other = Tenant::factory()->create();
    $this->actingAs(memberOf($other))->postJson(route('api.domains.store'), ['host' => 'ACME.com'])
        ->assertJsonValidationErrors('host');
    $this->actingAs(memberOf($other))->postJson(route('api.domains.store'), ['host' => 'acme.pagebuilder.test'])
        ->assertJsonValidationErrors('host');
    $this->actingAs(memberOf($other))->deleteJson(route('api.domains.destroy', Domain::withoutGlobalScope('tenant')->sole()))
        ->assertNotFound();
});

test('the admin and platform hosts are never taken for own domains', function () {
    $this->get('http://pagebuilder.test/login')->assertOk()->assertSee('<div id="app"></div>', false);
    $this->get('http://localhost/login')->assertOk()->assertSee('<div id="app"></div>', false);
});

test('the tls proxy may issue certificates only for verified domains', function () {
    connectDomain('acme.com');
    connectDomain('unverified.com', verify: false);

    $this->get('http://localhost/internal/tls-check?domain=acme.com')->assertOk();
    $this->get('http://localhost/internal/tls-check?domain=unverified.com')->assertNotFound();
    $this->get('http://localhost/internal/tls-check?domain=evil.com')->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->get('http://localhost/internal/tls-check?domain=acme.com')
        ->assertForbidden();
});
