<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A draft (one per target) or a published revision of a block tree.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $target
 * @property string $kind
 * @property array<int, array<string, mixed>> $tree
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class BlockVersion extends Model
{
    use BelongsToTenant;

    public const DRAFT = 'draft';

    public const REVISION = 'revision';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tree' => 'array',
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
