<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Events\FormSubmitted;
use App\Mail\FormSubmissionMail;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\Tenant;
use App\Support\Audit\ActivityLog;
use App\Support\Automation\AutomationDepth;
use App\Support\Collections\RecordRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Public forms that create records in a company collection.
 *
 * The form block gets an encrypted token naming the company, the collection and the
 * allowed fields. Submissions are accepted only with a valid token and only for those
 * fields, so a visitor cannot write to other collections or fields. Tokens are issued
 * for published content only, never for editor previews.
 */
class Forms
{
    /** Field types a visitor can fill in. */
    public const FIELD_TYPES = [
        FieldType::String, FieldType::Text, FieldType::Email, FieldType::Url, FieldType::Integer,
        FieldType::Decimal, FieldType::Boolean, FieldType::Date, FieldType::DateTime, FieldType::Select,
    ];

    /**
     * The fields of a form: those the editor chose plus every required field of the
     * collection (a record cannot be saved without them).
     *
     * @param  array<int, mixed>  $chosen
     * @return array{fields: array<int, array<string, mixed>>, token: string|null, error: string|null}
     */
    public function schema(Tenant $tenant, Collection $collection, array $chosen, bool $withToken, ?string $notifyEmail = null): array
    {
        $fields = $collection->fields
            ->filter(fn (CollectionField $f) => in_array($f->name, $chosen, true) || $f->is_required)
            ->sortBy(fn (CollectionField $f) => array_search($f->name, $chosen, true) === false ? PHP_INT_MAX : array_search($f->name, $chosen, true))
            ->values();

        $blocked = $fields->first(fn (CollectionField $f) => $f->is_required && ! in_array($f->type, self::FIELD_TYPES, true));
        $fields = $fields->filter(fn (CollectionField $f) => in_array($f->type, self::FIELD_TYPES, true))->values();

        return [
            'fields' => $fields->map(fn (CollectionField $f) => [
                'name' => $f->name,
                'label' => $f->label,
                'type' => $f->type->value,
                'required' => $f->is_required,
                'choices' => $f->type === FieldType::Select ? $f->choices() : null,
            ])->all(),
            'token' => $withToken && ! $blocked && $fields->isNotEmpty() ? $this->token($tenant, $collection, $fields->map(fn (CollectionField $f) => $f->name)->all(), $notifyEmail) : null,
            'error' => $blocked ? "Обязательное поле «{$blocked->label}» нельзя заполнить через форму." : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function submit(string $token, array $values): array
    {
        [$tenantId, $collectionId, $allowed, $notify] = $this->decode($token);

        $collection = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($collectionId)
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->first();

        if (! $collection) {
            throw ValidationException::withMessages(['form' => 'Форма больше не принимает заявки.']);
        }

        // Only the fields the form was issued for; nothing else reaches the record.
        $record = (new RecordRepository($collection))->create(array_intersect_key($values, array_flip($allowed)));

        app(ActivityLog::class)->record(
            'form.submitted',
            "{$collection->label} #{$record['id']}",
            tenant: $collection->tenant,
            actor: 'Посетитель сайта',
        );

        FormSubmitted::dispatch($collection, $record, [], AutomationDepth::current());

        if ($notify) {
            $this->notify($notify, $collection, $record, $allowed);
        }

        return $record;
    }

    /**
     * E-mails the submission to the address configured in the form block. A mail failure
     * must not lose the visitor's submission, so it is only reported.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $allowed
     */
    private function notify(string $email, Collection $collection, array $record, array $allowed): void
    {
        $values = $collection->fields
            ->filter(fn (CollectionField $f) => in_array($f->name, $allowed, true))
            ->map(fn (CollectionField $f) => [
                'label' => $f->label,
                'value' => match (true) {
                    is_bool($record[$f->name] ?? null) => $record[$f->name] ? 'Да' : 'Нет',
                    is_scalar($record[$f->name] ?? null) => (string) $record[$f->name],
                    default => '—',
                },
            ])
            ->values()
            ->all();

        try {
            Mail::to($email)->send(new FormSubmissionMail(
                $collection->tenant->name,
                $collection->label,
                $values,
                url('/admin/content/'.$collection->name.'?record='.$record['id']),
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<int, string>  $fields
     */
    private function token(Tenant $tenant, Collection $collection, array $fields, ?string $notifyEmail = null): string
    {
        $notify = $notifyEmail && filter_var($notifyEmail, FILTER_VALIDATE_EMAIL) ? $notifyEmail : null;

        return Crypt::encryptString((string) json_encode(['t' => $tenant->id, 'c' => $collection->id, 'f' => $fields, 'n' => $notify]));
    }

    /**
     * @return array{0: int, 1: int, 2: list<string>, 3: string|null}
     */
    private function decode(string $token): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            $data = null;
        }

        if (! is_array($data) || ! is_int($data['t'] ?? null) || ! is_int($data['c'] ?? null) || ! is_array($data['f'] ?? null)) {
            throw ValidationException::withMessages(['form' => 'Форма устарела, обновите страницу.']);
        }

        return [$data['t'], $data['c'], array_values(array_filter($data['f'], 'is_string')), is_string($data['n'] ?? null) ? $data['n'] : null];
    }
}
