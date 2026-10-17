<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use App\Enums\TenantRole;
use App\Mail\AccountLinkMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_super_admin
 * @property int|null $current_tenant_id
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Membership|null $membership
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * The reset link leads to the admin SPA (the e-mail is in Russian, like the rest of the app).
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = rtrim((string) config('app.url'), '/').'/reset-password/'.$token.'?email='.rawurlencode($this->email);
        $minutes = (int) config('auth.passwords.users.expire', 60);

        Mail::to($this->email)->send(AccountLinkMail::passwordReset((string) config('app.name'), $this->name, $url, $minutes));
    }

    /**
     * The confirmation link is signed without the host, so it works whatever APP_URL is.
     */
    public function sendEmailVerificationNotification(): void
    {
        $path = URL::temporarySignedRoute('verification.verify', now()->addDays(3), [
            'id' => $this->id,
            'hash' => sha1($this->email),
        ], absolute: false);

        Mail::to($this->email)->send(AccountLinkMail::emailVerification((string) config('app.name'), $this->name, rtrim((string) config('app.url'), '/').$path));
    }

    /**
     * @return BelongsToMany<Tenant, $this, Membership, 'membership'>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /**
     * The user's effective role in a tenant. Super admins act as owners everywhere.
     */
    public function roleIn(Tenant $tenant): ?TenantRole
    {
        if ($this->is_super_admin) {
            return TenantRole::Owner;
        }

        return $this->membershipIn($tenant)?->role;
    }

    public function membershipIn(Tenant $tenant): ?Membership
    {
        return $this->tenants()->whereKey($tenant->id)->first()?->membership;
    }

    public function hasPermissionIn(Tenant $tenant, Permission $permission): bool
    {
        return (bool) $this->roleIn($tenant)?->can($permission);
    }

    public function switchTenant(Tenant $tenant): void
    {
        $this->forceFill(['current_tenant_id' => $tenant->id])->save();
        $this->setRelation('currentTenant', $tenant);
    }
}
