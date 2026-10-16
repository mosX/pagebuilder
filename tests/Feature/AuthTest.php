<?php

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

test('guests get 401 from protected endpoints', function () {
    $this->getJson(route('api.me'))->assertUnauthorized();
    $this->getJson(route('api.dashboard'))->assertUnauthorized();
});

test('any non-api path serves the spa shell', function () {
    $this->get('/admin/members')->assertOk()->assertSee('<div id="app">', false);
    $this->get('/login')->assertOk();
});

test('sanctum issues the csrf cookie', function () {
    $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
});

test('users can log in with valid credentials', function () {
    $tenant = Tenant::factory()->create();
    $user = memberOf($tenant);

    $this->postJson(route('api.login'), ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('tenant.current.id', $tenant->id)
        ->assertJsonPath('tenant.role.value', 'owner');

    $this->assertAuthenticatedAs($user);
});

test('users cannot log in with a wrong password', function () {
    $user = User::factory()->create();

    $this->postJson(route('api.login'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest();
});

test('registration creates a user with their own company as owner', function () {
    $this->postJson(route('api.register'), [
        'company' => 'Acme Studio',
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertCreated()->assertJsonPath('tenant.current.slug', 'acme-studio');

    $user = User::where('email', 'jane@example.com')->firstOrFail();
    $tenant = Tenant::where('slug', 'acme-studio')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->current_tenant_id)->toBe($tenant->id)
        ->and($user->roleIn($tenant))->toBe(TenantRole::Owner);
});

test('users can log out', function () {
    $user = memberOf(Tenant::factory()->create());

    $this->actingAs($user)->postJson(route('api.logout'))->assertOk();

    $this->assertGuest('web');
});

test('profile and password can be updated', function () {
    $user = memberOf(Tenant::factory()->create());

    $this->actingAs($user)
        ->putJson(route('api.profile.update'), ['name' => 'Renamed', 'email' => 'renamed@example.com'])
        ->assertOk()
        ->assertJsonPath('user.name', 'Renamed');

    $this->actingAs($user)
        ->putJson(route('api.profile.password'), [
            'current_password' => 'wrong',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertJsonValidationErrors('current_password');

    $this->actingAs($user)
        ->putJson(route('api.profile.password'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();
});
