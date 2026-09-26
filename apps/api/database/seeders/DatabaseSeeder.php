<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. The platform owner and the first organization are created separately, via
     * `php artisan tracker:make-superadmin` and `tracker:make-organization` (docs/DEVELOPMENT_PLAN.md §9.4), not
     * here, since they need real names and emails rather than fake data. The platform settings row is made by a migration.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
        ]);
    }
}
