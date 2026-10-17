<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A visitor with an account on a company site (not an admin user).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property array<string, string>|null $profile
 * @property string $password
 * @property bool $is_blocked
 * @property string|null $remember_token
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['password', 'remember_token'])]
class SiteMember extends Authenticatable
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'profile' => 'array',
            'is_blocked' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
