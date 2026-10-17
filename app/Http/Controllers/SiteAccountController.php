<?php

namespace App\Http\Controllers;

use App\Mail\SiteMemberVerificationMail;
use App\Models\SiteMember;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteAccounts;
use App\Support\Site\SiteRenderer;
use App\Support\Site\SiteResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Accounts of site visitors (not admin users): sign-up, sign-in and a personal area
 * with the visitor's own form submissions. Plain HTML forms with CSRF protection,
 * so they work without JavaScript.
 */
class SiteAccountController extends Controller
{
    public const MAX_LOGIN_ATTEMPTS = 5;

    public function __construct(
        private SiteResolver $sites,
        private SiteRenderer $renderer,
        private SiteAccounts $accounts,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $site = $this->site($request);

        if (! $site->member) {
            return redirect($site->absolute($site->url('account/login')));
        }

        return $this->page($request, $site, 'Личный кабинет', 'account', [
            'view' => 'cabinet',
            'member' => [
                'name' => $site->member->name,
                'email' => $site->member->email,
                'verified' => $site->member->email_verified_at !== null,
            ],
            'submissions' => $this->accounts->submissions($site->tenant, $site->settings, $site->member),
            'actions' => [
                'logout' => $site->url('account/logout'),
                'profile' => $site->url('account/profile'),
                'resend' => $site->url('account/verify'),
            ],
        ]);
    }

    public function loginForm(Request $request): Response|RedirectResponse
    {
        $site = $this->site($request);

        if ($site->member) {
            return redirect($site->absolute($site->url('account')));
        }

        return $this->page($request, $site, 'Вход', 'account/login', [
            'view' => 'login',
            'actions' => [
                'login' => $site->url('account/login'),
                'register' => $site->account['registration'] ? $site->url('account/register') : null,
            ],
            'redirect' => $this->safeRedirect($site, $request->query('redirect')),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $site = $this->site($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = 'site-login:'.$site->tenant->id.'|'.Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_LOGIN_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Слишком много попыток входа. Попробуйте через '.RateLimiter::availableIn($key).' сек.',
            ]);
        }

        $member = SiteMember::query()->where('email', Str::lower($data['email']))->first();

        if (! $member || ! Hash::check($data['password'], $member->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => 'Неверный e-mail или пароль.']);
        }

        if ($member->is_blocked) {
            throw ValidationException::withMessages(['email' => 'Доступ к аккаунту закрыт.']);
        }

        RateLimiter::clear($key);
        $this->signIn($request, $member, $request->boolean('remember'));

        return redirect($site->absolute($this->safeRedirect($site, $request->input('redirect')) ?? $site->url('account')));
    }

    public function registerForm(Request $request): Response|RedirectResponse
    {
        $site = $this->site($request);

        abort_unless($site->account['registration'], 404);

        if ($site->member) {
            return redirect($site->absolute($site->url('account')));
        }

        return $this->page($request, $site, 'Регистрация', 'account/register', [
            'view' => 'register',
            'actions' => [
                'register' => $site->url('account/register'),
                'login' => $site->url('account/login'),
            ],
        ]);
    }

    public function register(Request $request): RedirectResponse
    {
        $site = $this->site($request);

        abort_unless($site->account['registration'], 404);

        $request->merge(['email' => Str::lower(trim($request->string('email')->toString()))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('site_members', 'email')->where('tenant_id', $site->tenant->id),
            ],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [
            'email.unique' => 'Этот e-mail уже зарегистрирован. Войдите в аккаунт.',
        ]);

        $member = new SiteMember;
        $member->forceFill([
            'tenant_id' => $site->tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ])->save();

        $this->sendVerification($site, $member);
        $this->signIn($request, $member);

        return redirect($site->absolute($site->url('account')))->with('status', 'Аккаунт создан. Мы отправили письмо для подтверждения e-mail.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $site = $this->site($request);

        // Only the visitor session ends: on the preview path the same browser may be signed in to the admin.
        Auth::guard('member')->logout();
        $request->session()->regenerateToken();

        return redirect($site->absolute($site->url()));
    }

    public function profile(Request $request): RedirectResponse
    {
        $site = $this->site($request);
        $member = $site->member;

        abort_unless($member !== null, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'string', 'confirmed', Password::min(8)],
        ]);

        if (! empty($data['password'])) {
            if (! Hash::check((string) $data['current_password'], $member->password)) {
                throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно.']);
            }

            $member->password = $data['password'];
        }

        $member->name = $data['name'];
        $member->save();

        return redirect($site->absolute($site->url('account')))->with('status', 'Профиль сохранён.');
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        $site = $this->site($request);

        abort_unless($site->member !== null, 403);

        if (! $site->member->email_verified_at) {
            $this->sendVerification($site, $site->member);
        }

        return redirect($site->absolute($site->url('account')))->with('status', 'Письмо для подтверждения отправлено ещё раз.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $site = $this->site($request);

        abort_unless($request->hasValidSignature(), 403, 'Ссылка устарела или повреждена.');

        $member = SiteMember::query()->findOrFail((int) $request->route('member'));

        abort_unless(hash_equals(sha1($member->email), (string) $request->route('hash')), 403);

        if (! $member->email_verified_at) {
            $member->forceFill(['email_verified_at' => now()])->save();
        }

        return redirect($site->absolute($site->url('account')))->with('status', 'E-mail подтверждён.');
    }

    /**
     * The account area exists only when the company turned it on.
     */
    private function site(Request $request): CurrentSite
    {
        $site = $this->sites->resolve($request);

        abort_unless($site->account['enabled'], 404);

        return $site;
    }

    private function signIn(Request $request, SiteMember $member, bool $remember = false): void
    {
        Auth::guard('member')->login($member, $remember);
        $request->session()->regenerate();
        $member->forceFill(['last_login_at' => now()])->save();
    }

    private function sendVerification(CurrentSite $site, SiteMember $member): void
    {
        // The link always leads to the company subdomain, also for sign-ups on the preview path.
        $url = URL::temporarySignedRoute('site.host.account.verify', now()->addDays(3), [
            'site' => $site->tenant->slug,
            'member' => $member->id,
            'hash' => sha1($member->email),
        ]);

        try {
            Mail::to($member->email)->send(new SiteMemberVerificationMail($site->tenant->name, $member->name, $url));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Only paths on this site are followed after sign-in, never other hosts.
     */
    private function safeRedirect(CurrentSite $site, mixed $url): ?string
    {
        if (! is_string($url) || ! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\')) {
            return null;
        }

        return $site->rootUrl === '' || $url === $site->rootUrl || str_starts_with($url, $site->rootUrl.'/') ? $url : null;
    }

    /**
     * A service page made of the account block, with the form state of the previous request.
     *
     * @param  array<string, mixed>  $data
     */
    private function page(Request $request, CurrentSite $site, string $title, string $path, array $data): Response
    {
        $errors = $request->session()->get('errors');
        $old = $request->session()->getOldInput();

        return $this->renderer->render($site, [
            'title' => $title,
            'slug' => $path,
            'description' => null,
            'blocks' => [[
                'id' => 'account',
                'type' => 'account',
                'props' => (object) [],
                'data' => [
                    ...$data,
                    'csrf' => csrf_token(),
                    'status' => $request->session()->get('status'),
                    'errors' => (object) ($errors instanceof ViewErrorBag
                        ? array_map(fn (array $messages) => (string) ($messages[0] ?? ''), $errors->getBag('default')->toArray())
                        : []),
                    'old' => (object) array_intersect_key(is_array($old) ? $old : [], array_flip(['name', 'email'])),
                ],
            ]],
        ], $path, indexable: false);
    }
}
