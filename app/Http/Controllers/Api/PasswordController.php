<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Password reset and e-mail confirmation of admin users.
 */
class PasswordController extends Controller
{
    /**
     * Sends a reset link. The answer is the same whether the address is registered or not,
     * so the form cannot be used to find out who has an account.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        Password::broker('users')->sendResetLink(['email' => Str::lower($data['email'])]);

        return response()->json(['message' => 'Если аккаунт с таким адресом есть, мы отправили на него письмо со ссылкой для сброса пароля.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('users')->reset(
            ['email' => Str::lower($data['email']), 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    // The link came by e-mail, so the address is confirmed too.
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Ссылка для сброса устарела или уже использована. Запросите новую.']);
        }

        return response()->json(['message' => 'Пароль изменён. Войдите с новым паролем.']);
    }

    public function sendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if (! $user->email_verified_at) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => "Письмо отправлено на {$user->email}."]);
    }

    /**
     * The link from the confirmation e-mail. It works without signing in (on another device),
     * the signature and the hash of the address prove it was sent to that address.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        abort_unless($request->hasValidRelativeSignature(), 403, 'Ссылка устарела или повреждена.');

        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->email), $hash), 403);

        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect('/admin?verified=1');
    }
}
