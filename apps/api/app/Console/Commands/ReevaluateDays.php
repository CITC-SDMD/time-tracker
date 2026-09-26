<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\IntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Works out the level of every stored day again from its chunks. Run it after changing a number in config/integrity.php
// (docs/DEVELOPMENT_PLAN.md §16): the desktop apps do not need to change and no tracked time is touched.
class ReevaluateDays extends Command
{
    protected $signature = 'tracker:reevaluate-days {organization? : only this organization (its id)}';

    protected $description = 'Work out the activity check level of every stored day again with the current thresholds';

    public function handle(IntegrityService $integrity): int
    {
        $people = User::withoutGlobalScopes()->where('detection_enabled', true)->where('is_superadmin', false)
            ->when($this->argument('organization'), fn ($q, $id) => $q->where('organization_id', (int) $id));

        $days = 0;
        foreach ($people->pluck('id') as $userId) {
            foreach (DB::table('daily_summaries')->where('user_id', $userId)->pluck('day') as $day) {
                $integrity->refreshDay($userId, (string) $day);
                $days++;
            }
        }
        $this->info("Worked out {$days} days again.");

        return self::SUCCESS;
    }
}
