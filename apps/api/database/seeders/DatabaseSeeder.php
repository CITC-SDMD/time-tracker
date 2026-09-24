<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. The first OIC account is created separately,
     * via `php artisan tracker:make-oic` (docs/DEVELOPMENT_PLAN.md §9.2) — not here,
     * since it needs a real name/email rather than fake data.
     */
    public function run(): void
    {
        $this->call(OfficeSettingsSeeder::class);
    }
}
