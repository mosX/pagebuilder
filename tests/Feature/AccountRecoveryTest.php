<?php

use App\Mail\AccountLinkMail;
use App\Models\SiteMember;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Site\SiteDesign;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Mail::fake();
    config(['tenancy.site_domain' => 'pagebuilder.test', 'app.url' => 'http://pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);
});

/**
 * The link of the last account e-mail sent to an address.
 */
function mailedLink(string $email): string
{
    $url = '';
    Mail::assertSent(AccountLinkMail::class, function (AccountLinkMail $mail) use ($email, &$url) {
        if ($mail->hasTo($email)) {
            $url = $mail->url;
        }

        return $mail->hasTo($email);
    });

    return $url;
}

// --- Admin users ---

test('admins reset a forgotten password by the e-mailed link', function () {
    $user = User::factory()->create(['email' => 'boss@example.com', 'email_verified_at' => null]);

    $this->postJson(route('api.password.forgot'), ['email' => 'BOSS@example.com'])->assertOk();
    $link = mailedLink('boss@example.com');
    expect($link)->toStartWith('http://pagebuilder.test/reset-password/');

    $token = basename((string) parse_url($link, PHP_URL_PATH));
    $this->postJson(route('api.password.reset'), ['token' => $token, 'email' => 'boss@example.com', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertOk();

    $user->refresh();
    expect(Hash::check('new-password', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    // The link works once.
    $this->postJson(route('api.password.reset'), ['token' => $token, 'email' => 'boss@example.com', 'password' => 'other-password', 'password_confirmation' => 'other-password'])
        ->assertJsonValidationErrors('email');
});

test('the reset form does not tell whether an address is registered', function () {
    $this->postJson(route('api.password.forgot'), ['email' => 'nobody@example.com'])
        ->assertOk()
        ->assertJsonPath('message', 'Если аккаунт с таким адресом есть, мы отправили на него письмо со ссылкой для сброса пароля.');

    Mail::assertNothingSent();
});

test('new admins confirm their e-mail by link', function () {
    $this->postJson(route('api.register'), [
        'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'password_confirmation' => 'password', 'company' => 'Acme', 'template' => 'blank',
    ])->assertSuccessful()->assertJsonPath('user.email_verified', false);

    $link = mailedLink('owner@example.com');
    Auth::forgetGuards();

    // Works without being signed in (e.g. opened on a phone); a changed link does not.
    $this->get(str_replace('http://pagebuilder.test', 'http://localhost', $link).'x')->assertForbidden();
    $this->get(str_replace('http://pagebuilder.test', 'http://localhost', $link))->assertRedirect('/admin?verified=1');

    expect(User::where('email', 'owner@example.com')->sole()->email_verified_at)->not->toBeNull();
});

// --- Site visitors ---

test('site visitors reset their password on the site', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    app(PagesCollection::class)->ensure($tenant);
    app(SiteDesign::class)->for($tenant)->forceFill(['account' => ['enabled' => true]])->save();
    $member = new SiteMember;
    $member->forceFill(['tenant_id' => $tenant->id, 'name' => 'Ivan', 'email' => 'ivan@example.com', 'password' => 'old-password'])->save();

    $this->get('http://acme.pagebuilder.test/account/forgot')->assertOk();
    $this->post('http://acme.pagebuilder.test/account/forgot', ['email' => 'ivan@example.com'])
        ->assertRedirect('http://acme.pagebuilder.test/account/forgot')
        ->assertSessionHas('status');

    $link = mailedLink('ivan@example.com');
    expect($link)->toStartWith('https://acme.pagebuilder.test/account/reset/');
    $path = (string) parse_url($link, PHP_URL_PATH);

    $this->get("http://acme.pagebuilder.test{$path}?email=ivan@example.com")->assertOk();

    // Another address cannot use the token.
    $this->post("http://acme.pagebuilder.test{$path}", ['email' => 'other@example.com', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertSessionHasErrors('email');

    $this->post("http://acme.pagebuilder.test{$path}", ['email' => 'ivan@example.com', 'password' => 'new-password', 'password_confirmation' => 'new-password'])
        ->assertRedirect('http://acme.pagebuilder.test/account');

    expect(Hash::check('new-password', $member->fresh()->password))->toBeTrue()
        ->and(Auth::guard('member')->id())->toBe($member->id);

    // Used once; and an expired link does not work either.
    Auth::guard('member')->logout();
    $this->post("http://acme.pagebuilder.test{$path}", ['email' => 'ivan@example.com', 'password' => 'x-password', 'password_confirmation' => 'x-password'])
        ->assertSessionHasErrors('email');

    $this->post('http://acme.pagebuilder.test/account/forgot', ['email' => 'ivan@example.com']);
    $path = (string) parse_url(mailedLink('ivan@example.com'), PHP_URL_PATH);
    $this->travel(61)->minutes();
    $this->post("http://acme.pagebuilder.test{$path}", ['email' => 'ivan@example.com', 'password' => 'late-password', 'password_confirmation' => 'late-password'])
        ->assertSessionHasErrors('email');
});

// --- Search engines and the 404 page ---

test('sites publish a sitemap and robots.txt; the admin is not indexed', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    $pages = app(PagesCollection::class)->ensure($tenant);
    $repository = new RecordRepository($pages);
    $repository->create(['title' => 'О нас', 'slug' => 'about', 'is_published' => true]);
    $repository->create(['title' => 'Черновик', 'slug' => 'draft', 'is_published' => false]);
    $repository->create(['title' => 'Клуб', 'slug' => 'club', 'is_published' => true, 'members_only' => true]);
    $repository->create(['title' => 'Скоро', 'slug' => 'soon', 'is_published' => true, 'publish_at' => now()->addDay()->toDateTimeString()]);
    app(SiteDesign::class)->for($tenant)->forceFill(['languages' => ['default' => 'ru', 'extra' => ['en']]])->save();

    $xml = $this->get('http://acme.pagebuilder.test/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->getContent();

    expect($xml)->toContain('<loc>https://acme.pagebuilder.test/</loc>')
        ->toContain('<loc>https://acme.pagebuilder.test/about</loc>')
        ->toContain('<loc>https://acme.pagebuilder.test/en/about</loc>')
        ->toContain('hreflang="en" href="https://acme.pagebuilder.test/en/about"')
        ->not->toContain('/draft')
        ->not->toContain('/club')
        ->not->toContain('/soon');

    $this->get('http://acme.pagebuilder.test/robots.txt')
        ->assertOk()
        ->assertSee('Sitemap: https://acme.pagebuilder.test/sitemap.xml')
        ->assertSee('Disallow: /account');

    $this->get('http://pagebuilder.test/robots.txt')->assertOk()->assertSee("Disallow: /\n", false);
});

test('a site may have its own 404 page', function () {
    $tenant = Tenant::factory()->create(['slug' => 'acme']);
    app(PagesCollection::class)->ensure($tenant);
    $owner = memberOf($tenant);

    $this->get('http://acme.pagebuilder.test/missing')->assertNotFound();
    [$request] = Http::recorded()->last();
    expect($request->data()['page'])->toBeNull();

    $this->actingAs($owner)->postJson(route('api.block-versions.publish'), [
        'target' => 'layout:not_found',
        'tree' => [['type' => 'heading', 'props' => ['text' => 'Такой страницы нет']]],
    ])->assertOk();

    $this->get('http://acme.pagebuilder.test/missing')
        ->assertNotFound()
        ->assertSee('<meta name="robots" content="noindex">', false);
    [$request] = Http::recorded()->last();
    expect($request->data()['page']['blocks'][0]['props']['text'])->toBe('Такой страницы нет');
});
