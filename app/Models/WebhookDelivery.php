<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One attempt to deliver an event to a webhook.
 *
 * @property int $id
 * @property int $webhook_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int|null $status
 * @property bool $success
 * @property int $attempt
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class WebhookDelivery extends Model
{
    public const UPDATED_AT = null;

    public const KEEP = 100;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'success' => 'boolean',
        ];
    }
}
