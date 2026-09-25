<?php

namespace Database\Seeders;

use App\Models\OfficeSetting;
use Illuminate\Database\Seeder;

// Defaults from docs/DEVELOPMENT_PLAN.md §12 Phase 1 task 9. Idempotent — safe to
// run again (e.g. after php artisan migrate:fresh --seed in dev).
class OfficeSettingsSeeder extends Seeder
{
    public function run(): void
    {
        OfficeSetting::query()->updateOrCreate(['id' => 1], [
            'timezone' => 'Asia/Manila',
            'idle_threshold_seconds' => 300,
            'window_title_mode' => 'full',
            'min_agent_version' => '0.1.0',
            'consent_version' => 1,
        ]);
    }
}
