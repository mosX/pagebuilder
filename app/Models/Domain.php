<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A company's own domain for its site. It serves the site once ownership is verified
 * with a TXT record; the primary domain is the canonical address of the site.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $host
 * @property string $verification_token
 * @property Carbon|null $verified_at
 * @property bool $is_primary
 * @property Carbon|null $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Domain extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'is_primary' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
