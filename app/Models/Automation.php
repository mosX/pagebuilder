<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * "When <event> in <collection> and <conditions> → do <actions>".
 *
 * trigger: {event, collection, conditions: [{field, operator, value}]}
 * actions: [{type: send_email|update_record|create_record|webhook, ...}]
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property bool $is_active
 * @property array<string, mixed> $trigger
 * @property list<array<string, mixed>> $actions
 * @property int $runs_count
 * @property Carbon|null $last_run_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
class Automation extends Model
{
    use BelongsToTenant;

    public const EVENTS = ['record.created', 'record.updated', 'record.deleted', 'form.submitted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trigger' => 'array',
            'actions' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<AutomationRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
