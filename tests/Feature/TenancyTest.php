<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

test('users without a company get no_tenant and an empty session tenant', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson(route('api.dashboard'))
        ->assertConflict()
        ->assertJsonPath('code', 'no_tenant');

    $this->actingAs($user)->getJson(route('api.me'))->assertOk()->assertJsonPath('tenant', null);
});

test('onboarding creates a company owned by the user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.tenants.create'), ['name' => 'New Co'])
        ->assertOk()
        ->assertJsonPath('tenant.current.name', 'New Co');

    $tenant = Tenant::where('name', 'New Co')->firstOrFail();
    expect($user->fresh()?->roleIn($tenant))->toBe(TenantRole::Owner);
});

test('the session exposes current tenant, role and permissions', function () {
    $tenant = Tenant::factory()->create();
    $user = memberOf($tenant, TenantRole::Editor);

    $permissions = $this->actingAs($user)
        ->getJson(route('api.me'))
        ->assertOk()
        ->assertJsonPath('tenant.current.id', $tenant->id)
        ->assertJsonPath('tenant.role.value', 'editor')
        ->json('tenant.permissions');

    expect($permissions)->toContain('content.manage')->not->toContain('members.manage');
});

test('a stale current tenant falls back to a company the user belongs to', function () {
    $own = Tenant::factory()->create();
    $foreign = Tenant::factory()->create();
    $user = memberOf($own);
    $user->forceFill(['current_tenant_id' => $foreign->id])->save();

    $this->actingAs($user)->getJson(route('api.dashboard'))->assertOk();

    expect($user->fresh()?->current_tenant_id)->toBe($own->id);
});

test('users can switch between their companies', function () {
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();
    $user = memberOf($first);
    $second->addMember($user, TenantRole::Viewer);

    $this->actingAs($user)
        ->putJson(route('api.tenants.switch', $second))
        ->assertOk()
        ->assertJsonPath('tenant.current.id', $second->id)
        ->assertJsonPath('tenant.role.value', 'viewer');

    expect($user->fresh()?->current_tenant_id)->toBe($second->id);
});

test('users cannot switch into a company they do not belong to', function () {
    $user = memberOf(Tenant::factory()->create());

    $this->actingAs($user)
        ->putJson(route('api.tenants.switch', Tenant::factory()->create()))
        ->assertForbidden();
});

test('inactive companies are not accessible to their members', function () {
    $user = memberOf(Tenant::factory()->create(['is_active' => false]));

    $this->actingAs($user)->getJson(route('api.dashboard'))->assertConflict();
});

test('company settings follow role permissions', function () {
    $tenant = Tenant::factory()->create();
    $payload = ['name' => 'Renamed', 'slug' => 'renamed'];

    $this->actingAs(memberOf($tenant, TenantRole::Viewer))
        ->getJson(route('api.company.show'))
        ->assertOk()
        ->assertJsonPath('can_update', false);

    $this->actingAs(memberOf($tenant, TenantRole::Editor))
        ->putJson(route('api.company.update'), $payload)
        ->assertForbidden();

    $this->actingAs(memberOf($tenant, TenantRole::Admin))
        ->putJson(route('api.company.update'), $payload)
        ->assertOk();

    expect($tenant->fresh()?->slug)->toBe('renamed');
});
