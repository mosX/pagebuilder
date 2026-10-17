<?php

namespace App\Support\Collections;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\RecordRevision;
use App\Models\User;
use App\Support\Audit\ActivityLog;
use Illuminate\Validation\ValidationException;

/**
 * History of records: after every change (admin, API, forms, automations) the record's values
 * are kept, so a mistaken edit can be rolled back. Block fields are left out: the block
 * constructor keeps its own revisions. A restore is a change too, so it can be undone.
 */
class RecordHistory
{
    public const KEEP = 50;

    public function __construct(private ActivityLog $activity) {}

    /**
     * @param  array<string, mixed>  $record  As presented by RecordRepository.
     * @param  list<string>  $changes
     */
    public function remember(Collection $collection, array $record, array $changes, int $depth): void
    {
        $user = auth()->user();
        $values = [];

        foreach ($this->fields($collection) as $field) {
            $values[$field->name] = $record[$field->name] ?? null;
        }

        $revision = new RecordRevision;
        $revision->forceFill([
            'tenant_id' => $collection->tenant_id,
            'collection_id' => $collection->id,
            'record_id' => (int) $record['id'],
            'user_id' => $user instanceof User ? $user->id : null,
            'actor' => match (true) {
                $depth > 0 => 'Автоматизация',
                $this->activity->actor() !== null => $this->activity->actor(),
                $user instanceof User => null,
                default => 'Сайт',
            },
            'changes' => $changes,
            'values' => $values,
            'created_at' => now(),
        ])->save();

        // Older versions beyond the limit are dropped.
        $keep = RecordRevision::withoutGlobalScope('tenant')
            ->where('collection_id', $collection->id)
            ->where('record_id', $record['id'])
            ->latest('id')
            ->limit(self::KEEP)
            ->pluck('id');

        RecordRevision::withoutGlobalScope('tenant')
            ->where('collection_id', $collection->id)
            ->where('record_id', $record['id'])
            ->whereNotIn('id', $keep)
            ->delete();
    }

    /**
     * Puts the record back to the values of a revision (fields that still exist).
     *
     * @return array<string, mixed> The restored record.
     */
    public function restore(Collection $collection, RecordRevision $revision): array
    {
        if ($revision->collection_id !== $collection->id) {
            throw ValidationException::withMessages(['revision' => 'Эта версия относится к другой коллекции.']);
        }

        $input = [];

        foreach ($this->fields($collection) as $field) {
            if (! array_key_exists($field->name, $revision->values)) {
                continue;
            }

            $value = $revision->values[$field->name];

            // Relations are kept as {id, label}; the repository takes ids.
            $input[$field->name] = match ($field->type) {
                FieldType::BelongsTo => is_array($value) ? ($value['id'] ?? null) : null,
                FieldType::BelongsToMany => array_values(array_filter(array_map(fn (mixed $v) => is_array($v) ? ($v['id'] ?? null) : null, (array) $value))),
                default => $value,
            };
        }

        return (new RecordRepository($collection))->update($revision->record_id, $input);
    }

    public function forget(Collection $collection, int $recordId): void
    {
        RecordRevision::withoutGlobalScope('tenant')->where('collection_id', $collection->id)->where('record_id', $recordId)->delete();
    }

    /**
     * @return \Illuminate\Support\Collection<int, CollectionField>
     */
    private function fields(Collection $collection): \Illuminate\Support\Collection
    {
        return $collection->fields->filter(fn (CollectionField $f) => $f->type !== FieldType::Blocks)->values();
    }
}
