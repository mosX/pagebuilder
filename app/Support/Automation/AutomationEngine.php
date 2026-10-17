<?php

namespace App\Support\Automation;

use App\Mail\AutomationMail;
use App\Models\Automation;
use App\Models\Collection;
use App\Support\Collections\RecordRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Evaluates and runs automations: "when <event> in <collection> and <conditions> → <actions>".
 *
 * Templates in action values use {{ field }} placeholders with the record's values
 * (relations give the related record's title), plus {{ id }} and {{ collection }}.
 */
class AutomationEngine
{
    public const OPERATORS = ['equals', 'not_equals', 'contains', 'empty', 'not_empty', 'changed'];

    public const ACTIONS = ['send_email', 'update_record', 'create_record', 'webhook'];

    public function __construct(private UrlGuard $guard) {}

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $changes
     */
    public function matches(Automation $automation, string $event, Collection $collection, array $record, array $changes): bool
    {
        $trigger = $automation->trigger;

        if (! $automation->is_active || ($trigger['event'] ?? null) !== $event || ($trigger['collection'] ?? null) !== $collection->name) {
            return false;
        }

        foreach ((array) ($trigger['conditions'] ?? []) as $condition) {
            if (is_array($condition) && ! $this->condition($condition, $record, $changes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs the actions in order; the first failing action stops the run.
     *
     * @param  array<string, mixed>  $record
     * @return list<string> What was done, for the run log.
     */
    public function run(Automation $automation, string $event, Collection $collection, array $record): array
    {
        $done = [];

        foreach ($automation->actions as $action) {
            $done[] = match ($action['type'] ?? null) {
                'send_email' => $this->sendEmail($action, $collection, $record),
                'update_record' => $this->updateRecord($action, $event, $collection, $record),
                'create_record' => $this->createRecord($action, $collection, $record),
                'webhook' => $this->webhook($action, $event, $collection, $record),
                default => throw new RuntimeException('Неизвестное действие.'),
            };
        }

        return $done;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function render(string $template, Collection $collection, array $record): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', function (array $match) use ($collection, $record) {
            return match ($match[1]) {
                'collection' => $collection->label,
                default => $this->text($record[$match[1]] ?? null),
            };
        }, $template);
    }

    public function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Да' : 'Нет',
            is_array($value) && isset($value['label']) => (string) $value['label'],
            is_array($value) => implode(', ', array_map(fn ($v) => is_array($v) ? (string) ($v['label'] ?? '') : (string) $v, $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $record
     * @param  list<string>  $changes
     */
    private function condition(array $condition, array $record, array $changes): bool
    {
        $field = (string) ($condition['field'] ?? '');
        $actual = $record[$field] ?? null;
        $text = mb_strtolower($this->text($actual));
        $expected = mb_strtolower(trim((string) ($condition['value'] ?? '')));

        // Relations match by the related record's title or id.
        $ids = array_map('strval', array_column(isset($actual['id']) ? [$actual] : (is_array($actual) ? $actual : []), 'id'));
        $equals = $text === $expected || in_array($expected, $ids, true)
            || (is_bool($actual) && in_array($expected, $actual ? ['да', 'true', '1'] : ['нет', 'false', '0'], true));

        return match ($condition['operator'] ?? 'equals') {
            'equals' => $equals,
            'not_equals' => ! $equals,
            'contains' => $expected !== '' && str_contains($text, $expected),
            'empty' => $text === '',
            'not_empty' => $text !== '',
            'changed' => in_array($field, $changes, true),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $record
     */
    private function sendEmail(array $action, Collection $collection, array $record): string
    {
        $to = trim($this->render((string) ($action['to'] ?? ''), $collection, $record));

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("Некорректный адрес письма: «{$to}».");
        }

        Mail::to($to)->send(new AutomationMail(
            Str::limit($this->render((string) ($action['subject'] ?? ''), $collection, $record), 200, ''),
            $this->render((string) ($action['body'] ?? ''), $collection, $record),
        ));

        return "Письмо на {$to}";
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $record
     */
    private function updateRecord(array $action, string $event, Collection $collection, array $record): string
    {
        if ($event === 'record.deleted') {
            throw new RuntimeException('Удалённую запись изменить нельзя.');
        }

        (new RecordRepository($collection))->update((int) $record['id'], $this->values($action, $collection, $record));

        return "Изменена запись {$collection->label} #{$record['id']}";
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $record
     */
    private function createRecord(array $action, Collection $collection, array $record): string
    {
        $target = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $collection->tenant_id)
            ->where('name', (string) ($action['collection'] ?? ''))
            ->first() ?? throw new RuntimeException('Коллекция для новой записи не найдена.');

        $created = (new RecordRepository($target))->create($this->values($action, $collection, $record));

        return "Создана запись {$target->label} #{$created['id']}";
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $record
     */
    private function webhook(array $action, string $event, Collection $collection, array $record): string
    {
        $url = (string) ($action['url'] ?? '');

        $response = Http::timeout((int) config('webhooks.timeout', 10))
            ->withoutRedirecting()
            ->withOptions(['curl' => $this->guard->assertPublic($url)])
            ->asJson()
            ->post($url, [
                'event' => $event,
                'collection' => ['name' => $collection->name, 'label' => $collection->label],
                'record' => $record,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Вебхук ответил {$response->status()}.");
        }

        return "Вебхук {$url}";
    }

    /**
     * Field values of update/create actions with templates rendered.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private function values(array $action, Collection $collection, array $record): array
    {
        $values = [];

        foreach ((array) ($action['values'] ?? []) as $item) {
            if (is_array($item) && is_string($item['field'] ?? null)) {
                $values[$item['field']] = $this->render((string) ($item['value'] ?? ''), $collection, $record);
            }
        }

        return $values;
    }
}
