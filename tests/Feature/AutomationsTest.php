<?php

use App\Enums\FieldType;
use App\Enums\TenantRole;
use App\Jobs\DeliverWebhook;
use App\Mail\AutomationMail;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Automation\UrlGuard;
use App\Support\Collections\PagesCollection;
use App\Support\Collections\RecordRepository;
use App\Support\Collections\SchemaManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // DNS is faked: "internal.test" points into a private network, everything else is public.
    $this->app->instance(UrlGuard::class, new class extends UrlGuard
    {
        protected function resolve(string $host): array
        {
            return $host === 'internal.test' ? ['10.0.0.5'] : ['93.184.216.34'];
        }
    });

    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = memberOf($this->tenant);

    $schema = app(SchemaManager::class);
    $this->leads = $schema->createCollection($this->tenant, ['name' => 'leads', 'label' => 'Заявки']);
    $schema->addField($this->leads, ['name' => 'name', 'label' => 'Имя', 'type' => FieldType::String]);
    $schema->addField($this->leads, ['name' => 'email', 'label' => 'Email', 'type' => FieldType::Email]);
    $schema->addField($this->leads, ['name' => 'status', 'label' => 'Статус', 'type' => FieldType::Select, 'options' => ['choices' => ['new', 'processed']]]);
    $this->tasks = $schema->createCollection($this->tenant, ['name' => 'tasks', 'label' => 'Задачи']);
    $schema->addField($this->tasks, ['name' => 'title', 'label' => 'Задача', 'type' => FieldType::String]);
});

function webhook(array $attributes = []): Webhook
{
    $webhook = new Webhook;
    $webhook->forceFill([
        'tenant_id' => test()->tenant->id,
        'name' => 'CRM',
        'url' => 'https://crm.example/hook',
        'events' => ['record.created'],
        'collection' => null,
        'secret' => 'secret',
        'is_active' => true,
        ...$attributes,
    ])->save();

    return $webhook;
}

function automation(array $trigger, array $actions): Automation
{
    $id = test()->actingAs(test()->owner)
        ->postJson(route('api.automations.store'), ['name' => 'Test', 'trigger' => $trigger, 'actions' => $actions])
        ->assertCreated()
        ->json('automation.id');

    return Automation::withoutGlobalScope('tenant')->findOrFail($id);
}

test('record events queue deliveries to matching webhooks only', function () {
    Queue::fake();
    $all = webhook();
    $tasksOnly = webhook(['collection' => 'tasks']);
    webhook(['is_active' => false]);
    webhook(['events' => ['record.deleted']]);

    $this->actingAs($this->owner)->postJson(route('api.records.store', 'leads'), ['name' => 'Ann'])->assertCreated();

    Queue::assertPushed(DeliverWebhook::class, 1);
    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->webhookId === $all->id
        && $job->event === 'record.created'
        && $job->payload['collection']['name'] === 'leads'
        && $job->payload['record']['name'] === 'Ann');
    Queue::assertNotPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->webhookId === $tasksOnly->id);
});

test('deliveries are signed with the webhook secret and logged', function () {
    Http::fake(['crm.example/*' => Http::response('ok')]);
    $webhook = webhook();

    $error = DeliverWebhook::send(app(UrlGuard::class), $webhook, 'record.created', ['record' => ['id' => 1]]);

    expect($error)->toBeNull();
    Http::assertSent(function ($request) {
        return $request->header('X-Pagebuilder-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 'secret')
            && $request['event'] === 'record.created'
            && $request['record'] === ['id' => 1];
    });

    expect(WebhookDelivery::sole())->success->toBeTrue()->status->toBe(200);
});

test('webhooks never reach internal networks', function () {
    Http::fake();

    foreach (['http://127.0.0.1/x', 'http://169.254.169.254/latest/meta-data', 'http://internal.test/x', 'http://[::ffff:127.0.0.1]/x', 'file:///etc/passwd'] as $url) {
        $webhook = webhook(['url' => $url]);
        expect(DeliverWebhook::send(app(UrlGuard::class), $webhook, 'ping', []))->not->toBeNull();
    }

    Http::assertNothingSent();

    $this->actingAs($this->owner)
        ->postJson(route('api.webhooks.store'), ['name' => 'X', 'url' => 'http://internal.test/hook', 'events' => ['record.created']])
        ->assertJsonValidationErrors('url');
});

test('failed deliveries are retried by the queue and logged', function () {
    Http::fake(['crm.example/*' => Http::response('nope', 500)]);
    $webhook = webhook();

    expect(fn () => (new DeliverWebhook($webhook->id, 'record.created', []))->handle(app(UrlGuard::class)))
        ->toThrow(RuntimeException::class, 'Ответ 500');

    expect(WebhookDelivery::sole())->success->toBeFalse()->status->toBe(500);
});

test('webhooks are managed by owners and admins of their company only', function () {
    $this->actingAs(memberOf($this->tenant, TenantRole::Editor))->getJson(route('api.webhooks.index'))->assertForbidden();

    $secret = $this->actingAs($this->owner)
        ->postJson(route('api.webhooks.store'), ['name' => 'CRM', 'url' => 'https://crm.example/hook', 'events' => ['form.submitted']])
        ->assertCreated()
        ->json('secret');

    expect($secret)->toHaveLength(40);

    $id = Webhook::withoutGlobalScope('tenant')->sole()->id;
    $this->actingAs(memberOf(Tenant::factory()->create()))->deleteJson(route('api.webhooks.destroy', $id))->assertNotFound();
});

test('an automation e-mails and updates the record when its conditions match', function () {
    Mail::fake();

    automation(
        ['event' => 'record.created', 'collection' => 'leads', 'conditions' => [['field' => 'status', 'operator' => 'equals', 'value' => 'new']]],
        [
            ['type' => 'send_email', 'to' => '{{ email }}', 'subject' => 'Спасибо, {{ name }}!', 'body' => 'Заявка #{{ id }} в «{{ collection }}» принята.'],
            ['type' => 'update_record', 'values' => [['field' => 'status', 'value' => 'processed']]],
        ],
    );

    $this->actingAs($this->owner)->postJson(route('api.records.store', 'leads'), ['name' => 'Ann', 'email' => 'ann@example.com', 'status' => 'new'])->assertCreated();
    $this->actingAs($this->owner)->postJson(route('api.records.store', 'leads'), ['name' => 'Bob', 'email' => 'bob@example.com', 'status' => 'processed'])->assertCreated();

    Mail::assertSent(AutomationMail::class, 1);
    Mail::assertSent(AutomationMail::class, fn (AutomationMail $mail) => $mail->hasTo('ann@example.com')
        && $mail->mailSubject === 'Спасибо, Ann!'
        && $mail->mailBody === 'Заявка #1 в «Заявки» принята.');

    expect(DB::table($this->leads->table_name)->where('name', 'Ann')->value('status'))->toBe('processed')
        ->and(AutomationRun::sole())->success->toBeTrue();
});

test('an automation can create records in another collection', function () {
    automation(
        ['event' => 'record.created', 'collection' => 'leads', 'conditions' => []],
        [['type' => 'create_record', 'collection' => 'tasks', 'values' => [['field' => 'title', 'value' => 'Позвонить: {{ name }}']]]],
    );

    $this->actingAs($this->owner)->postJson(route('api.records.store', 'leads'), ['name' => 'Ann'])->assertCreated();

    expect(DB::table($this->tasks->table_name)->value('title'))->toBe('Позвонить: Ann');
});

test('automations that trigger themselves stop at the depth limit', function () {
    automation(
        ['event' => 'record.updated', 'collection' => 'leads', 'conditions' => []],
        [['type' => 'update_record', 'values' => [['field' => 'name', 'value' => '{{ name }}!']]]],
    );

    $id = (new RecordRepository($this->leads->fresh()))->create(['name' => 'Ann'])['id'];
    $this->actingAs($this->owner)->putJson(route('api.records.update', ['collection' => 'leads', 'record' => $id]), ['name' => 'Bob'])->assertOk();

    expect(DB::table($this->leads->table_name)->value('name'))->toBe('Bob!!!')
        ->and(AutomationRun::count())->toBe(3);
});

test('"changed" conditions fire only when the field changed', function () {
    Mail::fake();
    automation(
        ['event' => 'record.updated', 'collection' => 'leads', 'conditions' => [['field' => 'status', 'operator' => 'changed', 'value' => '']]],
        [['type' => 'send_email', 'to' => 'sales@acme.test', 'subject' => 'Статус', 'body' => '{{ status }}']],
    );
    $id = (new RecordRepository($this->leads->fresh()))->create(['name' => 'Ann', 'status' => 'new'])['id'];

    $this->actingAs($this->owner)->putJson(route('api.records.update', ['collection' => 'leads', 'record' => $id]), ['name' => 'Anna'])->assertOk();
    Mail::assertNothingSent();

    $this->actingAs($this->owner)->putJson(route('api.records.update', ['collection' => 'leads', 'record' => $id]), ['status' => 'processed'])->assertOk();
    Mail::assertSent(AutomationMail::class, fn (AutomationMail $mail) => $mail->mailBody === 'processed');
});

test('failing actions are logged, and blocked webhooks never leave', function () {
    Http::fake();
    automation(
        ['event' => 'record.created', 'collection' => 'leads', 'conditions' => []],
        [['type' => 'webhook', 'url' => 'https://crm.example/hook']],
    );
    Automation::withoutGlobalScope('tenant')->sole()->forceFill(['actions' => [['type' => 'webhook', 'url' => 'http://internal.test/x']]])->save();

    $this->actingAs($this->owner)->postJson(route('api.records.store', 'leads'), ['name' => 'Ann'])->assertCreated();

    Http::assertNothingSent();
    expect(AutomationRun::sole())->success->toBeFalse()->message->toContain('внутренние сети')
        ->and(Automation::withoutGlobalScope('tenant')->sole()->last_error)->toContain('внутренние сети');
});

test('automation definitions are validated against the schema', function () {
    $post = fn (array $trigger, array $actions) => $this->actingAs($this->owner)
        ->postJson(route('api.automations.store'), ['name' => 'X', 'trigger' => $trigger, 'actions' => $actions]);

    $post(['event' => 'record.created', 'collection' => 'nope', 'conditions' => []], [['type' => 'webhook', 'url' => 'https://crm.example']])
        ->assertJsonValidationErrors('trigger.collection');
    $post(['event' => 'record.created', 'collection' => 'leads', 'conditions' => [['field' => 'ghost', 'operator' => 'equals']]], [['type' => 'webhook', 'url' => 'https://crm.example']])
        ->assertJsonValidationErrors('trigger.conditions.0.field');
    $post(['event' => 'record.deleted', 'collection' => 'leads', 'conditions' => []], [['type' => 'update_record', 'values' => [['field' => 'name', 'value' => 'x']]]])
        ->assertJsonValidationErrors('actions.0.type');
    $post(['event' => 'record.created', 'collection' => 'leads', 'conditions' => []], [['type' => 'create_record', 'collection' => 'tasks', 'values' => [['field' => 'ghost', 'value' => 'x']]]])
        ->assertJsonValidationErrors('actions.0.values');
    $post(['event' => 'record.created', 'collection' => 'leads', 'conditions' => []], [['type' => 'webhook', 'url' => 'http://internal.test/x']])
        ->assertJsonValidationErrors('actions.0.url');
});

test('publishing a page notifies page.published webhooks', function () {
    Queue::fake();
    app(PagesCollection::class)->ensure($this->tenant);
    webhook(['events' => ['page.published']]);

    $this->actingAs($this->owner)
        ->postJson(route('api.block-versions.publish'), ['target' => 'layout:header', 'tree' => []])
        ->assertOk();

    Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->event === 'page.published' && $job->payload['label'] === 'Шапка сайта');
});
