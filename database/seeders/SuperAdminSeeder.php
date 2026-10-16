<?php

namespace Database\Seeders;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Collections\PagesCollection;
use Illuminate\Database\Seeder;

/**
 * Creates (or updates) the platform super admin and their default company.
 * Credentials come from SUPERADMIN_* env variables; safe to run repeatedly.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array{name: string, email: string, password: string, company: string} $config */
        $config = config('tenancy.super_admin');
        $email = $config['email'];
        $password = $config['password'];

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $config['name'],
            'is_super_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if (! $user->exists) {
            $user->password = $password;
        }

        $user->save();

        $tenant = Tenant::firstOrCreate(
            ['slug' => 'main'],
            ['name' => $config['company']],
        );

        $tenant->addMember($user, TenantRole::Owner);
        app(PagesCollection::class)->ensure($tenant);

        if (! $user->current_tenant_id) {
            $user->switchTenant($tenant);
        }

        $this->command->info("Super admin: {$email}".($user->wasRecentlyCreated ? " / {$password}" : ' (already existed, password unchanged)'));
    }
}
