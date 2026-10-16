<?php

namespace App\Models;

use App\Enums\TenantRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property TenantRole $role
 */
class Membership extends Pivot
{
    protected $table = 'tenant_user';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
        ];
    }
}
