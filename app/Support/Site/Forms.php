<?php

namespace App\Support\Site;

use App\Enums\FieldType;
use App\Events\FormSubmitted;
use App\Mail\FormConfirmationMail;
use App\Mail\FormSubmissionMail;
use App\Models\Collection;
use App\Models\CollectionField;
use App\Models\Tenant;
use App\Support\Audit\ActivityLog;
use App\Support\Automation\AutomationDepth;
use App\Support\Collections\RecordRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
    /** Seconds a form token stays valid (pages, cached ones included, are re-rendered far more often). */
    public const TOKEN_TTL = 7 * 24 * 3600;

    /** Field types a visitor can fill in. */
    public const FIELD_TYPES = [
        FieldType::String, FieldType::Text, FieldType::Email, FieldType::Url, FieldType::Integer,
        FieldType::Decimal, FieldType::Boolean, FieldType::Date, FieldType::DateTime, FieldType::Select,
        FieldType::File,
    ];

    /** Confirmation e-mails per recipient per hour: the form must not become a way to spam people. */
    public const CONFIRMATIONS_PER_HOUR = 3;

    public function __construct(
        private FormUploads $uploads,
        private Captcha $captcha,
    ) {}

    /**
     * The fields of a form: those the editor chose plus every required field of the
     * collection (a record cannot be saved without them).
     *
     * @param  array<int, mixed>  $chosen
     * @param  array{captcha?: bool, confirm?: string|null}  $options  Captcha on/off; the message of the confirmation e-mail to the visitor.
     * @return array{fields: array<int, array<string, mixed>>, token: string|null, error: string|null, captcha: array<string, string>|null}
     */
    public function schema(Tenant $tenant, Collection $collection, array $chosen, bool $withToken, ?string $notifyEmail = null, array $options = []): array
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
            'token' => $withToken && ! $blocked && $fields->isNotEmpty() ? $this->token($tenant, $collection, $fields->map(fn (CollectionField $f) => $f->name)->all(), $notifyEmail, $options) : null,
            'captcha' => ($options['captcha'] ?? true) ? $this->captcha->widget() : null,
            'error' => $blocked ? "Обязательное поле «{$blocked->label}» нельзя заполнить через форму." : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $values  Field values; uploaded files for file fields.
     * @return array<string, mixed>
     */
    public function submit(string $token, array $values, ?string $captchaToken = null, ?string $ip = null): array
    {
        ['tenant' => $tenantId, 'collection' => $collectionId, 'fields' => $allowed, 'notify' => $notify, 'captcha' => $withCaptcha, 'confirm' => $confirm] = $this->decode($token);

        if ($withCaptcha && ! $this->captcha->verify($captchaToken, $ip)) {
            throw ValidationException::withMessages(['captcha' => 'Подтвердите, что вы не робот.']);
        }

        $collection = Collection::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereKey($collectionId)
            ->whereHas('tenant', fn ($q) => $q->where('is_active', true))
            ->first();

        if (! $collection) {
            throw ValidationException::withMessages(['form' => 'Форма больше не принимает заявки.']);
        }

        // Only the fields the form was issued for; nothing else reaches the record.
        $values = array_intersect_key($values, array_flip($allowed));
        [$values, $stored] = $this->storeFiles($collection, $values);

        try {
            $record = (new RecordRepository($collection))->create($values);
        } catch (Throwable $e) {
            // The submission was rejected: its files must not stay behind.
            foreach ($stored as $file) {
                $this->uploads->delete($file);
            }

            throw $e;
        }

        app(ActivityLog::class)->record(
            'form.submitted',
            "{$collection->label} #{$record['id']}",
            ['collection' => $collection->name, 'record' => $record['id']],
            tenant: $collection->tenant,
            actor: 'Посетитель сайта',
        );

        FormSubmitted::dispatch($collection, $record, [], AutomationDepth::current());

        if ($notify) {
            $this->notify($notify, $collection, $record, $allowed);
        }

        if ($confirm !== null) {
            $this->confirm($confirm, $collection, $record, $allowed);
        }

        return $record;
    }

    /**
     * Visitors attach files only as uploads (checked by content), stored privately;
     * a file field never takes a link or path from the visitor.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, mixed>, 1: list<string>} values with stored paths, stored files
     */
    private function storeFiles(Collection $collection, array $values): array
    {
        $stored = [];

        foreach ($collection->fields->where('type', FieldType::File) as $field) {
            if (! array_key_exists($field->name, $values)) {
                continue;
            }

            $file = $values[$field->name];

            if (! $file instanceof UploadedFile) {
                unset($values[$field->name]);

                continue;
            }

            Validator::make(
                [$field->name => $file],
                [$field->name => ['file', 'max:'.FormUploads::MAX_KILOBYTES, Rule::file()->types($this->uploads->extensions())]],
                [],
                [$field->name => $field->label],
            )->validate();

            $values[$field->name] = $stored[] = $this->uploads->store($collection->tenant_id, $file);
        }

        return [$values, $stored];
    }

    /**
     * Tells the visitor their submission arrived, at the first e-mail field of the form.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $allowed
     */
    private function confirm(string $message, Collection $collection, array $record, array $allowed): void
    {
        $field = $collection->fields->first(fn (CollectionField $f) => $f->type === FieldType::Email && in_array($f->name, $allowed, true) && is_string($record[$f->name] ?? null));
        $email = $field ? Str::lower((string) $record[$field->name]) : null;

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $key = 'form-confirm:'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, self::CONFIRMATIONS_PER_HOUR)) {
            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            Mail::to($email)->send(new FormConfirmationMail($collection->tenant->name, $message, $this->summary($collection, $record, $allowed)));
        } catch (Throwable $e) {
            report($e);
        }
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
        $values = $this->summary($collection, $record, $allowed);

        try {
            Mail::to($email)->send(new FormSubmissionMail(
                $collection->tenant->name,
                $collection->label,
                $values,
                rtrim((string) config('app.url'), '/').'/admin/content/'.$collection->name.'?record='.$record['id'],
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The submitted values as label/value pairs for e-mails.
     *
     * @param  array<string, mixed>  $record
     * @param  list<string>  $allowed
     * @return array<int, array{label: string, value: string}>
     */
    private function summary(Collection $collection, array $record, array $allowed): array
    {
        return $collection->fields
            ->filter(fn (CollectionField $f) => in_array($f->name, $allowed, true))
            ->map(fn (CollectionField $f) => [
                'label' => $f->label,
                'value' => match (true) {
                    $this->uploads->isPrivate($record[$f->name] ?? null) => 'файл: '.$this->uploads->name((string) $record[$f->name]),
                    is_bool($record[$f->name] ?? null) => $record[$f->name] ? 'Да' : 'Нет',
                    is_scalar($record[$f->name] ?? null) => (string) $record[$f->name],
                    default => '—',
                },
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $fields
     * @param  array{captcha?: bool, confirm?: string|null}  $options
     */
    private function token(Tenant $tenant, Collection $collection, array $fields, ?string $notifyEmail = null, array $options = []): string
    {
        $notify = $notifyEmail && filter_var($notifyEmail, FILTER_VALIDATE_EMAIL) ? $notifyEmail : null;
        $confirm = isset($options['confirm']) && trim($options['confirm']) !== '' ? Str::limit(trim($options['confirm']), 1000, '') : null;

        return Crypt::encryptString((string) json_encode([
            't' => $tenant->id,
            'c' => $collection->id,
            'f' => $fields,
            'n' => $notify,
            'i' => now()->getTimestamp(),
            // Whether the form shows a captcha, so a submission without one is refused.
            'k' => ($options['captcha'] ?? true) && $this->captcha->enabled(),
            'm' => $confirm,
        ]));
    }

    /**
     * @return array{tenant: int, collection: int, fields: list<string>, notify: string|null, captcha: bool, confirm: string|null}
     */
    private function decode(string $token): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            $data = null;
        }

        if (! is_array($data) || ! is_int($data['t'] ?? null) || ! is_int($data['c'] ?? null) || ! is_array($data['f'] ?? null)
            || ! is_int($data['i'] ?? null) || $data['i'] < now()->getTimestamp() - self::TOKEN_TTL) {
            throw ValidationException::withMessages(['form' => 'Форма устарела, обновите страницу.']);
        }

        return [
            'tenant' => $data['t'],
            'collection' => $data['c'],
            'fields' => array_values(array_filter($data['f'], 'is_string')),
            'notify' => is_string($data['n'] ?? null) ? $data['n'] : null,
            'captcha' => (bool) ($data['k'] ?? false),
            'confirm' => is_string($data['m'] ?? null) ? $data['m'] : null,
        ];
    }
}
