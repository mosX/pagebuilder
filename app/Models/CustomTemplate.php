<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A company site saved as a template (see App\Support\Site\SiteSnapshots).
 * Belongs to the user who saved it; super admins may offer it to everyone.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $source_tenant_id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $data
 * @property bool $is_public
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class CustomTemplate extends Model
{
    public const KEY_PREFIX = 'custom:';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_public' => 'boolean',
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
     * Templates a user may create companies from: their own and the public ones.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailableTo(Builder $query, ?User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('is_public', true)
            ->when($user, fn (Builder $q) => $q->orWhere('user_id', $user?->id)));
    }

    public function key(): string
    {
        return self::KEY_PREFIX.$this->id;
    }

    public function storageDirectory(): string
    {
        return "site-templates/{$this->id}";
    }
}
