<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteMember;
use App\Support\Audit\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visitors with accounts on the current company's site, for the admin "Посетители" page.
 */
class SiteMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->limit(100, '')->toString());

        $members = SiteMember::query()
            ->when($search !== '', function (Builder $query) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->latest('id')
            ->paginate(25, ['id', 'name', 'email', 'email_verified_at', 'profile', 'is_blocked', 'last_login_at', 'created_at']);

        return response()->json($members);
    }

    public function update(Request $request, SiteMember $siteMember, ActivityLog $activity): JsonResponse
    {
        $data = $request->validate(['is_blocked' => ['required', 'boolean']]);

        $siteMember->forceFill(['is_blocked' => $data['is_blocked']])->save();
        $activity->record($siteMember->is_blocked ? 'site_member.blocked' : 'site_member.unblocked', $siteMember->email);

        return response()->json([
            'message' => $siteMember->is_blocked ? 'Посетитель заблокирован.' : 'Посетитель разблокирован.',
            'member' => $siteMember->only(['id', 'name', 'email', 'email_verified_at', 'is_blocked', 'last_login_at', 'created_at']),
        ]);
    }

    public function destroy(SiteMember $siteMember, ActivityLog $activity): JsonResponse
    {
        $siteMember->delete();
        $activity->record('site_member.deleted', $siteMember->email);

        return response()->json(['message' => 'Аккаунт посетителя удалён.']);
    }
}
