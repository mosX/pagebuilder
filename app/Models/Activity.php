<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An entry of a company's activity log.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property string|null $actor
 * @property string $action
 * @property string $subject
 * @property array<string, mixed>|null $details
 * @property string|null $ip
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
final class Activity extends Model
{
    use BelongsToTenant, MassPrunable;

    public const KEEP_DAYS = 180;

    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Entries older than the retention period (runs across all companies).
     *
     * @return Builder<Activity>
     */
    public function prunable(): Builder
    {
        return self::withoutGlobalScope('tenant')->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
