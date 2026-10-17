<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            // The e-mail is the login: a stolen session must not be enough to take the account over.
            'current_password' => [Rule::requiredIf($request->input('email') !== $user->email), 'nullable', 'current_password'],
        ], [
            'current_password.required' => 'Чтобы сменить e-mail, введите текущий пароль.',
        ]);

        $user->update(['name' => $data['name'], 'email' => $data['email']]);

        return response()->json([
            'message' => 'Профиль сохранён.',
            'user' => $user->only(['id', 'name', 'email', 'is_super_admin']),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Пароль изменён.']);
    }
}
