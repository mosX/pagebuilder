<?php

use App\Enums\TenantRole;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->tenant = Tenant::factory()->create(['name' => 'Acme']);
    $this->owner = memberOf($this->tenant);
});

/**
 * Invites an address and returns the token from the e-mailed link.
 */
function invite(string $email, string $role = 'editor'): string
{
    test()->actingAs(test()->owner)
        ->postJson(route('api.members.store'), ['email' => $email, 'role' => $role])
        ->assertCreated();

    $url = null;
    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($email, &$url) {
        $url = $mail->url;

        return $mail->hasTo($email);
    });

    Auth::forgetGuards();

    return basename((string) $url);
}

test('inviting creates no account and stores only a hash of the token', function () {
    $token = invite('new@example.com');

    expect(User::where('email', 'new@example.com')->exists())->toBeFalse();

    $invitation = Invitation::withoutGlobalScope('tenant')->sole();
    expect($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->role)->toBe(TenantRole::Editor);

    $this->actingAs($this->owner)->getJson(route('api.members.index'))
        ->assertJsonPath('invitations.0.email', 'new@example.com');
});

test('a new person accepts by creating an account for the invited address', function () {
    $token = invite('new@example.com');

    $this->getJson(route('api.invitations.show', $token))
        ->assertOk()
        ->assertJsonPath('company', 'Acme')
        ->assertJsonPath('account_exists', false);

    $this->postJson(route('api.invitations.accept', $token), [
        'name' => 'Newbie',
        'password' => 'password',
        'password_confirmation' => 'password',
        // The address always comes from the invitation.
        'email' => 'attacker@example.com',
    ])->assertSuccessful()->assertJsonPath('user.email', 'new@example.com');

    $user = User::where('email', 'new@example.com')->sole();
    expect($user->roleIn($this->tenant))->toBe(TenantRole::Editor)
        ->and($user->current_tenant_id)->toBe($this->tenant->id)
        ->and(Invitation::withoutGlobalScope('tenant')->count())->toBe(0);

    // The link works once.
    $this->getJson(route('api.invitations.show', $token))->assertNotFound();
});

test('an existing account must sign in with the invited address to accept', function () {
    $existing = User::factory()->create(['email' => 'colleague@example.com']);
    $token = invite('colleague@example.com', 'viewer');

    // Nobody can take over the existing account by accepting as a guest.
    $this->postJson(route('api.invitations.accept', $token), ['name' => 'X', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertJsonValidationErrors('invitation');

    // Another account cannot use the link.
    $this->actingAs(User::factory()->create())->postJson(route('api.invitations.accept', $token))
        ->assertJsonValidationErrors('invitation');
    Auth::forgetGuards();

    expect($existing->roleIn($this->tenant))->toBeNull();

    $this->actingAs($existing)->postJson(route('api.invitations.accept', $token))->assertOk();
    expect($existing->roleIn($this->tenant))->toBe(TenantRole::Viewer);
});

test('expired, revoked and re-sent links stop working', function () {
    $first = invite('new@example.com');
    $this->actingAs($this->owner)
        ->postJson(route('api.member-invitations.resend', Invitation::withoutGlobalScope('tenant')->sole()))
        ->assertOk();
    Auth::forgetGuards();
    $this->getJson(route('api.invitations.show', $first))->assertNotFound();

    $token = invite('other@example.com');
    $this->travel(Invitation::VALID_DAYS + 1)->days();
    $this->postJson(route('api.invitations.accept', $token), ['name' => 'X', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertJsonValidationErrors('invitation');

    $this->actingAs($this->owner)
        ->deleteJson(route('api.member-invitations.destroy', Invitation::withoutGlobalScope('tenant')->where('email', 'other@example.com')->sole()))
        ->assertOk();
    Auth::forgetGuards();
    $this->getJson(route('api.invitations.show', $token))->assertNotFound();
});

test('members are not invited twice and invitations stay in their company', function () {
    $colleague = memberOf($this->tenant, TenantRole::Viewer);

    $this->actingAs($this->owner)->postJson(route('api.members.store'), ['email' => $colleague->email, 'role' => 'viewer'])
        ->assertJsonValidationErrors('email');

    invite('new@example.com');
    $other = Tenant::factory()->create();
    $this->actingAs(memberOf($other))
        ->deleteJson(route('api.member-invitations.destroy', Invitation::withoutGlobalScope('tenant')->sole()))
        ->assertNotFound();
});
