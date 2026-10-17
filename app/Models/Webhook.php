<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A URL notified about company events (record.created, form.submitted, …).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $url
 * @property list<string> $events
 * @property string|null $collection
 * @property string $secret
 * @property bool $is_active
 * @property Carbon|null $last_called_at
 * @property int|null $last_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
class Webhook extends Model
{
    use BelongsToTenant;

    public const EVENTS = ['record.created', 'record.updated', 'record.deleted', 'form.submitted', 'page.published'];

    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_called_at' => 'datetime',
        ];
    }

    public static function newSecret(): string
    {
        return Str::random(40);
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function listensTo(string $event, ?string $collection): bool
    {
        return $this->is_active
            && in_array($event, $this->events, true)
            && ($this->collection === null || $collection === null || $this->collection === $collection);
    }
}
