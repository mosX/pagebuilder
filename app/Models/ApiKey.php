<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A key for the company's headless API (/api/v1). Only its SHA-256 hash is stored.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $created_by
 * @property string $name
 * @property string $token_hash
 * @property string $prefix
 * @property bool $can_write
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
class ApiKey extends Model
{
    use BelongsToTenant;

    public const TOKEN_PREFIX = 'pb_';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_write' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Creates a key and returns it together with the plain token (shown once).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Tenant $tenant, string $name, bool $canWrite, ?User $creator): array
    {
        $token = self::TOKEN_PREFIX.Str::random(40);

        $key = new self;
        $key->forceFill([
            'tenant_id' => $tenant->id,
            'created_by' => $creator?->id,
            'name' => $name,
            'token_hash' => self::hash($token),
            'prefix' => substr($token, 0, 10),
            'can_write' => $canWrite,
        ])->save();

        return [$key, $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return str_starts_with($token, self::TOKEN_PREFIX)
            ? static::withoutGlobalScope('tenant')->with('tenant')->where('token_hash', self::hash($token))->first()
            : null;
    }
}
