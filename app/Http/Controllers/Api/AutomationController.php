<?php

namespace App\Http\Controllers\Api;

use App\Enums\FieldType;
use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Support\Audit\ActivityLog;
use App\Support\Automation\AutomationEngine;
use App\Support\Automation\UrlGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Automations of the current company: "when … → do …".
 */
class AutomationController extends Controller
{
    public function __construct(
        private TenantContext $context,
        private ActivityLog $activity,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(Automation::query()->latest('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $automation = new Automation;
        $automation->forceFill([...$this->validated($request), 'tenant_id' => $this->context->tenant()->id])->save();
        $this->activity->record('automation.created', $automation->name);

        return response()->json(['message' => 'Автоматизация создана.', 'automation' => $automation], 201);
    }

    public function update(Request $request, Automation $automation): JsonResponse
    {
        $automation->forceFill($this->validated($request))->save();
        $this->activity->record('automation.updated', $automation->name);

        return response()->json(['message' => 'Автоматизация сохранена.', 'automation' => $automation]);
    }

    public function destroy(Automation $automation): JsonResponse
    {
        $automation->delete();
        $this->activity->record('automation.deleted', $automation->name);

        return response()->json(['message' => "Автоматизация «{$automation->name}» удалена."]);
    }

    public function runs(Automation $automation): JsonResponse
    {
        return response()->json($automation->runs()->latest('id')->get());
    }

    /**
     * Validates the definition against the company schema: the collection, condition fields,
     * action types and their targets must exist.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'trigger' => ['required', 'array'],
            'trigger.event' => ['required', Rule::in(Automation::EVENTS)],
            'trigger.collection' => ['required', 'string'],
            'trigger.conditions' => ['present', 'array', 'max:10'],
            'trigger.conditions.*.field' => ['required', 'string'],
            'trigger.conditions.*.operator' => ['required', Rule::in(AutomationEngine::OPERATORS)],
            'trigger.conditions.*.value' => ['nullable', 'string', 'max:255'],
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(AutomationEngine::ACTIONS)],
        ], [], ['trigger.collection' => 'коллекция']);

        $collection = $this->collection($data['trigger']['collection'], 'trigger.collection');
        $names = $collection->fields->pluck('name')->all();

        foreach ($data['trigger']['conditions'] as $i => $condition) {
            if (! in_array($condition['field'], $names, true)) {
                $this->fail("trigger.conditions.{$i}.field", 'Такого поля нет в коллекции.');
            }
        }

        $actions = [];

        foreach ((array) $request->input('actions') as $i => $action) {
            $actions[] = $this->action((array) $action, (int) $i, $collection, $data['trigger']['event']);
        }

        return [
            'name' => $data['name'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'trigger' => [
                'event' => $data['trigger']['event'],
                'collection' => $collection->name,
                'conditions' => array_values(array_map(fn (array $c) => [
                    'field' => $c['field'],
                    'operator' => $c['operator'],
                    'value' => (string) ($c['value'] ?? ''),
                ], $data['trigger']['conditions'])),
            ],
            'actions' => $actions,
        ];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private function action(array $action, int $i, Collection $collection, string $event): array
    {
        $text = fn (string $key, int $max = 2000) => mb_substr(is_string($action[$key] ?? null) ? $action[$key] : '', 0, $max);

        switch ($action['type'] ?? null) {
            case 'send_email':
                if (trim($text('to')) === '') {
                    $this->fail("actions.{$i}.to", 'Укажите адрес или поле с адресом, например {{ email }}.');
                }

                return ['type' => 'send_email', 'to' => $text('to', 255), 'subject' => $text('subject', 200), 'body' => $text('body', 10000)];

            case 'update_record':
                if ($event === 'record.deleted') {
                    $this->fail("actions.{$i}.type", 'Удалённую запись изменить нельзя.');
                }

                return ['type' => 'update_record', 'values' => $this->values($action, $i, $collection)];

            case 'create_record':
                $target = $this->collection(is_string($action['collection'] ?? null) ? $action['collection'] : '', "actions.{$i}.collection");

                return ['type' => 'create_record', 'collection' => $target->name, 'values' => $this->values($action, $i, $target)];

            default:
                try {
                    app(UrlGuard::class)->assertPublic($text('url'));
                } catch (RuntimeException $e) {
                    $this->fail("actions.{$i}.url", $e->getMessage());
                }

                return ['type' => 'webhook', 'url' => $text('url')];
        }
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<array{field: string, value: string}>
     */
    private function values(array $action, int $i, Collection $collection): array
    {
        $writable = $collection->fields
            ->reject(fn (CollectionField $f) => $f->type === FieldType::Blocks)
            ->pluck('name')
            ->all();
        $values = [];

        foreach ((array) ($action['values'] ?? []) as $item) {
            $field = is_array($item) && is_string($item['field'] ?? null) ? $item['field'] : '';

            if (! in_array($field, $writable, true)) {
                $this->fail("actions.{$i}.values", "Поле «{$field}» нельзя заполнить.");
            }

            $values[] = ['field' => $field, 'value' => mb_substr(is_scalar($item['value'] ?? null) ? (string) $item['value'] : '', 0, 2000)];
        }

        if ($values === []) {
            $this->fail("actions.{$i}.values", 'Укажите хотя бы одно поле.');
        }

        return $values;
    }

    private function collection(string $name, string $key): Collection
    {
        return Collection::query()->with('fields')->where('name', $name)->first()
            ?? $this->fail($key, 'Коллекция не найдена.');
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
