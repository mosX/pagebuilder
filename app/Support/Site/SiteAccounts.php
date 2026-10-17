<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\SiteMember;
use App\Models\SiteSettings;
use App\Models\Tenant;
use App\Support\Collections\RecordRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The visitor account area of a company site: whether it is on, whether visitors may
 * sign up themselves, and which collections show a visitor's own records ("my submissions"),
 * matched by an e-mail field.
 */
class SiteAccounts
{
    public const MAX_COLLECTIONS = 5;

    public const MAX_RECORDS = 20;

    /**
     * @return array{enabled: bool, registration: bool, collections: list<array{collection: string, email_field: string}>}
     */
    public function of(SiteSettings $settings): array
    {
        $account = is_array($settings->account) ? $settings->account : [];

        return [
            'enabled' => (bool) ($account['enabled'] ?? false),
            'registration' => (bool) ($account['registration'] ?? true),
            'collections' => $this->collections($account['collections'] ?? []),
        ];
    }

    /**
     * Normalizes settings sent from the admin; collections and fields must exist in the company.
     *
     * @return array{enabled: bool, registration: bool, collections: list<array{collection: string, email_field: string}>}
     */
    public function normalize(Tenant $tenant, mixed $account): array
    {
        $account = is_array($account) ? $account : [];
        $collections = [];

        foreach ($this->collections($account['collections'] ?? []) as $item) {
            $collection = $this->collection($tenant, $item['collection']);

            if ($collection && $this->emailField($collection, $item['email_field'])) {
                $collections[] = $item;
            }
        }

        return [
            'enabled' => (bool) ($account['enabled'] ?? false),
            'registration' => (bool) ($account['registration'] ?? true),
            'collections' => $collections,
        ];
    }

    /**
     * The visitor's own records in the configured collections. Only for confirmed e-mails:
     * otherwise anyone could sign up with another person's address and read their submissions.
     *
     * @return list<array{label: string, items: list<array{title: string, date: string|null, fields: list<array{label: string, value: string}>}>}>
     */
    public function submissions(Tenant $tenant, SiteSettings $settings, SiteMember $member): array
    {
        if (! $member->email_verified_at) {
            return [];
        }

        $result = [];

        foreach ($this->of($settings)['collections'] as $item) {
            $collection = $this->collection($tenant, $item['collection']);
            $field = $collection ? $this->emailField($collection, $item['email_field']) : null;

            if (! $collection || ! $field) {
                continue;
            }

            $repository = new RecordRepository($collection);
            $display = $repository->displayField();
            $shown = $collection->fields
                ->filter(fn (CollectionField $f) => in_array($f->type, Forms::FIELD_TYPES, true) && $f->name !== $field->name && $f->id !== $display?->id)
                ->take(6);

            $ids = DB::table($collection->table_name)
                // Case-insensitive: visitors type their address in forms however they like.
                ->whereLike((string) $field->column(), addcslashes($member->email, '\%_'))
                ->orderByDesc('id')
                ->limit(self::MAX_RECORDS)
                ->pluck('id');

            $items = [];

            foreach ($ids as $id) {
                $record = $repository->find((int) $id);

                if (! $record) {
                    continue;
                }

                $items[] = [
                    'title' => $display && is_scalar($record[$display->name] ?? null) ? (string) $record[$display->name] : "#{$id}",
                    'date' => is_string($record['created_at'] ?? null) ? $record['created_at'] : null,
                    'fields' => array_values($shown
                        ->map(fn (CollectionField $f) => ['label' => $f->label, 'value' => $this->text($record[$f->name] ?? null)])
                        ->filter(fn (array $value) => $value['value'] !== '')
                        ->all()),
                ];
            }

            $result[] = ['label' => $collection->label, 'items' => $items];
        }

        return $result;
    }

    /**
     * @return list<array{collection: string, email_field: string}>
     */
    private function collections(mixed $items): array
    {
        $result = [];

        foreach (array_slice(is_array($items) ? array_values(array_filter($items, 'is_array')) : [], 0, self::MAX_COLLECTIONS) as $item) {
            $collection = is_string($item['collection'] ?? null) ? $item['collection'] : '';
            $field = is_string($item['email_field'] ?? null) ? $item['email_field'] : '';

            if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $collection) && preg_match('/^[a-z][a-z0-9_]{0,23}$/', $field)) {
                $result[] = ['collection' => $collection, 'email_field' => $field];
            }
        }

        return $result;
    }

    private function collection(Tenant $tenant, string $name): ?Collection
    {
        return Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->first();
    }

    private function emailField(Collection $collection, string $name): ?CollectionField
    {
        return $collection->fields->first(fn (CollectionField $f) => $f->name === $name
            && in_array($f->type, [FieldType::Email, FieldType::String], true));
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Да' : 'Нет',
            is_scalar($value) => Str::limit((string) $value, 300),
            default => '',
        };
    }
}
