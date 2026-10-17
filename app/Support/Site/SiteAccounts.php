<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Mail\AccountLinkMail;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\SiteMember;
use App\Models\SiteSettings;
use App\Models\Tenant;
use App\Support\Collections\RecordRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * The visitor account area of a company site: whether it is on, whether visitors may
 * sign up themselves, extra profile fields, and which collections show a visitor's own
 * records ("my submissions", matched by an e-mail field) with their status, optionally
 * e-mailing the visitor when the status changes.
 *
 * @phpstan-type AccountCollection array{collection: string, email_field: string, status_field: string|null, notify: bool}
 * @phpstan-type ProfileField array{name: string, label: string, type: string, required: bool}
 * @phpstan-type AccountSettings array{enabled: bool, registration: bool, collections: list<AccountCollection>, profile_fields: list<ProfileField>}
 */
class SiteAccounts
{
    public const MAX_COLLECTIONS = 5;

    public const MAX_RECORDS = 20;

    public const MAX_PROFILE_FIELDS = 6;

    public const PROFILE_TYPES = ['text', 'phone'];

    /**
     * @return AccountSettings
     */
    public function of(SiteSettings $settings): array
    {
        $account = is_array($settings->account) ? $settings->account : [];

        return [
            'enabled' => (bool) ($account['enabled'] ?? false),
            'registration' => (bool) ($account['registration'] ?? true),
            'collections' => $this->collections($account['collections'] ?? []),
            'profile_fields' => $this->profileFields($account['profile_fields'] ?? []),
        ];
    }

    /**
     * Normalizes settings sent from the admin; collections and fields must exist in the company.
     *
     * @return AccountSettings
     */
    public function normalize(Tenant $tenant, mixed $account): array
    {
        $account = is_array($account) ? $account : [];
        $collections = [];

        foreach ($this->collections($account['collections'] ?? []) as $item) {
            $collection = $this->collection($tenant, $item['collection']);

            if ($collection && $this->emailField($collection, $item['email_field'])) {
                // Only a list field makes a status.
                $status = $item['status_field'] ? $collection->field($item['status_field']) : null;
                $item['status_field'] = $status && $status->type === FieldType::Select ? $status->name : null;
                $item['notify'] = $item['notify'] && $item['status_field'] !== null;
                $collections[] = $item;
            }
        }

        return [
            'enabled' => (bool) ($account['enabled'] ?? false),
            'registration' => (bool) ($account['registration'] ?? true),
            'collections' => $collections,
            'profile_fields' => $this->profileFields($account['profile_fields'] ?? []),
        ];
    }

    /**
     * Validation rules of the profile fields on sign-up and in the personal area.
     *
     * @return array<string, list<string>>
     */
    public function profileRules(SiteSettings $settings): array
    {
        $rules = [];

        foreach ($this->of($settings)['profile_fields'] as $field) {
            $rules["profile.{$field['name']}"] = [
                $field['required'] ? 'required' : 'nullable',
                'string',
                ...($field['type'] === 'phone' ? ['max:30', 'regex:/^[0-9+()\-\s]{5,30}$/'] : ['max:255']),
            ];
        }

        return $rules;
    }

    /**
     * Only configured profile fields are kept.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public function profileValues(SiteSettings $settings, array $input): array
    {
        $values = [];

        foreach ($this->of($settings)['profile_fields'] as $field) {
            $value = $input[$field['name']] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $values[$field['name']] = Str::limit(trim($value), 255, '');
            }
        }

        return $values;
    }

    /**
     * The visitor's own records in the configured collections. Only for confirmed e-mails:
     * otherwise anyone could sign up with another person's address and read their submissions.
     *
     * @return list<array{label: string, items: list<array{title: string, date: string|null, status: string|null, fields: list<array{label: string, value: string}>}>}>
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
                ->filter(fn (CollectionField $f) => in_array($f->type, Forms::FIELD_TYPES, true)
                    && ! in_array($f->name, [$field->name, $item['status_field']], true)
                    && $f->id !== $display?->id)
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

                $status = $item['status_field'] ? ($record[$item['status_field']] ?? null) : null;

                $items[] = [
                    'title' => $display && is_scalar($record[$display->name] ?? null) ? (string) $record[$display->name] : "#{$id}",
                    'date' => is_string($record['created_at'] ?? null) ? $record['created_at'] : null,
                    'status' => is_string($status) && $status !== '' ? $status : null,
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
     * E-mails the author of a submission when its status changes, if the company asked for it.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $changes
     */
    public function statusChanged(Collection $collection, array $record, array $changes, SiteSettings $settings, Tenant $tenant): void
    {
        foreach ($this->of($settings)['collections'] as $item) {
            if ($item['collection'] !== $collection->name || ! $item['notify'] || ! in_array($item['status_field'], $changes, true)) {
                continue;
            }

            $email = $record[$item['email_field']] ?? null;
            $status = $record[(string) $item['status_field']] ?? null;

            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! is_string($status) || $status === '') {
                return;
            }

            $display = (new RecordRepository($collection))->displayField();
            $title = $display && is_scalar($record[$display->name] ?? null) ? (string) $record[$display->name] : "№{$record['id']}";
            $account = $this->of($settings)['enabled'] ? $tenant->siteUrl().'/account' : $tenant->siteUrl();

            try {
                Mail::to($email)->send(new AccountLinkMail(
                    "Статус вашей заявки: {$status} — {$tenant->name}",
                    'Здравствуйте!',
                    "Статус заявки «{$title}» ({$collection->label}) изменился: {$status}.",
                    $this->of($settings)['enabled'] ? 'Открыть личный кабинет' : 'Перейти на сайт',
                    $account,
                    'Это автоматическое уведомление, отвечать на него не нужно.',
                ));
            } catch (Throwable $e) {
                report($e);
            }

            return;
        }
    }

    /**
     * @return list<AccountCollection>
     */
    private function collections(mixed $items): array
    {
        $result = [];

        foreach (array_slice(is_array($items) ? array_values(array_filter($items, 'is_array')) : [], 0, self::MAX_COLLECTIONS) as $item) {
            $collection = is_string($item['collection'] ?? null) ? $item['collection'] : '';
            $field = is_string($item['email_field'] ?? null) ? $item['email_field'] : '';
            $status = is_string($item['status_field'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,23}$/', $item['status_field']) ? $item['status_field'] : null;

            if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $collection) && preg_match('/^[a-z][a-z0-9_]{0,23}$/', $field)) {
                $result[] = [
                    'collection' => $collection,
                    'email_field' => $field,
                    'status_field' => $status,
                    'notify' => (bool) ($item['notify'] ?? false),
                ];
            }
        }

        return $result;
    }

    /**
     * @return list<ProfileField>
     */
    private function profileFields(mixed $items): array
    {
        $result = [];
        $names = [];

        foreach (array_slice(is_array($items) ? array_values(array_filter($items, 'is_array')) : [], 0, self::MAX_PROFILE_FIELDS) as $item) {
            $name = is_string($item['name'] ?? null) ? $item['name'] : '';
            $label = Str::limit(trim(is_string($item['label'] ?? null) ? $item['label'] : ''), 60, '');

            // "name" and "email" are part of every account already.
            if (! preg_match('/^[a-z][a-z0-9_]{0,23}$/', $name) || in_array($name, ['name', 'email', ...$names], true) || $label === '') {
                continue;
            }

            $names[] = $name;
            $result[] = [
                'name' => $name,
                'label' => $label,
                'type' => in_array($item['type'] ?? null, self::PROFILE_TYPES, true) ? (string) $item['type'] : 'text',
                'required' => (bool) ($item['required'] ?? false),
            ];
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
            is_string($value) && str_starts_with($value, FormUploads::PREFIX) => 'файл: '.basename($value),
            is_bool($value) => $value ? 'Да' : 'Нет',
            is_scalar($value) => Str::limit((string) $value, 300),
            default => '',
        };
    }
}
