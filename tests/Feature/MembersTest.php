<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

test('members list shows only the current company members', function () {
    $owner = memberOf($this->tenant);
    $colleague = memberOf($this->tenant, TenantRole::Editor);
    memberOf(Tenant::factory()->create());

    $ids = $this->actingAs($owner)
        ->getJson(route('api.members.index'))
        ->assertOk()
        ->assertJsonCount(2, 'members')
        ->json('members.*.id');

    expect($ids)->toEqualCanonicalizing([$owner->id, $colleague->id]);
});

test('viewers cannot see the members list', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Viewer))
        ->getJson(route('api.members.index'))
        ->assertForbidden();
});

test('admins cannot invite with the owner role', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Admin))
        ->postJson(route('api.members.store'), ['email' => 'boss@example.com', 'role' => 'owner'])
        ->assertJsonValidationErrors('role');
});

test('editors cannot manage members', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Editor))
        ->postJson(route('api.members.store'), ['email' => 'x@example.com', 'role' => 'viewer'])
        ->assertForbidden();
});

test('owners can change roles', function () {
    $member = memberOf($this->tenant, TenantRole::Viewer);

    $this->actingAs(memberOf($this->tenant))
        ->putJson(route('api.members.update', $member), ['role' => 'admin'])
        ->assertOk();

    expect($member->roleIn($this->tenant))->toBe(TenantRole::Admin);
});

test('admins cannot manage other admins', function () {
    $otherAdmin = memberOf($this->tenant, TenantRole::Admin);

    $this->actingAs(memberOf($this->tenant, TenantRole::Admin))
        ->deleteJson(route('api.members.destroy', $otherAdmin))
        ->assertForbidden();
});

test('users cannot change their own membership', function () {
    $owner = memberOf($this->tenant);
    memberOf($this->tenant);

    $this->actingAs($owner)
        ->putJson(route('api.members.update', $owner), ['role' => 'viewer'])
        ->assertJsonValidationErrors('member');
});

test('the last owner cannot be demoted or removed', function () {
    $owner = memberOf($this->tenant);
    $superAdmin = User::factory()->superAdmin()->create(['current_tenant_id' => $this->tenant->id]);

    $this->actingAs($superAdmin)
        ->putJson(route('api.members.update', $owner), ['role' => 'admin'])
        ->assertJsonValidationErrors('member');

    $this->actingAs($superAdmin)
        ->deleteJson(route('api.members.destroy', $owner))
        ->assertJsonValidationErrors('member');

    expect($owner->roleIn($this->tenant))->toBe(TenantRole::Owner);
});

test('removing a member detaches them and clears their current company', function () {
    $member = memberOf($this->tenant, TenantRole::Editor);

    $this->actingAs(memberOf($this->tenant))
        ->deleteJson(route('api.members.destroy', $member))
        ->assertOk();

    expect($member->fresh()?->current_tenant_id)->toBeNull()
        ->and($member->roleIn($this->tenant))->toBeNull();
});

test('members of another company cannot be managed', function () {
    $stranger = memberOf(Tenant::factory()->create(), TenantRole::Viewer);

    $this->actingAs(memberOf($this->tenant))
        ->deleteJson(route('api.members.destroy', $stranger))
        ->assertNotFound();
});
