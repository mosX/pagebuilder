<?php

namespace App\Http\Controllers\Api;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Site\SiteTemplates;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-based SPA authentication (Sanctum stateful requests).
 * The SPA calls GET /sanctum/csrf-cookie before logging in or registering.
 */
class AuthController extends Controller
{
    public function __construct(
        private TenantResolver $resolver,
        private SiteTemplates $templates,
    ) {}

    /**
     * Starter sites to choose from when registering or creating a company.
     */
    public function templates(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json($this->templates->all($user instanceof User ? $user : null));
    }

    public function me(Request $request): SessionResource
    {
        return $this->session($request->user());
    }

    public function login(Request $request): SessionResource
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey), 'minutes' => 1]),
            ]);
        }

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'email' => 'Неверный email или пароль.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $this->regenerateSession($request);

        return $this->session(Auth::guard('web')->user());
    }

    /**
     * Registers a user together with their own company, in which they become the owner.
     */
    public function register(Request $request): SessionResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'company' => ['required', 'string', 'max:255'],
            'template' => ['nullable', Rule::in($this->templates->keys())],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $tenant = Tenant::create(['name' => $data['company']]);
            $tenant->addMember($user, TenantRole::Owner);
            $user->switchTenant($tenant);

            return $user;
        });

        // Creates tables, so it runs outside the transaction (DDL commits implicitly in MySQL).
        if ($user->currentTenant) {
            $this->templates->apply($user->currentTenant, $data['template'] ?? SiteTemplates::BLANK);
        }

        Auth::guard('web')->login($user);
        $this->regenerateSession($request);

        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);
        }

        return $this->session($user);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Вы вышли из системы.']);
    }

    private function session(mixed $user): SessionResource
    {
        abort_unless($user instanceof User, 401);

        return new SessionResource($user, $this->resolver->resolve($user));
    }

    private function regenerateSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
    }
}
