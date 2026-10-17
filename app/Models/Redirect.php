<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A redirect of a company site from an old address (relative to the site root, e.g.
 * "/old-page" or "/blog/*") to a new one ("/new-page", "/news/*" or "https://…").
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $from_path
 * @property string $to_path
 * @property int $status
 * @property bool $is_auto
 * @property int $hits
 * @property Carbon|null $last_hit_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Redirect extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_auto' => 'boolean',
            'last_hit_at' => 'datetime',
        ];
    }
}
