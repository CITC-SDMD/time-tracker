<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// two known accounts for local development, both with the password "password". never runs in
// production: `php artisan migrate --seed` is part of the server setup, and it must not leave
// accounts with a public password behind. the real first account is made with tracker:make-oic.
class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        // role and status are not mass-assignable on the user model (only its controllers set them),
        // so this seeder writes them explicitly. updateOrCreate lets it run more than once.
        User::unguarded(function () {
            foreach ([
                ['name' => 'Superadmin', 'email' => 'admin@test.com', 'role' => 'superadmin'],
                ['name' => 'OIC', 'email' => 'oic@test.com', 'role' => 'oic'],
            ] as $account) {
                User::updateOrCreate(['email' => $account['email']], [
                    'name' => $account['name'],
                    'password' => Hash::make('password'),
                    'role' => $account['role'],
                    'status' => 'active',
                ]);
            }
        });
    }
}
