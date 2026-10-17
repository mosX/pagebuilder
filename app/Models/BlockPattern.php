<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reusable section: a block tree inserted into pages by reference,
 * so editing it updates every page that uses it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property array<int, array<string, mixed>> $tree
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'tree'])]
class BlockPattern extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tree' => 'array',
        ];
    }
}
