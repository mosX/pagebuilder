<?php

namespace App\Http\Controllers\Api;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\ActivityLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Users of the current company: invitations, role changes, removal.
 */
class MemberController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->context->tenant();
        $actorRole = $this->actorRole();
        $canManage = Gate::allows('members.manage');

        $members = $tenant->members()
            ->orderBy('name')
            ->get()
            ->map(function (User $member) use ($request, $actorRole, $canManage) {
                $role = $this->roleOf($member);

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => ['value' => $role->value, 'label' => $role->label()],
                    'is_self' => $member->id === $request->user()?->id,
                    'can_manage' => $canManage && $member->id !== $request->user()?->id && $actorRole->canManage($role),
                ];
            });

        return response()->json([
            'members' => $members,
            // Open invitations, for those who manage members.
            'invitations' => $canManage ? Invitation::query()->with('inviter')->latest('id')->get()->map(fn (Invitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => ['value' => $invitation->role->value, 'label' => $invitation->role->label()],
                'invited_by' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'expired' => $invitation->isExpired(),
                'can_manage' => $actorRole->canAssign($invitation->role),
            ]) : [],
            'can_manage' => $canManage,
            'roles' => array_values(array_filter(
                TenantRole::options(),
                fn (array $option) => $actorRole->canAssign(TenantRole::from($option['value'])),
            )),
        ]);
    }

    /**
     * Invites a person by e-mail. They join by accepting the link themselves, so an admin
     * can neither attach someone's existing account nor create an account for their address.
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = $this->context->tenant();

        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', Rule::enum(TenantRole::class)],
        ]);

        $role = TenantRole::from($data['role']);
        $this->ensureCanAssign($role);

        if (User::where('email', $data['email'])->first()?->membershipIn($tenant)) {
            throw ValidationException::withMessages(['email' => 'Этот пользователь уже состоит в компании.']);
        }

        // Inviting the same address again renews the invitation.
        $invitation = Invitation::query()->where('email', $data['email'])->first()
            ?? (new Invitation)->forceFill(['email' => $data['email']]);
        $invitation->forceFill(['tenant_id' => $tenant->id, 'role' => $role, 'invited_by' => $request->user()?->getAuthIdentifier()]);
        $token = $invitation->refreshToken();
        $invitation->save();

        $this->sendInvitation($request, $invitation, $token);
        app(ActivityLog::class)->record('member.invited', $invitation->email, ['role' => $role->value]);

        return response()->json(['message' => "Приглашение отправлено на {$invitation->email}."], 201);
    }

    public function resendInvitation(Request $request, Invitation $invitation): JsonResponse
    {
        $this->ensureCanAssign($invitation->role, 'invitation');

        $token = $invitation->refreshToken();
        $invitation->save();
        $this->sendInvitation($request, $invitation, $token);

        return response()->json(['message' => "Приглашение отправлено ещё раз на {$invitation->email}."]);
    }

    public function revokeInvitation(Invitation $invitation): JsonResponse
    {
        $this->ensureCanAssign($invitation->role, 'invitation');

        $invitation->delete();
        app(ActivityLog::class)->record('member.invitation_revoked', $invitation->email);

        return response()->json(['message' => 'Приглашение отозвано.']);
    }

    private function sendInvitation(Request $request, Invitation $invitation, string $token): void
    {
        $user = $request->user();

        Mail::to($invitation->email)->send(new InvitationMail(
            $this->context->tenant()->name,
            $user instanceof User ? $user->name : 'Администратор',
            $invitation->role->label(),
            $invitation->url($token),
            Invitation::VALID_DAYS,
        ));
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $tenant = $this->context->tenant();

        $data = $request->validate([
            'role' => ['required', Rule::enum(TenantRole::class)],
        ]);

        $newRole = TenantRole::from($data['role']);
        $currentRole = $this->ensureCanManage($request, $tenant, $member);
        $this->ensureCanAssign($newRole, 'member');

        if ($currentRole === TenantRole::Owner && $newRole !== TenantRole::Owner) {
            $this->ensureNotLastOwner($tenant);
        }

        $tenant->members()->updateExistingPivot($member->id, ['role' => $newRole->value]);
        app(ActivityLog::class)->record('member.role_changed', $member->email, ['from' => $currentRole->value, 'to' => $newRole->value]);

        return response()->json(['message' => "Роль пользователя {$member->name} изменена."]);
    }

    public function destroy(Request $request, User $member): JsonResponse
    {
        $tenant = $this->context->tenant();

        $currentRole = $this->ensureCanManage($request, $tenant, $member);

        if ($currentRole === TenantRole::Owner) {
            $this->ensureNotLastOwner($tenant);
        }

        DB::transaction(function () use ($tenant, $member) {
            $tenant->members()->detach($member->id);

            if ($member->current_tenant_id === $tenant->id) {
                $member->forceFill(['current_tenant_id' => null])->save();
            }
        });

        app(ActivityLog::class)->record('member.removed', $member->email);

        return response()->json(['message' => "Пользователь {$member->name} удалён из компании."]);
    }

    private function actorRole(): TenantRole
    {
        return $this->context->role() ?? abort(403);
    }

    private function roleOf(User $member): TenantRole
    {
        $membership = $member->membership;

        return $membership instanceof Membership ? $membership->role : TenantRole::Viewer;
    }

    private function ensureCanManage(Request $request, Tenant $tenant, User $member): TenantRole
    {
        $role = $member->membershipIn($tenant)->role ?? abort(404);

        if ($member->id === $request->user()?->id) {
            throw ValidationException::withMessages(['member' => 'Нельзя изменить собственное членство.']);
        }

        if (! $this->actorRole()->canManage($role)) {
            abort(403, 'Недостаточно прав для управления этим пользователем.');
        }

        return $role;
    }

    private function ensureCanAssign(TenantRole $role, string $errorKey = 'role'): void
    {
        if (! $this->actorRole()->canAssign($role)) {
            throw ValidationException::withMessages([$errorKey => 'Вы не можете назначить эту роль.']);
        }
    }

    private function ensureNotLastOwner(Tenant $tenant): void
    {
        if ($tenant->ownersCount() <= 1) {
            throw ValidationException::withMessages(['member' => 'В компании должен остаться хотя бы один владелец.']);
        }
    }
}
