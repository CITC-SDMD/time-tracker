<?php

namespace App\Jobs;

use App\Models\OrganizationSetting;
use App\Services\SummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

// After an organization changes its timezone: puts every stored session on the right day and rebuilds the daily
// totals with the new day boundaries (SummaryService::rebuildForOrganization). It reads the timezone when it runs, so
// two quick changes end with the latest one, and only one rebuild of an organization runs at a time.
class RebuildDaysJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public int $organizationId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->organizationId))->releaseAfter(60)->expireAfter(1800)];
    }

    public function handle(SummaryService $summaries): void
    {
        $timezone = OrganizationSetting::withoutGlobalScopes()->where('organization_id', $this->organizationId)->value('timezone');
        if ($timezone === null) {
            return;
        }

        $summaries->rebuildForOrganization($this->organizationId, $timezone);
    }
}
