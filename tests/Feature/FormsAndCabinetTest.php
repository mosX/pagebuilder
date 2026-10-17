<?php

use App\Enums\FieldType;
use App\Mail\AccountLinkMail;
use App\Mail\FormConfirmationMail;
use App\Models\SiteMember;
use App\Models\Tenant;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use App\Support\Site\Forms;
use App\Support\Site\FormUploads;
use App\Support\Site\SiteDesign;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    config(['tenancy.site_domain' => 'pagebuilder.test']);
    URL::forceRootUrl('http://localhost');
    Http::fake(['127.0.0.1:13714/*' => Http::response(['html' => ''])]);

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'name' => 'Acme']);
    $this->owner = memberOf($this->tenant);
    app(PagesCollection::class)->ensure($this->tenant);

    $schema = app(SchemaManager::class);
    $this->jobs = $schema->createCollection($this->tenant, ['name' => 'jobs', 'label' => 'Отклики']);
    $schema->addField($this->jobs, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String, 'is_required' => true]);
    $schema->addField($this->jobs, ['name' => 'email', 'label' => 'Email', 'type' => FieldType::Email]);
    $schema->addField($this->jobs, ['name' => 'phone', 'label' => 'Телефон', 'type' => FieldType::String]);
    $schema->addField($this->jobs, ['name' => 'resume', 'label' => 'Резюме', 'type' => FieldType::File]);
    $schema->addField($this->jobs, ['name' => 'status', 'label' => 'Статус', 'type' => FieldType::Select, 'options' => ['choices' => ['Новый', 'Приглашён']]]);
    $this->jobs = $this->jobs->fresh();
});

/**
 * @param  array<string, mixed>  $options
 */
function jobToken(array $options = []): string
{
    return (string) app(Forms::class)->schema(test()->tenant, test()->jobs, ['name', 'email', 'phone', 'resume'], withToken: true, options: $options)['token'];
}

function realUpload(string $name, string $content): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'form');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

// --- Files ---

test('visitors attach files that stay private and are downloaded from the admin', function () {
    $this->post(route('api.forms.submit'), [
        'token' => jobToken(),
        'values' => ['name' => 'Ivan', 'resume' => realUpload('Резюме Иван.pdf', "%PDF-1.4\n%%EOF")],
    ], ['Accept' => 'application/json'])->assertCreated();

    $record = (new RecordRepository($this->jobs))->firstWhere([['name', 'Ivan']]);
    expect($record['resume'])->toStartWith("private://form-uploads/{$this->tenant->id}/")
        ->and($record['resume'])->toEndWith('/Резюме Иван.pdf');
    Storage::disk('local')->assertExists(app(FormUploads::class)->path($record['resume']));

    $this->actingAs($this->owner)
        ->get(route('api.records.file', ['collection' => 'jobs', 'record' => $record['id'], 'field' => 'resume']))
        ->assertOk()
        // The UTF-8 name for browsers, next to an ASCII fallback.
        ->assertHeader('Content-Disposition', "attachment; filename=\"Reziume Ivan.pdf\"; filename*=utf-8''".rawurlencode('Резюме Иван.pdf'));

    $stranger = memberOf(Tenant::factory()->create());
    $this->actingAs($stranger)
        ->get(route('api.records.file', ['collection' => 'jobs', 'record' => $record['id'], 'field' => 'resume']))
        ->assertNotFound();

    // Deleting the record deletes the file.
    $this->actingAs($this->owner)->deleteJson(route('api.records.destroy', ['collection' => 'jobs', 'record' => $record['id']]))->assertOk();
    Storage::disk('local')->assertMissing(app(FormUploads::class)->path($record['resume']));
});

test('visitors cannot upload pages or point a file field at other files', function () {
    $this->post(route('api.forms.submit'), [
        'token' => jobToken(),
        'values' => ['name' => 'Ivan', 'resume' => realUpload('cv.pdf', '<html><script>alert(1)</script></html>')],
    ], ['Accept' => 'application/json'])->assertJsonValidationErrors('resume');

    $this->postJson(route('api.forms.submit'), [
        'token' => jobToken(),
        'values' => ['name' => 'Petr', 'resume' => 'private://form-uploads/999/2026/10/x/secret.pdf'],
    ])->assertCreated();
    expect((new RecordRepository($this->jobs))->firstWhere([['name', 'Petr']])['resume'])->toBeNull();

    // Through the admin API another company's file cannot be referenced either.
    $id = (new RecordRepository($this->jobs))->firstWhere([['name', 'Petr']])['id'];
    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', ['collection' => 'jobs', 'record' => $id]), ['resume' => 'private://form-uploads/999/x/secret.pdf'])
        ->assertJsonValidationErrors('resume');
    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', ['collection' => 'jobs', 'record' => $id]), ['resume' => '/storage/tenants/1/price.pdf'])
        ->assertOk();
});

// --- Captcha ---

test('forms with a captcha accept only verified submissions', function () {
    config(['captcha.provider' => 'turnstile', 'captcha.site_key' => 'site-key', 'captcha.secret' => 'secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => fn ($request) => Http::response(['success' => $request['response'] === 'good-token']),
        '127.0.0.1:13714/*' => Http::response(['html' => '']),
    ]);

    $schema = app(Forms::class)->schema($this->tenant, $this->jobs, ['name'], withToken: true);
    expect($schema['captcha'])->toMatchArray(['provider' => 'turnstile', 'site_key' => 'site-key']);

    $this->postJson(route('api.forms.submit'), ['token' => $schema['token'], 'values' => ['name' => 'Bot']])
        ->assertJsonValidationErrors('captcha');
    $this->postJson(route('api.forms.submit'), ['token' => $schema['token'], 'values' => ['name' => 'Bot'], 'captcha' => 'forged'])
        ->assertJsonValidationErrors('captcha');
    $this->postJson(route('api.forms.submit'), ['token' => $schema['token'], 'values' => ['name' => 'Human'], 'captcha' => 'good-token'])
        ->assertCreated();

    // A form may switch the captcha off; the page policy allows the widget.
    expect(app(Forms::class)->schema($this->tenant, $this->jobs, ['name'], withToken: true, options: ['captcha' => false])['captcha'])->toBeNull();
    Vite::useHotFile(storage_path('framework/testing/no-hot'));
    expect((string) $this->get('http://acme.pagebuilder.test/')->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'self' https://challenges.cloudflare.com");
});

// --- Confirmation e-mail ---

test('the visitor gets a confirmation, but nobody can be flooded with them', function () {
    $token = jobToken(['confirm' => 'Спасибо! Мы ответим в течение дня.']);

    foreach (range(1, 4) as $i) {
        $this->postJson(route('api.forms.submit'), ['token' => $token, 'values' => ['name' => "Ivan {$i}", 'email' => 'Ivan@Example.com']])
            ->assertCreated();
    }

    Mail::assertSent(FormConfirmationMail::class, fn (FormConfirmationMail $mail) => $mail->hasTo('ivan@example.com')
        && $mail->text === 'Спасибо! Мы ответим в течение дня.');
    Mail::assertSent(FormConfirmationMail::class, Forms::CONFIRMATIONS_PER_HOUR);
});

// --- Personal area ---

test('visitors are told when the status of their submission changes', function () {
    app(SiteDesign::class)->for($this->tenant)->forceFill(['account' => ['enabled' => true, 'collections' => [
        ['collection' => 'jobs', 'email_field' => 'email', 'status_field' => 'status', 'notify' => true],
    ]]])->save();
    $record = (new RecordRepository($this->jobs))->create(['name' => 'Ivan', 'email' => 'ivan@example.com', 'status' => 'Новый']);

    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', ['collection' => 'jobs', 'record' => $record['id']]), ['phone' => '+7 900'])
        ->assertOk();
    Mail::assertNothingSent();

    $this->actingAs($this->owner)
        ->putJson(route('api.records.update', ['collection' => 'jobs', 'record' => $record['id']]), ['status' => 'Приглашён'])
        ->assertOk();

    Mail::assertSent(AccountLinkMail::class, fn (AccountLinkMail $mail) => $mail->hasTo('ivan@example.com')
        && str_contains($mail->text, 'Приглашён')
        && $mail->url === 'https://acme.pagebuilder.test/account');
});

test('sites collect extra profile fields that forms reuse', function () {
    $this->actingAs($this->owner)->putJson(route('api.site-settings.update'), [
        'theme' => [],
        'menu' => [],
        'account' => [
            'enabled' => true,
            'registration' => true,
            'collections' => [['collection' => 'jobs', 'email_field' => 'email', 'status_field' => 'phone', 'notify' => true]],
            'profile_fields' => [
                ['name' => 'phone', 'label' => 'Телефон', 'type' => 'phone', 'required' => true],
                ['name' => 'email', 'label' => 'Дубль', 'type' => 'text'],
                ['name' => 'Bad Name', 'label' => 'Плохое', 'type' => 'text'],
            ],
        ],
    ])
        ->assertOk()
        // Only a list field can be a status; reserved and invalid profile fields are dropped.
        ->assertJsonPath('account.collections.0.status_field', null)
        ->assertJsonPath('account.collections.0.notify', false)
        ->assertJsonCount(1, 'account.profile_fields');

    $register = fn (string $phone) => $this->post('http://acme.pagebuilder.test/account/register', [
        'name' => 'Ivan', 'email' => 'ivan@example.com', 'password' => 'secret-password', 'password_confirmation' => 'secret-password',
        'profile' => ['phone' => $phone, 'hack' => 'x'],
    ]);

    $register('<script>')->assertSessionHasErrors('profile.phone');
    $register('+7 (900) 123-45-67')->assertRedirect();

    $member = SiteMember::withoutGlobalScope('tenant')->sole();
    expect($member->profile)->toBe(['phone' => '+7 (900) 123-45-67']);

    // The signed-in visitor's profile reaches the page, so forms can prefill it.
    $this->get('http://acme.pagebuilder.test/')->assertOk();
    [$request] = Http::recorded()->last();
    expect((array) $request->data()['site']['account']['member']['profile'])->toBe(['phone' => '+7 (900) 123-45-67']);
});
