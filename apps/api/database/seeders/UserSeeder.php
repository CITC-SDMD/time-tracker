<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationService;
use App\Support\OrganizationContext;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// two known accounts for local development, both with the password "password": the platform owner (a superadmin with
// every platform permission) and the admin of a demo organization, which the demo seeder then fills. never runs in
// production: `php artisan migrate --seed` is part of the server setup, and it must not leave accounts with a
// public password behind. the real first accounts are made with tracker:make-superadmin and tracker:make-organization.
class UserSeeder extends Seeder
{
    public function run(OrganizationService $organizations, OrganizationContext $context): void
    {
        if (app()->isProduction()) {
            return;
        }

        // role, organization and status are not mass-assignable on the user model (only its controllers set them),
        // so this seeder writes them explicitly. updateOrCreate lets it run more than once.
        User::unguarded(function () use ($organizations, $context) {
            User::withoutGlobalScopes()->updateOrCreate(['email' => 'admin@test.com'], [
                'name' => 'Superadmin',
                'password' => Hash::make('password'),
                'is_superadmin' => true,
                'is_owner' => true,
                'superadmin_permissions' => Permissions::superadminKeys(),
                'organization_id' => null,
                'role_id' => null,
                'status' => 'active',
            ]);

            $demo = Organization::where('slug', 'demo-office')->first() ?? $organizations->create('Demo Office');
            $context->within($demo->id, function () use ($demo) {
                $admin = Role::where('is_system', true)->firstOrFail();
                $admin->name = 'Admin'; // the demo office calls its admin role by the name the live tests know
                $admin->save();

                User::withoutGlobalScopes()->updateOrCreate(['email' => 'oic@test.com'], [
                    'name' => 'OIC',
                    'password' => Hash::make('password'),
                    'organization_id' => $demo->id,
                    'role_id' => $admin->id,
                    'status' => 'active',
                ]);
            });
        });
    }
}
