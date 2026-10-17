<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\ActivityLog;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Accepting an invitation by its link. Only the owner of the invited address can accept:
 * a signed-in user with that e-mail, or a new account created for it right here
 * (whoever has the link has received the e-mail, which confirms the address).
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        $invitation = $this->find($token);
        $tenant = Tenant::findOrFail($invitation->tenant_id);
        $user = $request->user();

        return response()->json([
            'company' => $tenant->name,
            'email' => $invitation->email,
            'role' => $invitation->role->label(),
            'invited_by' => $invitation->inviter?->name,
            'expired' => $invitation->isExpired(),
            'account_exists' => User::where('email', $invitation->email)->exists(),
            'signed_in_as' => $user instanceof User ? $user->email : null,
        ]);
    }

    public function accept(Request $request, string $token, TenantResolver $resolver, ActivityLog $activity): SessionResource
    {
        $invitation = $this->find($token);
        $tenant = Tenant::where('is_active', true)->findOrFail($invitation->tenant_id);

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['invitation' => 'Срок приглашения истёк. Попросите прислать новое.']);
        }

        $user = $request->user();

        if ($user instanceof User) {
            if (Str::lower($user->email) !== $invitation->email) {
                throw ValidationException::withMessages(['invitation' => "Приглашение отправлено на {$invitation->email}. Войдите под этим адресом."]);
            }
        } else {
            if (User::where('email', $invitation->email)->exists()) {
                throw ValidationException::withMessages(['invitation' => 'Аккаунт с этим e-mail уже есть. Войдите, чтобы принять приглашение.']);
            }

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);

            $user = User::create(['name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password']]);
            // The link came to this address, so it is confirmed.
            $user->forceFill(['email_verified_at' => now()])->save();
            Auth::guard('web')->login($user);

            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        DB::transaction(function () use ($tenant, $user, $invitation) {
            if (! $user->membershipIn($tenant)) {
                $tenant->addMember($user, $invitation->role);
            }

            $user->switchTenant($tenant);
            $invitation->delete();
        });

        $activity->record('member.joined', $user->email, ['role' => $invitation->role->value], tenant: $tenant);

        return new SessionResource($user, $resolver->resolve($user));
    }

    private function find(string $token): Invitation
    {
        return Invitation::findByToken($token) ?? abort(404, 'Приглашение не найдено или уже принято.');
    }
}
