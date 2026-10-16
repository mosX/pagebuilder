<?php

namespace App\Models;

use App\Enums\TenantRole;
use App\Support\Collections\SchemaManager;
use App\Support\Media\MediaLibrary;
use App\Support\Tenancy\Subdomains;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Membership|null $membership
 */
#[Fillable(['name', 'slug', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->slug = $tenant->slug ?: static::uniqueSlug($tenant->name);
        });

        // Collection tables and stored files are not covered by foreign key cascades.
        static::deleting(function (Tenant $tenant) {
            app(SchemaManager::class)->dropTenant($tenant);
            app(MediaLibrary::class)->deleteTenant($tenant);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, Membership, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    public function addMember(User $user, TenantRole $role): void
    {
        $this->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
    }

    public function ownersCount(): int
    {
        return $this->members()->wherePivot('role', TenantRole::Owner->value)->count();
    }

    /**
     * A free slug that is also a valid, non-reserved subdomain.
     */
    public static function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = trim(Str::limit(Str::slug($name), 55, ''), '-');
        $base = Subdomains::isValid($base) ? $base : 'company'.($base !== '' ? '-'.$base : '');
        $base = Subdomains::isValid($base) ? $base : 'company';
        $slug = $base;
        $i = 2;

        while (static::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function siteUrl(): string
    {
        return Subdomains::siteUrl($this);
    }
}
