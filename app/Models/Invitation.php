<?php

namespace App\Models;

use App\Enums\TenantRole;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation to join a company with a role, accepted by the invited person via a link.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property TenantRole $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $inviter
 */
class Invitation extends Model
{
    use BelongsToTenant;

    public const VALID_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => TenantRole::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Issues a new link token (an earlier link stops working) and extends the validity.
     *
     * @return string The token for the link.
     */
    public function refreshToken(): string
    {
        $token = Str::random(48);
        $this->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        return $token;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * The invitation of a link token, in any company.
     */
    public static function findByToken(string $token): ?self
    {
        return static::withoutGlobalScope('tenant')
            ->where('token_hash', hash('sha256', $token))
            ->first();
    }

    public function url(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/invite/'.$token;
    }
}
