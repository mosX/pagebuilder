<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The values of a record right after one change (see App\Support\Collections\RecordHistory).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $collection_id
 * @property int $record_id
 * @property int|null $user_id
 * @property string|null $actor
 * @property list<string> $changes
 * @property array<string, mixed> $values
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class RecordRevision extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'values' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
