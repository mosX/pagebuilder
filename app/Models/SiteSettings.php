<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property array<string, mixed>|null $theme
 * @property array<int, array<string, mixed>>|null $menu
 * @property array<int, array<string, mixed>>|null $header
 * @property array<int, array<string, mixed>>|null $footer
 * @property array<string, mixed>|null $languages
 * @property array<string, mixed>|null $account
 * @property string|null $logo
 * @property string|null $favicon
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteSettings extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme' => 'array',
            'menu' => 'array',
            'header' => 'array',
            'footer' => 'array',
            'languages' => 'array',
            'account' => 'array',
        ];
    }
}
