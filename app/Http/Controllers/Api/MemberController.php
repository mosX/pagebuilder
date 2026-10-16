<?php

namespace App\Http\Controllers\Api;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Users of the current company: invite (attach or create), change role, remove.
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
            'can_manage' => $canManage,
            'roles' => array_values(array_filter(
                TenantRole::options(),
                fn (array $option) => $actorRole->canAssign(TenantRole::from($option['value'])),
            )),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $this->context->tenant();

        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', Rule::enum(TenantRole::class)],
        ]);

        $role = TenantRole::from($data['role']);
        $this->ensureCanAssign($role);

        $user = User::where('email', $data['email'])->first();

        if ($user && $user->membershipIn($tenant)) {
            throw ValidationException::withMessages(['email' => 'Этот пользователь уже состоит в компании.']);
        }

        if (! $user) {
            $extra = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
            ]);

            $user = User::create([
                'name' => $extra['name'],
                'email' => $data['email'],
                'password' => $extra['password'],
            ]);
        }

        $tenant->addMember($user, $role);

        return response()->json(['message' => "Пользователь {$user->email} добавлен в компанию."], 201);
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
