<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SuperAdminSeeder;

test('the seeder creates a super admin who owns the main company', function () {
    $this->seed(SuperAdminSeeder::class);
    $this->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('tenancy.super_admin.email'))->sole();
    $tenant = Tenant::where('slug', 'main')->sole();

    expect($admin->is_super_admin)->toBeTrue()
        ->and($admin->current_tenant_id)->toBe($tenant->id)
        ->and($admin->membershipIn($tenant)?->role)->toBe(TenantRole::Owner);
});

test('regular users cannot access platform management', function () {
    $this->actingAs(memberOf(Tenant::factory()->create()))
        ->getJson(route('api.super.tenants.index'))
        ->assertForbidden();
});

test('super admins see all companies', function () {
    $admin = User::factory()->superAdmin()->create();
    memberOf(Tenant::factory()->create());
    Tenant::factory()->count(2)->create();

    $this->actingAs($admin)
        ->getJson(route('api.super.tenants.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('total', 3);
});

test('super admins can create, update and delete companies', function () {
    $admin = User::factory()->superAdmin()->create();
    memberOf(Tenant::factory()->create());
    $owner = User::factory()->create();

    $this->actingAs($admin)
        ->postJson(route('api.super.tenants.store'), ['name' => 'Client One', 'owner_email' => $owner->email])
        ->assertCreated();

    $tenant = Tenant::where('slug', 'client-one')->sole();
    expect($owner->roleIn($tenant))->toBe(TenantRole::Owner);

    $this->actingAs($admin)
        ->putJson(route('api.super.tenants.update', $tenant), ['name' => 'Client 1', 'slug' => 'client-1', 'is_active' => false])
        ->assertOk();

    expect($tenant->fresh()?->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->deleteJson(route('api.super.tenants.destroy', $tenant))
        ->assertOk();

    expect(Tenant::find($tenant->id))->toBeNull();
});

test('super admins can enter any company with owner rights', function () {
    $admin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create(['is_active' => false]);

    $this->actingAs($admin)
        ->putJson(route('api.tenants.switch', $tenant))
        ->assertOk()
        ->assertJsonPath('tenant.role.value', 'owner');

    $this->actingAs($admin)
        ->getJson(route('api.members.index'))
        ->assertOk()
        ->assertJsonPath('can_manage', true);
});
